<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Extensions\QueueOps\Monitoring\WorkerMonitor;
use Glueful\Queue\Contracts\WorkerMonitorInterface;
use Psr\Container\ContainerInterface;

final class QueueOpsServiceProvider extends \Glueful\Extensions\ServiceProvider
{
    public static function services(): array
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
        ];
    }

    public function register(ApplicationContext $context): void
    {
        // mergeConfig + loadMigrationsFrom added in WS5.
    }

    public function boot(ApplicationContext $context): void
    {
        // discoverCommands added in WS4 task 4d.
    }
}
