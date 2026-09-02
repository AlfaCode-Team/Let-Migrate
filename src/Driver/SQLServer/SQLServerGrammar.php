<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\SQLServer;

use AlfaCode\LetMigrate\Schema\AbstractGrammar;
use AlfaCode\LetMigrate\Schema\Blueprint;
use AlfaCode\LetMigrate\Schema\ColumnDefinition;
use AlfaCode\LetMigrate\Schema\IndexDefinition;

/**
 * SQL Server DDL grammar.
 *
 * @fixed compileRenameColumn() — now uses EXEC sp_rename syntax instead of
 *        ANSI RENAME COLUMN which SQL Server does not support.
 *
 * @added compileModifyColumn() — SQL Server ALTER COLUMN syntax.
 *
 * @added compileRenameIndex() — sp_rename with 'INDEX' object type.
 */
final class SQLServerGrammar extends AbstractGrammar
{
    protected string $quoteChar = '[';

    // ── Identifier quoting ────────────────────────────────────────

    public function quoteIdentifier(string $identifier): string
    {
        return '[' . str_replace(']', ']]', $identifier) . ']';
    }

    // ── Table DDL ─────────────────────────────────────────────────

    public function compileCreateMigrationTable(string $tableName): string
    {
        $t = $this->quoteIdentifier($tableName);

        return "IF OBJECT_ID(N'{$tableName}', N'U') IS NULL
CREATE TABLE {$t} (
    [id]         INT IDENTITY(1,1) NOT NULL,
    [migration]  NVARCHAR(255)     NOT NULL,
    [batch]      INT               NOT NULL DEFAULT 1,
    [applied_at] DATETIME2         NOT NULL DEFAULT GETDATE(),
    CONSTRAINT [PK_{$tableName}] PRIMARY KEY CLUSTERED ([id]),
    CONSTRAINT [UQ_{$tableName}_migration] UNIQUE ([migration])
)";
    }

    public function compileRename(string $from, string $to): string
    {
        // SQL Server: sp_rename for tables
        return "EXEC sp_rename '{$from}', '{$to}'";
    }

    public function compileForeignKeyChecksOff(): string
    {
        return "EXEC sp_MSforeachtable 'ALTER TABLE ? NOCHECK CONSTRAINT ALL'";
    }

    public function compileForeignKeyChecksOn(): string
    {
        return "EXEC sp_MSforeachtable 'ALTER TABLE ? WITH CHECK CHECK CONSTRAINT ALL'";
    }

    // ── Column ────────────────────────────────────────────────────

