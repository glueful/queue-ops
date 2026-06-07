<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps;

use Glueful\Bootstrap\ApplicationContext;

final class QueueOpsServiceProvider extends \Glueful\Extensions\ServiceProvider
{
    public static function services(): array
    {
        // DI definitions added in WS4 tasks 4b–4d.
        return [];
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
