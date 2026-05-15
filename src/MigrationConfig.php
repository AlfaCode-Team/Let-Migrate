<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

/**
 * Typed, immutable configuration for the LetMigrate engine.
 *
 * All values are validated at construction time so errors surface
 * immediately rather than at migration-run time.
 */
final class MigrationConfig
{
    /**
     * @param string[] $paths         Absolute paths to migration directories.
     * @param string   $trackingTable Name of the DB table that records applied migrations.
     * @param bool     $pretend       When true, SQL is logged but never executed.
     * @param bool     $transactional Wrap each migration in its own transaction (default: true).
     */
    public function __construct(
        public readonly array  $paths,
        public readonly string $trackingTable = 'let_migrations',
        public readonly bool   $pretend = false,
        public readonly bool   $transactional = true,
    ) {
        if (empty($this->paths)) {
            throw new \InvalidArgumentException(
                'MigrationConfig: at least one migration path is required.',
            );
        }
    }

    /**
     * Build from a flat config array (same array passed to LetMigrate::configure()).
     *
     * Recognised keys:
     *   paths          string[]  migration directories (required — or use 'path' for single)
     *   path           string    single migration directory (alias for paths)
     *   tracking_table string    override tracking table name
     *   pretend        bool      dry-run mode
     *   transactional  bool      per-migration transaction wrapping
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $paths = (array) ($raw['paths'] ?? []);

        if (empty($paths) && isset($raw['path'])) {
            $paths = [(string) $raw['path']];
        }

        return new self(
            paths: $paths,
            trackingTable: (string) ($raw['tracking_table'] ?? 'let_migrations'),
            pretend: (bool) ($raw['pretend'] ?? false),
            transactional: (bool) ($raw['transactional'] ?? true),
        );
    }
}
