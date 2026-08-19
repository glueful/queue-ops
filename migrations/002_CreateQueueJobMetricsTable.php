<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Queue job metrics table — per-job lifecycle metrics for the queue-ops
 * WorkerMonitor.
 *
 * Mirrors WorkerMonitor::createMetricsTable() (R5 lazy-create fallback) so the
 * schema is identical whether created by migration or on first write. Owned by
 * the `glueful/queue-ops` extension; registered unconditionally.
 */
class CreateQueueJobMetricsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('queue_job_metrics')) {
            $schema->createTable('queue_job_metrics', function ($table) {
                $table->integer('id')->primary()->autoIncrement();
                $table->string('job_uuid', 255);
                $table->string('job_class', 255);
                $table->string('queue', 255);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->decimal('processing_time', 10, 4)->default(0);
                $table->bigInteger('memory_used')->default(0);
                $table->string('status', 20)->default('processing');
                $table->integer('attempts')->default(1);
                $table->text('error_message')->nullable();
                $table->text('error_trace')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

                $table->unique('job_uuid', 'idx_job_uuid');
                $table->index('status', 'idx_queue_job_metrics_status');
                $table->index('queue', 'idx_queue');
                $table->index('created_at', 'idx_created_at');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('queue_job_metrics');
    }

    public function getDescription(): string
    {
        return 'Creates the queue_job_metrics table for queue-ops job metrics';
    }
}
