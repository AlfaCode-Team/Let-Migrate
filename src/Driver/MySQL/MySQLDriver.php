<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\MySQL;

use AlfaCode\LetMigrate\Driver\AbstractPdoDriver;

/**
 * MySQL / MariaDB PDO driver.
 */
final class MySQLDriver extends AbstractPdoDriver
{
    public function __construct(
        private readonly string $host,
        private readonly int    $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
        private readonly string $charset  = 'utf8mb4',
        private readonly array  $options  = [],
    ) {}

    public static function fromDsn(string $dsn, string $username, string $password, array $options = []): self
    {
        // Parse DSN: mysql:host=localhost;port=3306;dbname=mydb;charset=utf8mb4
        $parts    = [];
        $segments = explode(';', ltrim($dsn, 'mysql:'));
        foreach ($segments as $seg) {
            [$k, $v]  = explode('=', $seg, 2) + ['', ''];
            $parts[$k] = $v;
        }

        return new self(
            host:     $parts['host']    ?? '127.0.0.1',
            port:     (int)($parts['port']    ?? 3306),
            database: $parts['dbname']  ?? '',
            username: $username,
            password: $password,
            charset:  $parts['charset'] ?? 'utf8mb4',
            options:  $options,
        );
    }

    protected function createConnection(): \PDO
    {
        $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset={$this->charset}";

        return new \PDO($dsn, $this->username, $this->password, $this->options);
    }

    public function getName(): string        { return 'mysql'; }
    public function getPlatformName(): string { return 'mysql'; }

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function tableExists(string $table): bool
    {
        $row = $this->fetchOne(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table],
        );

        return $row !== null;
    }

    public function columnExists(string $table, string $column): bool
    {
        $row = $this->fetchOne(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column],
        );

        return $row !== null;
    }

    public function listColumns(string $table): array
    {
        $rows = $this->fetchAll(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table],
        );

        return array_column($rows, 'COLUMN_NAME');
    }

    public function listTables(): array
    {
        $rows = $this->fetchAll('SHOW TABLES');

        return array_map(static fn($row) => current($row), $rows);
    }
}