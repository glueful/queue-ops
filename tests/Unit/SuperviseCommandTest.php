<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Tests\Unit;

use Glueful\Extensions\QueueOps\Console\SuperviseCommand;
use PHPUnit\Framework\TestCase;

final class SuperviseCommandTest extends TestCase
{
    public function testSupervisorParentLivenessRejectsMissingPid(): void
    {
        self::assertTrue(SuperviseCommand::isSupervisorParentAlive((string) getmypid()));
        self::assertFalse(SuperviseCommand::isSupervisorParentAlive('999999999'));
        self::assertTrue(SuperviseCommand::isSupervisorParentAlive(null));
    }
}
