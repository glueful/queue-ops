<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Console;

use Glueful\Console\Commands\Queue\BaseQueueCommand;
use Glueful\Extensions\QueueOps\Process\ProcessManager;
use Glueful\Queue\QueueManager;
use Glueful\Queue\WorkerOptions;
use Glueful\Lock\LockManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Queue Supervise Command
 *
 * Supervisor surface ported out of the (now lean) core `queue:work` command into
 * the `glueful/queue-ops` extension. Provides:
 *  - Multi-worker supervision using Symfony Process (spawn/scale/stop/restart).
 *  - Advanced worker monitoring and health checks.
 *  - Real-time status monitoring with auto-refresh.
 *  - The leaf-worker IPC loop (`process` action) that spawned workers run, which
 *    emits `[HEARTBEAT]` / `[JOB_COMPLETED]` / `[METRICS]` stdout lines parsed by
 *    the supervisor (G5).
 *
 * Dependencies that were previously `new`-ed in core's `initializeServices()` are
 * resolved from the container here (registered by QueueOpsServiceProvider in WS4c).
 *
 * @package Glueful\Extensions\QueueOps\Console
 */
#[AsCommand(
    name: 'queue:supervise',
    description: 'Supervise queue workers (multi-worker, scaling, monitoring, leaf IPC)'
)]
class SuperviseCommand extends BaseQueueCommand
{
    private ?ProcessManager $processManager = null;
    private ?LockManagerInterface $lockManager = null;

