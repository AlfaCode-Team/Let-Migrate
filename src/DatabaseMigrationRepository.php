<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\MigrationRepositoryInterface;
use AlfaCode\LetMigrate\Schema\GrammarInterface;

/**
 * Persists migration state to a tracking table in the database.
 */
final class DatabaseMigrationRepository implements MigrationRepositoryInterface
{
    public function __construct(
        private readonly DatabaseDriverInterface $driver,
        private readonly GrammarInterface        $grammar,
        private readonly string                  $table = 'let_migrations',
    ) {}

    public function all(): array
    {
        $rows = $this->driver->fetchAll(
            "SELECT * FROM {$this->driver->quoteIdentifier($this->table)} ORDER BY batch ASC, migration ASC",
        );

        return array_map(static fn($r) => MigrationRecord::fromRow($r), $rows);
    }

    public function lastBatch(): int
    {
        $row = $this->driver->fetchOne(
            "SELECT MAX(batch) AS max_batch FROM {$this->driver->quoteIdentifier($this->table)}",
        );

        return (int) ($row['max_batch'] ?? 0);
    }

    public function lastBatchRecords(): array
    {
        $batch = $this->lastBatch();

        if ($batch === 0) {
            return [];
        }

        $rows = $this->driver->fetchAll(
            "SELECT * FROM {$this->driver->quoteIdentifier($this->table)} WHERE batch = ? ORDER BY migration DESC",
            [$batch],
        );

        return array_map(static fn($r) => MigrationRecord::fromRow($r), $rows);
    }

    public function appliedFilenames(): array
    {
        $rows = $this->driver->fetchAll(
            "SELECT migration FROM {$this->driver->quoteIdentifier($this->table)} ORDER BY batch ASC, migration ASC",
        );

        return array_column($rows, 'migration');
    }

    public function log(string $filename, int $batch): void
    {
        $this->driver->insert($this->table, [
            'migration'  => $filename,
            'batch'      => $batch,
            'applied_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function remove(string $filename): void
    {
        $this->driver->delete($this->table, ['migration' => $filename]);
    }

    public function ensureTable(): void
    {
        if (!$this->repositoryExists()) {
            $sql = $this->grammar->compileCreateMigrationTable($this->table);
            $this->driver->execute($sql);
        }
    }

    public function repositoryExists(): bool
    {
        return $this->driver->tableExists($this->table);
    }
}