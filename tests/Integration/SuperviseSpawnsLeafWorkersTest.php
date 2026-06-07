<?php

declare(strict_types=1);

namespace Glueful\Extensions\QueueOps\Tests\Integration;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Database\Connection;
use Glueful\Extensions\QueueOps\Console\AutoScaleCommand;
use Glueful\Extensions\QueueOps\Console\SuperviseCommand;
use Glueful\Extensions\QueueOps\Process\ProcessFactory;
use Glueful\Extensions\QueueOps\QueueOpsServiceProvider;
use Glueful\Framework;
use Glueful\Queue\QueueManager;
use Glueful\Queue\WorkerOptions;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * WS4 Task 4d: the supervisor surface lives in the extension as `queue:supervise`,
 * spawned leaf workers shell to `queue:supervise process` (G5), and both
 * extension commands are wired via discoverCommands.
 *
 * Three properties under test:
 *  (1) Command registration — both #[AsCommand] classes carry the right names and
 *      boot() runs discoverCommands over the Console directory.
 *  (2) Spawn argv — ProcessFactory::buildWorkerCommand() shells to
 *      `queue:supervise process` (NOT `queue:work process`) with the option flags.
 *  (3) Leaf loop — `queue:supervise process` drains a queued job against a real
 *      SQLite DatabaseQueue and emits the [HEARTBEAT] + [JOB_COMPLETED] IPC lines,
 *      bounded by --stop-when-empty so the loop terminates.
 */
final class SuperviseSpawnsLeafWorkersTest extends TestCase
{
    private string $appPath;
    private string $dbFile;
    private ?Application $app = null;
    private ?ApplicationContext $context = null;

