<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Tests\Unit;

use Glueful\Extensions\QueueOps\Console\AutoScaleCommand;
use PHPUnit\Framework\TestCase;

final class AutoScaleCommandTest extends TestCase
{
    public function testCheckIntervalIsClampedToAtLeastOneSecond(): void
    {
        self::assertSame(1, AutoScaleCommand::normalizeCheckInterval('0'));
        self::assertSame(1, AutoScaleCommand::normalizeCheckInterval('-5'));
        self::assertSame(1, AutoScaleCommand::normalizeCheckInterval('not-a-number'));
        self::assertSame(30, AutoScaleCommand::normalizeCheckInterval('30'));
    }
}
