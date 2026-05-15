<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Exception\ConnectionException;
use AlfaCode\LetMigrate\Exception\QueryException;

/**
 * Shared PDO implementation for all drivers.
 *
 * Concrete drivers extend this and supply their PDO connection + identifier
 * quoting logic. Everything else (execute, fetch, transactions) is handled here.
 */
abstract class AbstractPdoDriver implements DatabaseDriverInterface
{
    private \PDO|null $pdo = null;

    private bool $inTransaction = false;

    // ── DatabaseDriverInterface ───────────────────────────────────

    public function execute(string $sql, array $bindings = []): int
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($bindings);

            return $stmt->rowCount();
        } catch (\PDOException $e) {
            throw new QueryException($sql, $bindings, $e);
        }
    }

    public function fetchOne(string $sql, array $bindings = []): array|null
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($bindings);
            $row = $stmt->fetch();

            return $row === false ? null : $row;
        } catch (\PDOException $e) {
            throw new QueryException($sql, $bindings, $e);
        }
    }

    public function fetchAll(string $sql, array $bindings = []): array
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($bindings);

            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            throw new QueryException($sql, $bindings, $e);
        }
    }

    public function insert(string $table, array $data): int
    {
        $cols = implode(', ', array_map([$this, 'quoteIdentifier'], array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$this->quoteIdentifier($table)} ({$cols}) VALUES ({$placeholders})";

        $this->execute($sql, array_values($data));

        return (int) $this->pdo()->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(fn($k) => "{$this->quoteIdentifier($k)} = ?", array_keys($data)));
        $cond = implode(' AND ', array_map(fn($k) => "{$this->quoteIdentifier($k)} = ?", array_keys($where)));
        $sql = "UPDATE {$this->quoteIdentifier($table)} SET {$set} WHERE {$cond}";

        return $this->execute($sql, [...array_values($data), ...array_values($where)]);
    }

    public function delete(string $table, array $where): int
    {
        $cond = implode(' AND ', array_map(fn($k) => "{$this->quoteIdentifier($k)} = ?", array_keys($where)));
        $sql = "DELETE FROM {$this->quoteIdentifier($table)} WHERE {$cond}";

        return $this->execute($sql, array_values($where));
    }

    // ── Transactions ──────────────────────────────────────────────

    public function beginTransaction(): void
    {
        $this->pdo()->beginTransaction();
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        $this->pdo()->commit();
        $this->inTransaction = false;
    }

    public function rollback(): void
    {
        $this->pdo()->rollBack();
        $this->inTransaction = false;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * Build and return the PDO connection.
     * Called lazily on first use.
     */
    abstract protected function createConnection(): \PDO;

    // ── Connection ────────────────────────────────────────────────

    protected function pdo(): \PDO
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = $this->createConnection();
                $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                throw new ConnectionException($e->getMessage(), (int) $e->getCode(), $e);
            }
        }

        return $this->pdo;
    }
}
