# Queue Ops Extension for Glueful

## Overview

Queue Ops restores production-grade queue operations on top of the framework's
lean queue runtime: supervised multi-worker fleets, intelligent autoscaling, and
persisted worker/job metrics. The framework core ships a single-worker
`queue:work`; this extension adds the supervisor and autoscaler around it.

It plugs into the framework through the
`Glueful\Queue\Contracts\WorkerMonitorInterface` seam: the core binds a no-op
`NullWorkerMonitor` by default, and this extension overrides it (last-provider-wins)
with a persistence-backed `WorkerMonitor` that records worker lifecycle and job
metrics into the `queue_workers` and `queue_job_metrics` tables. It then registers
the process-supervision tree (`ProcessManager`, `AutoScaler`, `ScheduledScaler`,
`ResourceMonitor`, `StreamingMonitor`) and the `queue:supervise` / `queue:autoscale`
commands. Core's `queue:work` keeps running unchanged whether or not this extension
is installed.

## Features

- **`queue:supervise`**: multi-worker supervision via Symfony Process — spawn, scale, stop, restart, status (with live watch), health checks, plus the leaf-worker IPC loop that spawned workers run
- **`queue:autoscale`**: resource-aware autoscaling daemon with load-based scaling, cron-scheduled scaling, resource monitoring, and real-time streaming
- **Worker & job metrics persistence**: lifecycle, heartbeats, and per-job metrics recorded to `queue_workers` / `queue_job_metrics`
- **Resource-aware scaling**: CPU / memory / disk / load thresholds gate scale-up decisions to prevent resource exhaustion
- **Scheduled scaling**: cron-like schedules for predictable load windows (`dragonmantank/cron-expression`)
- **Seam-based**: binds the core `WorkerMonitorInterface`, overriding the no-op default; the framework's lean `queue:work` is unaffected

## Installation

### Installation (Recommended)

**Install via Composer**

```bash
composer require glueful/queue-ops

# Rebuild the extensions cache after adding new packages
php glueful extensions:cache
```

Composer discovers packages of type `glueful-extension`, but **installing does not auto-enable** them — the provider must be added to `config/extensions.php`'s `enabled` allow-list. The CLI does that for you:

```bash
# Enable (adds the provider FQCN to config/extensions.php + recompiles the cache)
php glueful extensions:enable queue-ops

# Disable (removes it)
php glueful extensions:disable queue-ops
```

In production, manage the `enabled` list in config and run `php glueful extensions:cache` in your deploy step.

This extension ships migrations (`queue_workers`, `queue_job_metrics`). Run them after enabling:

```bash
php glueful migrate:run
```

### Local Development Installation

To develop the extension locally, register it as a Composer **path repository** in your app's `composer.json`, then require and enable it:

```jsonc
// composer.json
"repositories": [
    { "type": "path", "url": "extensions/queue-ops", "options": { "symlink": true } }
]
```

```bash
composer require glueful/queue-ops:@dev
php glueful extensions:enable queue-ops
php glueful migrate:run
```

Entries in `config/extensions.php` are plain string FQCNs (no `::class`) — prefer `extensions:enable` over editing by hand.

### Verify Installation

```bash
php glueful extensions:list
php glueful extensions:info queue-ops
php glueful extensions:diagnose
```

Post-install checklist:

- Run migrations (if not auto-run): `php glueful migrate:run`
- Rebuild cache after Composer operations: `php glueful extensions:cache`
- Confirm the seam is bound: resolving `Glueful\Queue\Contracts\WorkerMonitorInterface` yields `Glueful\Extensions\QueueOps\Monitoring\WorkerMonitor`

## Configuration

Configuration is loaded from the extension's `config/queue_ops.php` and merged under
the `queue_ops` key by the service provider. The keys below moved out of core
`config/queue.php` (the `queue.workers.*` tree) when the ops surface became this
extension; the feeding env vars are unchanged.

