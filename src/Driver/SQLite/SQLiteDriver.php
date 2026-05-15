<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\SQLite;

use AlfaCode\LetMigrate\Driver\AbstractPdoDriver;

/**
 * SQLite PDO driver.
 *
 * Pass ':memory:' as the $path for an in-memory database (ideal for tests).
 */
final class SQLiteDriver extends AbstractPdoDriver
{
    public function __construct(
        private readonly string $path,
        private readonly array $options = [],
    ) {
    }

    public function getName(): string
    {
        return 'sqlite';
    }

    public function getPlatformName(): string
    {
        return 'sqlite';
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function tableExists(string $table): bool
    {
        $row = $this->fetchOne(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1",
            [$table],
        );

        return $row !== null;
    }

    public function columnExists(string $table, string $column): bool
    {
        return in_array($column, $this->listColumns($table), true);
    }

    public function listColumns(string $table): array
    {
        $rows = $this->fetchAll("PRAGMA table_info({$this->quoteIdentifier($table)})");

        return array_column($rows, 'name');
    }

    public function listTables(): array
    {
        $rows = $this->fetchAll("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");

        return array_column($rows, 'name');
    }

    protected function createConnection(): \PDO
    {
        if (!is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0777, true);
        }
        
        $pdo = new \PDO("sqlite:{$this->path}", '', '', $this->options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        return $pdo;
    }
}