    protected function setUp(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-qops-supervise-' . uniqid('', true);
        $this->dbFile = $this->appPath . '/queue.sqlite';
        mkdir($this->appPath, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // (1) Command registration
    // -----------------------------------------------------------------

    /**
     * Both extension commands carry the expected #[AsCommand] name (reflection).
     */
    public function testBothCommandsCarryExpectedAsCommandNames(): void
    {
        self::assertSame('queue:supervise', $this->asCommandName(SuperviseCommand::class));
        self::assertSame('queue:autoscale', $this->asCommandName(AutoScaleCommand::class));
    }

    /**
     * The REAL provider's boot() discovers and registers both extension commands.
     *
     * Form used (strong): drive the actual QueueOpsServiceProvider::boot() in a
     * console context (PHP_SAPI is 'cli' under phpunit, so runningInConsole() is
     * true) with a container exposing a real `console.application`. discoverCommands
     * scans src/Console, instantiates each #[AsCommand] class via `new $class()`
     * (BaseCommand allows null ctor args), and adds it to the application. We then
     * assert both `queue:supervise` and `queue:autoscale` are registered.
     */
    public function testBootRegistersBothExtensionCommands(): void
    {
        $consoleApp = new \Symfony\Component\Console\Application();
        $container = new Container([
            'console.application' => new ValueDefinition('console.application', $consoleApp),
        ]);

        $provider = new QueueOpsServiceProvider($container);
        $provider->boot(new ApplicationContext($this->appPath, 'testing'));

        self::assertTrue($consoleApp->has('queue:supervise'), 'boot() registers queue:supervise');
        self::assertTrue($consoleApp->has('queue:autoscale'), 'boot() registers queue:autoscale');

        self::assertInstanceOf(SuperviseCommand::class, $consoleApp->find('queue:supervise'));
        self::assertInstanceOf(AutoScaleCommand::class, $consoleApp->find('queue:autoscale'));
    }

    // -----------------------------------------------------------------
    // (2) Spawn argv shells to queue:supervise process
    // -----------------------------------------------------------------

    public function testSpawnArgvShellsToQueueSuperviseProcess(): void
    {
        $factory = new ProcessFactory(new \Psr\Log\NullLogger(), $this->appPath);
        $factory->setPhpBinary('/usr/bin/php');

        $options = new WorkerOptions(
            sleep: 2,
            memory: 256,
            timeout: 90,
            maxJobs: 50,
            stopWhenEmpty: true,
            maxAttempts: 5,
            maxRuntime: 120,
        );

        $argv = $this->buildWorkerCommand($factory, 'reports', $options);
        $line = implode(' ', $argv);

        self::assertStringContainsString('queue:supervise process', $line, 'leaf workers shell to queue:supervise process');
        self::assertStringNotContainsString('queue:work process', $line, 'must NOT shell to the lean core queue:work');

        // Option flags preserved verbatim.
        self::assertContains('--queue=reports', $argv);
        self::assertContains('--sleep=2', $argv);
        self::assertContains('--max-jobs=50', $argv);
        self::assertContains('--max-runtime=120', $argv);
        self::assertContains('--timeout=90', $argv);
        self::assertContains('--memory=256', $argv);
        self::assertContains('--max-attempts=5', $argv);
        self::assertContains('--stop-when-empty', $argv);
        self::assertContains('--with-monitoring', $argv);
        self::assertContains('--emit-heartbeat', $argv);
    }

    // -----------------------------------------------------------------
    // (3) Leaf loop drains + emits IPC
    // -----------------------------------------------------------------

    public function testProcessLeafLoopDrainsJobAndEmitsIpcLines(): void
    {
        $this->bootApp();
        SupervisedLeafJob::$ran = 0;

        $manager = $this->manager();
        $manager->push(SupervisedLeafJob::class, ['n' => 1], 'default');
        self::assertSame(1, $this->queueSize('default'), 'job queued before run');

        $command = new SuperviseCommand($this->app->getContainer(), $this->context);
        $tester = new CommandTester($command);

        // The [HEARTBEAT] / [JOB_COMPLETED] / [METRICS] IPC lines are written with
        // bare `echo` (G5 — straight to the worker's real stdout for the supervisor
        // to parse), so CommandTester doesn't capture them. Wrap in an output buffer.
        // emit-heartbeat fires immediately on the first cycle (lastHeartbeatAt = 0).
        ob_start();
        // Bounded: --stop-when-empty terminates the loop once the queue drains.
        $exit = $tester->execute([
            'action' => 'process',
            '--queue' => 'default',
            '--with-monitoring' => true,
            '--emit-heartbeat' => true,
            '--stop-when-empty' => true,
            '--sleep' => '1',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit, 'process leaf action exits cleanly');
        self::assertSame(1, SupervisedLeafJob::$ran, 'the queued job fired in the leaf loop');
        self::assertSame(0, $this->queueSize('default'), 'queue drained');
        self::assertStringContainsString('[HEARTBEAT]', $output, 'heartbeat IPC line emitted (G5)');
        self::assertStringContainsString('[JOB_COMPLETED]', $output, 'job-completed IPC line emitted (G5)');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function asCommandName(string $class): ?string
    {
        $attrs = (new \ReflectionClass($class))->getAttributes(AsCommand::class);
        if ($attrs === []) {
            return null;
        }
        /** @var AsCommand $instance */
        $instance = $attrs[0]->newInstance();
        return $instance->name;
    }

    /**
     * Call the private buildWorkerCommand() via reflection (mirrors how the
     * original supervisor's spawn path exercised it).
     *
     * @return array<int, string>
     */
    private function buildWorkerCommand(ProcessFactory $factory, string $queue, WorkerOptions $options): array
    {
        $method = new \ReflectionMethod(ProcessFactory::class, 'buildWorkerCommand');
        $method->setAccessible(true);
        /** @var array<int, string> $argv */
        $argv = $method->invoke($factory, $queue, $options);
        return $argv;
    }

    private function bootApp(): void
    {
        RouteManifest::reset();

        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);

        file_put_contents(
            $cfg . '/app.php',
            "<?php\nreturn ['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true];\n"
        );
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->dbFile . "'], "
            . "'pooling' => ['enabled' => false]];\n"
        );
        file_put_contents(
            $cfg . '/cache.php',
            "<?php\nreturn ['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];\n"
        );
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");
        file_put_contents(
            $cfg . '/queue.php',
            "<?php\nreturn ['default' => 'database', 'connections' => ['database' => ['driver' => 'database', "
            . "'table' => 'queue_jobs', 'failed_table' => 'queue_failed_jobs', 'retry_after' => 90]]];\n"
        );

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContext();

        $this->createQueueSchema();
    }

    private function manager(): QueueManager
    {
        /** @var QueueManager $manager */
        $manager = $this->app->getContainer()->get(QueueManager::class);
        return $manager;
    }

    private function createQueueSchema(): void
    {
        $connection = Connection::fromContext($this->context);
        $schema = $connection->getSchemaBuilder();

        require_once dirname(__DIR__, 2)
            . '/vendor/glueful/framework/migrations/queue/001_CreateQueueSystemTables.php';
        $migration = new \Glueful\Migrations\Queue\CreateQueueSystemTables();
        $migration->up($schema);
    }

    private function queueSize(string $queue): int
    {
        $connection = Connection::fromContext($this->context);
        return (int) $connection->table('queue_jobs')->where('queue', $queue)->count();
    }

    private function recursiveRemoveDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}

/**
 * Job recording its executions, used to prove the leaf loop fired it.
 */
final class SupervisedLeafJob
{
    public static int $ran = 0;

    /** @param array<string,mixed> $data */
    public function handle(array $data): void
    {
        self::$ran++;
    }
}
