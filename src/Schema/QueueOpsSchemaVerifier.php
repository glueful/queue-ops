<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Schema;

use Glueful\Database\Connection;
use Glueful\Extensions\Schema\StructuralVerifierInterface;

/**
 * Structural verifier for glueful/queue-ops (schema policy spec B7): each create migration proves
 * every table it creates with its load-bearing columns. Unknown basenames are never adoptable.
 */
final class QueueOpsSchemaVerifier implements StructuralVerifierInterface
{
    public function source(): string
    {
        return 'glueful/queue-ops';
    }

    /** @return list<string> */
    public function migrationBasenames(): array
    {
        return [
            '001_CreateQueueWorkersTable.php',
            '002_CreateQueueJobMetricsTable.php',
        ];
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        return match ($migrationBasename) {
            '001_CreateQueueWorkersTable.php' => $this->tablesWithColumns($db, [
                'queue_workers' => ['connection', 'queue', 'pid', 'hostname', 'last_seen'],
            ]),
            '002_CreateQueueJobMetricsTable.php' => $this->tablesWithColumns($db, [
                'queue_job_metrics' => ['job_uuid', 'job_class', 'queue', 'status'],
            ]),
            default => false,
        };
    }

    /** @param array<string, list<string>> $expectations */
    private function tablesWithColumns(Connection $db, array $expectations): bool
    {
        $schema = $db->getSchemaBuilder();
        foreach ($expectations as $table => $columns) {
            if (!$schema->hasTable($table)) {
                return false;
            }
            foreach ($columns as $column) {
                if (!$schema->hasColumn($table, $column)) {
                    return false;
                }
            }
        }
        return true;
    }
}
