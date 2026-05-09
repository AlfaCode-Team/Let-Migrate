<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\SQLServer;

use AlfaCode\LetMigrate\Schema\AbstractGrammar;
use AlfaCode\LetMigrate\Schema\Blueprint;
use AlfaCode\LetMigrate\Schema\ColumnDefinition;

/**
 * Microsoft SQL Server (T-SQL) DDL grammar.
 *
 * Key differences:
 * - Square bracket identifier quoting: [table], [column]
 * - IDENTITY(1,1) for auto-increment (not AUTO_INCREMENT or SERIAL)
 * - NVARCHAR instead of VARCHAR for Unicode strings
 * - No DATETIME → use DATETIME2 for better precision
 * - Foreign keys must be named constraints
 */
final class SQLServerGrammar extends AbstractGrammar
{
    protected string $quoteChar = ']'; // Special — handled in quoteIdentifier override

    public function quoteIdentifier(string $identifier): string
    {
        return '[' . str_replace(']', ']]', $identifier) . ']';
    }

    protected function autoIncrementKeyword(): string
    {
        return 'IDENTITY(1,1)';
    }

    protected function compileColumn(ColumnDefinition $col): string
    {
        $type  = $this->mapType($col->getType(), $col->isAutoIncrement());
        $parts = [$this->quoteIdentifier($col->getName()), $type];

        if ($col->isAutoIncrement()) {
            $parts[] = 'IDENTITY(1,1)';
            $parts[] = 'NOT NULL';

            return implode(' ', $parts);
        }

        $parts[] = $col->isNullable() ? 'NULL' : 'NOT NULL';

        if ($col->hasDefault()) {
            $parts[] = 'DEFAULT ' . $this->wrapDefault($col->getDefault());
        }

        return implode(' ', $parts);
    }

    private function mapType(string $type, bool $autoInc = false): string
    {
        if ($autoInc) {
            return 'BIGINT';
        }

        $upper = strtoupper(trim($type));

        return match (true) {
            str_starts_with($upper, 'TINYINT(1)') => 'BIT',
            str_starts_with($upper, 'TINYINT')    => 'TINYINT',
            str_starts_with($upper, 'SMALLINT')   => 'SMALLINT',
            str_starts_with($upper, 'BIGINT')      => 'BIGINT',
            str_starts_with($upper, 'INT')         => 'INT',
            str_starts_with($upper, 'DATETIME'),
            str_starts_with($upper, 'TIMESTAMP')   => 'DATETIME2',
            str_starts_with($upper, 'DATE')        => 'DATE',
            str_starts_with($upper, 'TIME')        => 'TIME',
            str_starts_with($upper, 'TEXT'),
            str_starts_with($upper, 'TINYTEXT'),
            str_starts_with($upper, 'MEDIUMTEXT'),
            str_starts_with($upper, 'LONGTEXT')    => 'NVARCHAR(MAX)',
            preg_match('/^VARCHAR\((\d+)\)$/i', $upper, $m) === 1 => "NVARCHAR({$m[1]})",
            preg_match('/^CHAR\((\d+)\)$/i', $upper, $m) === 1    => "NCHAR({$m[1]})",
            str_starts_with($upper, 'FLOAT'),
            str_starts_with($upper, 'DOUBLE')      => 'FLOAT',
            str_starts_with($upper, 'DECIMAL'),
            str_starts_with($upper, 'NUMERIC')     => str_ireplace(['DECIMAL', 'NUMERIC'], 'DECIMAL', $type),
            str_starts_with($upper, 'BLOB'),
            str_starts_with($upper, 'BINARY')      => 'VARBINARY(MAX)',
            str_starts_with($upper, 'JSON')        => 'NVARCHAR(MAX)',
            str_starts_with($upper, 'ENUM')        => 'NVARCHAR(100)',
            str_starts_with($upper, 'YEAR')        => 'SMALLINT',
            default                                => $type,
        };
    }

    protected function compileTableOptions(Blueprint $blueprint): string
    {
        return ''; // SQL Server has no ENGINE/CHARSET clauses
    }

    public function compileRename(string $from, string $to): string
    {
        return "EXEC sp_rename '{$from}', '{$to}'";
    }

    public function compileForeignKeyChecksOff(): string
    {
        return "EXEC sp_MSforeachtable 'ALTER TABLE ? NOCHECK CONSTRAINT ALL'";
    }

    public function compileForeignKeyChecksOn(): string
    {
        return "EXEC sp_MSforeachtable 'ALTER TABLE ? CHECK CONSTRAINT ALL'";
    }

    protected function compileDropIndex(string $quotedTable, string $indexName): string
    {
        return "DROP INDEX [{$indexName}] ON {$quotedTable}";
    }

    protected function compileDropForeignKey(string $quotedTable, string $fkName): string
    {
        return "ALTER TABLE {$quotedTable} DROP CONSTRAINT [{$fkName}]";
    }

    public function compileCreateMigrationTable(string $tableName): string
    {
        $t = $this->quoteIdentifier($tableName);

        return "IF OBJECT_ID(N'{$tableName}', N'U') IS NULL
BEGIN
    CREATE TABLE {$t} (
        [id]         BIGINT        NOT NULL IDENTITY(1,1),
        [migration]  NVARCHAR(255) NOT NULL,
        [batch]      INT           NOT NULL DEFAULT 1,
        [applied_at] DATETIME2     NOT NULL DEFAULT GETDATE(),
        PRIMARY KEY ([id]),
        UNIQUE ([migration])
    )
END";
    }
}