    protected function configure(): void
    {
        $this->setDescription('Supervise queue workers (multi-worker, scaling, monitoring, leaf IPC)')
             ->setHelp('This command supervises queue workers with modern process management, ' .
                      'multi-worker support, and advanced monitoring capabilities.')
             ->addArgument(
                 'action',
                 InputArgument::OPTIONAL,
                 'Action to perform (work, process, spawn, scale, status, stop, restart, health)',
                 'work'
             )
             ->addOption(
                 'workers',
                 'w',
                 InputOption::VALUE_REQUIRED,
                 'Number of workers to spawn',
                 '2'
             )
             ->addOption(
                 'queue',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Queue(s) to process (comma-separated)',
                 'default'
             )
             ->addOption(
                 'memory',
                 'm',
                 InputOption::VALUE_REQUIRED,
                 'Memory limit per worker in MB',
                 '128'
             )
             ->addOption(
                 'timeout',
                 't',
                 InputOption::VALUE_REQUIRED,
                 'Job timeout in seconds',
                 '60'
             )
             ->addOption(
                 'max-jobs',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Max jobs per worker before restart',
                 '1000'
             )
             ->addOption(
                 'daemon',
                 'd',
                 InputOption::VALUE_NONE,
                 'Run in daemon mode (keep running)'
             )
             ->addOption(
                 'count',
                 'c',
                 InputOption::VALUE_REQUIRED,
                 'Number of workers to spawn/scale (for spawn/scale actions)',
                 '1'
             )
             ->addOption(
                 'worker-id',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Specific worker ID (for stop/restart actions)'
             )
             ->addOption(
                 'all',
                 'a',
                 InputOption::VALUE_NONE,
                 'Apply to all workers (for stop/restart actions)'
             )
             ->addOption(
                 'json',
                 'j',
                 InputOption::VALUE_NONE,
                 'Output status as JSON'
             )
             ->addOption(
                 'watch',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Auto-refresh interval in seconds (for status action)'
             )
             ->addOption(
                 'sleep',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Sleep duration in seconds when no job is available (process mode)',
                 '3'
             )
             ->addOption(
                 'max-runtime',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Maximum worker runtime in seconds before exit (0 = unlimited)',
                 '0'
             )
             ->addOption(
                 'max-attempts',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Maximum retry attempts for failed jobs in process mode',
                 '3'
             )
             ->addOption(
                 'stop-when-empty',
                 null,
                 InputOption::VALUE_NONE,
                 'Stop when queue is empty'
             )
             ->addOption(
                 'with-monitoring',
                 null,
                 InputOption::VALUE_NONE,
                 'Emit worker monitoring lines to stdout'
             )
             ->addOption(
                 'emit-heartbeat',
                 null,
                 InputOption::VALUE_NONE,
                 'Emit periodic heartbeat messages to stdout'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');

        try {
            return match ($action) {
                'work' => $this->executeWork($input),
                'process' => $this->executeProcess($input),
                'spawn' => $this->executeSpawn($input),
                'scale' => $this->executeScale($input),
                'status' => $this->executeStatus($input),
                'stop' => $this->executeStop($input),
                'restart' => $this->executeRestart($input),
                'health' => $this->executeHealth($input),
                default => $this->handleUnknownAction($action)
            };
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            if ((bool) $input->getOption('verbose') === true) {
                $this->error($e->getTraceAsString());
            }
            return self::FAILURE;
        }
    }

    /**
     * Resolve the ProcessManager from the container (registered by the queue-ops
     * provider in WS4c). Lazy so the leaf `process` action — which spawned workers
     * run and which needs only the QueueManager — does not pull the supervisor tree.
     */
    private function processManager(): ProcessManager
    {
        return $this->processManager ??= $this->getService(ProcessManager::class);
    }

    /**
     * Resolve the core LockManager from the container.
     */
    private function lockManager(): LockManagerInterface
    {
        return $this->lockManager ??= $this->getService(LockManagerInterface::class);
    }

    private function executeWork(InputInterface $input): int
    {
        $workerCount = (int) $input->getOption('workers');
        $queues = $this->parseQueues((string) $input->getOption('queue'));
        $memory = (int) $input->getOption('memory');
        $timeout = (int) $input->getOption('timeout');
        $maxJobs = (int) $input->getOption('max-jobs');
        $daemon = (bool) $input->getOption('daemon');
        $stopWhenEmpty = (bool) $input->getOption('stop-when-empty');

        $this->info("🚀 Starting queue workers...");
        $this->line("Workers: {$workerCount} (multi-worker enabled by default)");
        $this->line("Queue(s): " . implode(', ', $queues));
        $this->line("Memory limit: {$memory} MB per worker");
        $this->line();

        // Create worker options
        $workerOptions = new WorkerOptions(
            sleep: 3,
            memory: $memory,
            timeout: $timeout,
            maxJobs: $maxJobs,
            stopWhenEmpty: $stopWhenEmpty,
            maxAttempts: 3
        );

        // Spawn workers for each queue with lock coordination
        foreach ($queues as $queue) {
            $queue = trim($queue);
            $this->spawnWorkersWithLock($queue, $workerCount, $workerOptions);
        }

        $this->success("Spawned {$workerCount} worker(s) per queue");

        // Monitor workers if not in daemon mode
        if ($daemon !== true) {
            return $this->monitorWorkers();
        }

        return self::SUCCESS;
    }

    /**
     * Process queue jobs directly in the current process (leaf worker mode).
     *
     * This mode is used by ProcessFactory spawned workers to avoid recursive
     * queue manager spawning. Emits `[HEARTBEAT]` / `[JOB_COMPLETED]` /
     * `[METRICS]` stdout lines parsed by the supervisor (G5) — kept verbatim.
     */
    private function executeProcess(InputInterface $input): int
    {
        $queues = $this->parseQueues((string) $input->getOption('queue'));
        $sleep = max(1, (int) $input->getOption('sleep'));
        $maxJobs = max(0, (int) $input->getOption('max-jobs'));
        $maxRuntime = max(0, (int) $input->getOption('max-runtime'));
        $maxAttempts = max(1, (int) $input->getOption('max-attempts'));
        $stopWhenEmpty = (bool) $input->getOption('stop-when-empty');
        $withMonitoring = (bool) $input->getOption('with-monitoring');
        $emitHeartbeat = (bool) $input->getOption('emit-heartbeat');

        $queueManager = $this->getService(QueueManager::class);
        $driver = $queueManager->connection();

        $envWorkerId = $_ENV['WORKER_ID'] ?? null;
        $envSupervisorPid = $_ENV['QUEUE_SUPERVISOR_PID'] ?? getenv('QUEUE_SUPERVISOR_PID');
        $supervisorPid = is_string($envSupervisorPid) && $envSupervisorPid !== '' ? $envSupervisorPid : null;
        $hostname = gethostname();
        $workerId = is_string($envWorkerId) && $envWorkerId !== ''
            ? $envWorkerId
            : ($hostname !== false ? $hostname : 'worker');
        $startedAt = time();
        $processedJobs = 0;
        $running = true;
        $lastHeartbeatAt = 0;

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use (&$running): void {
                $running = false;
            });
            pcntl_signal(SIGINT, function () use (&$running): void {
                $running = false;
            });
        }

