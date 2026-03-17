<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * HTTP client for querying the Prometheus API.
 * Targets the /api/v1/query endpoint to fetch current metric values.
 */
class PrometheusClient
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('PROMETHEUS_URL', 'http://localhost:9090'), '/');
    }

    // -------------------------------------------------------------------------
    //  Core query methods
    // -------------------------------------------------------------------------

    /**
     * Execute an instant PromQL query and return the raw result array.
     * Returns null on network/parse failure.
     */
    public function query(string $promql): ?array
    {
        try {
            $ch = curl_init("{$this->baseUrl}/api/v1/query?" . http_build_query(['query' => $promql]));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $raw = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw === false || $httpCode !== 200) {
                return null;
            }

            $data = json_decode($raw, true);
            if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
                return null;
            }

            return $data['data']['result'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('PrometheusClient::query failed', [
                'query' => $promql,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Execute a PromQL query and return the first scalar value, or null.
     */
    public function queryScalar(string $promql): ?float
    {
        $results = $this->query($promql);
        if (empty($results)) {
            return null;
        }

        $raw = $results[0]['value'][1] ?? null;
        if ($raw === null || $raw === 'NaN' || $raw === '+Inf' || $raw === '-Inf') {
            return null;
        }

        return (float) $raw;
    }

    // -------------------------------------------------------------------------
    //  Per-endpoint metric helpers
    // -------------------------------------------------------------------------

    /**
     * Total request rate (req/s) for an endpoint over the given window.
     * Example path: "api/normal"
     */
    public function getRequestRate(string $path, string $window = '5m'): ?float
    {
        return $this->queryScalar(
            "sum(rate(http_requests_total{path=\"{$path}\"}[{$window}]))"
        );
    }

    /**
     * Error fraction (0–1) for an endpoint.
     * Returns 0 when there are requests but no errors.
     * Returns null when there is no traffic yet.
     */
    public function getErrorRate(string $path, string $window = '5m'): ?float
    {
        $totalRate = $this->queryScalar(
            "sum(rate(http_requests_total{path=\"{$path}\"}[{$window}]))"
        );

        if ($totalRate === null || $totalRate <= 0.0) {
            return null;
        }

        $errorRate = $this->queryScalar(
            "sum(rate(http_errors_total{path=\"{$path}\"}[{$window}]))"
        );

        // No error series means zero errors
        return ($errorRate ?? 0.0) / $totalRate;
    }

    /**
     * Mean request duration (seconds) for an endpoint.
     */
    public function getAvgLatency(string $path, string $window = '5m'): ?float
    {
        return $this->queryScalar(
            "sum(rate(http_request_duration_seconds_sum{path=\"{$path}\"}[{$window}]))"
            . " / "
            . "sum(rate(http_request_duration_seconds_count{path=\"{$path}\"}[{$window}]))"
        );
    }

    /**
     * Histogram quantile latency (seconds) for an endpoint.
     * $percentile e.g. 0.95 for p95.
     */
    public function getLatencyPercentile(string $path, float $percentile, string $window = '5m'): ?float
    {
        return $this->queryScalar(
            "histogram_quantile({$percentile},"
            . " sum by(le) (rate(http_request_duration_seconds_bucket{path=\"{$path}\"}[{$window}])))"
        );
    }

    /**
     * Snapshot of all error-category counters across all endpoints.
     * Returns: ["api/error:SYSTEM_ERROR" => 42.0, ...]
     */
    public function getErrorCategoryCounters(): array
    {
        $results = $this->query('http_errors_total') ?? [];
        $out = [];
        foreach ($results as $result) {
            $path = $result['metric']['path'] ?? 'unknown';
            $category = $result['metric']['error_category'] ?? 'UNKNOWN';
            $count = (float) ($result['value'][1] ?? 0);
            $out["{$path}:{$category}"] = $count;
        }
        return $out;
    }

    /**
     * Whether the ground-truth anomaly window marker is active.
     */
    public function getAnomalyWindowActive(): bool
    {
        $val = $this->queryScalar('anomaly_window_active');
        return $val !== null && $val > 0;
    }

    /**
     * Convenience method: fetch all key metrics for one endpoint in one call.
     *
     * Returns an array with keys:
     *   request_rate, error_rate, avg_latency, p95_latency, p50_latency
     * Any value may be null if the metric is unavailable.
     */
    public function getAllEndpointMetrics(string $path, string $window = '5m'): array
    {
        return [
            'request_rate' => $this->getRequestRate($path, $window),
            'error_rate' => $this->getErrorRate($path, $window),
            'avg_latency' => $this->getAvgLatency($path, $window),
            'p95_latency' => $this->getLatencyPercentile($path, 0.95, $window),
            'p50_latency' => $this->getLatencyPercentile($path, 0.50, $window),
        ];
    }
}
