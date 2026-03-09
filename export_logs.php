<?php

// Script to export logs to logs.json

$logFile = __DIR__ . '/api/storage/logs/aiops.log';
$outputFile = __DIR__ . '/logs.json';

if (!file_exists($logFile)) {
    echo "Log file not found: $logFile\n";
    exit(1);
}

$logs = [];
$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $line) {
    // Parse Laravel log format
    if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] local\.INFO: request_log\.(.+)$/', $line, $matches)) {
        $timestamp = $matches[1];
        $jsonStr = $matches[2];

        $data = json_decode($jsonStr, true);
        if ($data) {
            $data['timestamp'] = $timestamp;
            $logs[] = $data;
        }
    } elseif (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] local\.ERROR: request_log\.(.+)$/', $line, $matches)) {
        $timestamp = $matches[1];
        $jsonStr = $matches[2];

        $data = json_decode($jsonStr, true);
        if ($data) {
            $data['timestamp'] = $timestamp;
            $logs[] = $data;
        }
    }
}

// Filter to ensure we have at least 1500 entries and 100 errors
$filteredLogs = array_filter($logs, function($log) {
    return isset($log['request_id']); // Only request logs
});

$errors = array_filter($filteredLogs, function($log) {
    return ($log['severity'] ?? 'info') === 'error';
});

echo "Total logs: " . count($filteredLogs) . "\n";
echo "Error logs: " . count($errors) . "\n";

// Take last 1500 if more
if (count($filteredLogs) > 1500) {
    $filteredLogs = array_slice($filteredLogs, -1500);
}

file_put_contents($outputFile, json_encode($filteredLogs, JSON_PRETTY_PRINT));

echo "Exported " . count($filteredLogs) . " logs to $outputFile\n";