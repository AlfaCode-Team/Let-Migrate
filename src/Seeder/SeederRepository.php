<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Seeder;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Schema\GrammarInterface;

/**
 * Persists and queries seeder execution history using a dedicated tracking table.
 *
 * The tracking table (`let_seeders` by default) is created automatically on
 * first use via ensureTable().
 *
 * This class is intentionally NOT exposed through MigrationServiceInterface.
 * It is an internal component owned by SeederRunner.
 */
final class SeederRepository
{
    public function __construct(
        private readonly DatabaseDriverInterface $driver,
        private readonly GrammarInterface        $grammar,
        private readonly string                  $table = 'let_seeders',
    ) {}

    // ── Table bootstrap ───────────────────────────────────────────

    public function ensureTable(): void
    {
        if ($this->driver->tableExists($this->table)) {
            return;
        }

        $q  = fn(string $id) => $this->grammar->quoteIdentifier($id);
        $t  = $q($this->table);

        // Keep the DDL simple and driver-agnostic using raw SQL that works on
        // all four supported engines. SeederRepository does not go through
        // Blueprint / Grammar compilation to avoid circular dependencies.
        $driver = $this->driver->getName();

        $sql = match (true) {
            str_starts_with($driver, 'pgsql'), str_starts_with($driver, 'postgres') => <<<SQL
                CREATE TABLE IF NOT EXISTS {$t} (
                    {$q('id')}        SERIAL       NOT NULL,
                    {$q('seeder')}    VARCHAR(255) NOT NULL,
                    {$q('batch')}     INTEGER      NOT NULL DEFAULT 1,
                    {$q('seeded_at')} TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY ({$q('id')}),
                    UNIQUE ({$q('seeder')})
                )
                SQL,

            str_starts_with($driver, 'sqlite') => <<<SQL
                CREATE TABLE IF NOT EXISTS {$t} (
                    {$q('id')}        INTEGER      NOT NULL PRIMARY KEY AUTOINCREMENT,
                    {$q('seeder')}    TEXT         NOT NULL UNIQUE,
                    {$q('batch')}     INTEGER      NOT NULL DEFAULT 1,
                    {$q('seeded_at')} TEXT         NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
                SQL,

            str_starts_with($driver, 'sqlsrv'), str_starts_with($driver, 'mssql') => <<<SQL
                IF OBJECT_ID(N'{$this->table}', N'U') IS NULL
                CREATE TABLE {$t} (
                    {$q('id')}        INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                    {$q('seeder')}    NVARCHAR(255)     NOT NULL UNIQUE,
                    {$q('batch')}     INT               NOT NULL DEFAULT 1,
                    {$q('seeded_at')} DATETIME2         NOT NULL DEFAULT GETDATE()
                )
                SQL,

            default /* mysql */ => <<<SQL
                CREATE TABLE IF NOT EXISTS {$t} (
                    {$q('id')}        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                    {$q('seeder')}    VARCHAR(255)  NOT NULL,
                    {$q('batch')}     INT           NOT NULL DEFAULT 1,
                    {$q('seeded_at')} DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY ({$q('id')}),
                    UNIQUE KEY uq_seeder ({$q('seeder')})
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
        };

        $this->driver->execute($sql);
    }

    // ── Read ──────────────────────────────────────────────────────

    /**
     * Return all logged seeder records ordered by batch + id.
     *
     * @return SeederRecord[]
     */
    public function all(): array
    {
        $q    = fn(string $id) => $this->grammar->quoteIdentifier($id);
        $rows = $this->driver->fetchAll(
            "SELECT {$q('seeder')}, {$q('batch')}, {$q('seeded_at')}
             FROM {$this->grammar->quoteIdentifier($this->table)}
             ORDER BY {$q('batch')}, {$q('id')}",
        );

        return array_map(
            static fn(array $r) => new SeederRecord(
                seeder:   $r['seeder'],
                batch:    (int) $r['batch'],
                seededAt: $r['seeded_at'],
            ),
            $rows,
        );
    }

    /**
     * Return seeder names that have already been run.
     *
     * @return string[]
     */
    public function ranNames(): array
    {
        return array_column(
            $this->driver->fetchAll(
                "SELECT {$this->grammar->quoteIdentifier('seeder')}
                 FROM {$this->grammar->quoteIdentifier($this->table)}",
            ),
            'seeder',
        );
    }

    public function lastBatch(): int
    {
        $q   = fn(string $id) => $this->grammar->quoteIdentifier($id);
        $row = $this->driver->fetchOne(
            "SELECT MAX({$q('batch')}) AS last_batch FROM {$this->grammar->quoteIdentifier($this->table)}",
        );

        return (int) ($row['last_batch'] ?? 0);
    }

    // ── Write ─────────────────────────────────────────────────────

    public function log(string $seeder, int $batch): void
    {
        $this->driver->insert($this->table, [
            'seeder' => $seeder,
            'batch'  => $batch,
        ]);
    }

    public function delete(string $seeder): void
    {
        $t  = $this->grammar->quoteIdentifier($this->table);
        $q  = $this->grammar->quoteIdentifier('seeder');

        $this->driver->execute("DELETE FROM {$t} WHERE {$q} = ?", [$seeder]);
    }

    public function deleteAll(): void
    {
        $this->driver->execute(
            "DELETE FROM {$this->grammar->quoteIdentifier($this->table)}",
        );
    }
}
