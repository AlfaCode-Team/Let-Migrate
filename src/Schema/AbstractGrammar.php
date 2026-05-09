<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema;

/**
 * Shared DDL compilation logic.
 *
 * All types referenced in method signatures (Blueprint, ColumnDefinition,
 * IndexDefinition, ForeignKeyDefinition) share this namespace and require
 * no explicit `use` statements.
 *
 * Driver-specific grammars extend this class and override only the methods
 * that differ (e.g. auto-increment syntax, identifier quoting style).
 */
abstract class AbstractGrammar implements GrammarInterface
{
    protected string $quoteChar = '`';

    // ── GrammarInterface ──────────────────────────────────────────

    public function compileCreate(Blueprint $blueprint): string
    {
        $table   = $this->quoteIdentifier($blueprint->getTable());
        $columns = $this->compileColumns($blueprint);
        $indexes = $this->compileIndexes($blueprint);
        $fks     = $this->compileForeignKeys($blueprint);
        $parts   = array_filter(array_merge($columns, $indexes, $fks));
        $body    = implode(',' . PHP_EOL . '    ', $parts);
        $options = $this->compileTableOptions($blueprint);

        return 'CREATE TABLE ' . $table . ' (' . PHP_EOL . '    ' . $body . PHP_EOL . ')' . $options;
    }

    public function compileAlter(Blueprint $blueprint): array
    {
        $table    = $this->quoteIdentifier($blueprint->getTable());
        $clauses  = [];

        // Dropped columns
        foreach ($blueprint->getDroppedColumns() as $col) {
            $clauses[] = "ALTER TABLE {$table} DROP COLUMN {$this->quoteIdentifier($col)}";
        }

        // Dropped indexes
        foreach ($blueprint->getDroppedIndexes() as $idx) {
            $clauses[] = $this->compileDropIndex($table, $idx);
        }

        // Dropped foreign keys
        foreach ($blueprint->getDroppedForeignKeys() as $fk) {
            $clauses[] = $this->compileDropForeignKey($table, $fk);
        }

        // New columns
        $addClauses = [];
        foreach ($blueprint->getColumns() as $col) {
            $addClauses[] = 'ADD COLUMN ' . $this->compileColumn($col);
        }

        // New indexes
        foreach ($blueprint->getIndexes() as $idx) {
            $addClauses[] = 'ADD ' . $this->compileIndex($idx);
        }

        // New foreign keys
        foreach ($blueprint->getForeignKeys() as $fk) {
            $addClauses[] = 'ADD ' . $this->compileForeignKey($fk);
        }

        if (!empty($addClauses)) {
            $clauses[] = 'ALTER TABLE ' . $table . PHP_EOL . '    ' . implode(',' . PHP_EOL . '    ', $addClauses);
        }

        return $clauses;
    }

    public function compileDrop(string $table): string
    {
        return "DROP TABLE {$this->quoteIdentifier($table)}";
    }

    public function compileDropIfExists(string $table): string
    {
        return "DROP TABLE IF EXISTS {$this->quoteIdentifier($table)}";
    }

    public function compileRename(string $from, string $to): string
    {
        return "ALTER TABLE {$this->quoteIdentifier($from)} RENAME TO {$this->quoteIdentifier($to)}";
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->quoteChar . str_replace($this->quoteChar, $this->quoteChar . $this->quoteChar, $identifier) . $this->quoteChar;
    }

    public function wrapDefault(mixed $value): string
    {
        // Detect raw SQL expressions (CURRENT_TIMESTAMP, NULL, etc.)
        if (is_string($value) && preg_match('/^[A-Z_()]+$/', strtoupper($value))) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_null($value)) {
            return 'NULL';
        }

