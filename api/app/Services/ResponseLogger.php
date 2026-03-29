<?php

namespace App\Services;

class ResponseLogger
{
    private string $responsesPath;

    public function __construct()
    {
        $this->responsesPath = storage_path('aiops/responses.json');
    }

    /**
     * Append one response record to storage/aiops/responses.json.
     *
     * Required schema:
     * - incident_id
     * - action_taken
     * - timestamp
     * - result
     * - notes
     */
    public function log(string $incidentId, string $actionTaken, string $result, string $notes): array
    {
        $this->ensureDir($this->responsesPath);

        $records = $this->loadResponses();

        $record = [
            'incident_id' => $incidentId,
            'action_taken' => $actionTaken,
            'timestamp' => now()->toISOString(),
            'result' => $result,
            'notes' => $notes,
        ];

        $records[] = $record;

        file_put_contents(
            $this->responsesPath,
            json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $record;
    }

    public function loadResponses(): array
    {
        if (!file_exists($this->responsesPath)) {
            return [];
        }

        return json_decode(file_get_contents($this->responsesPath), true) ?? [];
    }

    private function ensureDir(string $filePath): void
    {
        $dir = dirname($filePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
