<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Tests\Unit;

use Glueful\Extensions\QueueOps\QueueOpsServiceProvider;
use PHPUnit\Framework\TestCase;

final class QueueOpsServiceProviderTest extends TestCase
{
    public function testProviderClassExists(): void
    {
        self::assertTrue(class_exists(QueueOpsServiceProvider::class));
    }

    public function testServicesReturnsArray(): void
    {
        self::assertIsArray(QueueOpsServiceProvider::services());
    }

    public function testProviderExtendsServiceProvider(): void
    {
        self::assertTrue(
            is_subclass_of(QueueOpsServiceProvider::class, \Glueful\Extensions\ServiceProvider::class),
        );
    }
}
