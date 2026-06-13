<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Tests\Unit;

use Glueful\Extensions\QueueOps\Process\ResourceMonitor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

final class ResourceMonitorTest extends TestCase
{
    public function testResourceThresholdsConfigKeyOverridesDefaults(): void
    {
        $monitor = new ResourceMonitor(new NullLogger(), [
            'resource_thresholds' => [
                'memory' => ['warning' => 50, 'critical' => 60, 'scale_limit' => 55],
                'cpu' => ['warning' => 51, 'critical' => 61, 'scale_limit' => 56],
                'disk' => ['warning' => 52, 'critical' => 62, 'scale_limit' => 57],
                'load' => ['warning' => 1.5, 'critical' => 2.5, 'scale_limit' => 2.0],
            ],
        ]);

        $thresholds = new ReflectionProperty(ResourceMonitor::class, 'thresholds');

        self::assertSame(55, $thresholds->getValue($monitor)['memory']['scale_limit']);
        self::assertSame(56, $thresholds->getValue($monitor)['cpu']['scale_limit']);
        self::assertSame(57, $thresholds->getValue($monitor)['disk']['scale_limit']);
        self::assertSame(2.0, $thresholds->getValue($monitor)['load']['scale_limit']);
    }
}
