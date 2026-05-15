<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\MigrationServiceInterface;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * LetMigrate — public-facing facade.
 *
 * This is the single class most application bootstrappers need to touch.
 * It delegates all work to MigrationService through MigrationServiceFactory,
 * keeping the repository entirely hidden from application code.
 *
 * ─────────────────────────────────────────────────────────────────
 * Minimal bootstrap
 * ─────────────────────────────────────────────────────────────────
 *
 *   $engine = LetMigrate::configure([
 *       'driver'   => 'mysql',
 *       'host'     => '127.0.0.1',
 *       'database' => 'my_app',
 *       'username' => 'root',
 *       'password' => 'secret',
 *       'paths'    => [__DIR__ . '/migrations/mysql'],
 *   ]);
 *
 *   $result = $engine->run();
 *   echo $result->summary();
 *
 * ─────────────────────────────────────────────────────────────────
 * With a pre-built DriverRegistry (tests / custom DI containers)
 * ─────────────────────────────────────────────────────────────────
 *
 *   $engine = LetMigrate::fromRegistry(
 *       DriverRegistry::fromDriverAndGrammar($driver, $grammar),
 *       ['paths' => [$migrationsDir]],
 *   );
 *
 * ─────────────────────────────────────────────────────────────────
 * Event hooks
 * ─────────────────────────────────────────────────────────────────
 *
 *   $engine->events()->on(MigrationFailed::class, fn($e) => report($e->exception));
 *   $engine->run();
 */
final class LetMigrate
{
    private readonly MigrationServiceInterface $service;

    private function __construct(
        private readonly DriverRegistry $registry,
        MigrationServiceInterface       $service,
    ) {
        $this->service = $service;
    }

    // ── Factory methods ───────────────────────────────────────────

    /**
     * Create a LetMigrate engine from a flat config array.
     *
     * Recognised keys (in addition to driver-specific ones):
     *   driver         string    — 'mysql' | 'pgsql' | 'sqlite' | 'sqlsrv'
     *   paths          string[]  — migration directories (required)
     *   path           string    — single migration directory (alias for paths)
     *   tracking_table string    — override migration tracking table name
     *   pretend        bool      — log SQL without executing
     *
     * @param array<string, mixed> $config
     */
    public static function configure(
        array           $config,
        LoggerInterface $logger = new NullLogger(),
    ): self {
        $registry = DriverRegistry::fromConfig($config);
        $service = MigrationServiceFactory::create(
            $registry,
            MigrationConfig::fromArray($config),
            $logger,
        );

        return new self($registry, $service);
    }

    /**
     * Create from a pre-built DriverRegistry.
     *
     * Useful when you manage the connection lifecycle yourself or in tests:
     *
     *   LetMigrate::fromRegistry(
     *       DriverRegistry::fromDriverAndGrammar($driver, $grammar),
     *       ['paths' => [$migrationsDir]],
     *   );
     *
     * @param array<string, mixed> $config
     */
    public static function fromRegistry(
        DriverRegistry  $registry,
        array           $config = [],
        LoggerInterface $logger = new NullLogger(),
    ): self {
        $service = MigrationServiceFactory::create(
            $registry,
            MigrationConfig::fromArray($config),
            $logger,
        );

        return new self($registry, $service);
    }

    // ── Delegated operations ──────────────────────────────────────

    /**
     * Apply all pending migrations.
     */
    public function run(): MigrationResult
    {
        return $this->service->run();
    }

    /**
     * Reverse the last N batches.
     */
    public function rollback(int $steps = 1): MigrationResult
    {
        return $this->service->rollback($steps);
    }

    /**
     * Roll back ALL migrations (destructive — dev / test only).
     */
    public function reset(): MigrationResult
    {
        return $this->service->reset();
    }

    /**
     * Reset then re-run all migrations (destructive — dev / test only).
     */
    public function refresh(): MigrationResult
    {
        return $this->service->refresh();
    }

    /**
     * Return the status of every discovered migration.
     *
     * @return array<string, array{status: string, batch: int|null}>
     */
    public function status(): array
    {
        return $this->service->status();
    }

    /**
     * Return migrations that have not yet been applied.
     *
     * @return array<string, MigrationInterface>
     */
    public function pending(): array
    {
        return $this->service->pending();
    }

    // ── Accessors ─────────────────────────────────────────────────

    public function events(): MigrationEventDispatcher
    {
        return $this->service->events();
    }

    public function registry(): DriverRegistry
    {
        return $this->registry;
    }

    /**
     * Access the underlying service for advanced use.
     * Prefer the methods above for standard application code.
     */
    public function service(): MigrationServiceInterface
    {
        return $this->service;
    }
}
