<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Extensions\QueueOps\Monitoring\WorkerMonitor;
use Glueful\Extensions\QueueOps\Process\AutoScaler;
use Glueful\Extensions\QueueOps\Process\ProcessFactory;
use Glueful\Extensions\QueueOps\Process\ProcessManager;
use Glueful\Extensions\QueueOps\Process\ResourceMonitor;
use Glueful\Extensions\QueueOps\Process\ScheduledScaler;
use Glueful\Extensions\QueueOps\Process\StreamingMonitor;
use Glueful\Queue\Contracts\WorkerMonitorInterface;
use Glueful\Queue\QueueManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class QueueOpsServiceProvider extends \Glueful\Extensions\ServiceProvider
{
    /**
     * @return array<string, mixed>
     */
    public static function defs(): array
    {
        return [
            // Override core's WorkerMonitorInterface => NullWorkerMonitor default
            // with the persistence-backed monitor (last-provider-wins, R3).
            WorkerMonitorInterface::class => new FactoryDefinition(
                WorkerMonitorInterface::class,
                static function (ContainerInterface $c): WorkerMonitor {
                    $context = $c->get(ApplicationContext::class);
                    // Builds its own Connection::fromContext($context).
                    return new WorkerMonitor(null, true, $context);
                },
                true, // shared
            ),
            // Let WS4c/4d ops code resolve the concrete by class name and get
            // the same shared instance as the interface.
            WorkerMonitor::class => new AliasDefinition(
                WorkerMonitor::class,
                WorkerMonitorInterface::class,
            ),

            // Process supervision / auto-scaling tree (copied from core in WS4c).
            // Config derivation mirrors AutoScaleCommand::initializeServices():
            // ops config is read from config('queue_ops', []); per-queue KEPT keys
            // (priority/memory_limit/timeout/max_jobs) still come from core
            // config('queue.workers.queues', []).
            ProcessFactory::class => new FactoryDefinition(
                ProcessFactory::class,
                static function (ContainerInterface $c): ProcessFactory {
                    $context = $c->get(ApplicationContext::class);
                    return new ProcessFactory(
                        $c->get(LoggerInterface::class),
                        base_path($context),
                    );
                },
                true,
            ),
            ProcessManager::class => new FactoryDefinition(
                ProcessManager::class,
                static function (ContainerInterface $c): ProcessManager {
                    $context = $c->get(ApplicationContext::class);
                    /** @var array<string, mixed> $opsConfig */
                    $opsConfig = config($context, 'queue_ops', []);
                    /** @var array<string, mixed> $processConfig */
                    $processConfig = $opsConfig['process'] ?? [];

                    $processManagerConfig = array_merge($processConfig, [
                        'max_workers' => $processConfig['max_workers']
                            ?? $processConfig['max_workers_global']
                            ?? 10,
                    ]);

                    return new ProcessManager(
                        $c->get(ProcessFactory::class),
                        // Extension WorkerMonitor (shared instance bound above).
                        $c->get(WorkerMonitorInterface::class),
                        $c->get(LoggerInterface::class),
                        $processManagerConfig,
                    );
                },
                true,
            ),
            AutoScaler::class => new FactoryDefinition(
                AutoScaler::class,
                static function (ContainerInterface $c): AutoScaler {
                    $context = $c->get(ApplicationContext::class);
                    /** @var array<string, mixed> $opsConfig */
                    $opsConfig = config($context, 'queue_ops', []);
                    /** @var array<string, mixed> $processConfig */
                    $processConfig = $opsConfig['process'] ?? [];
                    /** @var array<string, mixed> $autoScalingConfig */
                    $autoScalingConfig = $opsConfig['auto_scaling'] ?? [];

                    $autoScalerConfig = [
                        'enabled' => (bool) ($autoScalingConfig['enabled'] ?? false),
                        // Split-read: ops keys (workers/max_workers/auto_scale) from
                        // queue_ops.queues + kept keys (priority/memory_limit/timeout/
                        // max_jobs) from core queue.workers.queues.
                        'queues' => self::mergeQueueConfigs(
                            $opsConfig['queues'] ?? [],
                            config($context, 'queue.workers.queues', []),
                        ),
                        'auto_scale' => [
                            'scale_up_threshold' => (int) ($autoScalingConfig['scale_up_threshold'] ?? 100),
                            'scale_down_threshold' => (int) ($autoScalingConfig['scale_down_threshold'] ?? 10),
                            'scale_up_step' => (int) ($autoScalingConfig['scale_up_step'] ?? 2),
                            'scale_down_step' => (int) ($autoScalingConfig['scale_down_step'] ?? 1),
                            'cooldown_period' => (int) ($autoScalingConfig['cooldown_period'] ?? 300),
                        ],
                        'limits' => [
                            'max_workers_per_queue' => (int) (
                                $processConfig['max_workers_per_queue']
                                ?? $processConfig['max_workers']
                                ?? 10
                            ),
                        ],
                    ];

                    return new AutoScaler(
                        $c->get(ProcessManager::class),
                        $c->get(QueueManager::class),
                        $c->get(LoggerInterface::class),
                        $autoScalerConfig,
                    );
                },
                true,
            ),
            ScheduledScaler::class => new FactoryDefinition(
                ScheduledScaler::class,
                static function (ContainerInterface $c): ScheduledScaler {
                    return new ScheduledScaler(
                        $c->get(ProcessManager::class),
                        $c->get(LoggerInterface::class),
                    );
                },
                true,
            ),
            ResourceMonitor::class => new FactoryDefinition(
                ResourceMonitor::class,
                static function (ContainerInterface $c): ResourceMonitor {
                    $context = $c->get(ApplicationContext::class);
                    /** @var array<string, mixed> $opsConfig */
                    $opsConfig = config($context, 'queue_ops', []);
                    return new ResourceMonitor(
                        $c->get(LoggerInterface::class),
                        $opsConfig,
                    );
                },
                true,
            ),
            StreamingMonitor::class => new FactoryDefinition(
                StreamingMonitor::class,
                static function (ContainerInterface $c): StreamingMonitor {
                    return new StreamingMonitor(
                        $c->get(ProcessManager::class),
                        $c->get(LoggerInterface::class),
                    );
                },
                true,
            ),
        ];
    }

    /**
     * Merge per-queue ops config (workers/max_workers/auto_scale, from queue_ops)
     * with the per-queue KEPT keys (priority/memory_limit/timeout/max_jobs, from
     * core queue.workers.queues). The AutoScaler reads both: max_workers from ops,
     * memory_limit/timeout/max_jobs from core.
     *
     * @param array<string, mixed> $opsQueues
     * @param array<string, mixed> $coreQueues
     * @return array<string, array<string, mixed>>
     */
    private static function mergeQueueConfigs(array $opsQueues, array $coreQueues): array
    {
        $merged = [];
        /** @var array<string> $names */
        $names = array_unique(array_merge(array_keys($opsQueues), array_keys($coreQueues)));
        foreach ($names as $name) {
            /** @var array<string, mixed> $core */
            $core = is_array($coreQueues[$name] ?? null) ? $coreQueues[$name] : [];
            /** @var array<string, mixed> $ops */
            $ops = is_array($opsQueues[$name] ?? null) ? $opsQueues[$name] : [];
            // Ops keys win on overlap (there is none today); kept keys come from core.
            $merged[$name] = array_merge($core, $ops);
        }
        return $merged;
    }

    public function register(ApplicationContext $context): void
    {
        // Own the relocated ops config (WS5b): the `queue.workers.{process,
        // auto_scaling,resource_limits,resource_thresholds,supervisor}` blocks and
        // the per-queue worker/max_worker/auto_scale keys now live under `queue_ops.*`.
        $this->mergeConfig('queue_ops', require __DIR__ . '/../config/queue_ops.php');

        // queue_workers + queue_job_metrics schema. Registered unconditionally:
        // ops persistence IS this extension's purpose (not config-gated).
        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            \Glueful\Database\Migrations\MigrationPriority::DEFAULT,
            'glueful/queue-ops',
        );
    }

    public function boot(ApplicationContext $context): void
    {
        // Auto-discover #[AsCommand] CLI commands (SuperviseCommand + AutoScaleCommand).
        $this->discoverCommands(
            'Glueful\\Extensions\\QueueOps\\Console',
            __DIR__ . '/Console',
        );
    }
}
