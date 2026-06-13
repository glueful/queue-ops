<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Tests\Unit;

use Glueful\Container\Definition\DefinitionInterface;
use Glueful\Container\Loader\DefaultServicesLoader;
use Glueful\Extensions\QueueOps\QueueOpsServiceProvider;
use Glueful\Queue\Contracts\WorkerMonitorInterface;
use PHPUnit\Framework\TestCase;

final class QueueOpsServiceProviderTest extends TestCase
{
    public function testProviderClassExists(): void
    {
        self::assertTrue(class_exists(QueueOpsServiceProvider::class));
    }

    public function testServicesReturnsArray(): void
    {
        self::assertIsArray(QueueOpsServiceProvider::defs());
    }

    /**
     * Discovery-path guard. Loads the provider the way ContainerFactory::loadExtensionDefinitions
     * does: a `defs()` map passes through as DefinitionInterface objects; a `services()` map is
     * compiled by DefaultServicesLoader, which REJECTS non-array specs. Fails loudly if typed
     * Definition objects are ever returned from `services()` (they belong in `defs()`).
     */
    public function testLoadsThroughExtensionDiscoveryDispatch(): void
    {
        $provider = QueueOpsServiceProvider::class;

        if (method_exists($provider, 'defs')) {
            $defs = (array) $provider::defs();
        } else {
            $defs = (new DefaultServicesLoader())->load($provider::services(), $provider, false);
        }

        self::assertNotEmpty($defs);
        self::assertArrayHasKey(WorkerMonitorInterface::class, $defs);
        foreach ($defs as $id => $def) {
            self::assertInstanceOf(
                DefinitionInterface::class,
                $def,
                "Definition for '{$id}' must be a DefinitionInterface after discovery-path loading"
            );
        }
    }

    public function testProviderExtendsServiceProvider(): void
    {
        self::assertTrue(
            is_subclass_of(QueueOpsServiceProvider::class, \Glueful\Extensions\ServiceProvider::class),
        );
    }
}
