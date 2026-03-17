<?php

namespace App\Console\Commands;

use App\Services\AlertManager;
use App\Services\AnomalyDetector;
use App\Services\BaselineComputer;
use App\Services\EventCorrelator;
use App\Services\IncidentManager;
use App\Services\PrometheusClient;
use Illuminate\Console\Command;

/**
 * AIOps Detection Engine
 *
 * Runs continuously, querying Prometheus every 20–30 seconds.
 * Each cycle:
 *   1. Fetches live metrics per endpoint
 *   2. Updates per-endpoint baselines (EMA)
 *   3. Detects multi-signal anomalies
 *   4. Correlates signals into typed incidents
 *   5. Persists incidents to storage/aiops/incidents.json
 *   6. Emits deduplicated console + JSON alerts
 *
 * Usage:
 *   php artisan aiops:detect
 *   php artisan aiops:detect --interval=20
 */
class AIOpsDetect extends Command
{
    protected $signature = 'aiops:detect
                            {--interval=25 : Detection interval in seconds (20–30)}';

    protected $description = 'AIOps Detection Engine — continuously detects anomalies from Prometheus metrics';

    /** Endpoints to monitor — must match Prometheus path label values. */
    private array $endpoints = [
        'api/normal',
        'api/slow',
        'api/db',
        'api/error',
        'api/validate',
    ];

    // -------------------------------------------------------------------------
    //  Entry point
    // -------------------------------------------------------------------------

    public function handle(
        PrometheusClient $prometheus,
        BaselineComputer $baselineComputer,
        AnomalyDetector  $anomalyDetector,
        EventCorrelator  $correlator,
        IncidentManager  $incidentManager,
        AlertManager     $alertManager
    ): void {
        $interval = (int) $this->option('interval');
        $interval = max(20, min(30, $interval));

        $this->printBanner($interval);

        $cycle = 0;
        while (true) {
            $cycle++;
            $this->runDetectionCycle(
                $cycle,
                $prometheus,
                $baselineComputer,
                $anomalyDetector,
                $correlator,
                $incidentManager,
                $alertManager
            );
            sleep($interval);
        }
    }

    // -------------------------------------------------------------------------
    //  Detection cycle
    // -------------------------------------------------------------------------

    private function runDetectionCycle(
        int              $cycle,
        PrometheusClient $prometheus,
        BaselineComputer $baselineComputer,
        AnomalyDetector  $anomalyDetector,
        EventCorrelator  $correlator,
        IncidentManager  $incidentManager,
        AlertManager     $alertManager
    ): void {
        $ts = now()->toDateTimeString();
        $this->line('');
        $this->line("┌─ Cycle #{$cycle}  [{$ts}] " . str_repeat('─', 38));

        // ── 1. Query Prometheus ───────────────────────────────────────────────
        $this->line('│  Querying Prometheus metrics…');
        $currentMetrics = [];
        foreach ($this->endpoints as $path) {
            $metrics             = $prometheus->getAllEndpointMetrics($path);
            $currentMetrics[$path] = $metrics;
            $this->printEndpointRow($path, $metrics);
        }

        // Ground-truth anomaly window marker
        $anomalyWindowActive = $prometheus->getAnomalyWindowActive();
        if ($anomalyWindowActive) {
            $this->line('│  <fg=magenta>⚑  GROUND-TRUTH anomaly window is ACTIVE</>');
        }

        // Error category breakdown
        $errorCategories = $prometheus->getErrorCategoryCounters();
        if (!empty($errorCategories)) {
            $cats = implode('  ', array_map(
                fn($k, $v) => "{$k}=" . number_format($v, 0),
                array_keys($errorCategories),
                array_values($errorCategories)
            ));
            $this->line("│  Error categories: {$cats}");
        }

        // ── 2. Update baselines ───────────────────────────────────────────────
        $baselines = $baselineComputer->computeAndUpdate($this->endpoints, $currentMetrics);
        $this->line('│  Baselines updated for ' . count($baselines) . ' endpoint(s).');

        // ── 3. Detect anomalies ───────────────────────────────────────────────
        $signals = $anomalyDetector->detectAnomalies($currentMetrics, $baselines);

        if (empty($signals)) {
            $this->line('│  <fg=green>✓  No anomalies detected — system healthy.</>');
            $this->line('└' . str_repeat('─', 58));
            return;
        }

        $this->line("│  <fg=yellow>⚠  Detected " . count($signals) . " anomalous signal(s):</>");
        foreach ($signals as $s) {
            $this->line("│     · <fg=yellow>[{$s['type']}]</> {$s['endpoint']} — {$s['description']}");
        }

        // ── 4. Correlate signals ──────────────────────────────────────────────
        $correlation = $correlator->correlate($signals);
        if ($correlation === null) {
            $this->line('│  <fg=green>No correlation result (empty signals after filter).</>');
            $this->line('└' . str_repeat('─', 58));
            return;
        }

        $this->line("│  Correlation → <fg=red>{$correlation['incident_type']}</> [{$correlation['severity']}]");

        // ── 5. Create & persist incident ──────────────────────────────────────
        $incident = $incidentManager->createIncident(
            $correlation,
            $signals,
            $baselines,
            $currentMetrics
        );
        $incidentManager->saveIncident($incident);
        $this->line("│  Incident saved: <options=bold>{$incident['incident_id']}</>");

        // ── 6. Alert (with deduplication) ─────────────────────────────────────
        $fingerprint = $alertManager->fingerprint($incident);
        if ($alertManager->shouldAlert($fingerprint)) {
            $alertManager->markAlerted($fingerprint);
            $alertManager->emitConsoleAlert($incident, $this);
            $alertManager->writeJsonAlertToFile($incident);
            $this->line('│  JSON alert:');
            $this->line($alertManager->buildJsonAlert($incident));
        } else {
            $this->line('│  <fg=gray>Alert suppressed — same pattern alerted recently (deduplication).</>');
        }

        $this->line('└' . str_repeat('─', 58));
    }

    // -------------------------------------------------------------------------
    //  Console helpers
    // -------------------------------------------------------------------------

    private function printBanner(int $interval): void
    {
        $this->line('');
        $this->line('╔══════════════════════════════════════════════════════════╗');
        $this->line('║         AIOps Detection Engine  —  STARTED              ║');
        $this->line('╚══════════════════════════════════════════════════════════╝');
        $this->line("  Prometheus   : " . env('PROMETHEUS_URL', 'http://localhost:9090'));
        $this->line("  Endpoints    : " . implode(', ', $this->endpoints));
        $this->line("  Interval     : {$interval}s");
        $this->line("  Incidents    : storage/aiops/incidents.json");
        $this->line("  Alerts       : storage/aiops/alerts.json");
        $this->line("  Press Ctrl+C to stop.");
        $this->line('');
    }

    private function printEndpointRow(string $path, array $metrics): void
    {
        $latency   = $metrics['avg_latency']  !== null ? round($metrics['avg_latency']  * 1000, 1) . ' ms' : 'N/A';
        $p95       = $metrics['p95_latency']  !== null ? round($metrics['p95_latency']  * 1000, 1) . ' ms' : 'N/A';
        $errorRate = $metrics['error_rate']   !== null ? round($metrics['error_rate']   * 100, 1) . '%'    : 'N/A';
        $reqRate   = $metrics['request_rate'] !== null ? round($metrics['request_rate'], 4) . ' req/s'     : 'N/A';

        $this->line("│    {$path}: avg={$latency}  p95={$p95}  err={$errorRate}  rate={$reqRate}");
    }
}
