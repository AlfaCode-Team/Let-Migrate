<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

/**
 * Immutable record of an applied migration.
 */
final readonly class MigrationRecord
{
    public function __construct(
        public int    $id,
        public string $migration,
        public int    $batch,
        public string $appliedAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:        (int) ($row['id'] ?? $row['ID'] ?? 0),
            migration: (string) ($row['migration'] ?? ''),
            batch:     (int) ($row['batch'] ?? 1),
            appliedAt: (string) ($row['applied_at'] ?? ''),
        );
    }
}