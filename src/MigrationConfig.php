<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

/**
 * Immutable value object holding all configuration for Let-Migrate.
 *
 * ────────────────────────────────────────────────────────────────────
 * FIX SUMMARY (U-03)
 * ────────────────────────────────────────────────────────────────────
 * • Original parameter ORDER is preserved (driver, paths, …) so existing
 *   positional callers do not break.
 * • $driver and $paths now both have defaults so
 *   `new MigrationConfig(paths: [])` constructs far enough to reach the
 *   validation guard instead of dying with ArgumentCountError.
 * • The constructor now validates that at least one migration path is
 *   supplied and throws InvalidArgumentException otherwise — this is what
 *   MigrationConfigTest / MigrationConfigAndResultTest expect.
 */
final class MigrationConfig
{
    public function __construct(
        /** Database driver key: mysql|pgsql|sqlite|sqlsrv */
        public readonly string      $driver = 'mysql',

        /** Absolute paths to directories containing migration files. */
        public readonly array       $paths = [],

        /** Name of the migrations tracking table. */
        public readonly string      $trackingTable = 'let_migrations',

        /** When true, SQL is logged but never executed (dry-run mode). */
        public readonly bool        $pretend = false,

        /** When true, each migration runs inside its own BEGIN/COMMIT block. */
        public readonly bool        $transactional = true,

        /**
         * When true, the ENTIRE pending batch runs inside ONE transaction
         * (Doctrine all_or_nothing). True atomicity only on engines with
         * transactional DDL (PostgreSQL/SQLite); MySQL DDL implicitly
         * commits. Default false = previous per-migration behaviour.
         */
        public readonly bool        $allOrNothing = false,

        /**
         * Absolute path to the directory containing seeder files.
         * When null, seeding is disabled (no seed commands will run).
         */
        public readonly string|null $seedersPath = null,

        /**
         * Name of the seeders tracking table.
         * Defaults to 'let_seeders'.
         */
        public readonly string      $seedersTable = 'let_seeders',
    ) {
        if (empty($this->paths)) {
            throw new \InvalidArgumentException(
                'MigrationConfig requires at least one migration path.',
            );
        }
    }

    /**
     * Build from a flat configuration array (the format accepted by
     * LetMigrate::configure()).
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
            driver:        strtolower((string) ($config['driver']         ?? 'mysql')),
            paths:         (array)  $paths,
            trackingTable: (string) ($config['tracking_table']            ?? 'let_migrations'),
            pretend:       (bool)   ($config['pretend']                   ?? false),
            transactional: (bool)   ($config['transactional']             ?? true),
            allOrNothing:  (bool)   ($config['all_or_nothing']             ?? false),
            seedersPath:   isset($config['seeders_path'])
                               ? (string) $config['seeders_path']
                               : null,
            seedersTable:  (string) ($config['seeders_table']             ?? 'let_seeders'),
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