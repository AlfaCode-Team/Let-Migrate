<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\MigrationServiceInterface;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Factory that constructs MigrationService with all required dependencies.
 *
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  SOLE INSTANTIATION POINT                                   ║
 * ║                                                             ║
 * ║  This is the ONLY class permitted to:                       ║
 * ║    • instantiate DatabaseMigrationRepository                ║
 * ║    • inject MigrationRepositoryInterface into MigrationService║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * ────────────────────────────────────────────────────────────────────
 * FIX SUMMARY (S-05 / C-04)
 * ────────────────────────────────────────────────────────────────────
 * • The runner now receives $config->transactional so the transactional
 *   flag is actually honoured.
 * • $schema is documented as SchemaBuilderInterface for the runner while
 *   the concrete SchemaBuilder is passed to MigrationService (which needs
 *   getGrammar()/getInspector()). Both are satisfied by the one instance.
 */
final class MigrationServiceFactory
{
    /**
     * Build and return a fully-wired MigrationService.
     */
    public static function create(
        DriverRegistry $registry,
        MigrationConfig $config,
        LoggerInterface $logger = new NullLogger(),
        MigrationEventDispatcher|null $events = null,
        \AlfaCode\LetMigrate\Contract\MigrationFactoryInterface|null $migrationFactory = null,
        \AlfaCode\LetMigrate\BreakpointStore|null $breakpoints = null,
    ): MigrationServiceInterface {
        // ── Repository is constructed HERE — the only place in the codebase ──
        $repository = new DatabaseMigrationRepository(
            driver: $registry->driver(),
            grammar: $registry->grammar(),
            table: $config->trackingTable,
        );


        $resolver = new FilesystemMigrationResolver($config->paths, $migrationFactory);
       
        $schema = $registry->schemaBuilder();
        $events ??= new MigrationEventDispatcher();

        $runner = new MigrationRunner(
            repository: $repository,
            resolver: $resolver,
            schema: $schema,
            events: $events,
            logger: $logger,
            pretend: $config->pretend,
            transactional: $config->transactional,
            allOrNothing: $config->allOrNothing,
            breakpoints: $breakpoints,  
        );


        return new MigrationService(
            runner: $runner,
            repository: $repository,
            resolver: $resolver,
            schemaBuilder: $schema,
            dispatcher: $events,
            paths: $config->paths,
            seedersPath: $config->seedersPath,
            seedersTable: $config->seedersTable,
        );
    }

    /**
     * Build from raw config array — convenience wrapper used by LetMigrate facade.
     *
     * Supports both the legacy flat config and the new multi-connection
     * shape (see ConnectionResolver). $connection is the LAST parameter so
     * existing positional callers (`fromConfig($cfg, $logger, $events)`)
     * are unaffected.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(
        array $config,
        LoggerInterface $logger = new NullLogger(),
        MigrationEventDispatcher|null $events = null,
        string|null $connection = null,
    ): MigrationServiceInterface {
        $resolved = ConnectionResolver::resolve($config, $connection);

        // When a global table prefix is configured, the migration
        // tracking table must be prefixed too — otherwise two prefixed
        // apps sharing one database collide on the same tracking table.
        $prefix = (string) ($resolved['prefix'] ?? '');
        if ($prefix !== '') {
            $tracking = (string) ($resolved['tracking_table'] ?? 'let_migrations');
            if (!str_starts_with($tracking, $prefix)) {
                $resolved['tracking_table'] = $prefix . $tracking;
            }
            if (isset($resolved['seeders_table'])) {
                $seeders = (string) $resolved['seeders_table'];
                if (!str_starts_with($seeders, $prefix)) {
                    $resolved['seeders_table'] = $prefix . $seeders;
                }
            }
        }

        $registry = DriverRegistry::fromConfig($resolved);
        $migrationConfig = MigrationConfig::fromArray($resolved);

        $breakpoints = new BreakpointStore(
            $registry->driver(),
            ($resolved['prefix'] ?? '') . 'let_breakpoints',
        );

        return self::create(
            $registry,
            $migrationConfig,
            $logger,
            $events,
            null,
            $breakpoints
        );
    }
}