<?php

namespace App\Services;

/**
 * Correlates a flat list of per-endpoint anomaly signals into a single
 * higher-level incident.
 *
 * Incident types (in priority order):
 *
 *   SERVICE_DEGRADATION      — both latency and error signals, ≥2 endpoints
 *   ERROR_STORM              — error signals only, ≥2 endpoints
 *   LOCALIZED_ENDPOINT_FAILURE — error + latency on exactly 1 endpoint
 *   LATENCY_SPIKE            — latency signals only (any endpoint count)
 *   TRAFFIC_SURGE            — traffic spike, no errors or latency issues
 *
 * Severity mapping:
 *   CRITICAL — SERVICE_DEGRADATION or ERROR_STORM
 *   HIGH     — LOCALIZED_ENDPOINT_FAILURE, or latency ratio ≥ 5
 *   MEDIUM   — latency ratio ≥ 3
 *   LOW      — everything else
 */
class EventCorrelator
{
    /**
     * Accepts the raw signal list from AnomalyDetector.
     * Returns a correlation summary array, or null if signals is empty.
     *
     * @param  array[] $signals
     * @return array{incident_type: string, severity: string, affected_endpoints: string[], signal_summary: array}|null
     */
    public function correlate(array $signals): ?array
    {
        if (empty($signals)) {
            return null;
        }

        $signalTypes       = array_column($signals, 'type');
        $affectedEndpoints = array_values(array_unique(array_column($signals, 'endpoint')));
        $typeCounts        = array_count_values($signalTypes);

        $hasLatency  = isset($typeCounts['LATENCY_ANOMALY']) || isset($typeCounts['P95_LATENCY_ANOMALY']);
        $hasErrors   = isset($typeCounts['ERROR_RATE_ANOMALY']);
        $hasTraffic  = isset($typeCounts['TRAFFIC_ANOMALY']);
        $endpointCnt = count($affectedEndpoints);

        $incidentType = $this->classifyType($hasLatency, $hasErrors, $hasTraffic, $endpointCnt);
        $severity     = $this->computeSeverity($signals, $incidentType);

        return [
            'incident_type'      => $incidentType,
            'severity'           => $severity,
            'affected_endpoints' => $affectedEndpoints,
            'signal_summary'     => $typeCounts,
        ];
    }

    // -------------------------------------------------------------------------
    //  Private helpers
    // -------------------------------------------------------------------------

    private function classifyType(bool $hasLatency, bool $hasErrors, bool $hasTraffic, int $endpointCnt): string
    {
        // Broad degradation: both latency and errors across multiple endpoints
        if ($hasLatency && $hasErrors && $endpointCnt >= 2) {
            return 'SERVICE_DEGRADATION';
        }

        // Error rain across the service
        if ($hasErrors && $endpointCnt >= 2) {
            return 'ERROR_STORM';
        }

        // Single endpoint with errors (with or without latency)
        if ($hasErrors && $endpointCnt === 1) {
            return 'LOCALIZED_ENDPOINT_FAILURE';
        }

        // Latency-only (broad or single endpoint)
        if ($hasLatency) {
            return 'LATENCY_SPIKE';
        }

        // Pure traffic surge
        if ($hasTraffic) {
            return 'TRAFFIC_SURGE';
        }

        // Fallback — should not normally reach here
        return 'SERVICE_DEGRADATION';
    }

    private function computeSeverity(array $signals, string $incidentType): string
    {
        $maxRatio = 1.0;
        foreach ($signals as $signal) {
            $ratio    = $signal['ratio'] ?? 1.0;
            $maxRatio = max($maxRatio, (float) $ratio);
        }

        if (in_array($incidentType, ['SERVICE_DEGRADATION', 'ERROR_STORM'], true)) {
            return 'CRITICAL';
        }

        if ($incidentType === 'LOCALIZED_ENDPOINT_FAILURE' || $maxRatio >= 5.0) {
            return 'HIGH';
        }

        if ($maxRatio >= 3.0) {
            return 'MEDIUM';
        }

        return 'LOW';
    }
}
