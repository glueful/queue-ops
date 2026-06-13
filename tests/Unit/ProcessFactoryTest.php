<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Tests\Unit;

use Glueful\Extensions\QueueOps\Process\ProcessFactory;
use Glueful\Extensions\QueueOps\Process\WorkerProcess;
use Glueful\Queue\WorkerOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Symfony\Component\Process\Process;

final class ProcessFactoryTest extends TestCase
{
    public function testSpawnedWorkersReceiveSupervisorPidEnvironment(): void
    {
        $worker = (new ProcessFactory(new NullLogger(), sys_get_temp_dir()))
            ->createWorker('default', new WorkerOptions());

        $process = new ReflectionProperty(WorkerProcess::class, 'process');
        $child = $process->getValue($worker);

        self::assertInstanceOf(Process::class, $child);
        self::assertSame((string) getmypid(), $child->getEnv()['QUEUE_SUPERVISOR_PID'] ?? null);
    }
}
