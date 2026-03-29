<?php

namespace App\Services;

class AutomationEngine
{
    private string $incidentsPath;

    public function __construct(private ResponseLogger $responseLogger)
    {
        $this->incidentsPath = storage_path('aiops/incidents.json');
    }

    /**
     * Process all OPEN incidents and execute policy actions.
     *
     * @return array{processed:int, acted:int, escalated:int, skipped:int, logs:array<int,array>}
     */
    public function processOpenIncidents(): array
    {
        $incidents = $this->loadIncidents();
        $responses = $this->responseLogger->loadResponses();

        $summary = [
            'processed' => 0,
            'acted' => 0,
            'escalated' => 0,
            'skipped' => 0,
            'logs' => [],
        ];

        foreach ($incidents as $index => $incident) {
            if (($incident['status'] ?? 'OPEN') !== 'OPEN') {
                continue;
            }

            $summary['processed']++;
            $incidentId = (string) ($incident['incident_id'] ?? 'UNKNOWN');
            $incidentResponses = $this->responsesForIncident($responses, $incidentId);

            if ($this->alreadyEscalated($incidentResponses)) {
                $summary['skipped']++;
                continue;
            }

            $escalationReason = $this->escalationReason($incident, $incidentResponses);
            if ($escalationReason !== null) {
                $log = $this->escalateIncident($incidentId, $escalationReason);
                $summary['logs'][] = $log;
                $summary['escalated']++;
                $incidents[$index]['status'] = 'ESCALATED';
                continue;
            }

            $policy = $this->resolvePolicy((string) ($incident['incident_type'] ?? 'UNKNOWN'));
            $actionResult = $this->simulateAction($policy, $incident);

            $log = $this->responseLogger->log(
                $incidentId,
                $policy['action'],
                $actionResult['result'],
                $actionResult['notes']
            );
            $summary['logs'][] = $log;
            $summary['acted']++;

            if ($actionResult['result'] === 'SUCCESS') {
                $incidents[$index]['responded_at'] = now()->toISOString();
                $incidents[$index]['last_action_taken'] = $policy['action'];
                continue;
            }

            $escalationLog = $this->escalateIncident(
                $incidentId,
                'Automated action failed: ' . $policy['action']
            );
            $summary['logs'][] = $escalationLog;
            $summary['escalated']++;
            $incidents[$index]['status'] = 'ESCALATED';
            $incidents[$index]['escalated_at'] = now()->toISOString();
        }

        $this->persistIncidents($incidents);

        return $summary;
    }

    private function loadIncidents(): array
    {
        if (!file_exists($this->incidentsPath)) {
            return [];
        }

        return json_decode(file_get_contents($this->incidentsPath), true) ?? [];
    }

    private function persistIncidents(array $incidents): void
    {
        $this->ensureDir($this->incidentsPath);

        file_put_contents(
            $this->incidentsPath,
            json_encode($incidents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function resolvePolicy(string $incidentType): array
    {
        $policies = config('aiops_response_policies.policies', []);
        $default = config('aiops_response_policies.default_policy', [
            'action' => 'SEND_ALERT',
            'notes' => 'Default fallback policy.',
            'success_rate' => 90,
        ]);

        return $policies[$incidentType] ?? $default;
    }

    private function simulateAction(array $policy, array $incident): array
    {
        $incidentId = (string) ($incident['incident_id'] ?? 'UNKNOWN');
        $action = (string) ($policy['action'] ?? 'SEND_ALERT');
        $successRate = (int) ($policy['success_rate'] ?? 90);
        $notes = (string) ($policy['notes'] ?? 'Automated response executed.');

        // Deterministic pseudo-random simulation so demos are reproducible.
        $bucket = abs(crc32($incidentId . '|' . $action)) % 100;
        $success = $bucket < $successRate;

        return [
            'result' => $success ? 'SUCCESS' : 'FAILED',
            'notes' => $success
                ? $notes
                : $notes . ' Simulated execution failed and requires escalation.',
        ];
    }

    private function escalateIncident(string $incidentId, string $reason): array
    {
        $action = (string) config('aiops_response_policies.escalation.action', 'CRITICAL_ALERT');

        return $this->responseLogger->log(
            $incidentId,
            $action,
            'ESCALATED',
            $reason
        );
    }

    private function escalationReason(array $incident, array $incidentResponses): ?string
    {
        $attemptThreshold = (int) config('aiops_response_policies.escalation.persisted_attempts_threshold', 2);
        $occurrenceThreshold = (int) config('aiops_response_policies.escalation.occurrence_count_threshold', 3);

        if (!empty($incidentResponses)) {
            $last = end($incidentResponses);
            if (($last['result'] ?? '') === 'FAILED') {
                return 'Previous automated action failed.';
            }
        }

        $occurrenceCount = (int) ($incident['occurrence_count'] ?? 1);
        if ($occurrenceCount >= $occurrenceThreshold) {
            return 'Anomaly persists (high occurrence_count=' . $occurrenceCount . ').';
        }

        $successfulAttempts = 0;
        foreach ($incidentResponses as $response) {
            $isEscalation = ($response['action_taken'] ?? '') === config('aiops_response_policies.escalation.action', 'CRITICAL_ALERT');
            if ($isEscalation) {
                continue;
            }
            if (($response['result'] ?? '') === 'SUCCESS') {
                $successfulAttempts++;
            }
        }

        if ($successfulAttempts >= $attemptThreshold) {
            return 'Anomaly persists after ' . $successfulAttempts . ' automated attempt(s).';
        }

        return null;
    }

    private function alreadyEscalated(array $incidentResponses): bool
    {
        $escalationAction = (string) config('aiops_response_policies.escalation.action', 'CRITICAL_ALERT');

        foreach ($incidentResponses as $response) {
            if (($response['action_taken'] ?? '') === $escalationAction) {
                return true;
            }
        }

        return false;
    }

    private function responsesForIncident(array $responses, string $incidentId): array
    {
        return array_values(array_filter(
            $responses,
            fn(array $record): bool => (string) ($record['incident_id'] ?? '') === $incidentId
        ));
    }

    private function ensureDir(string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
