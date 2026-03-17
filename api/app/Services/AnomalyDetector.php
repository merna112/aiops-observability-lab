<?php

namespace App\Services;

/**
 * Multi-signal anomaly detector.
 *
 * Compares live endpoint metrics against stored baselines and produces a flat
 * list of typed "signal" objects whenever a threshold is exceeded.
 *
 * Thresholds (all configurable via env):
 *   AIOPS_LATENCY_MULTIPLIER   (default 3.0)  — avg latency > N × baseline
 *   AIOPS_P95_MULTIPLIER       (default 3.0)  — p95 latency  > N × baseline
 *   AIOPS_ERROR_RATE_THRESHOLD (default 0.10) — error rate   > N (absolute)
 *   AIOPS_TRAFFIC_MULTIPLIER   (default 2.0)  — req/s        > N × baseline
 *
 * Floor values prevent false positives when baselines are near-zero.
 */
class AnomalyDetector
{
    // ── Detection thresholds ──────────────────────────────────────────────────
    private float $latencyMultiplier;
    private float $p95Multiplier;
    private float $errorRateThreshold;
    private float $trafficMultiplier;

    // ── Floor values — avoids division / false positive issues ───────────────
    /** Minimum baseline latency (s) before latency rules fire */
    private float $minBaselineLatency = 0.005;  // 5 ms
    /** Minimum baseline request rate (req/s) before traffic rules fire */
    private float $minBaselineRate = 0.001;

    public function __construct()
    {
        $this->latencyMultiplier   = (float) env('AIOPS_LATENCY_MULTIPLIER',    3.0);
        $this->p95Multiplier       = (float) env('AIOPS_P95_MULTIPLIER',        3.0);
        $this->errorRateThreshold  = (float) env('AIOPS_ERROR_RATE_THRESHOLD',  0.10);
        $this->trafficMultiplier   = (float) env('AIOPS_TRAFFIC_MULTIPLIER',    2.0);
    }

    // -------------------------------------------------------------------------
    //  Public API
    // -------------------------------------------------------------------------

    /**
     * Evaluate all endpoints and return a list of anomaly signal objects.
     *
     * Each signal contains:
     *   type, endpoint, observed, baseline, ratio|threshold, description
     *
     * @param  array $currentMetrics  ['api/normal' => ['avg_latency' => …], …]
     * @param  array $baselines       same shape as currentMetrics
     * @return array<int, array>
     */
    public function detectAnomalies(array $currentMetrics, array $baselines): array
    {
        $signals = [];

        foreach ($currentMetrics as $path => $metrics) {
            $base = $baselines[$path] ?? null;

            // We cannot detect anomalies without a baseline reference
            if ($base === null || ($base['sample_count'] ?? 0) < 1) {
                continue;
            }

            $newSignals = array_merge(
                $this->checkLatency($path, $metrics, $base),
                $this->checkP95Latency($path, $metrics, $base),
                $this->checkErrorRate($path, $metrics, $base),
                $this->checkTraffic($path, $metrics, $base)
            );

            $signals = array_merge($signals, $newSignals);
        }

        return $signals;
    }

    // -------------------------------------------------------------------------
    //  Per-signal check methods
    // -------------------------------------------------------------------------

    private function checkLatency(string $path, array $metrics, array $base): array
    {
        $current  = $metrics['avg_latency'] ?? null;
        $baseline = max($base['avg_latency'] ?? 0.0, $this->minBaselineLatency);

        if ($current === null || $current <= $this->latencyMultiplier * $baseline) {
            return [];
        }

        return [[
            'type'        => 'LATENCY_ANOMALY',
            'endpoint'    => $path,
            'observed'    => round($current, 6),
            'baseline'    => round($baseline, 6),
            'ratio'       => round($current / $baseline, 2),
            'threshold'   => $this->latencyMultiplier,
            'description' => sprintf(
                'avg latency %.1fms exceeds %.1fx baseline (%.1fms)',
                $current * 1000,
                $this->latencyMultiplier,
                $baseline * 1000
            ),
        ]];
    }

    private function checkP95Latency(string $path, array $metrics, array $base): array
    {
        $current  = $metrics['p95_latency'] ?? null;
        $baseline = max($base['p95_latency'] ?? 0.0, $this->minBaselineLatency);

        if ($current === null || $current <= $this->p95Multiplier * $baseline) {
            return [];
        }

        return [[
            'type'        => 'P95_LATENCY_ANOMALY',
            'endpoint'    => $path,
            'observed'    => round($current, 6),
            'baseline'    => round($baseline, 6),
            'ratio'       => round($current / $baseline, 2),
            'threshold'   => $this->p95Multiplier,
            'description' => sprintf(
                'p95 latency %.1fms exceeds %.1fx baseline (%.1fms)',
                $current * 1000,
                $this->p95Multiplier,
                $baseline * 1000
            ),
        ]];
    }

    private function checkErrorRate(string $path, array $metrics, array $base): array
    {
        $current  = $metrics['error_rate'] ?? null;
        $baseline = $base['error_rate'] ?? 0.0;

        if ($current === null || $current <= $this->errorRateThreshold) {
            return [];
        }

        return [[
            'type'        => 'ERROR_RATE_ANOMALY',
            'endpoint'    => $path,
            'observed'    => round($current, 4),
            'baseline'    => round($baseline, 4),
            'ratio'       => $baseline > 0 ? round($current / $baseline, 2) : null,
            'threshold'   => $this->errorRateThreshold,
            'description' => sprintf(
                'error rate %.1f%% exceeds threshold %.0f%%',
                $current * 100,
                $this->errorRateThreshold * 100
            ),
        ]];
    }

    private function checkTraffic(string $path, array $metrics, array $base): array
    {
        $current  = $metrics['request_rate'] ?? null;
        $baseline = max($base['request_rate'] ?? 0.0, $this->minBaselineRate);

        if ($current === null || $current <= $this->trafficMultiplier * $baseline) {
            return [];
        }

        return [[
            'type'        => 'TRAFFIC_ANOMALY',
            'endpoint'    => $path,
            'observed'    => round($current, 6),
            'baseline'    => round($baseline, 6),
            'ratio'       => round($current / $baseline, 2),
            'threshold'   => $this->trafficMultiplier,
            'description' => sprintf(
                'request rate %.4f req/s exceeds %.1fx baseline (%.4f req/s)',
                $current,
                $this->trafficMultiplier,
                $baseline
            ),
        ]];
    }
}
