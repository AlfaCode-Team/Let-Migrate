<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Capture;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;

/**
 * A DatabaseDriverInterface decorator that captures all SQL statements instead
 * of executing them. Used by migrate:squash and --dry-run output.
 *
 * ────────────────────────────────────────────────────────────────────
 * FIX SUMMARY (NF-01 — NEW CRITICAL FINDING)
 * ────────────────────────────────────────────────────────────────────
 * The original CaptureDriver declared `implements DatabaseDriverInterface`
 * but was NOT a valid implementation, so the class could not even be
 * loaded (fatal at autoload time):
 *
 *   1. execute() / insert() / update() were declared `: bool` while the
 *      interface declares `: int` — incompatible return types (fatal).
 *   2. delete(), inTransaction(), listColumns(), listTables() and
 *      quoteIdentifier() were MISSING entirely — unimplemented abstract
 *      interface methods (fatal).
 *
 * Consequently `MigrationService::captureSql()` and the entire
 * `migrate:squash` path could never run — which is also why there was no
 * CaptureDriver test (it would crash on instantiation). This file makes
 * CaptureDriver a complete, loadable implementation.
 *
 * Behaviour:
 *   • write operations (execute/insert/update/delete) are intercepted and
 *     buffered, returning 0 affected rows;
 *   • read/schema operations delegate to the wrapped real driver so
 *     introspection still works against the live database;
 *   • transactions are no-ops (squash runs outside transactions).
 */
final class CaptureDriver implements DatabaseDriverInterface
{
    /** @var string[] */
    private array $capturedSql = [];

    public function __construct(
        private readonly DatabaseDriverInterface $realDriver,
    ) {}

    // ── Write operations — intercepted (return int per interface) ──

    public function execute(string $sql, array $bindings = []): int
    {
        $this->capturedSql[] = $this->interpolate($sql, $bindings);

        return 0;
    }

    public function insert(string $table, array $data): int
    {
        $cols = implode(', ', array_keys($data));
        $vals = implode(', ', array_map(
            static fn($v) => $v === null ? 'NULL' : "'" . addslashes((string) $v) . "'",
            $data,
        ));

        $this->capturedSql[] = "INSERT INTO {$table} ({$cols}) VALUES ({$vals})";

        return 0;
    }

    public function update(string $table, array $data, array $where = []): int
    {
        $sets = implode(', ', array_map(
            static fn($k, $v) => "{$k} = " . ($v === null ? 'NULL' : "'" . addslashes((string) $v) . "'"),
            array_keys($data),
            $data,
        ));
        $sql = "UPDATE {$table} SET {$sets}";

        if (!empty($where)) {
            $conditions = implode(' AND ', array_map(
                static fn($k, $v) => "{$k} = '" . addslashes((string) $v) . "'",
                array_keys($where),
                $where,
            ));
            $sql .= " WHERE {$conditions}";
        }

        $this->capturedSql[] = $sql;

        return 0;
    }

    public function delete(string $table, array $where): int
    {
        $sql = "DELETE FROM {$table}";

        if (!empty($where)) {
            $conditions = implode(' AND ', array_map(
                static fn($k, $v) => "{$k} = '" . addslashes((string) $v) . "'",
                array_keys($where),
                $where,
            ));
            $sql .= " WHERE {$conditions}";
        }

        $this->capturedSql[] = $sql;

        return 0;
    }

    // ── Transaction stubs — no-op (squash runs outside transactions) ───

    public function beginTransaction(): void {}

    public function commit(): void {}

    public function rollback(): void {}

    public function inTransaction(): bool
    {
        return false;
    }

    // ── Read / schema operations — delegate to the real driver ────

    public function fetchOne(string $sql, array $bindings = []): array|null
    {
        return $this->realDriver->fetchOne($sql, $bindings);
    }

    public function fetchAll(string $sql, array $bindings = []): array
    {
        return $this->realDriver->fetchAll($sql, $bindings);
    }

    public function tableExists(string $table): bool
    {
        return $this->realDriver->tableExists($table);
    }

    public function columnExists(string $table, string $column): bool
    {
        return $this->realDriver->columnExists($table, $column);
    }

    public function listColumns(string $table): array
    {
        return $this->realDriver->listColumns($table);
    }

    public function listTables(): array
    {
        return $this->realDriver->listTables();
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->realDriver->quoteIdentifier($identifier);
    }

    public function getName(): string
    {
        return $this->realDriver->getName();
    }

    // ── Capture API ───────────────────────────────────────────────

    /**
     * Return all intercepted SQL statements in order.
     *
     * @return string[]
     */
    public function getCapturedSql(): array
    {
        return $this->capturedSql;
    }

    /**
     * Return captured SQL joined by ";\n" — ready to embed in a migration file.
     */
    public function getCapturedSqlString(string $separator = ";\n"): string
    {
        return implode($separator, $this->capturedSql);
    }

    /**
     * Clear all captured statements.
     */
    public function reset(): void
    {
        $this->capturedSql = [];
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Inline positional ? bindings for display purposes only.
     * Not safe for execution — this is a display helper for squash output.
     *
     * @param array<int, mixed> $bindings
     */
    private function interpolate(string $sql, array $bindings): string
    {
        if (empty($bindings)) {
            return $sql;
        }

        foreach ($bindings as $value) {
            $replacement = $value === null
                ? 'NULL'
                : "'" . addslashes((string) $value) . "'";

            $sql = preg_replace('/\?/', $replacement, $sql, 1) ?? $sql;
        }

        return $sql;
    }

    /**
     * @inheritDoc
     */
    public function getPlatformName(): string {
        return $this->realDriver->getPlatformName();
    }
}