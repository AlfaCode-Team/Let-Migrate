<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Capture;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;

/**
 * A DatabaseDriverInterface decorator that captures all SQL statements instead
 * of executing them. Used by migrate:squash and --dry-run output improvements.
 *
 * Wraps a real driver so that schema inspection calls (tableExists, columnExists,
 * fetchOne, fetchAll) still work against the live database, while all DDL/DML
 * writes (execute, insert, update) are intercepted and buffered.
 *
 * Usage:
 *
 *   $capture = new CaptureDriver($realDriver);
 *   $runner->runWith($capture);          // runs up() methods against the capture driver
 *   $sql = $capture->getCapturedSql();   // returns buffered statements
 */
final class CaptureDriver implements DatabaseDriverInterface
{
    /** @var string[] */
    private array $capturedSql = [];

    public function __construct(
        private readonly DatabaseDriverInterface $realDriver,
    ) {}

    // ── Write operations — intercepted ────────────────────────────

    public function execute(string $sql, array $bindings = []): bool
    {
        $this->capturedSql[] = $this->interpolate($sql, $bindings);
        return true;
    }

    public function insert(string $table, array $data): bool
    {
        $cols = implode(', ', array_keys($data));
        $vals = implode(', ', array_map(
            fn($v) => $v === null ? 'NULL' : "'" . addslashes((string) $v) . "'",
            $data,
        ));
        $this->capturedSql[] = "INSERT INTO {$table} ({$cols}) VALUES ({$vals})";
        return true;
    }

    public function update(string $table, array $data, array $where = []): bool
    {
        $sets = implode(', ', array_map(
            fn($k, $v) => "{$k} = " . ($v === null ? 'NULL' : "'" . addslashes((string) $v) . "'"),
            array_keys($data),
            $data,
        ));
        $sql = "UPDATE {$table} SET {$sets}";

        if (!empty($where)) {
            $conditions = implode(' AND ', array_map(
                fn($k, $v) => "{$k} = '" . addslashes((string) $v) . "'",
                array_keys($where),
                $where,
            ));
            $sql .= " WHERE {$conditions}";
        }

        $this->capturedSql[] = $sql;
        return true;
    }

    // ── Transaction stubs — no-op (squash runs outside transactions) ───

    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollback(): void {}

    // ── Read operations — delegate to real driver ─────────────────

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
}
