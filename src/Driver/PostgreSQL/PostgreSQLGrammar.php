<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\PostgreSQL;

use AlfaCode\LetMigrate\Schema\AbstractGrammar;
use AlfaCode\LetMigrate\Schema\Blueprint;
use AlfaCode\LetMigrate\Schema\ColumnDefinition;

/**
 * PostgreSQL DDL grammar.
 *
 * Key differences from MySQL:
 * - Double-quote identifier quoting
 * - SERIAL / BIGSERIAL for auto-increment (not AUTO_INCREMENT)
 * - No ENGINE / CHARSET / COLLATE table options
 * - DISABLE / ENABLE TRIGGER ALL for FK checks
 * - DROP INDEX is standalone (not ALTER TABLE … DROP INDEX)
 */
final class PostgreSQLGrammar extends AbstractGrammar
{
    protected string $quoteChar = '"';

    public function compileDropIfExists(string $table): string
    {
        return "DROP TABLE IF EXISTS {$this->quoteIdentifier($table)} CASCADE";
    }

    public function compileRename(string $from, string $to): string
    {
        return "ALTER TABLE {$this->quoteIdentifier($from)} RENAME TO {$this->quoteIdentifier($to)}";
    }

    public function compileForeignKeyChecksOff(): string
    {
        return 'SET session_replication_role = replica';
    }

    public function compileForeignKeyChecksOn(): string
    {
        return 'SET session_replication_role = DEFAULT';
    }

    public function compileCreateMigrationTable(string $tableName): string
    {
        $t = $this->quoteIdentifier($tableName);

        return "CREATE TABLE IF NOT EXISTS {$t} (
    \"id\"         SERIAL       NOT NULL,
    \"migration\"  VARCHAR(255) NOT NULL,
    \"batch\"      INTEGER      NOT NULL DEFAULT 1,
    \"applied_at\" TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (\"id\"),
    UNIQUE (\"migration\")
)";
    }

    protected function autoIncrementKeyword(): string
    {
        return ''; // PostgreSQL uses SERIAL / BIGSERIAL types instead
    }

    protected function compileColumn(ColumnDefinition $col): string
    {
        $type = $col->getType();

        // Map MySQL-style types to PostgreSQL equivalents
        if ($col->isAutoIncrement()) {
            $type = str_contains(mb_strtoupper($type), 'BIGINT') ? 'BIGSERIAL' : 'SERIAL';
        } else {
            $type = $this->mapType($type);
        }

        $parts = [
            $this->quoteIdentifier($col->getName()),
            $type,
        ];

        $parts[] = $col->isNullable() ? 'NULL' : 'NOT NULL';

        if ($col->hasDefault() && !$col->isAutoIncrement()) {
            $parts[] = 'DEFAULT ' . $this->wrapDefault($col->getDefault());
        }

        if ($col->getComment() !== '') {
            // Comments are separate in PostgreSQL: COMMENT ON COLUMN; skip inline
        }

        return implode(' ', $parts);
    }

    protected function compileTableOptions(Blueprint $blueprint): string
    {
        return ''; // PostgreSQL has no ENGINE/CHARSET options
    }

    protected function compileDropIndex(string $quotedTable, string $indexName): string
    {
        return "DROP INDEX IF EXISTS \"{$indexName}\"";
    }

    protected function compileDropForeignKey(string $quotedTable, string $fkName): string
    {
        return "ALTER TABLE {$quotedTable} DROP CONSTRAINT \"{$fkName}\"";
    }

    /**
     * Map MySQL type names to PostgreSQL equivalents.
     */
    private function mapType(string $type): string
    {
        $upper = mb_strtoupper(mb_trim($type));

        return match (true) {
            str_starts_with($upper, 'TINYINT(1)') => 'BOOLEAN',
            str_starts_with($upper, 'TINYINT') => 'SMALLINT',
            str_starts_with($upper, 'SMALLINT') => 'SMALLINT',
            str_starts_with($upper, 'INT') => 'INTEGER',
            str_starts_with($upper, 'BIGINT') => 'BIGINT',
            str_starts_with($upper, 'VARCHAR') => str_replace('VARCHAR', 'VARCHAR', $type),
            str_starts_with($upper, 'TINYTEXT') => 'TEXT',
            str_starts_with($upper, 'MEDIUMTEXT') => 'TEXT',
            str_starts_with($upper, 'LONGTEXT') => 'TEXT',
            str_starts_with($upper, 'DATETIME') => 'TIMESTAMP',
            str_starts_with($upper, 'BLOB') => 'BYTEA',
            str_starts_with($upper, 'ENUM') => 'VARCHAR(100)',
            str_starts_with($upper, 'FLOAT') => 'REAL',
            str_starts_with($upper, 'DOUBLE') => 'DOUBLE PRECISION',
            str_starts_with($upper, 'YEAR') => 'SMALLINT',
            str_starts_with($upper, 'JSON') => 'JSONB',
            default => $type,
        };
    }
}