        return "'" . addslashes((string) $value) . "'";
    }

    public function compileCreateMigrationTable(string $tableName): string
    {
        $t = $this->quoteIdentifier($tableName);

        return "CREATE TABLE IF NOT EXISTS {$t} (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration  VARCHAR(255) NOT NULL,
    batch      INT          NOT NULL DEFAULT 1,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    public function compileForeignKeyChecksOff(): string
    {
        return 'SET FOREIGN_KEY_CHECKS = 0';
    }

    public function compileForeignKeyChecksOn(): string
    {
        return 'SET FOREIGN_KEY_CHECKS = 1';
    }

    // ── Shared helpers ────────────────────────────────────────────

    /** @return string[] */
    protected function compileColumns(Blueprint $blueprint): array
    {
        $cols     = [];
        $hasPkCol = false;

        foreach ($blueprint->getColumns() as $col) {
            $cols[]   = $this->compileColumn($col);
            if ($col->isPrimary() && !$col->isAutoIncrement()) {
                $hasPkCol = true;
            }
        }

        // Add a standalone PRIMARY KEY clause for non-autoincrement primary columns
        foreach ($blueprint->getColumns() as $col) {
            if ($col->isPrimary() && !$col->isAutoIncrement()) {
                $cols[] = 'PRIMARY KEY (' . $this->quoteIdentifier($col->getName()) . ')';
                break;
            }
        }

        return $cols;
    }

    protected function compileColumn(ColumnDefinition $col): string
    {
        $parts = [
            $this->quoteIdentifier($col->getName()),
            $col->getType(),
        ];

        if ($col->isUnsigned()) {
            $parts[] = 'UNSIGNED';
        }

        $parts[] = $col->isNullable() ? 'NULL' : 'NOT NULL';

        if ($col->hasDefault()) {
            $parts[] = 'DEFAULT ' . $this->wrapDefault($col->getDefault());
        }

        if ($col->isAutoIncrement()) {
            $parts[] = $this->autoIncrementKeyword();
        }

        if ($col->getComment() !== '') {
            $parts[] = "COMMENT '" . addslashes($col->getComment()) . "'";
        }

        if ($col->getAfter() !== null) {
            $parts[] = 'AFTER ' . $this->quoteIdentifier($col->getAfter());
        } elseif ($col->isFirst()) {
            $parts[] = 'FIRST';
        }

        return implode(' ', $parts);
    }

    protected function autoIncrementKeyword(): string
    {
        return 'AUTO_INCREMENT';
    }

    /** @return string[] */
    protected function compileIndexes(Blueprint $blueprint): array
    {
        return array_map([$this, 'compileIndex'], $blueprint->getIndexes());
    }

    protected function compileIndex(IndexDefinition $idx): string
    {
        $cols = implode(', ', array_map([$this, 'quoteIdentifier'], $idx->getColumns()));

        return match ($idx->getType()) {
            IndexDefinition::TYPE_PRIMARY => "PRIMARY KEY ({$cols})",
            IndexDefinition::TYPE_UNIQUE  => "UNIQUE KEY {$this->indexName($idx)} ({$cols})",
            default                       => "INDEX {$this->indexName($idx)} ({$cols})",
        };
    }

    protected function indexName(IndexDefinition $idx): string
    {
        if ($idx->getName() !== '') {
            return $this->quoteIdentifier($idx->getName());
        }

        return $this->quoteIdentifier('idx_' . implode('_', $idx->getColumns()));
    }

    /** @return string[] */
    protected function compileForeignKeys(Blueprint $blueprint): array
    {
        return array_map([$this, 'compileForeignKey'], $blueprint->getForeignKeys());
    }

    protected function compileForeignKey(ForeignKeyDefinition $fk): string
    {
        $name       = $fk->getConstraintName() !== ''
            ? $this->quoteIdentifier($fk->getConstraintName())
            : $this->quoteIdentifier('fk_' . $fk->getColumn());
        $col        = $this->quoteIdentifier($fk->getColumn());
        $refTable   = $this->quoteIdentifier($fk->getReferencedTable());
        $refCol     = $this->quoteIdentifier($fk->getReferencedColumn());

        return "CONSTRAINT {$name} FOREIGN KEY ({$col}) REFERENCES {$refTable} ({$refCol})"
             . " ON DELETE {$fk->getOnDelete()}"
             . " ON UPDATE {$fk->getOnUpdate()}";
    }

    protected function compileDropIndex(string $quotedTable, string $indexName): string
    {
        return "ALTER TABLE {$quotedTable} DROP INDEX {$this->quoteIdentifier($indexName)}";
    }

    protected function compileDropForeignKey(string $quotedTable, string $fkName): string
    {
        return "ALTER TABLE {$quotedTable} DROP FOREIGN KEY {$this->quoteIdentifier($fkName)}";
    }

    protected function compileTableOptions(Blueprint $blueprint): string
    {
        return '';
    }
}