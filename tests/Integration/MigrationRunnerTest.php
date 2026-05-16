<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Integration;

use AlfaCode\LetMigrate\DatabaseMigrationRepository;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteDriver;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use AlfaCode\LetMigrate\Event\MigrationFailed;
use AlfaCode\LetMigrate\Event\MigrationFinished;
use AlfaCode\LetMigrate\Event\MigrationsCompleted;
use AlfaCode\LetMigrate\Event\MigrationStarted;
use AlfaCode\LetMigrate\Exception\MigrationException;
use AlfaCode\LetMigrate\FilesystemMigrationResolver;
use AlfaCode\LetMigrate\LetMigrate;
use AlfaCode\LetMigrate\MigrationConfig;
use AlfaCode\LetMigrate\MigrationRunner;
use AlfaCode\LetMigrate\MigrationService;
use AlfaCode\LetMigrate\MigrationServiceFactory;
use AlfaCode\LetMigrate\DriverRegistry;
use AlfaCode\LetMigrate\Schema\SchemaBuilder;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    private SQLiteDriver   $driver;

    private SQLiteGrammar  $grammar;

    private SchemaBuilder  $schema;

    private DatabaseMigrationRepository $repository;

    private string         $migrationsDir;

    protected function setUp(): void
    {
        $this->driver = new SQLiteDriver(':memory:');
        $this->grammar = new SQLiteGrammar();
        $this->schema = new SchemaBuilder($this->driver, $this->grammar);
        $this->repository = new DatabaseMigrationRepository($this->driver, $this->grammar);
        $this->migrationsDir = sys_get_temp_dir() . '/let_migrate_test_' . uniqid('', true);
        mkdir($this->migrationsDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationsDir . '/*.php') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->migrationsDir);
    }

    // ── run ───────────────────────────────────────────────────────

    public function test_run_applies_pending_migrations(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');

        $result = $this->makeRunner()->run();

        $this->assertSame(1, $result->appliedCount());
        $this->assertContains('2024_01_01_000001_create_alpha', $result->applied);
        $this->assertTrue($this->driver->tableExists('alpha'));
    }

    public function test_run_applies_multiple_in_order(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');
        $this->writeMigration('2024_01_01_000002_create_beta', 'beta');
        $this->writeMigration('2024_01_01_000003_create_gamma', 'gamma');

        $result = $this->makeRunner()->run();

        $this->assertSame(3, $result->appliedCount());
        $this->assertSame(1, $result->batch);
    }

    public function test_run_skips_already_applied(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');
        $runner = $this->makeRunner();

        $first = $runner->run();
        $second = $runner->run();

        $this->assertSame(1, $first->appliedCount());
        $this->assertTrue($second->isEmpty());
    }

    public function test_run_groups_in_same_batch(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');
        $this->writeMigration('2024_01_01_000002_create_beta', 'beta');

        $this->makeRunner()->run();

        $batches = array_unique(
            array_map(static fn($r) => $r->batch, $this->repository->all()),
        );
        $this->assertCount(1, $batches);
    }

    public function test_run_increments_batch_on_subsequent_run(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');
        $runner = $this->makeRunner();
        $runner->run();

        $this->writeMigration('2024_01_01_000002_create_beta', 'beta');
        $runner->run();

        $batches = array_unique(
            array_map(static fn($r) => $r->batch, $this->repository->all()),
        );
        sort($batches);
        $this->assertSame([1, 2], $batches);
    }

    public function test_run_returns_empty_when_nothing_pending(): void
    {
        $result = $this->makeRunner()->run();

        $this->assertTrue($result->isEmpty());
    }

    // ── rollback ─────────────────────────────────────────────────

    public function test_rollback_reverses_last_batch(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');
        $runner = $this->makeRunner();
        $runner->run();

        $result = $runner->rollback();

        $this->assertSame(1, $result->rolledBackCount());
        $this->assertFalse($this->driver->tableExists('alpha'));
    }

    public function test_rollback_only_reverses_last_batch_by_default(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');
        $runner = $this->makeRunner();
        $runner->run();

        $this->writeMigration('2024_01_01_000002_create_beta', 'beta');
        $runner->run();

        $runner->rollback(steps: 1);

        $this->assertTrue($this->driver->tableExists('alpha'));
        $this->assertFalse($this->driver->tableExists('beta'));
    }

    public function test_rollback_returns_empty_when_nothing_applied(): void
    {
        $result = $this->makeRunner()->rollback();

        $this->assertTrue($result->isEmpty());
    }

    // ── reset & refresh ───────────────────────────────────────────

    public function test_reset_rolls_back_all_applied(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');
        $this->writeMigration('2024_01_01_000002_create_beta', 'beta');
        $runner = $this->makeRunner();
        $runner->run();

        $runner->reset();

        $this->assertFalse($this->driver->tableExists('alpha'));
        $this->assertFalse($this->driver->tableExists('beta'));
        $this->assertEmpty($this->repository->all());
    }

    public function test_refresh_resets_and_reruns(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');
        $runner = $this->makeRunner();
        $runner->run();

        $result = $runner->refresh();

        $this->assertTrue($this->driver->tableExists('alpha'));
        $this->assertSame(1, $result->appliedCount());
    }

    // ── status ───────────────────────────────────────────────────

    public function test_status_shows_pending_before_run(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');

        $status = $this->makeRunner()->status();

        $this->assertSame('pending', $status['2024_01_01_000001_create_alpha']['status']);
        $this->assertNull($status['2024_01_01_000001_create_alpha']['batch']);
    }

    public function test_status_shows_applied_after_run(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');
        $runner = $this->makeRunner();
        $runner->run();

        $status = $runner->status();

        $this->assertSame('applied', $status['2024_01_01_000001_create_alpha']['status']);
        $this->assertSame(1, $status['2024_01_01_000001_create_alpha']['batch']);
    }

    // ── events ───────────────────────────────────────────────────

    public function test_run_dispatches_started_and_finished_events(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');

        $log = [];
        $events = new MigrationEventDispatcher();
        $events->on(MigrationStarted::class, static function ($e) use (&$log): void {
            $log[] = "started:{$e->migration}";
        });
        $events->on(MigrationFinished::class, static function ($e) use (&$log): void {
            $log[] = "finished:{$e->migration}";
        });

        $this->makeRunner($events)->run();

        $this->assertContains('started:2024_01_01_000001_create_alpha', $log);
        $this->assertContains('finished:2024_01_01_000001_create_alpha', $log);
    }

    public function test_run_dispatches_completed_event(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');

        $completed = false;
        $events = new MigrationEventDispatcher();
        $events->on(MigrationsCompleted::class, static function () use (&$completed): void {
            $completed = true;
        });

        $this->makeRunner($events)->run();

        $this->assertTrue($completed);
    }

    public function test_failed_migration_dispatches_failed_event(): void
    {
        file_put_contents("{$this->migrationsDir}/2024_01_01_000001_bad_migration.php", <<<'PHP'
            <?php
            use AlfaCode\LetMigrate\Contract\MigrationInterface;
            use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
            return new class implements MigrationInterface {
                public function up(SchemaBuilderInterface $schema): void {
                    throw new \RuntimeException('Intentional failure');
                }
                public function down(SchemaBuilderInterface $schema): void {}
            };
            PHP);

        $failedEvents = [];
        $events = new MigrationEventDispatcher();
        $events->on(MigrationFailed::class, static function ($e) use (&$failedEvents): void {
            $failedEvents[] = $e->migration;
        });

        try {
            $this->makeRunner($events)->run();
        } catch (MigrationException) {
            // expected
        }

        $this->assertContains('2024_01_01_000001_bad_migration', $failedEvents);
    }

    // ── Service layer / LetMigrate facade ─────────────────────────

    public function test_service_runs_migrations_via_factory(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');

        $service = MigrationServiceFactory::create(
            DriverRegistry::fromDriverAndGrammar("sqlite", $this->driver, $this->grammar),
            MigrationConfig::fromArray(['paths' => [$this->migrationsDir]]),
        );

        $result = $service->run();

        $this->assertSame(1, $result->appliedCount());
    }

    public function test_let_migrate_facade_runs_via_from_registry(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');

        $engine = LetMigrate::fromRegistry(
            DriverRegistry::fromDriverAndGrammar("sqlite", $this->driver, $this->grammar),
            ['paths' => [$this->migrationsDir]],
        );

        $result = $engine->run();

        $this->assertSame(1, $result->appliedCount());
    }

    public function test_let_migrate_facade_pending_list(): void
    {
        $this->writeMigration('2024_01_01_000001_create_alpha', 'alpha');
        $this->writeMigration('2024_01_01_000002_create_beta', 'beta');

        $engine = LetMigrate::fromRegistry(
            DriverRegistry::fromDriverAndGrammar("sqlite", $this->driver, $this->grammar),
            ['paths' => [$this->migrationsDir]],
        );

        $engine->run();

        $this->writeMigration('2024_01_01_000003_create_gamma', 'gamma');

        $engine2 = LetMigrate::fromRegistry(
            DriverRegistry::fromDriverAndGrammar("sqlite", $this->driver, $this->grammar),
            ['paths' => [$this->migrationsDir]],
        );

        $pending = $engine2->pending();

        $this->assertArrayHasKey('2024_01_01_000003_create_gamma', $pending);
    }

    // ── Service boundary — repository not reachable from facade ───

    public function test_let_migrate_facade_does_not_expose_repository(): void
    {
        $engine = LetMigrate::fromRegistry(
            DriverRegistry::fromDriverAndGrammar("sqlite", $this->driver, $this->grammar),
            ['paths' => [$this->migrationsDir]],
        );

        $ref = new \ReflectionObject($engine);
        $methods = array_map(
            static fn(\ReflectionMethod $m) => $m->getName(),
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $this->assertNotContains('repository', $methods);
        $this->assertNotContains('getRepository', $methods);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function writeMigration(string $filename, string $table): void
    {
        file_put_contents("{$this->migrationsDir}/{$filename}.php", <<<PHP
            <?php
            use AlfaCode\\LetMigrate\\Contract\\MigrationInterface;
            use AlfaCode\\LetMigrate\\Contract\\SchemaBuilderInterface;
            use AlfaCode\\LetMigrate\\Schema\\Blueprint;
            return new class implements MigrationInterface {
                public function up(SchemaBuilderInterface \$schema): void {
                    \$schema->create('{$table}', static function (Blueprint \$t): void {
                        \$t->id();
                        \$t->string('name');
                    });
                }
                public function down(SchemaBuilderInterface \$schema): void {
                    \$schema->dropIfExists('{$table}');
                }
            };
            PHP);
    }

    private function makeRunner(MigrationEventDispatcher|null $events = null): MigrationRunner
    {
        $resolver = new FilesystemMigrationResolver([$this->migrationsDir]);

        return new MigrationRunner(
            repository: $this->repository,
            resolver: $resolver,
            schema: $this->schema,
            events: $events ?? new MigrationEventDispatcher(),
        );
    }

    private function makeService(MigrationEventDispatcher|null $events = null): MigrationService
    {
        $resolver = new FilesystemMigrationResolver([$this->migrationsDir]);

        return new MigrationService(
            repository: $this->repository,
            resolver: $resolver,
            schema: $this->schema,
            events: $events ?? new MigrationEventDispatcher(),
        );
    }
}
