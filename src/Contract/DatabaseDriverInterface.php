<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

/**
 * Low-level database driver contract.
 *
 * Each supported RDBMS (MySQL, PostgreSQL, SQLite, SQL Server) provides its
 * own implementation. Drivers handle connection management, DDL execution,
 * transaction control, and quoting rules that differ per dialect.
 */
interface DatabaseDriverInterface
{
    /**
     * Return the canonical driver name: 'mysql' | 'pgsql' | 'sqlite' | 'sqlsrv'
     */
    public function getName(): string;

    /**
     * Execute a raw SQL statement. Returns the number of affected rows.
     *
     * @throws \AlfaCode\LetMigrate\Exception\QueryException
     */
    public function execute(string $sql, array $bindings = []): int;

    /**
     * Fetch a single row, or null if no result.
     *
     * @return array<string, mixed>|null
     * @throws \AlfaCode\LetMigrate\Exception\QueryException
     */
    public function fetchOne(string $sql, array $bindings = []): ?array;

    /**
     * Fetch all rows.
     *
     * @return array<int, array<string, mixed>>
     * @throws \AlfaCode\LetMigrate\Exception\QueryException
     */
    public function fetchAll(string $sql, array $bindings = []): array;

    /**
     * Insert a row and return the last-insert ID.
     *
     * @param array<string, mixed> $data
     * @throws \AlfaCode\LetMigrate\Exception\QueryException
     */
    public function insert(string $table, array $data): int;

    /**
     * Update matching rows and return the affected row count.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int;

    /**
     * Delete matching rows and return the affected row count.
     *
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int;

    // ── Transactions ──────────────────────────────────────────────

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollback(): void;

    public function inTransaction(): bool;

    // ── Schema helpers ────────────────────────────────────────────

    /**
     * Return true when a table with the given name exists in the current schema.
     */
    public function tableExists(string $table): bool;

    /**
     * Return true when the column exists in the given table.
     */
    public function columnExists(string $table, string $column): bool;

    /**
     * Return all column names for the given table.
     *
     * @return string[]
     */
    public function listColumns(string $table): array;

    /**
     * Return all table names in the current schema.
     *
     * @return string[]
     */
    public function listTables(): array;

    /**
     * Quote an identifier (table name, column name) for the driver dialect.
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Return the DSN prefix used to identify this driver, e.g. "mysql".
     */
    public function getPlatformName(): string;
}