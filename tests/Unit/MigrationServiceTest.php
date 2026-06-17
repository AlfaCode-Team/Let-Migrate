<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\MigrationRepositoryInterface;
use AlfaCode\LetMigrate\Contract\MigrationResolverInterface;
use AlfaCode\LetMigrate\Contract\MigrationServiceInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use AlfaCode\LetMigrate\Event\MigrationFinished;
use AlfaCode\LetMigrate\Event\MigrationStarted;
use AlfaCode\LetMigrate\MigrationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the MigrationService service-layer boundary:
 *
 *  1. MigrationService implements MigrationServiceInterface (contract compliance)
 *  2. The repository reference is private — no external accessor
 *  3. Delegates run / rollback / status / pending to the runner correctly
 *  4. Events fired by the service-owned dispatcher are observable
 */
final class MigrationServiceTest extends TestCase
{
    // ── Architectural boundary ────────────────────────────────────

    public function test_service_implements_interface(): void
    {
        $service = $this->makeService();

        $this->assertInstanceOf(MigrationServiceInterface::class, $service);
    }

    public function test_repository_property_is_private(): void
    {
        $ref = new \ReflectionClass(MigrationService::class);
        $prop = $ref->getProperty('repository');

        $this->assertTrue($prop->isPrivate(), 'repository must be private — architectural boundary');
    }

    public function test_repository_has_no_public_getter(): void
    {
        $ref = new \ReflectionClass(MigrationService::class);
        $methods = array_map(static fn($m) => $m->getName(), $ref->getMethods(\ReflectionMethod::IS_PUBLIC));

        $this->assertNotContains('repository', $methods, 'No public repository() accessor may exist on MigrationService');
        $this->assertNotContains('getRepository', $methods);
    }

    // ── run() ─────────────────────────────────────────────────────

    public function test_run_returns_empty_when_no_pending(): void
    {
        $service = $this->makeService(pending: []);

        $result = $service->run();

        $this->assertTrue($result->isEmpty());
    }

    public function test_run_returns_applied_result(): void
    {
        $migration = $this->makeMigration();
        $service = $this->makeService(pending: ['2024_01_run_test' => $migration]);

        $result = $service->run();

        $this->assertSame(1, $result->appliedCount());
        $this->assertContains('2024_01_run_test', $result->applied);
    }

    // ── rollback() ────────────────────────────────────────────────

    public function test_rollback_returns_empty_when_nothing_applied(): void
    {
        $service = $this->makeService(applied: []);

        $result = $service->rollback();

        $this->assertTrue($result->isEmpty());
    }

    // ── status() ─────────────────────────────────────────────────

    public function test_status_returns_pending_for_unapplied_migration(): void
    {
        $migration = $this->makeMigration();
        $service = $this->makeService(pending: ['2024_status_test' => $migration]);

        $status = $service->status();

        $this->assertArrayHasKey('2024_status_test', $status);
        $this->assertSame('pending', $status['2024_status_test']['status']);
    }

    // ── pending() ────────────────────────────────────────────────

    public function test_pending_returns_unapplied_migrations(): void
    {
        $migration = $this->makeMigration();
        $service = $this->makeService(pending: ['2024_pending_test' => $migration]);

        $pending = $service->pending();

        $this->assertArrayHasKey('2024_pending_test', $pending);
    }

    // ── events() ─────────────────────────────────────────────────

    public function test_events_returns_dispatcher_instance(): void
    {
        $service = $this->makeService();

        $this->assertInstanceOf(MigrationEventDispatcher::class, $service->events());
    }

    public function test_run_dispatches_started_and_finished_events(): void
    {
        $log = [];
        $events = new MigrationEventDispatcher();
        $events->on(MigrationStarted::class, static function ($e) use (&$log): void {
            $log[] = "started:{$e->migration}";
        });
        $events->on(MigrationFinished::class, static function ($e) use (&$log): void {
            $log[] = "finished:{$e->migration}";
        });

        $migration = $this->makeMigration();
        $service = $this->makeService(
            pending: ['2024_event_test' => $migration],
            events: $events,
        );

        $service->run();

        $this->assertContains('started:2024_event_test', $log);
        $this->assertContains('finished:2024_event_test', $log);
    }

    public function test_events_dispatcher_is_same_instance_as_injected(): void
    {
        $events = new MigrationEventDispatcher();
        $service = $this->makeService(events: $events);

        $this->assertSame($events, $service->events());
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * @param array<string, MigrationInterface> $pending
     * @param string[]                          $applied
     */
    private function makeService(
        array $pending = [],
        array $applied = [],
        MigrationEventDispatcher|null $events = null,
    ): MigrationService {
        $repository = $this->createStub(MigrationRepositoryInterface::class);
        $repository->method('ensureTable')->willReturnCallback(fn() => null);
        $repository->method('lastBatch')->willReturn(0);
        $repository->method('appliedFilenames')->willReturn($applied);
        $repository->method('all')->willReturn([]);

        $resolver = $this->createStub(MigrationResolverInterface::class);
        $resolver->method('resolve')->willReturn($pending);

        $schema = $this->createStub(SchemaBuilderInterface::class);
        $driver = $this->createStub(\AlfaCode\LetMigrate\Contract\DatabaseDriverInterface::class);
        $driver->method('beginTransaction')->willReturnCallback(fn() => null);
        $driver->method('commit')->willReturnCallback(fn() => null);
        $schema->method('getDriver')->willReturn($driver);

        $events ??= new MigrationEventDispatcher();

        $runner = new \AlfaCode\LetMigrate\MigrationRunner(
            repository: $repository,
            resolver: $resolver,
            schema: $schema,
            events: $events,
            transactional: true,
        );

        // NOTE: MigrationService::captureSql() needs a concrete SchemaBuilder
        // (getGrammar/getInspector). Tests that don't call captureSql() can
        // pass a real SchemaBuilder over an in-memory SQLite driver, or skip
        // captureSql coverage here and cover it in the integration suite.
        $schemaBuilder = new \AlfaCode\LetMigrate\Schema\SchemaBuilder(
            $driver,
            new \AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar(),
        );

        return new MigrationService(
            runner: $runner,
            repository: $repository,
            resolver: $resolver,
            schemaBuilder: $schemaBuilder,
            dispatcher: $events,
            paths: [],
        );
    }

    private function makeMigration(): MigrationInterface
    {
        return new class implements MigrationInterface {
            public function up(SchemaBuilderInterface $schema): void
            {
            }

            public function down(SchemaBuilderInterface $schema): void
            {
            }
        };
    }
}
