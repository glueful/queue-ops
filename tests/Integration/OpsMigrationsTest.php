<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Extensions\QueueOps\Migrations\CreateQueueJobMetricsTable;
use Glueful\Extensions\QueueOps\Migrations\CreateQueueWorkersTable;
use Glueful\Extensions\QueueOps\Monitoring\WorkerMonitor;
use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Contracts\QueueDriverInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the queue-ops migrations create/drop the queue_workers and
 * queue_job_metrics tables, and that their hasTable guards make up()
 * idempotent and tolerant of the R5 lazy-create (WorkerMonitor) having already
 * created the tables.
 *
 * Harness: a file-based SQLite Connection (pooling off) whose
 * getSchemaBuilder() yields the real framework SchemaBuilderInterface the
 * migrations run against.
 */
final class OpsMigrationsTest extends TestCase
{
    private string $appPath;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-qops-mig-' . uniqid('', true);
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

    private function schema(): SchemaBuilderInterface
    {
        return Connection::fromContext($this->context())->getSchemaBuilder();
    }

    public function testUpCreatesBothTables(): void
    {
        $schema = $this->schema();

        self::assertFalse($schema->hasTable('queue_workers'));
        self::assertFalse($schema->hasTable('queue_job_metrics'));

        (new CreateQueueWorkersTable())->up($schema);
        (new CreateQueueJobMetricsTable())->up($schema);

        self::assertTrue($schema->hasTable('queue_workers'), 'queue_workers must exist after up()');
        self::assertTrue($schema->hasTable('queue_job_metrics'), 'queue_job_metrics must exist after up()');
    }

    public function testCreatedSchemaAcceptsRowsMatchingTheColumns(): void
    {
        $context = $this->context();
        $connection = Connection::fromContext($context);
        $schema = $connection->getSchemaBuilder();

        (new CreateQueueWorkersTable())->up($schema);
        (new CreateQueueJobMetricsTable())->up($schema);

        // Insert a row touching every queue_workers column → proves the columns exist.
        $connection->table('queue_workers')->insert([
            'uuid' => 'worker-uuid-1',
            'connection' => 'redis',
            'queue' => 'default',
            'pid' => 4242,
            'hostname' => 'host-a',
            'started_at' => '2026-06-07 00:00:00',
            'stopped_at' => null,
            'last_seen' => '2026-06-07 00:01:00',
            'jobs_processed' => 5,
            'jobs_failed' => 1,
            'memory_usage' => 1024,
            'memory_peak' => 2048,
            'total_runtime' => 60,
            'final_jobs_processed' => 5,
            'final_jobs_failed' => 1,
            'final_memory_peak' => 2048,
            'status' => 'active',
            'options' => '{"x":1}',
        ]);

        $workers = $connection->table('queue_workers')
            ->select(['*'])
            ->where('uuid', 'worker-uuid-1')
            ->get();
        self::assertCount(1, $workers);
        self::assertSame('redis', $workers[0]['connection']);
        self::assertSame('active', $workers[0]['status']);

        // Insert a row touching every queue_job_metrics column.
        $connection->table('queue_job_metrics')->insert([
            'job_uuid' => 'job-uuid-1',
            'job_class' => 'App\\Jobs\\Demo',
            'queue' => 'default',
            'started_at' => '2026-06-07 00:00:00',
            'completed_at' => '2026-06-07 00:00:01',
            'failed_at' => null,
            'processing_time' => 0.5,
            'memory_used' => 512,
            'status' => 'completed',
            'attempts' => 1,
            'error_message' => null,
            'error_trace' => null,
        ]);

        $metrics = $connection->table('queue_job_metrics')
            ->select(['*'])
            ->where('job_uuid', 'job-uuid-1')
            ->get();
        self::assertCount(1, $metrics);
        self::assertSame('completed', $metrics[0]['status']);
        self::assertSame('App\\Jobs\\Demo', $metrics[0]['job_class']);
    }

    public function testDownDropsBothTables(): void
    {
        $schema = $this->schema();

        (new CreateQueueWorkersTable())->up($schema);
        (new CreateQueueJobMetricsTable())->up($schema);
        self::assertTrue($schema->hasTable('queue_workers'));
        self::assertTrue($schema->hasTable('queue_job_metrics'));

        (new CreateQueueWorkersTable())->down($schema);
        (new CreateQueueJobMetricsTable())->down($schema);

        self::assertFalse($schema->hasTable('queue_workers'), 'down() must drop queue_workers');
        self::assertFalse($schema->hasTable('queue_job_metrics'), 'down() must drop queue_job_metrics');
    }

    public function testUpIsIdempotentWhenRunTwice(): void
    {
        $schema = $this->schema();

        (new CreateQueueWorkersTable())->up($schema);
        (new CreateQueueJobMetricsTable())->up($schema);

        // Second run must be a no-op (hasTable guard) and not throw.
        (new CreateQueueWorkersTable())->up($schema);
        (new CreateQueueJobMetricsTable())->up($schema);

        self::assertTrue($schema->hasTable('queue_workers'));
        self::assertTrue($schema->hasTable('queue_job_metrics'));
    }

    public function testUpCoexistsWithR5LazyCreate(): void
    {
        $context = $this->context();
        $connection = Connection::fromContext($context);

        // Let the WorkerMonitor R5 lazy-create create queue_job_metrics first.
        $monitor = new WorkerMonitor($connection, true, $context);
        $job = new MigrationsFakeJob('lazy-job-' . uniqid('', true), 'default', 1, 3);
        $monitor->recordJobStart($job);
        $monitor->recordJobSuccess($job, 0.5);

        $schema = $connection->getSchemaBuilder();
        self::assertTrue(
            $schema->hasTable('queue_job_metrics'),
            'WorkerMonitor R5 lazy-create must have created queue_job_metrics'
        );

        // Running the migration after the lazy-create must NOT error (guarded no-op).
        (new CreateQueueJobMetricsTable())->up($schema);
        (new CreateQueueWorkersTable())->up($schema);

        self::assertTrue($schema->hasTable('queue_job_metrics'));
        self::assertTrue($schema->hasTable('queue_workers'));

        // The pre-existing lazy-created row survives the no-op up().
        $rows = $connection->table('queue_job_metrics')
            ->select(['*'])
            ->where('job_uuid', $job->getUuid())
            ->get();
        self::assertCount(1, $rows, 'lazy-created metrics row must survive the guarded up()');
    }
}

/**
 * Minimal JobInterface stub for driving the WorkerMonitor R5 lazy-create.
 */
final class MigrationsFakeJob implements JobInterface
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
        return 'MigrationsFakeJob';
    }

    public function getDriver(): ?QueueDriverInterface
    {
        return null;
    }

    public function setDriver(QueueDriverInterface $driver): void
    {
    }
}
