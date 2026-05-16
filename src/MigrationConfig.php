<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

/**
 * Immutable value object holding all configuration for Let-Migrate.
 *
 * @updated Added $seedersPath and $seedersTable properties so the seeder
 *          engine reads from config instead of hard-coding paths/table names
 *          in the SeederRunner constructor.
 */
final class MigrationConfig
{
    public function __construct(
        /** Database driver key: mysql|pgsql|sqlite|sqlsrv */
        public readonly string $driver,
 
        /** Absolute paths to directories containing migration files. */
        public readonly array  $paths,
 
        /** Name of the migrations tracking table. */
        public readonly string $trackingTable = 'let_migrations',
 
        /** When true, SQL is logged but never executed (dry-run mode). */
        public readonly bool   $pretend = false,
 
        /** When true, each migration runs inside its own BEGIN/COMMIT block. */
        public readonly bool   $transactional = true,
 
        /**
         * Absolute path to the directory containing seeder files.
         * When null, seeding is disabled (no seed commands will run).
         */
        public readonly string|null $seedersPath = null,
 
        /**
         * Name of the seeders tracking table.
         * Defaults to 'let_seeders'.
         */
        public readonly string $seedersTable = 'let_seeders',
    ) {}
 
    /**
     * Build from a flat configuration array (the format accepted by LetMigrate::configure()).
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // Normalise 'path' (singular) → 'paths' (array)
        $paths = $config['paths'] ?? [];
        if (isset($config['path']) && is_string($config['path'])) {
            $paths = [$config['path']];
        }
 
        return new self(
            driver:        strtolower((string) ($config['driver']          ?? 'mysql')),
            paths:         (array)  $paths,
            trackingTable: (string) ($config['tracking_table']             ?? 'let_migrations'),
            pretend:       (bool)   ($config['pretend']                    ?? false),
            transactional: (bool)   ($config['transactional']              ?? true),
            seedersPath:   isset($config['seeders_path'])
                               ? (string) $config['seeders_path']
                               : null,
            seedersTable:  (string) ($config['seeders_table']              ?? 'let_seeders'),
        );
    }
 
    /**
     * Return true when at least one migration path is configured.
     */
    public function hasPaths(): bool
    {
        return !empty($this->paths);
    }
 
    /**
     * Return true when a seeders path is configured.
     */
    public function hasSeederPath(): bool
    {
        return $this->seedersPath !== null && $this->seedersPath !== '';
    }
}
 