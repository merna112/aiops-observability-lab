<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Computes and maintains per-endpoint baselines for latency, request rate,
 * and error rate using an Exponential Moving Average (EMA).
 *
 * On the first observation for an endpoint the baseline is seeded from a
 * wider historical window (30 m) queried from Prometheus so that short
 * anomaly spikes do not corrupt the initial reference value.
 *
 * Subsequent updates apply a low alpha (0.1) so that current anomalies
 * shift the baseline only very slowly.
 */
class BaselineComputer
{
    /** EMA smoothing factor — lower values make the baseline more stable. */
    private float $alpha = 0.1;

    private string $baselinePath;
    private PrometheusClient $prometheus;

    public function __construct(PrometheusClient $prometheus)
    {
        $this->prometheus = $prometheus;
        $this->baselinePath = storage_path('aiops/baselines.json');
    }

    // -------------------------------------------------------------------------
    //  Public API
    // -------------------------------------------------------------------------

    /**
     * Update baselines for all monitored endpoints using the supplied
     * current-window metrics, then persist and return the updated baselines.
     *
     * @param  string[] $endpoints  e.g. ['api/normal', 'api/slow', ...]
     * @param  array    $currentMetrics  keyed by endpoint path
     */
    public function computeAndUpdate(array $endpoints, array $currentMetrics): array
    {
        $existing = $this->loadBaselines();
        $updated = $existing;

        foreach ($endpoints as $path) {
            $current = $currentMetrics[$path] ?? [];

            // Skip endpoints with no live data yet
            if (empty(array_filter($current, fn($v) => $v !== null))) {
                continue;
            }

            if (!isset($existing[$path])) {
                // ── Seed from a wide historical window ─────────────────────
                $historical = $this->prometheus->getAllEndpointMetrics($path, '30m');

                $updated[$path] = [
                    'avg_latency' => $historical['avg_latency'] ?? $current['avg_latency'],
                    'p95_latency' => $historical['p95_latency'] ?? $current['p95_latency'],
                    'request_rate' => $historical['request_rate'] ?? $current['request_rate'],
                    'error_rate' => $historical['error_rate'] ?? $current['error_rate'],
                    'sample_count' => 1,
                    'seeded_at' => now()->toISOString(),
                    'updated_at' => now()->toISOString(),
                ];

                Log::info('BaselineComputer: seeded baseline', [
                    'endpoint' => $path,
                    'baseline' => $updated[$path],
                ]);
            } else {
                // ── Exponential moving average update ──────────────────────
                $base = $existing[$path];
                $a = $this->alpha;

                $updated[$path] = [
                    'avg_latency' => $this->ema($base['avg_latency'] ?? null, $current['avg_latency'] ?? null, $a),
                    'p95_latency' => $this->ema($base['p95_latency'] ?? null, $current['p95_latency'] ?? null, $a),
                    'request_rate' => $this->ema($base['request_rate'] ?? null, $current['request_rate'] ?? null, $a),
                    'error_rate' => $this->ema($base['error_rate'] ?? null, $current['error_rate'] ?? null, $a),
                    'sample_count' => ($base['sample_count'] ?? 0) + 1,
                    'seeded_at' => $base['seeded_at'] ?? now()->toISOString(),
                    'updated_at' => now()->toISOString(),
                ];
            }
        }

        $this->saveBaselines($updated);
        return $updated;
    }

    // -------------------------------------------------------------------------
    //  Persistence helpers
    // -------------------------------------------------------------------------

    public function loadBaselines(): array
    {
        if (!file_exists($this->baselinePath)) {
            return [];
        }
        return json_decode(file_get_contents($this->baselinePath), true) ?? [];
    }

    public function saveBaselines(array $baselines): void
    {
        $this->ensureDir($this->baselinePath);
        file_put_contents(
            $this->baselinePath,
            json_encode($baselines, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    // -------------------------------------------------------------------------
    //  Private helpers
    // -------------------------------------------------------------------------

    /**
     * One-step EMA update.
     * α · current + (1 − α) · existing
     * If either value is null the other is returned unchanged.
     */
    private function ema(?float $existing, ?float $current, float $alpha): ?float
    {
        if ($current === null) {
            return $existing;
        }
        if ($existing === null) {
            return $current;
        }
        return $alpha * $current + (1.0 - $alpha) * $existing;
    }

    private function ensureDir(string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
