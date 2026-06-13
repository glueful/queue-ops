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

    public function testWorkerOutputReadsDrainProcessBuffer(): void
    {
        $worker = new WorkerProcess(
            new Process([PHP_BINARY, '-r', 'echo "worker-output";']),
            'worker-output-test',
            'default',
            new WorkerOptions(),
            new NullLogger()
        );

        $worker->start();
        usleep(200000);

        self::assertSame('worker-output', $worker->getOutput());
        self::assertSame('', $worker->getOutput());
    }

    public function testScaleDownStopsLeastActiveWorkersFirst(): void
    {
        $factory = new PrioritizedProcessFactory([50, 0, 10]);
        $manager = new ProcessManager(
            $factory,
            $this->createStub(WorkerMonitor::class),
            new NullLogger(),
            ['max_workers' => 5]
        );

        $manager->spawn('default', new WorkerOptions());
        $manager->spawn('default', new WorkerOptions());
        $manager->spawn('default', new WorkerOptions());

        $manager->scale(1, 'default');

        self::assertSame(['worker-2', 'worker-3'], PrioritizedWorkerProcess::$stopped);
        self::assertSame(1, $manager->getWorkerCount('default'));
        self::assertNotNull($manager->getWorker('worker-1'));
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

final class PrioritizedProcessFactory extends ProcessFactory
{
    /** @param array<int, int> $jobCounts */
    public function __construct(private readonly array $jobCounts)
    {
        parent::__construct(new NullLogger(), sys_get_temp_dir());
        PrioritizedWorkerProcess::$created = [];
        PrioritizedWorkerProcess::$stopped = [];
    }

    public function createWorker(string $queue, WorkerOptions $options): WorkerProcess
    {
        $workerNumber = count(PrioritizedWorkerProcess::$created) + 1;
        $workerId = 'worker-' . $workerNumber;
        PrioritizedWorkerProcess::$created[] = $workerId;

        return new PrioritizedWorkerProcess(
            $workerId,
            $queue,
            $options,
            $this->jobCounts[$workerNumber - 1] ?? 0
        );
    }
}

final class PrioritizedWorkerProcess extends WorkerProcess
{
    /** @var array<int, string> */
    public static array $created = [];
    /** @var array<int, string> */
    public static array $stopped = [];
    private bool $running = true;

    public function __construct(
        string $workerId,
        string $queue,
        WorkerOptions $options,
        private readonly int $jobsProcessed
    ) {
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
        self::$stopped[] = $this->getWorkerId();
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

    public function getJobsProcessed(): int
    {
        return $this->jobsProcessed;
    }
}
