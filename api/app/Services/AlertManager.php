<?php

namespace App\Services;

use Illuminate\Console\Command;

/**
 * Manages alert emission and deduplication.
 *
 * Deduplication strategy:
 *   A "fingerprint" is derived from (incident_type, sorted affected_endpoints).
 *   If the same fingerprint was already alerted within AIOPS_ALERT_SUPPRESS_SECONDS
 *   (default 300 s / 5 min), the alert is suppressed so the same anomaly does not
 *   flood the operator on every detection cycle.
 *
 * Persistence:
 *   Alerted fingerprints are written to storage/aiops/alerted_fingerprints.json
 *   so deduplication survives process restarts.
 *
 * Supported alert formats:
 *   • Console (coloured table printed to the terminal via the Command object)
 *   • JSON    (structured payload returned as a string / written to disk)
 */
class AlertManager
{
    /** Seconds during which the same fingerprint is suppressed. */
    private int $suppressSeconds;

    private string $fingerprintsPath;

    /** In-memory cache: fingerprint => last_alerted_at (unix timestamp) */
    private array $alerted = [];

    public function __construct()
    {
        $this->suppressSeconds  = (int) env('AIOPS_ALERT_SUPPRESS_SECONDS', 300);
        $this->fingerprintsPath = storage_path('aiops/alerted_fingerprints.json');
        $this->loadFromDisk();
    }

    // -------------------------------------------------------------------------
    //  Public API
    // -------------------------------------------------------------------------

    /**
     * Compute the deduplication fingerprint for an incident.
     * Format: <incident_type>|<sorted-endpoint-list>
     */
    public function fingerprint(array $incident): string
    {
        $endpoints = $incident['affected_endpoints'];
        sort($endpoints);
        return $incident['incident_type'] . '|' . implode(',', $endpoints);
    }

    /**
     * Returns true if this fingerprint has not been alerted recently.
     */
    public function shouldAlert(string $fingerprint): bool
    {
        $lastAlerted = $this->alerted[$fingerprint] ?? null;
        if ($lastAlerted === null) {
            return true;
        }
        return (time() - $lastAlerted) > $this->suppressSeconds;
    }

    /**
     * Record that this fingerprint has been alerted right now.
     */
    public function markAlerted(string $fingerprint): void
    {
        $this->alerted[$fingerprint] = time();
        $this->persistToDisk();
    }

    /**
     * Emit a rich console alert through the artisan Command output.
     */
    public function emitConsoleAlert(array $incident, Command $command): void
    {
        $bar      = str_repeat('═', 68);
        $severity = $incident['severity'];
        $color    = match ($severity) {
            'CRITICAL' => 'red',
            'HIGH'     => 'yellow',
            'MEDIUM'   => 'cyan',
            default    => 'white',
        };

        $command->line('');
        $command->line("<fg={$color}>{$bar}</>");
        $command->line("<fg={$color}>  🚨  ALERT: {$incident['incident_type']}  —  severity: {$severity}</>");
        $command->line("<fg={$color}>{$bar}</>");
        $command->line("  Incident ID   : <options=bold>{$incident['incident_id']}</>");
        $command->line("  Type          : {$incident['incident_type']}");
        $command->line("  Severity      : <fg={$color}>{$severity}</>");
        $command->line("  Timestamp     : {$incident['detected_at']}");
        $command->line("  Service       : {$incident['affected_service']}");
        $command->line("  Endpoints     : " . implode(', ', $incident['affected_endpoints']));
        $command->line("  Summary       : {$incident['summary']}");
        $command->line("<fg={$color}>{$bar}</>");
        $command->line('');
    }

    /**
     * Build a compact JSON alert payload (required schema).
     */
    public function buildJsonAlert(array $incident): string
    {
        $payload = [
            'incident_id'   => $incident['incident_id'],
            'incident_type' => $incident['incident_type'],
            'severity'      => $incident['severity'],
            'timestamp'     => $incident['detected_at'],
            'summary'       => $incident['summary'],
        ];
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Write a JSON alert to the alerts log file.
     */
    public function writeJsonAlertToFile(array $incident): void
    {
        $alertsPath = storage_path('aiops/alerts.json');
        $this->ensureDir($alertsPath);

        $alerts   = [];
        if (file_exists($alertsPath)) {
            $alerts = json_decode(file_get_contents($alertsPath), true) ?? [];
        }

        $alerts[] = [
            'incident_id'   => $incident['incident_id'],
            'incident_type' => $incident['incident_type'],
            'severity'      => $incident['severity'],
            'timestamp'     => $incident['detected_at'],
            'summary'       => $incident['summary'],
        ];

        file_put_contents(
            $alertsPath,
            json_encode($alerts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    // -------------------------------------------------------------------------
    //  Persistence helpers
    // -------------------------------------------------------------------------

    private function loadFromDisk(): void
    {
        if (!file_exists($this->fingerprintsPath)) {
            return;
        }
        $data = json_decode(file_get_contents($this->fingerprintsPath), true);
        if (is_array($data)) {
            $this->alerted = $data;
        }
    }

    private function persistToDisk(): void
    {
        $this->ensureDir($this->fingerprintsPath);
        file_put_contents(
            $this->fingerprintsPath,
            json_encode($this->alerted, JSON_PRETTY_PRINT)
        );
    }

    private function ensureDir(string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
