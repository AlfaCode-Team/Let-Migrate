<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\SQLite;

use AlfaCode\LetMigrate\Schema\AbstractGrammar;
use AlfaCode\LetMigrate\Schema\Blueprint;
use AlfaCode\LetMigrate\Schema\ColumnDefinition;
use AlfaCode\LetMigrate\Schema\ForeignKeyDefinition;
use AlfaCode\LetMigrate\Schema\IndexDefinition;

/**
 * SQLite DDL grammar.
 *
 * Key rules:
 * - Indexes (INDEX/UNIQUE) cannot appear inside CREATE TABLE — separate CREATE INDEX statements required
 * - FOREIGN KEY constraints ARE valid inside CREATE TABLE body
 * - INTEGER PRIMARY KEY AUTOINCREMENT handles auto-increment
 * - No ENGINE / CHARSET / COLLATE options
 * - DDL is fully transactional
 */
final class SQLiteGrammar extends AbstractGrammar
{
    protected string $quoteChar = '"';

    public function compileCreate(Blueprint $blueprint): string
    {
        $table = $this->quoteIdentifier($blueprint->getTable());
        $parts = array_filter($this->buildBodyParts($blueprint));
        $body = implode(',' . PHP_EOL . '    ', $parts);

        return 'CREATE TABLE ' . $table . ' (' . PHP_EOL . '    ' . $body . PHP_EOL . ')';
    }

    /**
     * Returns CREATE INDEX statements to run after CREATE TABLE.
     * SchemaBuilder::create() must call this for SQLite.
     *
     * @return string[]
     */
    public function compileIndexStatements(Blueprint $blueprint): array
    {
        $table = $this->quoteIdentifier($blueprint->getTable());
        $stmts = [];

        foreach ($blueprint->getIndexes() as $idx) {
            if ($idx->getType() === IndexDefinition::TYPE_PRIMARY) {
                continue;
            }

            $cols = implode(', ', array_map([$this, 'quoteIdentifier'], $idx->getColumns()));
            $idxName = $idx->getName() !== ''
                ? $this->quoteIdentifier($idx->getName())
                : $this->quoteIdentifier('idx_' . $blueprint->getTable() . '_' . implode('_', $idx->getColumns()));

            $unique = $idx->getType() === IndexDefinition::TYPE_UNIQUE ? 'UNIQUE ' : '';
            $stmts[] = "CREATE {$unique}INDEX IF NOT EXISTS {$idxName} ON {$table} ({$cols})";
        }

        return $stmts;
    }

    public function compileForeignKeyChecksOff(): string
    {
        return 'PRAGMA foreign_keys = OFF';
    }

    public function compileForeignKeyChecksOn(): string
    {
        return 'PRAGMA foreign_keys = ON';
    }

    public function compileCreateMigrationTable(string $tableName): string
    {
        $t = $this->quoteIdentifier($tableName);

        return "CREATE TABLE IF NOT EXISTS {$t} (\n"
             . "    \"id\"         INTEGER  NOT NULL PRIMARY KEY AUTOINCREMENT,\n"
             . "    \"migration\"  TEXT     NOT NULL UNIQUE,\n"
             . "    \"batch\"      INTEGER  NOT NULL DEFAULT 1,\n"
             . "    \"applied_at\" TEXT     NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
             . ')';
    }

    protected function compileColumn(ColumnDefinition $col): string
    {
        $type = $this->mapType($col->getType(), $col->isAutoIncrement());
        $parts = [$this->quoteIdentifier($col->getName()), $type];

        if ($col->isAutoIncrement()) {
            $parts[] = 'PRIMARY KEY AUTOINCREMENT';

            return implode(' ', $parts);
        }

        $parts[] = $col->isNullable() ? 'NULL' : 'NOT NULL';

        if ($col->hasDefault()) {
            $parts[] = 'DEFAULT ' . $this->wrapDefault($col->getDefault());
        }

        if ($col->isUnique()) {
            $parts[] = 'UNIQUE';
        }

        return implode(' ', $parts);
    }

    protected function compileTableOptions(Blueprint $blueprint): string
    {
        return '';
    }

    protected function compileForeignKey(ForeignKeyDefinition $fk): string
    {
        $col = $this->quoteIdentifier($fk->getColumn());
        $refTable = $this->quoteIdentifier($fk->getReferencedTable());
        $refCol = $this->quoteIdentifier($fk->getReferencedColumn());

        return "FOREIGN KEY ({$col}) REFERENCES {$refTable} ({$refCol})"
             . " ON DELETE {$fk->getOnDelete()}"
             . " ON UPDATE {$fk->getOnUpdate()}";
    }

    protected function compileDropIndex(string $quotedTable, string $indexName): string
    {
        return "DROP INDEX IF EXISTS \"{$indexName}\"";
    }

    protected function compileDropForeignKey(string $quotedTable, string $fkName): string
    {
        return "-- SQLite: recreate table to drop foreign key '{$fkName}'";
    }

    /** @return string[] */
    private function buildBodyParts(Blueprint $blueprint): array
    {
        $parts = [];

        foreach ($blueprint->getColumns() as $col) {
            $parts[] = $this->compileColumn($col);
        }

        // Composite PRIMARY KEY from blueprint->primary() calls
        $hasPkAutoInc = false;
        foreach ($blueprint->getColumns() as $col) {
            if ($col->isPrimary() && $col->isAutoIncrement()) {
                $hasPkAutoInc = true;

                break;
            }
        }

        if (!$hasPkAutoInc) {
            // Check blueprint-level composite primary key index
            foreach ($blueprint->getIndexes() as $idx) {
                if ($idx->getType() === IndexDefinition::TYPE_PRIMARY) {
                    $cols = implode(', ', array_map([$this, 'quoteIdentifier'], $idx->getColumns()));
                    $parts[] = "PRIMARY KEY ({$cols})";

                    break;
                }
            }
            // Single-column non-autoincrement PK from column modifier
            foreach ($blueprint->getColumns() as $col) {
                if ($col->isPrimary() && !$col->isAutoIncrement()) {
                    $parts[] = 'PRIMARY KEY (' . $this->quoteIdentifier($col->getName()) . ')';

                    break;
                }
            }
        }

        // FOREIGN KEY constraints are valid inline in SQLite CREATE TABLE
        foreach ($blueprint->getForeignKeys() as $fk) {
            $parts[] = $this->compileForeignKey($fk);
        }

        return $parts;
    }

    private function mapType(string $type, bool $autoInc = false): string
    {
        if ($autoInc) {
            return 'INTEGER';
        }

        $upper = mb_strtoupper(mb_trim($type));

        return match (true) {
            str_starts_with($upper, 'TINYINT'),
            str_starts_with($upper, 'SMALLINT'),
            str_starts_with($upper, 'MEDIUMINT'),
            str_starts_with($upper, 'BIGINT'),
            str_starts_with($upper, 'INT') => 'INTEGER',

            str_starts_with($upper, 'FLOAT'),
            str_starts_with($upper, 'DOUBLE'),
            str_starts_with($upper, 'DECIMAL'),
            str_starts_with($upper, 'NUMERIC') => 'REAL',

            str_starts_with($upper, 'DATETIME'),
            str_starts_with($upper, 'TIMESTAMP'),
            str_starts_with($upper, 'DATE'),
            str_starts_with($upper, 'TIME') => 'TEXT',

            str_starts_with($upper, 'BLOB'),
            str_starts_with($upper, 'BINARY') => 'BLOB',

            default => 'TEXT',
        };
    }
}
