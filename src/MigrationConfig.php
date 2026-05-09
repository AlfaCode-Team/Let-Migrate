<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

/**
 * Typed, immutable configuration for the LetMigrate engine.
 */
final class MigrationConfig
{
    /**
     * @param string[] $paths          Absolute paths to migration directories.
     * @param string   $trackingTable  Name of the DB table that records applied migrations.
     * @param bool     $pretend        When true, SQL is logged but never executed.
     * @param bool     $transactional  Wrap each migration in its own transaction (default: true).
     */
    public function __construct(
        public readonly array  $paths,
        public readonly string $trackingTable  = 'let_migrations',
        public readonly bool   $pretend        = false,
        public readonly bool   $transactional  = true,
    ) {
        if (empty($this->paths)) {
            throw new \InvalidArgumentException('MigrationConfig: at least one migration path is required.');
        }
    }

    /**
     * Build from a flat config array (same array passed to LetMigrate::configure()).
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $paths = (array) ($raw['paths'] ?? []);

        if (empty($paths)) {
            // Fall back to a single 'path' key for convenience
            if (isset($raw['path'])) {
                $paths = [(string) $raw['path']];
            }
        }

        return new self(
            paths:         $paths,
            trackingTable: (string) ($raw['tracking_table'] ?? 'let_migrations'),
            pretend:       (bool)   ($raw['pretend']        ?? false),
            transactional: (bool)   ($raw['transactional']  ?? true),
        );
    }
}
