<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Contract\MigrationServiceInterface;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteDriver;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\DriverRegistry;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use AlfaCode\LetMigrate\MigrationConfig;
use AlfaCode\LetMigrate\MigrationService;
use AlfaCode\LetMigrate\MigrationServiceFactory;
use PHPUnit\Framework\TestCase;

final class MigrationServiceFactoryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/let_migrate_factory_' . uniqid('', true);
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.php') ?: [] as $f) {
            unlink($f);
        }

        rmdir($this->tmpDir);
    }

    // ── create() returns service implementing interface ────────────

    public function test_create_returns_migration_service_interface(): void
    {
        $registry = $this->makeRegistry();
        $config = $this->makeConfig();

        $service = MigrationServiceFactory::create($registry, $config);

        $this->assertInstanceOf(MigrationServiceInterface::class, $service);
    }

    public function test_create_returns_concrete_migration_service(): void
    {
        $registry = $this->makeRegistry();
        $config = $this->makeConfig();

        $service = MigrationServiceFactory::create($registry, $config);

        $this->assertInstanceOf(MigrationService::class, $service);
    }

    // ── fromConfig() ─────────────────────────────────────────────

    public function test_from_config_resolves_service_from_array(): void
    {
        $service = MigrationServiceFactory::fromConfig([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'paths' => [$this->tmpDir],
        ]);

        $this->assertInstanceOf(MigrationServiceInterface::class, $service);
    }

    // ── events dispatcher is shared ───────────────────────────────

    public function test_injected_events_dispatcher_is_used_by_service(): void
    {
        $events = new MigrationEventDispatcher();
        $service = MigrationServiceFactory::create(
            $this->makeRegistry(),
            $this->makeConfig(),
            events: $events,
        );

        $this->assertSame($events, $service->events());
    }

    public function test_default_events_dispatcher_is_created_when_not_injected(): void
    {
        $service = MigrationServiceFactory::create(
            $this->makeRegistry(),
            $this->makeConfig(),
        );

        $this->assertInstanceOf(MigrationEventDispatcher::class, $service->events());
    }

    // ── Repository is NOT accessible from outside the service ──────

    public function test_factory_does_not_expose_repository_reference(): void
    {
        $service = MigrationServiceFactory::create(
            $this->makeRegistry(),
            $this->makeConfig(),
        );

        $ref = new \ReflectionObject($service);
        $methods = array_map(
            static fn(\ReflectionMethod $m) => $m->getName(),
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $this->assertNotContains('repository', $methods);
        $this->assertNotContains('getRepository', $methods);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function makeRegistry(): DriverRegistry
    {
        return DriverRegistry::fromDriverAndGrammar(
            "sqlite",
            new SQLiteDriver(':memory:'),
            new SQLiteGrammar(),
        );
    }

    private function makeConfig(): MigrationConfig
    {
        return MigrationConfig::fromArray(['paths' => [$this->tmpDir]]);
    }
}
