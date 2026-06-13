<?php

/**
 * Queue Ops Configuration
 *
 * Worker-management, auto-scaling, resource-monitoring and supervisor settings
 * for the queue system. These blocks were relocated out of core `config/queue.php`
 * (the `queue.workers.*` tree) when the ops surface moved to `glueful/queue-ops`.
 * The feeding env vars are unchanged — they are simply read here now.
 *
 * Kept in core `config/queue.php`: `queue.workers.performance.*` (read by the lean
 * core QueueWorker) and the per-queue `priority`/`memory_limit`/`timeout`/`max_jobs`
 * (read by core). This file owns the moved keys only.
 *
 * @package Glueful\Extensions\QueueOps
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Process Management (Symfony Process)
    |--------------------------------------------------------------------------
    | Modern multi-worker process management using Symfony Process.
    */
    'process' => [
        'enabled' => env('QUEUE_PROCESS_ENABLED', true), // Default to enabled
        'default_workers' => env('QUEUE_DEFAULT_WORKERS', 2),
        'max_workers_global' => env('QUEUE_MAX_WORKERS_GLOBAL', 50),
        'max_workers_per_queue' => env('QUEUE_MAX_WORKERS', 10),
        'restart_delay' => env('QUEUE_RESTART_DELAY', 5),
        'health_check_interval' => env('QUEUE_HEALTH_CHECK_INTERVAL', 30),
        'worker_timeout' => env('QUEUE_WORKER_TIMEOUT', 300),
        'graceful_shutdown_timeout' => env('QUEUE_GRACEFUL_SHUTDOWN_TIMEOUT', 30),
        'heartbeat_interval' => env('QUEUE_HEARTBEAT_INTERVAL', 15),
        'max_restarts_per_hour' => env('QUEUE_MAX_RESTARTS_PER_HOUR', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-scaling Configuration
    |--------------------------------------------------------------------------
    | Intelligent scaling based on queue load, schedule, and resources.
    */
    'auto_scaling' => [
        'enabled' => env('QUEUE_AUTO_SCALING', false),
        'check_interval' => env('QUEUE_SCALE_CHECK_INTERVAL', 60),
        'scale_up_threshold' => env('QUEUE_SCALE_UP_THRESHOLD', 100),
        'scale_down_threshold' => env('QUEUE_SCALE_DOWN_THRESHOLD', 10),
        'scale_up_step' => env('QUEUE_SCALE_UP_STEP', 2),
        'scale_down_step' => env('QUEUE_SCALE_DOWN_STEP', 1),
        'cooldown_period' => env('QUEUE_SCALE_COOLDOWN', 300), // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue-Specific Worker Configuration
    |--------------------------------------------------------------------------
    | Per-queue ops settings (worker counts + auto-scale opt-in). The kept
    | per-queue keys (priority/memory_limit/timeout/max_jobs) remain in core
    | `queue.workers.queues.<name>`.
    */
    'queues' => [
        'critical' => [
            'workers' => env('CRITICAL_QUEUE_WORKERS', 2),
            'max_workers' => env('CRITICAL_QUEUE_MAX_WORKERS', 6),
            'auto_scale' => env('CRITICAL_QUEUE_AUTO_SCALE', false),
        ],
        'maintenance' => [
            'workers' => env('MAINTENANCE_QUEUE_WORKERS', 1),
            'max_workers' => env('MAINTENANCE_QUEUE_MAX_WORKERS', 2),
            'auto_scale' => env('MAINTENANCE_QUEUE_AUTO_SCALE', false),
        ],
        'default' => [
            'workers' => env('DEFAULT_QUEUE_WORKERS', 2),
            'max_workers' => env('DEFAULT_QUEUE_MAX_WORKERS', 5),
            'auto_scale' => env('DEFAULT_QUEUE_AUTO_SCALE', false),
        ],
        'high' => [
            'workers' => env('HIGH_QUEUE_WORKERS', 3),
            'max_workers' => env('HIGH_QUEUE_MAX_WORKERS', 8),
            'auto_scale' => env('HIGH_QUEUE_AUTO_SCALE', false),
        ],
        'emails' => [
            'workers' => env('EMAIL_QUEUE_WORKERS', 2),
            'max_workers' => env('EMAIL_QUEUE_MAX_WORKERS', 4),
            'auto_scale' => env('EMAIL_QUEUE_AUTO_SCALE', false),
        ],
        'reports' => [
            'workers' => env('REPORTS_QUEUE_WORKERS', 1),
            'max_workers' => env('REPORTS_QUEUE_MAX_WORKERS', 2),
            'auto_scale' => env('REPORTS_QUEUE_AUTO_SCALE', false),
        ],
        'notifications' => [
            'workers' => env('NOTIFICATIONS_QUEUE_WORKERS', 2),
            'max_workers' => env('NOTIFICATIONS_QUEUE_MAX_WORKERS', 4),
            'auto_scale' => env('NOTIFICATIONS_QUEUE_AUTO_SCALE', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Monitoring & Limits
    |--------------------------------------------------------------------------
    | System resource monitoring for scaling decisions.
    */
    'resource_limits' => [
        'memory_limit' => env('QUEUE_WORKER_MEMORY_LIMIT', '512M'),
        'time_limit' => env('QUEUE_WORKER_TIME_LIMIT', 3600), // 1 hour
        'job_timeout' => env('QUEUE_JOB_TIMEOUT', 300), // 5 minutes
        'max_jobs_per_worker' => env('QUEUE_MAX_JOBS_PER_WORKER', 1000),
        'worker_memory_mb' => env('QUEUE_WORKER_MEMORY_MB', 128),
        'worker_cpu_percent' => env('QUEUE_WORKER_CPU_PERCENT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Monitoring Thresholds
    |--------------------------------------------------------------------------
    | Thresholds for resource-aware scaling decisions.
    */
    'resource_thresholds' => [
        'memory' => [
            'warning' => env('QUEUE_MEMORY_WARNING', 75),
            'critical' => env('QUEUE_MEMORY_CRITICAL', 90),
            'scale_limit' => env('QUEUE_MEMORY_SCALE_LIMIT', 85),
        ],
        'cpu' => [
            'warning' => env('QUEUE_CPU_WARNING', 70),
            'critical' => env('QUEUE_CPU_CRITICAL', 90),
            'scale_limit' => env('QUEUE_CPU_SCALE_LIMIT', 80),
        ],
        'disk' => [
            'warning' => env('QUEUE_DISK_WARNING', 80),
            'critical' => env('QUEUE_DISK_CRITICAL', 95),
            'scale_limit' => env('QUEUE_DISK_SCALE_LIMIT', 90),
        ],
        'load' => [
            'warning' => env('QUEUE_LOAD_WARNING', 2.0),
            'critical' => env('QUEUE_LOAD_CRITICAL', 4.0),
            'scale_limit' => env('QUEUE_LOAD_SCALE_LIMIT', 3.0),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Retention
    |--------------------------------------------------------------------------
    | Retention for persisted worker and job metrics written by WorkerMonitor.
    */
    'monitoring' => [
        'worker_retention_days' => env('QUEUE_WORKER_RETENTION_DAYS', 7),
        'metrics_retention_days' => env('QUEUE_METRICS_RETENTION_DAYS', 30),
        'cleanup_interval_seconds' => env('QUEUE_METRICS_CLEANUP_INTERVAL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy Supervisor Support
    |--------------------------------------------------------------------------
    | Legacy supervisor configuration (use process management instead).
    */
    'supervisor' => [
        'enabled' => env('QUEUE_SUPERVISOR_ENABLED', false),
        'config_path' => env('QUEUE_SUPERVISOR_CONFIG', '/etc/supervisor/conf.d/'),
        'restart_threshold' => 10, // restart worker after N failures
        'restart_cooldown' => 60, // seconds
    ],
];
