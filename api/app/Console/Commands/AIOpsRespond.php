<?php

namespace App\Console\Commands;

use App\Services\AutomationEngine;
use Illuminate\Console\Command;

class AIOpsRespond extends Command
{
    protected $signature =
        'aiops:respond
        {--interval=20 : Monitoring interval in seconds}
        {--once : Process currently open incidents once and exit}';

    protected $description = 'AIOps Automation Engine - monitors incidents and executes automated response policies';

    public function handle(AutomationEngine $automationEngine): int
    {
        $interval = max(5, (int) $this->option('interval'));
        $runOnce = (bool) $this->option('once');

        $this->line('');
        $this->line('---------AIOps Automation Engine  -  INCIDENT RESPONSE---------');
        $this->line('  Incidents : storage/aiops/incidents.json');
        $this->line('  Responses : storage/aiops/responses.json');

        if ($runOnce) {
            $this->runCycle($automationEngine);
            return self::SUCCESS;
        }

        $this->line("  Interval  : {$interval}s");
        $this->line('  Press Ctrl+C to stop.');

        $cycle = 0;
        while (true) {
            $cycle++;
            $this->line('');
            $this->line('Cycle #' . $cycle . ' @ ' . now()->toDateTimeString());
            $this->runCycle($automationEngine);
            sleep($interval);
        }
    }

    private function runCycle(AutomationEngine $automationEngine): void
    {
        $summary = $automationEngine->processOpenIncidents();

        $this->line('  Open incidents processed : ' . $summary['processed']);
        $this->line('  Policy actions executed  : ' . $summary['acted']);
        $this->line('  Escalations triggered    : ' . $summary['escalated']);
        $this->line('  Skipped (already escalated): ' . $summary['skipped']);

        if (empty($summary['logs'])) {
            $this->line('  No response actions logged in this cycle.');
            return;
        }

        $this->line('');
        $this->line('  Response logs:');
        foreach ($summary['logs'] as $log) {
            $this->line(sprintf(
                '   - %s | %s | %s | %s',
                $log['incident_id'] ?? 'UNKNOWN',
                $log['action_taken'] ?? 'UNKNOWN',
                $log['result'] ?? 'UNKNOWN',
                $log['timestamp'] ?? 'UNKNOWN'
            ));
        }
    }
}
