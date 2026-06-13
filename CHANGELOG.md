# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [Unreleased]

### Added

- **Discovery-path regression test.** Loads the provider through the framework's real
  extension-discovery dispatch (`defs()` pass-through, else `services()` via the DSL loader),
  guarding against typed `Definition` objects being returned from `services()` — a regression the
  existing `Container::load()`-based tests cannot catch.

### Fixed

- **Restart storm control.** `ProcessManager::monitorHealth()` now honors
  `max_restarts_per_hour` per queue before restarting unhealthy workers, so a
  crash-on-boot worker cannot be respawned indefinitely.
- **Bounded worker output reads.** `WorkerProcess` now drains incremental stdout/stderr buffers
  when supervisors read worker output, avoiding repeated retention of the full Symfony Process
  output buffer for long-running workers.
- **Autoscale interval clamp.** `queue:autoscale run --interval` is now clamped to at least one
  second, preventing zero or negative values from creating a busy loop.
- **Scheduled scaling bounds.** Scheduled worker targets now honor `min_workers` and
  `max_workers` options at registration time, so scheduled scale operations cannot request an
  out-of-bounds worker count.
- **Resource threshold config loading.** `ResourceMonitor` now honors the shipped
  `resource_thresholds` config key instead of silently falling back to default thresholds.
- **Resource-aware scale-up.** `AutoScaler` now consults `ResourceMonitor::canScaleUp()` before
  adding workers, so configured memory/CPU/disk/load ceilings can block scale-up instead of only
  emitting a warning.
- **Boot compatibility with framework 1.55.** The service provider declared its bindings via the
  DSL `services()` method but returned strongly-typed `DefinitionInterface` objects, which the
  framework's DSL service loader rejects (`"Service '<id>' must be an array"`). Under framework
  1.55 this threw during boot in dev/test and silently dropped the bindings in production. The
  method is now `defs()`, the strongly-typed pass-through path that accepts `DefinitionInterface`
  objects.

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