        while ($running) {
            if (!self::isSupervisorParentAlive(is_string($supervisorPid) ? $supervisorPid : null)) {
                return self::SUCCESS;
            }

            if ($emitHeartbeat && (time() - $lastHeartbeatAt) >= 15) {
                $lastHeartbeatAt = time();
                echo '[HEARTBEAT] worker=' . $workerId . ' ts=' . date('c') . PHP_EOL;
            }

            $jobProcessedInCycle = false;
            foreach ($queues as $queue) {
                $queue = trim($queue);
                if ($queue === '') {
                    continue;
                }

                $job = $driver->pop($queue);
                if ($job === null) {
                    continue;
                }

                $jobProcessedInCycle = true;

                try {
                    $job->fire();
                    $processedJobs++;

                    if ($withMonitoring) {
                        echo '[JOB_COMPLETED] worker=' . $workerId .
                             ' queue=' . $queue .
                             ' uuid=' . $job->getUuid() . PHP_EOL;
                        echo '[METRICS] memory=' . memory_get_usage(true) . ' cpu=0.0' . PHP_EOL;
                    }
                } catch (\Throwable $e) {
                    if ($job->getAttempts() < min($job->getMaxAttempts(), $maxAttempts)) {
                        $job->release($sleep);
                    } else {
                        $job->failed($e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e));
                    }

                    if ($withMonitoring) {
                        $this->error(sprintf(
                            'Job failed (queue=%s, uuid=%s, attempts=%d): %s',
                            $queue,
                            $job->getUuid(),
                            $job->getAttempts(),
                            $e->getMessage()
                        ));
                    }
                }

                if ($maxJobs > 0 && $processedJobs >= $maxJobs) {
                    return self::SUCCESS;
                }
            }

