# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [1.0.0] - 2026-06-07 — Initial release (extracted from Glueful framework 1.52.0)

Queue operations — worker supervision, autoscaling, and worker/job metrics —
extracted from framework core in **Glueful framework 1.52.0**. Requires
`glueful/framework >=1.52.0`, which slimmed core's queue runtime to a single-worker
`queue:work` and introduced the `Glueful\Queue\Contracts\WorkerMonitorInterface`
seam this extension binds.

### Added

- **`WorkerMonitor`** implementing the core `Glueful\Queue\Contracts\WorkerMonitorInterface`
  seam — worker registration/heartbeats, job execution metrics, and stale-worker
  cleanup persisted to `queue_workers` / `queue_job_metrics`. `QueueOpsServiceProvider`
  binds it (last-provider-wins over the core no-op `NullWorkerMonitor`) and aliases
  the concrete class to the same shared instance.
- **Process-supervision tree**: `ProcessManager`, `ProcessFactory`, `WorkerProcess`,
  `AutoScaler`, `ScheduledScaler`, `ResourceMonitor`, and `StreamingMonitor` — moved
  from core with their namespaces remapped, registered as shared services.
- **`queue:supervise`** command (`SuperviseCommand`): multi-worker supervision via
  Symfony Process with `work` / `process` / `spawn` / `scale` / `status` / `stop` /
  `restart` / `health` actions, including the leaf-worker IPC loop.
- **`queue:autoscale`** command (`AutoScaleCommand`): resource-aware autoscaling
  daemon with `run` / `status` / `config` / `schedule` / `resources` / `stream`
  actions.
- **`queue_workers` and `queue_job_metrics` migrations**, registered unconditionally
  (ops persistence is this extension's purpose, not config-gated).
- **`config/queue_ops.php`** (the `queue_ops` config key) — the `process`,
  `auto_scaling`, `queues`, `resource_limits`, `resource_thresholds`, and
  `supervisor` blocks relocated out of core `config/queue.php`. The feeding env vars
  (`QUEUE_PROCESS_*`, `QUEUE_AUTO_SCALING`, `*_QUEUE_WORKERS`, etc.) are unchanged.

### Migration from framework core

Namespace map for any app/extension code referencing the moved classes:

```
Glueful\Queue\Monitoring\WorkerMonitor               →  Glueful\Extensions\QueueOps\Monitoring\WorkerMonitor
Glueful\Queue\Process\*                              →  Glueful\Extensions\QueueOps\Process\*
Glueful\Console\Commands\Queue\AutoScaleCommand      →  Glueful\Extensions\QueueOps\Console\AutoScaleCommand
Glueful\Console\Commands\Queue\SuperviseCommand      →  Glueful\Extensions\QueueOps\Console\SuperviseCommand
```

The lean `queue:work` command and the per-queue **kept** keys
(`priority` / `memory_limit` / `timeout` / `max_jobs` under
`queue.workers.queues.<name>`) stay in core; this extension merges those kept keys
with its own per-queue ops keys at runtime.