```env
# Process management (Symfony Process)
QUEUE_PROCESS_ENABLED=true
QUEUE_DEFAULT_WORKERS=2
QUEUE_MAX_WORKERS_GLOBAL=50
QUEUE_MAX_WORKERS=10
QUEUE_RESTART_DELAY=5
QUEUE_HEALTH_CHECK_INTERVAL=30
QUEUE_WORKER_TIMEOUT=300
QUEUE_GRACEFUL_SHUTDOWN_TIMEOUT=30
QUEUE_HEARTBEAT_INTERVAL=15
QUEUE_MAX_RESTARTS_PER_HOUR=10

# Auto-scaling
QUEUE_AUTO_SCALING=false
QUEUE_SCALE_CHECK_INTERVAL=60
QUEUE_SCALE_UP_THRESHOLD=100
QUEUE_SCALE_DOWN_THRESHOLD=10
QUEUE_SCALE_UP_STEP=2
QUEUE_SCALE_DOWN_STEP=1
QUEUE_SCALE_COOLDOWN=300

# Resource limits & thresholds
QUEUE_WORKER_MEMORY_LIMIT=512M
QUEUE_WORKER_TIME_LIMIT=3600
QUEUE_JOB_TIMEOUT=300
QUEUE_MAX_JOBS_PER_WORKER=1000
QUEUE_MEMORY_WARNING=75
QUEUE_MEMORY_CRITICAL=90
QUEUE_CPU_WARNING=70
QUEUE_CPU_CRITICAL=90
QUEUE_LOAD_WARNING=2.0
QUEUE_LOAD_CRITICAL=4.0

# Legacy supervisor (prefer process management)
QUEUE_SUPERVISOR_ENABLED=false
QUEUE_SUPERVISOR_CONFIG=/etc/supervisor/conf.d/
```

The config exposes these blocks: `process`, `auto_scaling`, `queues` (per-queue
`workers` / `max_workers` / `auto_scale`), `resource_limits`, `resource_thresholds`,
and `supervisor`. Per-queue worker counts are set with env vars such as
`CRITICAL_QUEUE_WORKERS` / `CRITICAL_QUEUE_MAX_WORKERS` / `CRITICAL_QUEUE_AUTO_SCALE`
(and the equivalent `DEFAULT_*`, `HIGH_*`, `EMAIL_*`, `REPORTS_*`,
`NOTIFICATIONS_*`, `MAINTENANCE_*` sets).

The per-queue **kept** keys — `priority` / `memory_limit` / `timeout` / `max_jobs` —
remain in core `config/queue.workers.queues.<name>` and are read by the core worker;
this extension merges them with its own per-queue keys at runtime.

## Usage

Core keeps a lean single-worker runner regardless of this extension:

```bash
php glueful queue:work            # core, single worker — always available
```

### Supervise a worker fleet — `queue:supervise`

```bash
# Start the default fleet (2 workers per queue, then monitor)
php glueful queue:supervise

# Actions: work | process | spawn | scale | status | stop | restart | health
php glueful queue:supervise spawn   --queue=high --count=3
php glueful queue:supervise scale   --queue=default --count=5
php glueful queue:supervise status  --watch=2          # live-refresh, or --json
php glueful queue:supervise health
php glueful queue:supervise stop    --all              # or --worker-id=ID
php glueful queue:supervise restart --all              # or --worker-id=ID
```

The `process` action is the leaf-worker IPC loop spawned workers run; it emits
`[HEARTBEAT]` / `[JOB_COMPLETED]` / `[METRICS]` lines the supervisor parses
(use `--with-monitoring` / `--emit-heartbeat`).

### Autoscale — `queue:autoscale`

```bash
# Run the autoscaling daemon (load + schedule + resource aware)
php glueful queue:autoscale                 # = run
php glueful queue:autoscale run --interval=30 --streaming
php glueful queue:autoscale run --no-resource-checks --no-scheduling

# Actions: run | status | config | schedule | resources | stream
php glueful queue:autoscale status   --detailed         # or --json
php glueful queue:autoscale config   --show             # --validate | --reload
php glueful queue:autoscale schedule --list             # or --preview --days=7
php glueful queue:autoscale resources --current         # --history | --trends
php glueful queue:autoscale stream   --format=table     # --filter=, --export=
```

## Requirements

- PHP 8.3 or higher
- Glueful 1.52.0 or higher
- `ext-pcntl` recommended (graceful signal handling for daemons/leaf workers)
- `symfony/process` and `dragonmantank/cron-expression` (provided by the framework)

## License

MIT — licensed consistently with the Glueful framework.

## Support

For issues, feature requests, or questions, please create an issue in the repository.
