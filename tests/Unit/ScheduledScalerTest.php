<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Tests\Unit;

use Glueful\Extensions\QueueOps\Monitoring\WorkerMonitor;
use Glueful\Extensions\QueueOps\Process\ProcessFactory;
use Glueful\Extensions\QueueOps\Process\ProcessManager;
use Glueful\Extensions\QueueOps\Process\ScheduledScaler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ScheduledScalerTest extends TestCase
{
    public function testScheduleWorkerCountIsClampedToMinAndMaxBounds(): void
    {
        $scaler = new ScheduledScaler($this->processManager(), new NullLogger());

        $scaler->addSchedule('too-low', '* * * * *', 'default', 0, [
            'min_workers' => 2,
            'max_workers' => 5,
        ]);
        $scaler->addSchedule('too-high', '* * * * *', 'default', 10, [
            'min_workers' => 2,
            'max_workers' => 5,
        ]);

        self::assertSame(2, $scaler->getSchedule('too-low')['workers']);
        self::assertSame(5, $scaler->getSchedule('too-high')['workers']);
    }

    private function processManager(): ProcessManager
    {
        return new ProcessManager(
            new ProcessFactory(new NullLogger(), sys_get_temp_dir()),
            $this->createStub(WorkerMonitor::class),
            new NullLogger()
        );
    }
}
