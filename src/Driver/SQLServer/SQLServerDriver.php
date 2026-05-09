<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\SQLServer;

use AlfaCode\LetMigrate\Driver\AbstractPdoDriver;

/**
 * Microsoft SQL Server PDO driver (via sqlsrv or dblib PDO extension).
 */
final class SQLServerDriver extends AbstractPdoDriver
{
    public function __construct(
        private readonly string $host,
        private readonly int    $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
        private readonly string $schema  = 'dbo',
        private readonly array  $options = [],
    ) {}

    protected function createConnection(): \PDO
    {
        // Use sqlsrv extension if available, fall back to dblib (Linux / FreeTDS)
        if (in_array('sqlsrv', \PDO::getAvailableDrivers(), true)) {
            $dsn = "sqlsrv:Server={$this->host},{$this->port};Database={$this->database}";
        } else {
            $dsn = "dblib:host={$this->host}:{$this->port};dbname={$this->database}";
        }

        return new \PDO($dsn, $this->username, $this->password, $this->options);
    }

    public function getName(): string         { return 'sqlsrv'; }
    public function getPlatformName(): string  { return 'sqlsrv'; }

    public function quoteIdentifier(string $identifier): string
    {
        return '[' . str_replace(']', ']]', $identifier) . ']';
    }

    public function tableExists(string $table): bool
    {
        $row = $this->fetchOne(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
            [$this->schema, $table],
        );

        return $row !== null;
    }

    public function columnExists(string $table, string $column): bool
    {
        $row = $this->fetchOne(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$this->schema, $table, $column],
        );

        return $row !== null;
    }

    public function listColumns(string $table): array
    {
        $rows = $this->fetchAll(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
            [$this->schema, $table],
        );

        return array_column($rows, 'COLUMN_NAME');
    }

    public function listTables(): array
    {
        $rows = $this->fetchAll(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
            [$this->schema],
        );

        return array_column($rows, 'TABLE_NAME');
    }
}