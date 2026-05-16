<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\MigrationConfig;
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
 * ║                                                             ║
 * ║  All application bootstrappers must call                    ║
 * ║  MigrationServiceFactory::create() or LetMigrate::configure()║
 * ║  — never construct MigrationService or the repository       ║
 * ║  manually.                                                  ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * Usage
 * ─────
 *   $service = MigrationServiceFactory::create(
 *       DriverRegistry::fromConfig($config),
 *       MigrationConfig::fromArray($config),
 *       $logger,
 *   );
 *
 *   $result = $service->run();
 */
final class MigrationServiceFactory
{
    /**
     * Build and return a fully-wired MigrationService.
     *
     * This method is the single authorised entry-point for constructing
     * the service. It instantiates the repository internally so that
     * no external code ever holds a direct reference to it.
     */
    public static function create(
        DriverRegistry          $registry,
        MigrationConfig         $config,
        LoggerInterface         $logger = new NullLogger(),
        MigrationEventDispatcher|null $events = null,
    ): MigrationServiceInterface {
        // ── Repository is constructed HERE — the only place in the codebase ──
        $repository = new DatabaseMigrationRepository(
            driver: $registry->driver(),
            grammar: $registry->grammar(),
            table: $config->trackingTable,
        );

        $resolver = new FilesystemMigrationResolver($config->paths);
        $schema = $registry->schemaBuilder();
        $events ??= new MigrationEventDispatcher();

        $runner = new MigrationRunner(
            repository: $repository,
            resolver: $resolver,
            schema: $schema,
            events: $events,
            logger: $logger ?? new NullLogger(),
            pretend: $config->pretend,
        );

        return new MigrationService(
            runner: $runner,
            repository: $repository,
            resolver: $resolver,
            schemaBuilder: $schema,
            dispatcher: $events,
            paths: $config->paths,
        );
    }

    /**
     * Build from raw config array — convenience wrapper used by LetMigrate facade.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(
        array                         $config,
        LoggerInterface               $logger = new NullLogger(),
        MigrationEventDispatcher|null $events = null,
    ): MigrationServiceInterface {
        $registry = DriverRegistry::fromConfig($config);
        $migrationConfig = MigrationConfig::fromArray($config);

        return self::create($registry, $migrationConfig, $logger, $events);
    }
}
