<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\MigrationConfig;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use AlfaCode\LetMigrate\DatabaseMigrationRepository;
use AlfaCode\LetMigrate\FilesystemMigrationResolver;
use AlfaCode\LetMigrate\MigrationResult;
use AlfaCode\LetMigrate\MigrationRunner;
use AlfaCode\LetMigrate\DriverRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * LetMigrate — main facade.
 *
 * This is the single class most application bootstrappers need to touch.
 * It wires the registry, repository, resolver, and runner together.
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
 * Per-driver migration paths
 * ─────────────────────────────────────────────────────────────────
 *
 *   'paths' => [
 *       __DIR__ . '/migrations/mysql',        // MySQL-specific DDL
 *       __DIR__ . '/migrations/shared',       // driver-agnostic seeds
 *   ]
 *
 * ─────────────────────────────────────────────────────────────────
 * With event listeners and PSR-3 logger
 * ─────────────────────────────────────────────────────────────────
 *
 *   $engine = LetMigrate::configure($config, $logger);
 *   $engine->events()->on(MigrationFailed::class, fn($e) => sentry($e->exception));
 *   $engine->run();
 */
final class LetMigrate
{
    private MigrationRunner           $runner;
    private MigrationEventDispatcher  $events;

    private function __construct(
        private readonly DriverRegistry $registry,
        MigrationConfig                 $config,
        LoggerInterface                 $logger,
    ) {
        $this->events = new MigrationEventDispatcher();

        $schema     = $this->registry->schemaBuilder();
        $grammar    = $this->registry->grammar();
        $driver     = $this->registry->driver();

        $repository = new DatabaseMigrationRepository($driver, $grammar, $config->trackingTable);
        $resolver   = new FilesystemMigrationResolver($config->paths);

        $this->runner = new MigrationRunner(
            repository: $repository,
            resolver:   $resolver,
            schema:     $schema,
            events:     $this->events,
            logger:     $logger,
            pretend:    $config->pretend,
        );
    }

    // ── Factory ───────────────────────────────────────────────────

    /**
     * Create a LetMigrate engine from a flat config array.
     *
     * Recognised top-level keys (in addition to driver-specific ones):
     *   paths          string[]  — migration directories (required)
     *   tracking_table string    — name of the migration tracking table (default: 'let_migrations')
     *   pretend        bool      — log SQL without executing (default: false)
     *
     * @param array<string, mixed> $config
     */
    public static function configure(
        array           $config,
        LoggerInterface $logger  = new NullLogger(),
    ): self {
        $migrationConfig = MigrationConfig::fromArray($config);
        $registry        = DriverRegistry::fromConfig($config);

        return new self($registry, $migrationConfig, $logger);
    }

    /**
     * Create from a pre-built DriverRegistry (useful when you manage the
     * connection lifecycle yourself or in tests).
     *
     * @param array<string, mixed> $config
     */
    public static function fromRegistry(
        DriverRegistry  $registry,
        array           $config  = [],
        LoggerInterface $logger  = new NullLogger(),
    ): self {
        $migrationConfig = MigrationConfig::fromArray($config);

        return new self($registry, $migrationConfig, $logger);
    }

    // ── Operations ────────────────────────────────────────────────

    /**
     * Apply all pending migrations.
     */
    public function run(): MigrationResult
    {
        return $this->runner->run();
    }

    /**
     * Reverse the last N batches of migrations.
     */
    public function rollback(int $steps = 1): MigrationResult
    {
        return $this->runner->rollback($steps);
    }

    /**
     * Roll back ALL migrations (destructive — dev/test only).
     */
    public function reset(): MigrationResult
    {
        return $this->runner->reset();
    }

    /**
     * Reset then re-run all migrations (destructive — dev/test only).
     */
    public function refresh(): MigrationResult
    {
        return $this->runner->refresh();
    }

    /**
     * Return the status of every discovered migration.
     *
     * @return array<string, array{status: string, batch: int|null}>
     */
    public function status(): array
    {
        return $this->runner->status();
    }

    /**
     * Return migrations that have not yet been applied.
     *
     * @return array<string, \AlfaCode\LetMigrate\Contract\MigrationInterface>
     */
    public function pending(): array
    {
        return $this->runner->pending();
    }

    // ── Accessors ─────────────────────────────────────────────────

    public function events(): MigrationEventDispatcher
    {
        return $this->events;
    }

    public function registry(): DriverRegistry
    {
        return $this->registry;
    }

    public function runner(): MigrationRunner
    {
        return $this->runner;
    }
}
