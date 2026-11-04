<?php

use Illuminate\Support\Str;

/*
|------------------------------------------------------------------------------
| Horizon
|------------------------------------------------------------------------------
|
| Published because the package default defines supervisors for `production`
| and `local` only. This application also runs as `staging`, where Horizon
| therefore found no supervisor, started no workers, and processed nothing —
| while its dashboard reported a healthy, idle system.
|
| Horizon is the only consumer. A standalone `queue:work` alongside it competes
| for the same jobs and keeps them out of Horizon's metrics, so the compose
| files no longer run one.
*/

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'leadflow'), '_').'_horizon:'),

    'middleware' => ['web'],

    'waits' => [
        'redis:default' => 60,
        'redis-heavy:heavy' => 300,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    /*
     * Headroom above the PHP memory limit the workers run with. A worker killed
     * by the OOM reaper fails its job silently; Horizon restarting it on its own
     * terms records the restart.
     */
    'memory_limit' => 128,

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            // `heavy` is read here too wherever no worker of its own is defined.
            'queue' => ['default', 'heavy'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            // Longer than the heaviest job: a CSV import of thousands of rows.
            'timeout' => 300,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'queue' => ['default'],
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            // Imports and sheet syncs on their own worker: a big file does not hold up an alert.
            'supervisor-heavy' => [
                'connection' => 'redis-heavy',
                'queue' => ['heavy'],
                'balance' => 'simple',
                'maxProcesses' => 2,
                'memory' => 256,
                'timeout' => 900,
            ],
        ],

        // Named explicitly. Its absence is the bug this file exists to fix.
        'staging' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],
        ],
    ],
];
