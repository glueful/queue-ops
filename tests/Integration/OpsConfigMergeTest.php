<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Extensions\QueueOps\QueueOpsServiceProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves the queue-ops provider's register() mergeConfig('queue_ops', ...) makes
 * the relocated ops config resolvable under `queue_ops.*` with the documented
 * env-default values — the keys that used to live under core `queue.workers.*`.
 *
 * Harness: build a real framework Container holding the ApplicationContext, run
 * QueueOpsServiceProvider::register() (which calls mergeConfig), then read the
 * merged config back through the framework config() helper.
 */
final class OpsConfigMergeTest extends TestCase
{
    private string $appPath;

    protected function setUp(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-qops-cfg-' . uniqid('', true);
        mkdir($this->appPath, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->appPath)) {
            foreach (glob($this->appPath . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->appPath);
        }
        parent::tearDown();
    }

    private function bootContextWithProvider(): ApplicationContext
    {
        $context = new ApplicationContext($this->appPath, 'testing');
        $container = new Container([
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
        ]);
        $context->setContainer($container);

        // Apply the provider's register() — this is what wires mergeConfig('queue_ops').
        $provider = new QueueOpsServiceProvider($container);
        $provider->register($context);

        return $context;
    }

    public function testMergedQueueOpsConfigResolvesEnvDefaults(): void
    {
        $context = $this->bootContextWithProvider();

        // process block
        self::assertSame(2, config($context, 'queue_ops.process.default_workers'));

        // auto_scaling block
        self::assertFalse(config($context, 'queue_ops.auto_scaling.enabled'));

        // resource_thresholds block
        self::assertSame(75, config($context, 'queue_ops.resource_thresholds.memory.warning'));

        // supervisor block
        self::assertFalse(config($context, 'queue_ops.supervisor.enabled'));

        // per-queue ops keys
        self::assertSame(2, config($context, 'queue_ops.queues.critical.workers'));
        self::assertSame(6, config($context, 'queue_ops.queues.critical.max_workers'));
        self::assertFalse(config($context, 'queue_ops.queues.critical.auto_scale'));
    }

    public function testResourceLimitsAndScalingDefaultsResolve(): void
    {
        $context = $this->bootContextWithProvider();

        self::assertSame('512M', config($context, 'queue_ops.resource_limits.memory_limit'));
        self::assertSame(100, config($context, 'queue_ops.auto_scaling.scale_up_threshold'));
        self::assertSame(50, config($context, 'queue_ops.process.max_workers_global'));
    }
}
