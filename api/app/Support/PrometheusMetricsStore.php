<?php

namespace App\Support;

class PrometheusMetricsStore
{
    private array $buckets;
    private string $storagePath;

    public function __construct(?string $storagePath = null, ?array $buckets = null)
    {
        $this->storagePath = $storagePath ?? storage_path('app/metrics_store.json');
        $this->buckets = $buckets ?? [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];
    }

    public function observeRequest(
        string $method,
        string $path,
        string $status,
        ?string $errorCategory,
        float $durationSeconds
    ): void {
        $this->mutate(function (array $state) use ($method, $path, $status, $errorCategory, $durationSeconds) {
            $requestKey = $this->buildKey([$method, $path, $status]);
            $state['requests_total'][$requestKey] = ($state['requests_total'][$requestKey] ?? 0) + 1;

            if ($errorCategory) {
                $errorKey = $this->buildKey([$method, $path, $errorCategory]);
                $state['errors_total'][$errorKey] = ($state['errors_total'][$errorKey] ?? 0) + 1;
            }

            $durationKey = $this->buildKey([$method, $path]);
            $state['duration_count'][$durationKey] = ($state['duration_count'][$durationKey] ?? 0) + 1;
            $state['duration_sum'][$durationKey] = ($state['duration_sum'][$durationKey] ?? 0.0) + $durationSeconds;

            foreach ($this->buckets as $bucket) {
                if ($durationSeconds <= $bucket) {
                    $bucketKey = $this->buildKey([$method, $path, (string) $bucket]);
                    $state['duration_buckets'][$bucketKey] = ($state['duration_buckets'][$bucketKey] ?? 0) + 1;
                }
            }

            $infBucketKey = $this->buildKey([$method, $path, '+Inf']);
            $state['duration_buckets'][$infBucketKey] = ($state['duration_buckets'][$infBucketKey] ?? 0) + 1;

            return $state;
        });
    }

    public function setAnomalyWindowActive(int $value): void
    {
        $this->mutate(function (array $state) use ($value) {
            $state['anomaly_window_active'] = $value > 0 ? 1 : 0;
            return $state;
        });
    }

    public function render(): string
    {
        $state = $this->readState();
        $lines = [];

        $lines[] = '# HELP http_requests_total Total HTTP requests';
        $lines[] = '# TYPE http_requests_total counter';
        foreach ($state['requests_total'] as $key => $count) {
            [$method, $path, $status] = explode('|', $key, 3);
            $lines[] = $this->line(
                'http_requests_total',
                ['method' => $method, 'path' => $path, 'status' => $status],
                $count
            );
        }

        $lines[] = '# HELP http_errors_total Total HTTP errors';
        $lines[] = '# TYPE http_errors_total counter';
        foreach ($state['errors_total'] as $key => $count) {
            [$method, $path, $category] = explode('|', $key, 3);
            $lines[] = $this->line(
                'http_errors_total',
                ['method' => $method, 'path' => $path, 'error_category' => $category],
                $count
            );
        }

        $lines[] = '# HELP http_request_duration_seconds HTTP request duration in seconds';
        $lines[] = '# TYPE http_request_duration_seconds histogram';
        foreach ($state['duration_count'] as $key => $count) {
            [$method, $path] = explode('|', $key, 2);
            foreach ($this->buckets as $bucket) {
                $bucketKey = $this->buildKey([$method, $path, (string) $bucket]);
                $bucketCount = $state['duration_buckets'][$bucketKey] ?? 0;
                $lines[] = $this->line(
                    'http_request_duration_seconds_bucket',
                    ['method' => $method, 'path' => $path, 'le' => (string) $bucket],
                    $bucketCount
                );
            }

            $infBucketKey = $this->buildKey([$method, $path, '+Inf']);
            $infCount = $state['duration_buckets'][$infBucketKey] ?? 0;
            $lines[] = $this->line(
                'http_request_duration_seconds_bucket',
                ['method' => $method, 'path' => $path, 'le' => '+Inf'],
                $infCount
            );

            $lines[] = $this->line(
                'http_request_duration_seconds_sum',
                ['method' => $method, 'path' => $path],
                $state['duration_sum'][$key] ?? 0
            );
            $lines[] = $this->line(
                'http_request_duration_seconds_count',
                ['method' => $method, 'path' => $path],
                $count
            );
        }

        $lines[] = '# HELP anomaly_window_active Ground-truth anomaly window marker (1=active,0=inactive)';
        $lines[] = '# TYPE anomaly_window_active gauge';
        $lines[] = 'anomaly_window_active ' . ($state['anomaly_window_active'] ?? 0);

        return implode("\n", $lines) . "\n";
    }

    private function mutate(callable $mutator): void
    {
        if (!is_dir(dirname($this->storagePath))) {
            mkdir(dirname($this->storagePath), 0777, true);
        }

        $handle = fopen($this->storagePath, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }

            $state = $this->decodeStream($handle);
            $nextState = $mutator($state);

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($nextState, JSON_UNESCAPED_SLASHES));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function readState(): array
    {
        if (!file_exists($this->storagePath)) {
            return $this->defaultState();
        }

        $content = file_get_contents($this->storagePath);
        if (!is_string($content) || $content === '') {
            return $this->defaultState();
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return $this->defaultState();
        }

        return array_merge($this->defaultState(), $decoded);
    }

    private function decodeStream($handle): array
    {
        rewind($handle);
        $content = stream_get_contents($handle);
        if (!is_string($content) || $content === '') {
            return $this->defaultState();
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return $this->defaultState();
        }

        return array_merge($this->defaultState(), $decoded);
    }

    private function defaultState(): array
    {
        return [
            'requests_total' => [],
            'errors_total' => [],
            'duration_buckets' => [],
            'duration_sum' => [],
            'duration_count' => [],
            'anomaly_window_active' => 0,
        ];
    }

    private function buildKey(array $parts): string
    {
        return implode('|', $parts);
    }

    private function line(string $metric, array $labels, int|float $value): string
    {
        $serialized = [];
        foreach ($labels as $name => $labelValue) {
            $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $labelValue);
            $serialized[] = $name . '="' . $escaped . '"';
        }

        return $metric . '{' . implode(',', $serialized) . '} ' . $value;
    }
}