    protected function compileColumn(ColumnDefinition $col): string
    {
        $type = $this->mapType($col->getType(), $col->isAutoIncrement());

        $parts = [
            $this->quoteIdentifier($col->getName()),
            $type,
        ];

        $parts[] = $col->isNullable() ? 'NULL' : 'NOT NULL';

        if ($col->hasDefault() && !$col->isAutoIncrement()) {
            $parts[] = 'DEFAULT ' . $this->wrapDefault($col->getDefault());
        }

        if ($col->isPrimary() && !$col->isAutoIncrement()) {
            $parts[] = 'PRIMARY KEY';
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * T-SQL has no COLUMN keyword in `ALTER TABLE ... ADD`.
     *
     *     ALTER TABLE [users] ADD COLUMN [nickname] NVARCHAR(40)   -- error
     *     ALTER TABLE [users] ADD [nickname] NVARCHAR(40)          -- correct
     *
     * SQL Server answers "Incorrect syntax near the keyword 'COLUMN'", which
     * points at a statement that is valid ANSI SQL on all three other engines.
     */
    protected function addColumnKeyword(): string
    {
        return 'ADD';
    }

    /**
     * SQL Server has no RESTRICT.
     *
     * Its referential actions are NO ACTION, CASCADE, SET NULL and SET DEFAULT
     * only; RESTRICT — the default this engine emits, and ANSI everywhere else
     * — is a syntax error. NO ACTION is the T-SQL equivalent: both refuse the
     * parent-row change when a child row references it. (The ANSI distinction
     * is that RESTRICT checks immediately and NO ACTION may defer to end of
     * statement; SQL Server does not implement deferred constraint checking at
     * all, so nothing is lost in the mapping.)
     */
    protected function mapReferentialAction(string $action): string
    {
        return strcasecmp(trim($action), 'RESTRICT') === 0
            ? 'NO ACTION'
            : $action;
    }

    protected function autoIncrementKeyword(): string
    {
        return ''; // SQL Server uses IDENTITY(1,1) in the type
    }

    // ── MODIFY COLUMN ─────────────────────────────────────────────

    /**
     * SQL Server ALTER COLUMN syntax.
     * Note: does not carry over DEFAULT constraints — those require a separate
     * DROP CONSTRAINT + ADD DEFAULT pattern not covered here.
     */
    protected function compileModifyColumn(string $quotedTable, ColumnDefinition $col): string
    {
        $qcol    = $this->quoteIdentifier($col->getName());
        $newType = $this->mapType($col->getType(), false);
        $null    = $col->isNullable() ? 'NULL' : 'NOT NULL';

        return "ALTER TABLE {$quotedTable} ALTER COLUMN {$qcol} {$newType} {$null}";
    }

    // ── RENAME COLUMN — sp_rename ─────────────────────────────────

    /**
     * SQL Server does not support ANSI RENAME COLUMN syntax.
     * Uses sp_rename with the 'COLUMN' object type instead.
     *
     * @fixed Previous implementation inherited ANSI RENAME COLUMN from
     *        AbstractGrammar which SQL Server does not support.
     *
     * Example output:
     *   EXEC sp_rename '[dbo].[users].[name]', 'full_name', 'COLUMN'
     */
    protected function compileRenameColumn(string $quotedTable, string $from, string $to): string
    {
        // sp_rename expects 'table.column' as the object name (unbracketed for the string literal)
        $rawTable = trim(str_replace(['[', ']'], '', $quotedTable));

        return "EXEC sp_rename '{$rawTable}.{$from}', '{$to}', 'COLUMN'";
    }

    // ── RENAME INDEX — sp_rename ──────────────────────────────────

    /**
     * Rename an index using sp_rename with 'INDEX' object type.
     * Called by compileAlter() when Blueprint::renameIndex() entries are present.
     *
     * Example output:
     *   EXEC sp_rename '[users].[idx_email]', 'idx_email_address', 'INDEX'
     */
    public function compileRenameIndex(string $quotedTable, string $from, string $to): string
    {
        $rawTable = trim(str_replace(['[', ']'], '', $quotedTable));

        return "EXEC sp_rename '{$rawTable}.{$from}', '{$to}', 'INDEX'";
    }

    // ── DROP ──────────────────────────────────────────────────────

    public function compileDropIfExists(string $table): string
    {
        return "IF OBJECT_ID(N'{$table}', N'U') IS NOT NULL DROP TABLE {$this->quoteIdentifier($table)}";
    }

    protected function compileDropIndex(string $quotedTable, string $indexName): string
    {
        return "DROP INDEX {$this->quoteIdentifier($indexName)} ON {$quotedTable}";
    }

    protected function compileDropForeignKey(string $quotedTable, string $fkName): string
    {
        return "ALTER TABLE {$quotedTable} DROP CONSTRAINT {$this->quoteIdentifier($fkName)}";
    }

    // ── Table options ─────────────────────────────────────────────

    protected function compileTableOptions(Blueprint $blueprint): string
    {
        return ''; // SQL Server has no MySQL-style ENGINE / CHARSET options
    }

    // ── Index compilation ─────────────────────────────────────────

    protected function compileIndex(IndexDefinition $idx): string
    {
        $cols = implode(', ', array_map([$this, 'quoteIdentifier'], $idx->getColumns()));
        $name = $this->quoteIdentifier($idx->getName());

        return match ($idx->getType()) {
            'unique'  => "CONSTRAINT {$name} UNIQUE ({$cols})",
            'primary' => "CONSTRAINT {$name} PRIMARY KEY CLUSTERED ({$cols})",
            default   => "INDEX {$name} ({$cols})",
        };
    }

    // ── Type mapping ──────────────────────────────────────────────

    private function mapType(string $type, bool $isIdentity = false): string
    {
        if ($isIdentity) {
            $upper = mb_strtoupper(trim($type));
            return str_contains($upper, 'BIGINT') ? 'BIGINT IDENTITY(1,1)' : 'INT IDENTITY(1,1)';
        }

        $upper = mb_strtoupper(trim($type));

        return match (true) {
            str_starts_with($upper, 'TINYINT(1)')                  => 'BIT',
            str_starts_with($upper, 'TINYINT')                     => 'TINYINT',
            str_starts_with($upper, 'SMALLINT')                    => 'SMALLINT',
            str_starts_with($upper, 'MEDIUMINT'),
            str_starts_with($upper, 'INT')                         => 'INT',
            str_starts_with($upper, 'BIGINT')                      => 'BIGINT',
            str_starts_with($upper, 'FLOAT')                       => 'FLOAT',
            str_starts_with($upper, 'DOUBLE')                      => 'FLOAT(53)',
            str_starts_with($upper, 'DECIMAL'),
            str_starts_with($upper, 'NUMERIC')                     => $type,
            str_starts_with($upper, 'CHAR')                        => str_replace('CHAR', 'NCHAR', $type),
            str_starts_with($upper, 'VARCHAR')                     => str_replace('VARCHAR', 'NVARCHAR', $type),
            str_starts_with($upper, 'TINYTEXT'),
            str_starts_with($upper, 'MEDIUMTEXT'),
            str_starts_with($upper, 'LONGTEXT'),
            str_starts_with($upper, 'TEXT')                        => 'NVARCHAR(MAX)',
            str_starts_with($upper, 'BLOB'),
            str_starts_with($upper, 'BINARY')                      => 'VARBINARY(MAX)',
            str_starts_with($upper, 'JSON')                        => 'NVARCHAR(MAX)',
            str_starts_with($upper, 'ENUM')                        => 'NVARCHAR(255)',
            str_starts_with($upper, 'DATETIME'),
            str_starts_with($upper, 'TIMESTAMP')                   => 'DATETIME2',
            str_starts_with($upper, 'DATE')                        => 'DATE',
            str_starts_with($upper, 'TIME')                        => 'TIME',
            str_starts_with($upper, 'YEAR')                        => 'SMALLINT',
            str_starts_with($upper, 'SET')                         => 'NVARCHAR(255)',
            default                                                => $type,
        };
    }
}
