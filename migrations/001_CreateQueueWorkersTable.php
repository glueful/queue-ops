<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Queue workers table — persistence backing for the queue-ops WorkerMonitor.
 *
 * Mirrors WorkerMonitor::createWorkersTable() (R5 lazy-create fallback) so the
 * schema is identical whether created by migration or on first write. Owned by
 * the `glueful/queue-ops` extension; registered unconditionally (ops is the
 * extension's purpose).
 */
class CreateQueueWorkersTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('queue_workers')) {
            $schema->createTable('queue_workers', function ($table) {
                $table->integer('id')->primary()->autoIncrement();
                $table->string('uuid', 255);
                $table->string('connection', 255);
                $table->string('queue', 255);
                $table->integer('pid');
                $table->string('hostname', 255);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('stopped_at')->nullable();
                $table->timestamp('last_seen')->nullable();
                $table->integer('jobs_processed')->default(0);
                $table->integer('jobs_failed')->default(0);
                $table->bigInteger('memory_usage')->default(0);
                $table->bigInteger('memory_peak')->default(0);
                $table->integer('total_runtime')->default(0);
                $table->integer('final_jobs_processed')->default(0);
                $table->integer('final_jobs_failed')->default(0);
                $table->bigInteger('final_memory_peak')->default(0);
                $table->string('status', 20)->default('active');
                $table->text('options')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

                $table->unique('uuid');
                $table->index('status', 'idx_status');
                $table->index('last_seen', 'idx_last_seen');
                $table->index(['connection', 'queue'], 'idx_connection_queue');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('queue_workers');
    }

    public function getDescription(): string
    {
        return 'Creates the queue_workers table for queue-ops worker monitoring';
    }
}
