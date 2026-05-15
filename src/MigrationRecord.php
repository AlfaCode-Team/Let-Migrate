<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

/**
 * Immutable record of an applied migration row in the tracking table.
 */
final readonly class MigrationRecord
{
    public function __construct(
        public int    $id,
        public string $migration,
        public int    $batch,
        public string $appliedAt,
    ) {}

    /**
     * Hydrate from a raw PDO fetch row.
     *
     * Handles both lower-case (MySQL/PostgreSQL/SQLite) and upper-case
     * (SQL Server) column name conventions.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? $row['ID'] ?? 0),
            migration: (string) ($row['migration'] ?? $row['Migration'] ?? ''),
            batch: (int) ($row['batch'] ?? $row['Batch'] ?? 1),
            appliedAt: (string) ($row['applied_at'] ?? $row['AppliedAt'] ?? ''),
        );
    }
}
