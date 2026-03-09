<?php

$logFile = __DIR__ . '/api/storage/logs/aiops.log';
$outputFile = __DIR__ . '/logs.json';

if (!file_exists($logFile)) {
    echo "Log file not found: $logFile\n";
    exit(1);
}

$schemaKeys = [
    'timestamp',
    'request_id',
    'method',
    'path',
    'status_code',
    'latency_ms',
    'client_ip',
    'user_agent',
    'query',
    'payload_size_bytes',
    'response_size_bytes',
    'route_name',
    'severity',
    'build_version',
    'host',
    'error_category',
    'error_message',
];

$logs = [];
$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*request_log\s+(\{.*\})\s*$/', $line, $matches)) {
        continue;
    }

    $decoded = json_decode($matches[2], true);
    if (!is_array($decoded)) {
        continue;
    }

    $normalized = array_fill_keys($schemaKeys, null);
    $normalized['timestamp'] = $matches[1];
    foreach ($schemaKeys as $key) {
        if ($key === 'timestamp') {
            continue;
        }

        if (array_key_exists($key, $decoded)) {
            $normalized[$key] = $decoded[$key];
        }
    }

    $logs[] = $normalized;
}

$totalLogs = count($logs);
$errorLogs = count(array_filter($logs, fn ($log) => ($log['severity'] ?? 'info') === 'error'));

echo "Total logs: {$totalLogs}\n";
echo "Error logs: {$errorLogs}\n";
if ($totalLogs < 1500) {
    echo "WARNING: fewer than 1500 entries in aiops.log\n";
}
if ($errorLogs < 100) {
    echo "WARNING: fewer than 100 error entries in aiops.log\n";
}

if ($totalLogs > 1500) {
    $logs = array_slice($logs, -1500);
}

file_put_contents($outputFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Exported " . count($logs) . " logs to $outputFile\n";
