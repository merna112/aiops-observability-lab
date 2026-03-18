<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Creates, stores, and loads structured incident records.
 *
 * Incidents are written to storage/aiops/incidents.json as a JSON array.
 * Each incident follows the canonical schema required by Lab Work 2.
 */
class IncidentManager
{
    private string $incidentsPath;

    public function __construct()
    {
        $this->incidentsPath = storage_path('aiops/incidents.json');
    }

    // -------------------------------------------------------------------------
    //  Public API
    // -------------------------------------------------------------------------

    /**
     * Build a fully-populated incident record from the correlation result,
     * the raw signal list, and the current metric snapshots.
     *
     * @param  array $correlation   Output of EventCorrelator::correlate()
     * @param  array $signals       Output of AnomalyDetector::detectAnomalies()
     * @param  array $baselines     Keyed by endpoint path
     * @param  array $currentMetrics Keyed by endpoint path
     */
    public function createIncident(
        array $correlation,
        array $signals,
        array $baselines,
        array $currentMetrics
    ): array {
        $incidentId = $this->generateId();
        $affectedEndpoints = $correlation['affected_endpoints'];

        $baselineValues = [];
        $observedValues = [];

        foreach ($affectedEndpoints as $endpoint) {
            if (isset($baselines[$endpoint])) {
                $b = $baselines[$endpoint];
                $baselineValues[$endpoint] = [
                    'avg_latency_s' => $this->round4($b['avg_latency'] ?? null),
                    'p95_latency_s' => $this->round4($b['p95_latency'] ?? null),
                    'request_rate' => $this->round4($b['request_rate'] ?? null),
                    'error_rate' => $this->round4($b['error_rate'] ?? null),
                ];
            }

            if (isset($currentMetrics[$endpoint])) {
                $m = $currentMetrics[$endpoint];
                $observedValues[$endpoint] = [
                    'avg_latency_s' => $this->round4($m['avg_latency'] ?? null),
                    'p95_latency_s' => $this->round4($m['p95_latency'] ?? null),
                    'request_rate' => $this->round4($m['request_rate'] ?? null),
                    'error_rate' => $this->round4($m['error_rate'] ?? null),
                ];
            }
        }

        return [
            'incident_id' => $incidentId,
            'incident_type' => $correlation['incident_type'],
            'severity' => $correlation['severity'],
            'status' => 'OPEN',
            'detected_at' => now()->toISOString(),
            'affected_service' => 'laravel-api',
            'affected_endpoints' => $affectedEndpoints,
            'triggering_signals' => $signals,
            'baseline_values' => $baselineValues,
            'observed_values' => $observedValues,
            'summary' => $this->buildSummary($correlation, $signals, $affectedEndpoints),
        ];
    }

    /**
     * Persist incident with upsert semantics.
     *
     * If an OPEN incident with the same incident_type + affected_endpoints
     * already exists, update that record instead of appending a new one.
     * Returns both the persisted incident and the performed action.
     *
     * @return array{incident: array, action: string}
     */
    public function saveIncident(array $incident): array
    {
        $this->ensureDir($this->incidentsPath);
        $incidents = $this->loadIncidents();

        $incomingFingerprint = $this->fingerprintFromIncident($incident);
        $nowIso = now()->toISOString();

        foreach ($incidents as $idx => $existing) {
            $isOpen = ($existing['status'] ?? 'OPEN') === 'OPEN';
            if (!$isOpen) {
                continue;
            }

            if ($this->fingerprintFromIncident($existing) !== $incomingFingerprint) {
                continue;
            }

            $updated = $existing;
            $updated['severity'] = $this->maxSeverity(
                $existing['severity'] ?? 'LOW',
                $incident['severity'] ?? 'LOW'
            );
            $updated['triggering_signals'] = $incident['triggering_signals'];
            $updated['baseline_values'] = $incident['baseline_values'];
            $updated['observed_values'] = $incident['observed_values'];
            $updated['summary'] = $incident['summary'];
            $updated['last_detected_at'] = $nowIso;
            $updated['occurrence_count'] = (int) ($existing['occurrence_count'] ?? 1) + 1;

            $incidents[$idx] = $updated;
            file_put_contents(
                $this->incidentsPath,
                json_encode($incidents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            return [
                'incident' => $updated,
                'action' => 'updated',
            ];
        }

        $incident['last_detected_at'] = $incident['detected_at'] ?? $nowIso;
        $incident['occurrence_count'] = 1;
        $incidents[] = $incident;

        file_put_contents(
            $this->incidentsPath,
            json_encode($incidents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return [
            'incident' => $incident,
            'action' => 'created',
        ];
    }

    /**
     * Load all previously recorded incidents from disk.
     */
    public function loadIncidents(): array
    {
        if (!file_exists($this->incidentsPath)) {
            return [];
        }
        return json_decode(file_get_contents($this->incidentsPath), true) ?? [];
    }

    // -------------------------------------------------------------------------
    //  Private helpers
    // -------------------------------------------------------------------------

    private function generateId(): string
    {
        // e.g. INC-A3F9B2C1-20260317153042
        return 'INC-' . strtoupper(substr(md5(uniqid('', true)), 0, 8))
            . '-' . now()->format('YmdHis');
    }

    private function buildSummary(array $correlation, array $signals, array $endpoints): string
    {
        $type = $correlation['incident_type'];
        $severity = $correlation['severity'];
        $count = count($signals);
        $endpointList = implode(', ', $endpoints);

        return "[{$severity}] {$type} detected — {$count} anomalous signal(s) on: {$endpointList}.";
    }

    private function fingerprintFromIncident(array $incident): string
    {
        $endpoints = $incident['affected_endpoints'] ?? [];
        sort($endpoints);

        return ($incident['incident_type'] ?? 'UNKNOWN') . '|' . implode(',', $endpoints);
    }

    private function maxSeverity(string $a, string $b): string
    {
        $rank = [
            'LOW' => 1,
            'MEDIUM' => 2,
            'HIGH' => 3,
            'CRITICAL' => 4,
        ];

        return ($rank[$a] ?? 0) >= ($rank[$b] ?? 0) ? $a : $b;
    }

    private function round4(?float $value): ?float
    {
        return $value !== null ? round($value, 4) : null;
    }

    private function ensureDir(string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
