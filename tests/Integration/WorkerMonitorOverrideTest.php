<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Database\Connection;
use Glueful\Extensions\QueueOps\Monitoring\WorkerMonitor;
use Glueful\Extensions\QueueOps\QueueOpsServiceProvider;
use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Contracts\QueueDriverInterface;
use Glueful\Queue\Contracts\WorkerMonitorInterface;
use Glueful\Queue\Monitoring\NullWorkerMonitor;
use PHPUnit\Framework\TestCase;

/**
 * Proves the queue-ops extension's provider definitions win over core's default
 * WorkerMonitorInterface => NullWorkerMonitor binding (last-provider-wins, R3),
 * exactly as they would when `glueful/queue-ops` is installed and its provider
 * is applied after CoreProvider.
 *
 * Harness:
 * - Override resolve: build the real framework Container, load core's null
 *   binding FIRST, then load QueueOpsServiceProvider::services() AFTER.
 *   Container::load() overwrites by id, mirroring provider-registration order.
 * - DB write: construct the concrete WorkerMonitor against a file-based SQLite
 *   Connection (pooling off) and assert the lazy createMetricsTable() (R5)
 *   auto-creates the table and a metrics row is persisted on success.
 */
final class WorkerMonitorOverrideTest extends TestCase
{
    private string $appPath;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-qops-' . uniqid('', true);
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

    /**
     * Build a context with a file-based SQLite database (pooling off) so the
     * monitor's Connection and the test's verification Connection share the db.
     */
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

    public function testQueueOpsProviderOverridesCoreNullBinding(): void
    {
        $context = $this->context();

        // 1. Core binding: WorkerMonitorInterface => NullWorkerMonitor (no-op default).
        $container = new Container([
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
            WorkerMonitorInterface::class => new ValueDefinition(
                WorkerMonitorInterface::class,
                new NullWorkerMonitor()
            ),
        ]);
        $context->setContainer($container);

        // 2. queue-ops provider definitions applied AFTER core (last-provider-wins).
        $container->load(QueueOpsServiceProvider::services());

        $resolved = $container->get(WorkerMonitorInterface::class);

        self::assertInstanceOf(
            WorkerMonitor::class,
            $resolved,
            'queue-ops provider applied last must override core NullWorkerMonitor'
        );
        self::assertNotInstanceOf(NullWorkerMonitor::class, $resolved);
    }

    public function testInterfaceAndConcreteShareTheSameInstance(): void
    {
        $context = $this->context();

        $container = new Container([
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
            WorkerMonitorInterface::class => new ValueDefinition(
                WorkerMonitorInterface::class,
                new NullWorkerMonitor()
            ),
        ]);
        $context->setContainer($container);
        $container->load(QueueOpsServiceProvider::services());

        $viaInterface = $container->get(WorkerMonitorInterface::class);
        $viaConcrete = $container->get(WorkerMonitor::class);

        // FactoryDefinition is shared + WorkerMonitor is an alias to the interface,
        // so both ids must resolve to the identical instance.
        self::assertSame(
            $viaInterface,
            $viaConcrete,
            'WorkerMonitor alias + shared factory must yield one shared instance'
        );
    }

    public function testRecordJobLifecyclePersistsMetricsRow(): void
    {
        $context = $this->context();
        $connection = Connection::fromContext($context);

        $monitor = new WorkerMonitor($connection, true, $context);

        $job = new FakeJob('job-' . uniqid('', true), 'default', 1, 3);

        // Lazy createMetricsTable() (R5) auto-creates queue_job_metrics here.
        $monitor->recordJobStart($job);
        $monitor->recordJobSuccess($job, 0.5);

        $rows = $connection->table('queue_job_metrics')
            ->select(['*'])
            ->where('job_uuid', $job->getUuid())
            ->get();

        self::assertCount(1, $rows, 'a metrics row must exist for the job');
        self::assertSame('completed', $rows[0]['status'], 'status must reflect success');
        self::assertSame($job->getUuid(), $rows[0]['job_uuid']);
    }
}

/**
 * Minimal JobInterface stub for the metrics-write assertion.
 */
final class FakeJob implements JobInterface
{
    public function __construct(
        private string $uuid,
        private ?string $queue,
        private int $attempts,
        private int $maxAttempts,
    ) {
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getQueue(): ?string
    {
        return $this->queue;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getPayload(): array
    {
        return [];
    }

    public function fire(): void
    {
    }

    public function release(int $delay = 0): void
    {
    }

    public function delete(): void
    {
    }

    public function failed(\Exception $exception): void
    {
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getTimeout(): int
    {
        return 60;
    }

    public function getBatchUuid(): ?string
    {
        return null;
    }

    public function shouldRetry(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
    }

    public function getDescription(): string
    {
        return 'FakeJob';
    }

    public function getDriver(): ?QueueDriverInterface
    {
        return null;
    }

    public function setDriver(QueueDriverInterface $driver): void
    {
    }
}