            if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                return self::SUCCESS;
            }

            if ($stopWhenEmpty && !$jobProcessedInCycle) {
                return self::SUCCESS;
            }

            if (!$jobProcessedInCycle) {
                sleep($sleep);
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        return self::SUCCESS;
    }

    public static function isSupervisorParentAlive(?string $pid): bool
    {
        if ($pid === null || $pid === '') {
            return true;
        }

        $pidValue = filter_var($pid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($pidValue === false) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return posix_kill((int) $pidValue, 0);
        }

        return file_exists('/proc/' . $pidValue);
    }

    private function executeSpawn(InputInterface $input): int
    {
        $count = (int) $input->getOption('count');
        $queueOption = $input->getOption('queue');
        $queue = $queueOption !== false && $queueOption !== null ? (string) $queueOption : 'default';

        $this->info("➕ Spawning {$count} worker(s) for queue: {$queue}");

        $workerOptions = $this->createWorkerOptionsFromInput($input);

        for ($i = 0; $i < $count; $i++) {
            try {
                $worker = $this->processManager()->spawn($queue, $workerOptions);
                $this->success("Spawned worker: {$worker->getWorkerId()}");
            } catch (\Exception $e) {
                $this->error("Failed to spawn worker: " . $e->getMessage());
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function executeScale(InputInterface $input): int
    {
        $count = (int) $input->getOption('count');
        $queueOption = $input->getOption('queue');
        $queue = $queueOption !== false && $queueOption !== null ? (string) $queueOption : 'default';

        $currentCount = $this->processManager()->getWorkerCount($queue);
        $this->info("📊 Scaling workers for queue: {$queue}");
        $this->line("Current: {$currentCount} → Target: {$count}");

        $workerOptions = $this->createWorkerOptionsFromInput($input);
        $this->processManager()->scale($count, $queue, $workerOptions);

        $newCount = $this->processManager()->getWorkerCount($queue);
        $this->success("Scaled to {$newCount} worker(s)");

        return self::SUCCESS;
    }

    private function executeStatus(InputInterface $input): int
    {
        $json = (bool) $input->getOption('json');
        $watch = $input->getOption('watch');

        if ($watch !== false && $watch !== null) {
            return $this->watchStatus((int) $watch, $json);
        }

        $status = $this->processManager()->getStatus();

        if ($json === true) {
            $this->displayJson($status);
        } else {
            $this->displayWorkerStatus($status);
        }

        return self::SUCCESS;
    }

    private function executeStop(InputInterface $input): int
    {
        $timeout = 30; // Default timeout
        $all = (bool) $input->getOption('all');
        $workerId = $input->getOption('worker-id');

        if ($all === true) {
            $this->info("🛑 Stopping all workers...");
            $this->processManager()->stopAll($timeout);
            $this->success("All workers stopped");
        } elseif ($workerId !== false && $workerId !== null) {
            $workerId = (string) $workerId;
            $this->info("🛑 Stopping worker: {$workerId}");
            if ($this->processManager()->stop($workerId, $timeout)) {
                $this->success("Worker stopped");
            } else {
                $this->error("Worker not found: {$workerId}");
                return self::FAILURE;
            }
        } else {
            $this->error("Please specify --all or --worker-id=ID");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function executeRestart(InputInterface $input): int
    {
        $all = (bool) $input->getOption('all');
        $workerId = $input->getOption('worker-id');

        if ($all === true) {
            $this->info("🔄 Restarting all workers...");
            $workers = $this->processManager()->getStatus();
            foreach ($workers as $workerInfo) {
                try {
                    $this->processManager()->restart((string) $workerInfo['id']);
                    $this->line("Restarted: {$workerInfo['id']}");
                } catch (\Exception $e) {
                    $this->error("Failed to restart {$workerInfo['id']}: " . $e->getMessage());
                }
            }
            $this->success("All workers restarted");
        } elseif ($workerId !== false && $workerId !== null) {
            $workerId = (string) $workerId;
            $this->info("🔄 Restarting worker: {$workerId}");
            try {
                $this->processManager()->restart($workerId);
                $this->success("Worker restarted");
            } catch (\Exception $e) {
                $this->error("Failed to restart: " . $e->getMessage());
                return self::FAILURE;
            }
        } else {
            $this->error("Please specify --all or --worker-id=ID");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function executeHealth(InputInterface $input): int
    {
        $this->info("🏥 Checking worker health...");

        $this->processManager()->monitorHealth();
        $status = $this->processManager()->getStatus();

        $healthy = 0;
        $unhealthy = 0;

        foreach ($status as $worker) {
            if ($worker['status'] === 'running') {
                $healthy++;
            } else {
                $unhealthy++;
            }
        }

        $this->line("Healthy workers: {$healthy}");
        if ($unhealthy > 0) {
            $this->warning("Unhealthy workers: {$unhealthy} (restarted)");
        }

        return self::SUCCESS;
    }

    private function monitorWorkers(): int
    {
        $this->info("📊 Monitoring workers (Ctrl+C to exit)");
        $this->line();

        $running = true;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$running) {
                $running = false;
            });
            pcntl_signal(SIGTERM, function () use (&$running) {
                $running = false;
            });
        }

        while ($running) {
            $this->clearScreen();
            $status = $this->processManager()->getStatus();
            $this->displayWorkerStatus($status);

            sleep(5);

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        $this->line();
        $this->info("Stopping all workers...");
        $this->processManager()->stopAll();

        return self::SUCCESS;
    }

    private function watchStatus(int $interval, bool $json): int
    {
        $this->info("👁️  Watching worker status (Ctrl+C to exit)");
        $this->line();

        $running = true;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$running) {
                $running = false;
            });
            pcntl_signal(SIGTERM, function () use (&$running) {
                $running = false;
            });
        }

        while ($running) {
            if (!$json) {
                $this->clearScreen();
            }

            $status = $this->processManager()->getStatus();

            if ($json) {
                $this->displayJson($status);
            } else {
                $this->displayWorkerStatus($status);
            }

            sleep($interval);

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $status
     */
    private function displayWorkerStatus(array $status): void
    {
        if (count($status) === 0) {
            $this->warning("No workers running");
            $this->line();
            $this->info("💡 Start workers with: php glueful queue:supervise");
            return;
        }

        $this->info("🔧 Multi-Worker Queue Status:");
        $this->line(str_repeat('-', 100));

        foreach ($status as $worker) {
            $this->line(sprintf(
                "ID: %s | Queue: %s | PID: %s | Status: %s | Memory: %s | Jobs: %d | Runtime: %s",
                substr((string) $worker['id'], 0, 20) . '...',
                (string) $worker['queue'],
                $worker['pid'] ?? 'N/A',
                $this->formatStatus((string) $worker['status']),
                $this->formatBytes((int) $worker['memory_usage']),
                (int) $worker['jobs_processed'],
                $this->formatDuration((float) ($worker['runtime'] ?? 0))
            ));
        }

        $this->line();
        $totalWorkers = count($status);
        $runningWorkers = count(array_filter($status, fn($w) => $w['status'] === 'running'));
        $totalJobs = array_sum(array_column($status, 'jobs_processed'));

        $this->line("Summary: {$runningWorkers}/{$totalWorkers} workers running | {$totalJobs} jobs processed");
    }

    private function createWorkerOptionsFromInput(InputInterface $input): WorkerOptions
    {
        return new WorkerOptions(
            sleep: (int) $input->getOption('sleep'),
            memory: (int) $input->getOption('memory'),
            timeout: (int) $input->getOption('timeout'),
            maxJobs: (int) $input->getOption('max-jobs'),
            stopWhenEmpty: (bool) $input->getOption('stop-when-empty'),
            maxAttempts: (int) $input->getOption('max-attempts'),
            maxRuntime: (int) $input->getOption('max-runtime')
        );
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            'running' => "● Running",
            'stopped' => "● Stopped",
            default => "● {$status}"
        };
    }

    private function handleUnknownAction(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line();
        $this->info("Available actions: work, process, spawn, scale, status, stop, restart, health");
        $this->line();
        $this->info("💡 The supervise command defaults to multi-worker mode.");
        $this->info("   Use 'php glueful queue:supervise' to start with 2 workers by default.");
        return self::FAILURE;
    }

    /**
     * Spawn workers with distributed lock coordination
     *
     * Prevents multiple manager processes from interfering with each other
     * when spawning workers for the same queue.
     */
    private function spawnWorkersWithLock(string $queue, int $workerCount, WorkerOptions $workerOptions): void
    {
        $lockResource = "queue:manager:{$queue}";
        $lockTtl = 60.0; // 1 minute TTL for worker spawning

        $this->lockManager()->executeWithLock($lockResource, function () use ($queue, $workerCount, $workerOptions) {
            // Check if workers are already running for this queue to prevent duplicates
            $currentWorkers = $this->processManager()->getWorkerCount($queue);

            if ($currentWorkers > 0) {
                $this->line("⚠️  Found {$currentWorkers} existing worker(s) for queue '{$queue}'");

                if ($currentWorkers < $workerCount) {
                    $needed = $workerCount - $currentWorkers;
                    $this->line("📈 Scaling up: adding {$needed} worker(s)");
                    $this->processManager()->scale($workerCount, $queue, $workerOptions);
                } else {
                    $this->line("✅ Queue '{$queue}' already has sufficient workers");
                }
            } else {
                $this->line("🚀 Spawning {$workerCount} new worker(s) for queue '{$queue}'");
                $this->processManager()->scale($workerCount, $queue, $workerOptions);
            }
        }, $lockTtl);
    }
}
