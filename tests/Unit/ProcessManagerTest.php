<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Tests\Unit;

use Glueful\Extensions\QueueOps\Monitoring\WorkerMonitor;
use Glueful\Extensions\QueueOps\Process\ProcessFactory;
use Glueful\Extensions\QueueOps\Process\ProcessManager;
use Glueful\Extensions\QueueOps\Process\WorkerProcess;
use Glueful\Queue\WorkerOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

final class ProcessManagerTest extends TestCase
{
    public function testHealthMonitorHonorsHourlyRestartLimit(): void
    {
        $factory = new RecordingProcessFactory();
        $manager = new ProcessManager(
            $factory,
            $this->createStub(WorkerMonitor::class),
            new NullLogger(),
            [
                'max_workers' => 5,
                'restart_delay' => 0,
                'max_restarts_per_hour' => 1,
            ]
        );

        $manager->spawn('default', new WorkerOptions());
        self::assertSame(1, $factory->created);

        $manager->monitorHealth();
        self::assertSame(2, $factory->created, 'first unhealthy worker is restarted');

        $manager->monitorHealth();
        self::assertSame(2, $factory->created, 'restart limit blocks the next restart in the same hour');
    }
}

final class RecordingProcessFactory extends ProcessFactory
{
    public int $created = 0;

    public function __construct()
    {
        parent::__construct(new NullLogger(), sys_get_temp_dir());
    }

    public function createWorker(string $queue, WorkerOptions $options): WorkerProcess
    {
        $this->created++;

        return new UnhealthyWorkerProcess('worker-' . $this->created, $queue, $options);
    }
}

final class UnhealthyWorkerProcess extends WorkerProcess
{
    private bool $running = true;

    public function __construct(string $workerId, string $queue, WorkerOptions $options)
    {
        parent::__construct(
            new Process([PHP_BINARY, '-r', '']),
            $workerId,
            $queue,
            $options,
            new NullLogger()
        );
    }

    public function start(): void
    {
    }

    public function stop(int $timeout = 30): void
    {
        $this->running = false;
    }

    public function forceStop(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function isHealthy(): bool
    {
        return false;
    }
}
