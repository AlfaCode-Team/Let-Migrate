<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Integration;

use AlfaCode\LetMigrate\Contract\MigrationServiceInterface;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteDriver;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\DriverRegistry;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use AlfaCode\LetMigrate\Event\MigrationStarted;
use AlfaCode\LetMigrate\LetMigrate;
use AlfaCode\LetMigrate\MigrationConfig;
use AlfaCode\LetMigrate\MigrationServiceFactory;
use PHPUnit\Framework\TestCase;

/**
 * Full end-to-end integration test that exercises the complete five-migration
 * vote-system schema against an in-memory SQLite database.
 *
 * Tests the entire stack:
 *   LetMigrate facade → MigrationServiceFactory → MigrationService
 *     → MigrationRunner → FilesystemMigrationResolver
 *     → DatabaseMigrationRepository → SQLiteDriver
 *
 * No mocks — real driver, real grammar, real schema builder.
 */
final class FullMigrationSuiteTest extends TestCase
{
    private SQLiteDriver $driver;

    private DriverRegistry $registry;

    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->driver = new SQLiteDriver(':memory:');
        $this->registry = DriverRegistry::fromDriverAndGrammar($this->driver, new SQLiteGrammar());
        $this->migrationsDir = sys_get_temp_dir() . '/let_migrate_suite_' . uniqid('', true);
        mkdir($this->migrationsDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationsDir . '/*.php') ?: [] as $f) {
            unlink($f);
        }

        rmdir($this->migrationsDir);
    }

    // ── Full run ──────────────────────────────────────────────────

    public function test_run_applies_all_five_migrations(): void
    {
        $this->writeAllMigrations();

        $result = $this->makeEngine()->run();

        $this->assertSame(5, $result->appliedCount());
        $this->assertSame(1, $result->batch);
    }

    public function test_all_tables_exist_after_full_run(): void
    {
        $this->writeAllMigrations();
        $this->makeEngine()->run();

        foreach ([
            'vote_editions',
            'vote_contestants',
            'vote_voting',
            'vote_payments',
            'vote_audit_log',
            'vote_user_subscriptions',
        ] as $table) {
            $this->assertTrue(
                $this->driver->tableExists($table),
                "Expected table '{$table}' to exist after migration",
            );
        }
    }

    public function test_tracking_table_records_all_migrations(): void
    {
        $this->writeAllMigrations();
        $engine = $this->makeEngine();
        $engine->run();

        $status = $engine->status();

        $this->assertCount(5, $status);

        foreach ($status as $filename => $entry) {
            $this->assertSame('applied', $entry['status'], "{$filename} should be applied");
            $this->assertSame(1, $entry['batch'], "{$filename} should be in batch 1");
        }
    }

    // ── Pending detection ─────────────────────────────────────────

    public function test_pending_returns_nothing_after_full_run(): void
    {
        $this->writeAllMigrations();
        $engine = $this->makeEngine();
        $engine->run();

        $engine2 = $this->makeEngine();
        $this->assertEmpty($engine2->pending());
    }

    public function test_pending_shows_only_new_migration_after_partial_run(): void
    {
        $this->writeEditionsMigration();
        $this->writeContestantsMigration();

        $engine = $this->makeEngine();
        $engine->run();

        // Add a third migration after the first run
        $this->writeVotingMigration();

        $engine2 = $this->makeEngine();
        $pending = $engine2->pending();

        $this->assertCount(1, $pending);
        $this->assertArrayHasKey('2024_01_01_000003_create_vote_voting', $pending);
    }

    // ── Rollback ─────────────────────────────────────────────────

    public function test_rollback_removes_last_batch_tables(): void
    {
        $this->writeAllMigrations();
        $engine = $this->makeEngine();
        $engine->run();

        $engine->rollback();

        // Migration 5 (audit + subscriptions) should be gone
        $this->assertFalse($this->driver->tableExists('vote_audit_log'));
        $this->assertFalse($this->driver->tableExists('vote_user_subscriptions'));

        // Migrations 1–4 should remain
        $this->assertTrue($this->driver->tableExists('vote_editions'));
        $this->assertTrue($this->driver->tableExists('vote_contestants'));
        $this->assertTrue($this->driver->tableExists('vote_voting'));
        $this->assertTrue($this->driver->tableExists('vote_payments'));
    }

    public function test_rollback_decrements_tracking_table(): void
    {
        $this->writeAllMigrations();
        $engine = $this->makeEngine();
        $engine->run();
        $engine->rollback();

        $status = $engine->status();
        $applied = array_filter($status, static fn($s) => $s['status'] === 'applied');

        $this->assertCount(4, $applied);
    }

    // ── Reset ─────────────────────────────────────────────────────

    public function test_reset_removes_all_tables(): void
    {
        $this->writeAllMigrations();
        $engine = $this->makeEngine();
        $engine->run();
        $engine->reset();

        foreach ([
            'vote_editions',
            'vote_contestants',
            'vote_voting',
            'vote_payments',
            'vote_audit_log',
            'vote_user_subscriptions',
        ] as $table) {
            $this->assertFalse(
                $this->driver->tableExists($table),
                "Expected table '{$table}' to be gone after reset",
            );
        }
    }

    public function test_reset_clears_tracking_table(): void
    {
        $this->writeAllMigrations();
        $engine = $this->makeEngine();
        $engine->run();

        $result = $engine->reset();

        $this->assertSame(5, $result->rolledBackCount());
        $this->assertTrue($engine->status() === [] || array_filter($engine->status(), static fn($s) => $s['status'] === 'applied') === []);
    }

    // ── Refresh ───────────────────────────────────────────────────

    public function test_refresh_recreates_all_tables(): void
    {
        $this->writeAllMigrations();
        $engine = $this->makeEngine();
        $engine->run();
        $result = $engine->refresh();

        $this->assertSame(5, $result->appliedCount());

        foreach ([
            'vote_editions',
            'vote_contestants',
            'vote_voting',
            'vote_payments',
            'vote_audit_log',
            'vote_user_subscriptions',
        ] as $table) {
            $this->assertTrue($this->driver->tableExists($table));
        }
    }

    // ── Data insertion after migrations ───────────────────────────

    public function test_can_insert_edition_after_migration(): void
    {
        $this->writeEditionsMigration();
        $this->makeEngine()->run();

        $id = $this->driver->insert('vote_editions', [
            'Title' => 'Season 1',
            'StartDate' => '2024-01-01 00:00:00',
            'EndDate' => '2024-06-30 23:59:59',
            'Status' => 'active',
        ]);

        $this->assertSame(1, $id);

        $row = $this->driver->fetchOne('SELECT "Title" FROM "vote_editions" WHERE "id" = 1');
        $this->assertSame('Season 1', $row['Title']);
    }

    public function test_can_insert_contestant_with_fk_after_migration(): void
    {
        $this->writeEditionsMigration();
        $this->writeContestantsMigration();
        $this->makeEngine()->run();

        $editionId = $this->driver->insert('vote_editions', [
            'Title' => 'Season 1',
            'StartDate' => '2024-01-01 00:00:00',
            'EndDate' => '2024-06-30 23:59:59',
        ]);

        $contestantId = $this->driver->insert('vote_contestants', [
            'EditionID' => $editionId,
            'FullName' => 'Alice Mwangi',
        ]);

        $this->assertSame(1, $contestantId);

        $row = $this->driver->fetchOne(
            'SELECT "FullName" FROM "vote_contestants" WHERE "id" = 1',
        );
        $this->assertSame('Alice Mwangi', $row['FullName']);
    }

    // ── Multi-batch incremental runs ──────────────────────────────

    public function test_incremental_runs_use_ascending_batch_numbers(): void
    {
        $this->writeEditionsMigration();
        $engine = $this->makeEngine();
        $r1 = $engine->run();

        $this->writeContestantsMigration();
        $engine2 = $this->makeEngine();
        $r2 = $engine2->run();

        $this->writeVotingMigration();
        $engine3 = $this->makeEngine();
        $r3 = $engine3->run();

        $this->assertSame(1, $r1->batch);
        $this->assertSame(2, $r2->batch);
        $this->assertSame(3, $r3->batch);
    }

    // ── Service boundary from facade ─────────────────────────────

    public function test_engine_service_returns_migration_service_interface(): void
    {
        $engine = $this->makeEngine();

        $this->assertInstanceOf(MigrationServiceInterface::class, $engine->service());
    }

    public function test_engine_does_not_expose_repository(): void
    {
        $engine = $this->makeEngine();
        $ref = new \ReflectionObject($engine);
        $methods = array_map(
            static fn(\ReflectionMethod $m) => $m->getName(),
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $this->assertNotContains('repository', $methods);
        $this->assertNotContains('getRepository', $methods);
    }

    // ── Events during full run ────────────────────────────────────

    public function test_full_run_fires_started_event_for_each_migration(): void
    {
        $this->writeAllMigrations();

        $started = [];
        $events = new MigrationEventDispatcher();
        $events->on(MigrationStarted::class, static function (MigrationStarted $e) use (&$started): void {
            $started[] = $e->migration;
        });

        $this->makeService($events)->run();

        $this->assertCount(5, $started);
        $this->assertContains('2024_01_01_000001_create_vote_editions', $started);
        $this->assertContains('2024_01_01_000002_create_vote_contestants', $started);
        $this->assertContains('2024_01_01_000003_create_vote_voting', $started);
        $this->assertContains('2024_01_01_000004_create_vote_payments', $started);
        $this->assertContains('2024_01_01_000005_create_audit_and_subscriptions', $started);
    }

    // ── Idempotency of second run ─────────────────────────────────

    public function test_second_run_is_idempotent_and_returns_empty(): void
    {
        $this->writeAllMigrations();
        $engine = $this->makeEngine();
        $engine->run();

        $second = $this->makeEngine()->run();

        $this->assertTrue($second->isEmpty());
        $this->assertSame('Nothing to migrate.', $second->summary());
    }

    // ── Pretend mode ──────────────────────────────────────────────

    public function test_pretend_mode_does_not_create_tables(): void
    {
        $this->writeAllMigrations();

        $service = MigrationServiceFactory::create(
            $this->registry,
            MigrationConfig::fromArray([
                'paths' => [$this->migrationsDir],
                'pretend' => true,
            ]),
        );

        $service->run();

        $this->assertFalse($this->driver->tableExists('vote_editions'));
        $this->assertFalse($this->driver->tableExists('vote_contestants'));
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function writeEditionsMigration(): void
    {
        file_put_contents("{$this->migrationsDir}/2024_01_01_000001_create_vote_editions.php", <<<'PHP'
            <?php
            use AlfaCode\LetMigrate\Contract\MigrationInterface;
            use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
            use AlfaCode\LetMigrate\Schema\Blueprint;
            return new class implements MigrationInterface {
                public function up(SchemaBuilderInterface $schema): void {
                    $schema->create('vote_editions', static function (Blueprint $t): void {
                        $t->id();
                        $t->string('Title');
                        $t->text('Description')->nullable();
                        $t->dateTime('StartDate');
                        $t->dateTime('EndDate');
                        $t->string('Status')->default('draft');
                        $t->timestamps();
                        $t->index(['Status'], 'idx_status');
                    });
                }
                public function down(SchemaBuilderInterface $schema): void {
                    $schema->dropIfExists('vote_editions');
                }
            };
            PHP);
    }

    private function writeContestantsMigration(): void
    {
        file_put_contents("{$this->migrationsDir}/2024_01_01_000002_create_vote_contestants.php", <<<'PHP'
            <?php
            use AlfaCode\LetMigrate\Contract\MigrationInterface;
            use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
            use AlfaCode\LetMigrate\Schema\Blueprint;
            return new class implements MigrationInterface {
                public function up(SchemaBuilderInterface $schema): void {
                    $schema->create('vote_contestants', static function (Blueprint $t): void {
                        $t->id();
                        $t->bigInteger('EditionID')->notNull();
                        $t->string('FullName');
                        $t->string('StageName')->nullable();
                        $t->bigInteger('Votes')->default(0);
                        $t->string('Status')->default('active');
                        $t->timestamps();
                        $t->foreign('EditionID')->references('ID')->on('vote_editions')->cascadeOnDelete()->name('fk_contestant_edition');
                    });
                }
                public function down(SchemaBuilderInterface $schema): void {
                    $schema->dropIfExists('vote_contestants');
                }
            };
            PHP);
    }

    private function writeVotingMigration(): void
    {
        file_put_contents("{$this->migrationsDir}/2024_01_01_000003_create_vote_voting.php", <<<'PHP'
            <?php
            use AlfaCode\LetMigrate\Contract\MigrationInterface;
            use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
            use AlfaCode\LetMigrate\Schema\Blueprint;
            return new class implements MigrationInterface {
                public function up(SchemaBuilderInterface $schema): void {
                    $schema->create('vote_voting', static function (Blueprint $t): void {
                        $t->id('VoteID');
                        $t->bigInteger('UserID')->notNull();
                        $t->bigInteger('ContestantID')->notNull();
                        $t->bigInteger('EditionID')->notNull();
                        $t->integer('VoteCount')->default(1);
                        $t->boolean('IsPaid')->default(false);
                        $t->string('Status')->default('approved');
                        $t->string('IPAddress', 45)->notNull();
                        $t->string('UserAgent')->default('');
                        $t->dateTime('CreatedAt')->default('CURRENT_TIMESTAMP');
                        $t->foreign('ContestantID')->references('ID')->on('vote_contestants')->cascadeOnDelete()->name('fk_vote_contestant');
                        $t->foreign('EditionID')->references('ID')->on('vote_editions')->cascadeOnDelete()->name('fk_vote_edition');
                    });
                }
                public function down(SchemaBuilderInterface $schema): void {
                    $schema->dropIfExists('vote_voting');
                }
            };
            PHP);
    }

    private function writePaymentsMigration(): void
    {
        file_put_contents("{$this->migrationsDir}/2024_01_01_000004_create_vote_payments.php", <<<'PHP'
            <?php
            use AlfaCode\LetMigrate\Contract\MigrationInterface;
            use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
            use AlfaCode\LetMigrate\Schema\Blueprint;
            return new class implements MigrationInterface {
                public function up(SchemaBuilderInterface $schema): void {
                    $schema->create('vote_payments', static function (Blueprint $t): void {
                        $t->id();
                        $t->bigInteger('UserID')->notNull();
                        $t->bigInteger('ContestantID')->notNull();
                        $t->bigInteger('EditionID')->notNull();
                        $t->integer('VoteCount')->notNull();
                        $t->bigInteger('AmountKobo')->notNull();
                        $t->string('Reference', 64)->notNull();
                        $t->string('Status')->default('pending');
                        $t->text('GatewayResponse')->nullable();
                        $t->timestamps();
                        $t->foreign('ContestantID')->references('ID')->on('vote_contestants')->cascadeOnDelete()->name('fk_payment_contestant');
                        $t->foreign('EditionID')->references('ID')->on('vote_editions')->cascadeOnDelete()->name('fk_payment_edition');
                    });
                }
                public function down(SchemaBuilderInterface $schema): void {
                    $schema->dropIfExists('vote_payments');
                }
            };
            PHP);
    }

    private function writeAuditMigration(): void
    {
        file_put_contents("{$this->migrationsDir}/2024_01_01_000005_create_audit_and_subscriptions.php", <<<'PHP'
            <?php
            use AlfaCode\LetMigrate\Contract\MigrationInterface;
            use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
            use AlfaCode\LetMigrate\Schema\Blueprint;
            return new class implements MigrationInterface {
                public function up(SchemaBuilderInterface $schema): void {
                    $schema->create('vote_audit_log', static function (Blueprint $t): void {
                        $t->id('LogID');
                        $t->string('Event', 100)->notNull();
                        $t->bigInteger('UserID')->nullable();
                        $t->string('IPAddress', 45)->notNull();
                        $t->text('Payload')->nullable();
                        $t->dateTime('CreatedAt')->default('CURRENT_TIMESTAMP');
                    });
                    $schema->create('vote_user_subscriptions', static function (Blueprint $t): void {
                        $t->id();
                        $t->bigInteger('UserID')->notNull();
                        $t->bigInteger('EditionID')->notNull();
                        $t->string('PlanName', 100)->default('basic');
                        $t->integer('DailyLimit')->default(1);
                        $t->dateTime('ValidFrom')->notNull();
                        $t->dateTime('ValidTo')->notNull();
                        $t->timestamps();
                        $t->foreign('EditionID')->references('ID')->on('vote_editions')->cascadeOnDelete()->name('fk_sub_edition');
                    });
                }
                public function down(SchemaBuilderInterface $schema): void {
                    $schema->dropIfExists('vote_user_subscriptions');
                    $schema->dropIfExists('vote_audit_log');
                }
            };
            PHP);
    }

    private function writeAllMigrations(): void
    {
        $this->writeEditionsMigration();
        $this->writeContestantsMigration();
        $this->writeVotingMigration();
        $this->writePaymentsMigration();
        $this->writeAuditMigration();
    }

    private function makeEngine(): LetMigrate
    {
        return LetMigrate::fromRegistry(
            $this->registry,
            ['paths' => [$this->migrationsDir]],
        );
    }

    private function makeService(MigrationEventDispatcher|null $events = null): MigrationServiceInterface
    {
        return MigrationServiceFactory::create(
            $this->registry,
            MigrationConfig::fromArray(['paths' => [$this->migrationsDir]]),
            events: $events,
        );
    }
}
