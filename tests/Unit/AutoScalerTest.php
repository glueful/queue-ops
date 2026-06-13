<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Tests\Unit;

use Glueful\Extensions\QueueOps\Monitoring\WorkerMonitor;
use Glueful\Extensions\QueueOps\Process\AutoScaler;
use Glueful\Extensions\QueueOps\Process\ProcessFactory;
use Glueful\Extensions\QueueOps\Process\ProcessManager;
use Glueful\Extensions\QueueOps\Process\ResourceMonitor;
use Glueful\Queue\QueueManager;
use Glueful\Queue\WorkerOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AutoScalerTest extends TestCase
{
    public function testScaleUpIsBlockedWhenResourceMonitorRejectsAdditionalWorkers(): void
    {
        $processManager = new RecordingScaleProcessManager(1, $this->createStub(WorkerMonitor::class));
        $queueManager = $this->createStub(QueueManager::class);
        $queueManager->method('size')->willReturn(150);

        $scaler = new AutoScaler(
            $processManager,
            $queueManager,
            new NullLogger(),
            [
                'auto_scale' => [
                    'scale_up_threshold' => 100,
                    'scale_down_threshold' => 10,
                    'scale_up_step' => 2,
                    'scale_down_step' => 1,
                    'cooldown_period' => 0,
                ],
                'limits' => ['max_workers_per_queue' => 10],
            ],
            new BlockingResourceMonitor()
        );

        self::assertNull($scaler->scaleQueue('default', [
            'auto_scale' => true,
            'max_workers' => 10,
        ]));
        self::assertSame([], $processManager->scaleCalls);
    }
}

final class RecordingScaleProcessManager extends ProcessManager
{
    /** @var array<int, array{count: int, queue: string}> */
    public array $scaleCalls = [];

    public function __construct(private readonly int $currentWorkers, WorkerMonitor $workerMonitor)
    {
        parent::__construct(
            new ProcessFactory(new NullLogger(), sys_get_temp_dir()),
            $workerMonitor,
            new NullLogger()
        );
    }

    public function getWorkerCount(?string $queue = null): int
    {
        return $this->currentWorkers;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getStatus(): array
    {
        return [];
    }

    public function scale(int $count, string $queue = 'default', ?WorkerOptions $options = null): void
    {
        $this->scaleCalls[] = ['count' => $count, 'queue' => $queue];
    }
}

final class BlockingResourceMonitor extends ResourceMonitor
{
    public function __construct()
    {
        parent::__construct(new NullLogger());
    }

    /**
     * @return array<string, mixed>
     */
    public function canScaleUp(int $additionalWorkers = 1): array
    {
        return [
            'can_scale' => false,
            'reasons' => ['resource ceiling reached'],
        ];
    }
}
