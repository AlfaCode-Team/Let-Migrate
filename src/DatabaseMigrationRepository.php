<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\MigrationRepositoryInterface;
use AlfaCode\LetMigrate\Schema\GrammarInterface;

/**
 * Persists migration state to a tracking table in the database.
 *
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  ARCHITECTURE BOUNDARY — DO NOT INJECT DIRECTLY             ║
 * ║                                                             ║
 * ║  This class MUST only be instantiated by MigrationService   ║
 * ║  via MigrationServiceFactory.                               ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * ────────────────────────────────────────────────────────────────────
 * FIX SUMMARY (M-01 / M-02 / M-03)
 * ────────────────────────────────────────────────────────────────────
 * Implements the three convenience accessors added to
 * MigrationRepositoryInterface so the contract is complete:
 *   • getAll()             → alias of all()
 *   • getLastBatchNumber() → alias of lastBatch()
 *   • getLastBatches(int)  → records from the last N batches, newest first
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
        $t    = $this->driver->quoteIdentifier($this->table);
        $rows = $this->driver->fetchAll(
            "SELECT * FROM {$t} ORDER BY batch ASC, migration ASC",
        );

        return array_map(
            static fn(array $r) => MigrationRecord::fromRow($r),
            $rows,
        );
    }

    /** M-02: explicit alias of all(). */
    public function getAll(): array
    {
        return $this->all();
    }

    public function lastBatch(): int
    {
        $t   = $this->driver->quoteIdentifier($this->table);
        $row = $this->driver->fetchOne("SELECT MAX(batch) AS max_batch FROM {$t}");

        return (int) ($row['max_batch'] ?? 0);
    }

    /** M-03: explicit alias of lastBatch(). */
    public function getLastBatchNumber(): int
    {
        return $this->lastBatch();
    }

    public function lastBatchRecords(): array
    {
        $batch = $this->lastBatch();

        if ($batch === 0) {
            return [];
        }

        $t    = $this->driver->quoteIdentifier($this->table);
        $rows = $this->driver->fetchAll(
            "SELECT * FROM {$t} WHERE batch = ? ORDER BY migration DESC",
            [$batch],
        );

        return array_map(
            static fn(array $r) => MigrationRecord::fromRow($r),
            $rows,
        );
    }

    /**
     * M-01: return all records from the last N batches, newest batch first.
     */
    public function getLastBatches(int $steps): array
    {
        $lastBatch = $this->lastBatch();

        if ($lastBatch === 0) {
            return [];
        }

        $from = max(1, $lastBatch - $steps + 1);
        $t    = $this->driver->quoteIdentifier($this->table);
        $rows = $this->driver->fetchAll(
            "SELECT * FROM {$t} WHERE batch >= ? ORDER BY batch DESC, migration DESC",
            [$from],
        );

        return array_map(
            static fn(array $r) => MigrationRecord::fromRow($r),
            $rows,
        );
    }

    public function appliedFilenames(): array
    {
        $t    = $this->driver->quoteIdentifier($this->table);
        $rows = $this->driver->fetchAll(
            "SELECT migration FROM {$t} ORDER BY batch ASC, migration ASC",
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