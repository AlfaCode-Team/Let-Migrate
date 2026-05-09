<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\PostgreSQL;

use AlfaCode\LetMigrate\Driver\AbstractPdoDriver;

/**
 * PostgreSQL PDO driver.
 */
final class PostgreSQLDriver extends AbstractPdoDriver
{
    public function __construct(
        private readonly string $host,
        private readonly int    $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
        private readonly string $schema  = 'public',
        private readonly array  $options = [],
    ) {}

    protected function createConnection(): \PDO
    {
        $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->database}";
        $pdo = new \PDO($dsn, $this->username, $this->password, $this->options);
        // Set search_path to the configured schema
        $pdo->exec("SET search_path TO \"{$this->schema}\"");

        return $pdo;
    }

    public function getName(): string         { return 'pgsql'; }
    public function getPlatformName(): string  { return 'pgsql'; }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function tableExists(string $table): bool
    {
        $row = $this->fetchOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = $1 LIMIT 1",
            [$table],
        );

        return $row !== null;
    }

    public function columnExists(string $table, string $column): bool
    {
        $row = $this->fetchOne(
            "SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = $1 AND column_name = $2 LIMIT 1",
            [$table, $column],
        );

        return $row !== null;
    }

    public function listColumns(string $table): array
    {
        $rows = $this->fetchAll(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = $1 ORDER BY ordinal_position",
            [$table],
        );

        return array_column($rows, 'column_name');
    }

    public function listTables(): array
    {
        $rows = $this->fetchAll(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' ORDER BY table_name",
        );

        return array_column($rows, 'table_name');
    }
}