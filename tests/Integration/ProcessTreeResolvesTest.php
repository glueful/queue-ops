<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Extensions\QueueOps\Process\AutoScaler;
use Glueful\Extensions\QueueOps\Process\ProcessFactory;
use Glueful\Extensions\QueueOps\Process\ProcessManager;
use Glueful\Extensions\QueueOps\Process\ResourceMonitor;
use Glueful\Extensions\QueueOps\Process\ScheduledScaler;
use Glueful\Extensions\QueueOps\Process\StreamingMonitor;
use Glueful\Extensions\QueueOps\QueueOpsServiceProvider;
use Glueful\Queue\Contracts\WorkerMonitorInterface;
use Glueful\Queue\Monitoring\NullWorkerMonitor;
use Glueful\Queue\QueueManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test for WS4c: the Process supervision / auto-scaling tree copied into
 * the extension is registered in QueueOpsServiceProvider::services() and every
 * container-resolved class constructs with all deps satisfied.
 *
 * Harness mirrors WorkerMonitorOverrideTest: build the real framework Container,
 * seed the deps the Process closures pull (ApplicationContext, LoggerInterface,
 * QueueManager, core's WorkerMonitorInterface default), then load the extension
 * provider definitions AFTER (last-provider-wins) and resolve.
 *
 * A file-based SQLite context (pooling off) is used so the extension's concrete
 * WorkerMonitor can build its Connection during ProcessManager construction
 * without needing a live external DB.
 */
final class ProcessTreeResolvesTest extends TestCase
{
    private string $appPath;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-qops-proc-' . uniqid('', true);
        $this->dbFile = $this->appPath . '/queue.sqlite';
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

    private function context(): ApplicationContext
    {
        $context = new ApplicationContext($this->appPath, 'testing');
        $context->mergeConfigDefaults('database', [
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbFile],
            'pooling' => ['enabled' => false],
        ]);
        return $context;
    }

    private function buildContainer(ApplicationContext $context): Container
    {
        $container = new Container([
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
            LoggerInterface::class => new ValueDefinition(LoggerInterface::class, new NullLogger()),
            QueueManager::class => new ValueDefinition(
                QueueManager::class,
                new QueueManager([], $context)
            ),
            // Core default that the extension provider overrides (last-provider-wins).
            WorkerMonitorInterface::class => new ValueDefinition(
                WorkerMonitorInterface::class,
                new NullWorkerMonitor()
            ),
        ]);
        $context->setContainer($container);

        // queue-ops provider definitions applied AFTER core.
        $container->load(QueueOpsServiceProvider::services());

        return $container;
    }

    public function testProcessManagerResolvesAsExtensionClass(): void
    {
        $context = $this->context();
        $container = $this->buildContainer($context);

        $manager = $container->get(ProcessManager::class);

        self::assertInstanceOf(ProcessManager::class, $manager);
    }

    public function testAutoScalerResolvesAsExtensionClass(): void
    {
        $context = $this->context();
        $container = $this->buildContainer($context);

        $autoScaler = $container->get(AutoScaler::class);

        self::assertInstanceOf(AutoScaler::class, $autoScaler);
    }

    public function testWholeProcessTreeConstructsWithoutError(): void
    {
        $context = $this->context();
        $container = $this->buildContainer($context);

        self::assertInstanceOf(ProcessFactory::class, $container->get(ProcessFactory::class));
        self::assertInstanceOf(ProcessManager::class, $container->get(ProcessManager::class));
        self::assertInstanceOf(AutoScaler::class, $container->get(AutoScaler::class));
        self::assertInstanceOf(ScheduledScaler::class, $container->get(ScheduledScaler::class));
        self::assertInstanceOf(ResourceMonitor::class, $container->get(ResourceMonitor::class));
        self::assertInstanceOf(StreamingMonitor::class, $container->get(StreamingMonitor::class));
    }

    public function testProcessManagerIsSharedSingleton(): void
    {
        $context = $this->context();
        $container = $this->buildContainer($context);

        self::assertSame(
            $container->get(ProcessManager::class),
            $container->get(ProcessManager::class),
            'ProcessManager FactoryDefinition is shared, so the same instance must be returned'
        );
    }
}
