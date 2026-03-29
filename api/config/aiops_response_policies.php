<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Automated Response Policies
    |--------------------------------------------------------------------------
    | Each incident_type maps to a primary simulated action.
    */
    'policies' => [
        'LATENCY_SPIKE' => [
            'action' => 'RESTART_SERVICE',
            'notes' => 'Restarting the affected API worker to clear transient latency issues.',
            'success_rate' => 85,
        ],
        'ERROR_STORM' => [
            'action' => 'SEND_ALERT',
            'notes' => 'Sending an immediate operator alert for broad error activity.',
            'success_rate' => 95,
        ],
        'TRAFFIC_SURGE' => [
            'action' => 'SCALE_SERVICE',
            'notes' => 'Scaling service replicas to absorb traffic pressure.',
            'success_rate' => 90,
        ],
        'SERVICE_DEGRADATION' => [
            'action' => 'THROTTLE_TRAFFIC',
            'notes' => 'Applying temporary traffic throttling to protect stability.',
            'success_rate' => 80,
        ],
        'LOCALIZED_ENDPOINT_FAILURE' => [
            'action' => 'RESTART_SERVICE',
            'notes' => 'Restarting localized service components for the impacted endpoint.',
            'success_rate' => 85,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation Settings
    |--------------------------------------------------------------------------
    */
    'escalation' => [
        'action' => 'CRITICAL_ALERT',
        'persisted_attempts_threshold' => 2,
        'occurrence_count_threshold' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Policy
    |--------------------------------------------------------------------------
    */
    'default_policy' => [
        'action' => 'SEND_ALERT',
        'notes' => 'Unknown incident type; fallback to operator alert.',
        'success_rate' => 90,
    ],
];
