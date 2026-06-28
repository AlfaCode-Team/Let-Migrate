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
 *
 * @fixed wrapDefault() — replaced fragile regex with an explicit allowlist.
 *        The old regex `/^[A-Z_()]+$/` incorrectly treated any all-caps user
 *        string (e.g. 'ADMIN') as a raw SQL expression and skipped quoting it.
 */
abstract class AbstractGrammar implements GrammarInterface
{
    protected string $quoteChar = '`';

    protected bool $supportsDeferrable = false;
    /**
     * Raw SQL expressions that must never be quoted as string literals.
     * Any value NOT in this list is quoted with addslashes().
     */
    private const RAW_EXPRESSIONS = [
        'CURRENT_TIMESTAMP',
        'CURRENT_TIMESTAMP()',
        'CURRENT_DATE',
        'CURRENT_TIME',
        'NOW()',
        'GETDATE()',
        'GETUTCDATE()',
        'SYSDATETIME()',
        'TRUE',
        'FALSE',
        'NULL',
        'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', // kept for MySQL grammar only
    ];

    // ── GrammarInterface ──────────────────────────────────────────

    public function compileCreate(Blueprint $blueprint): string
    {
        $table = $this->quoteIdentifier($blueprint->getTable());
        $columns = $this->compileColumns($blueprint);
        $indexes = $this->compileIndexes($blueprint);
        $fks = $this->compileForeignKeys($blueprint);
        $checks = $this->compileChecks($blueprint);
        $parts = array_filter(array_merge($columns, $indexes, $fks, $checks));
        $body = implode(',' . PHP_EOL . '    ', $parts);
        $options = $this->compileTableOptions($blueprint);

        return 'CREATE TABLE ' . $table . ' (' . PHP_EOL . '    ' . $body . PHP_EOL . ')' . $options;
    }

    public function compileAlter(Blueprint $blueprint): array
    {
        $table = $this->quoteIdentifier($blueprint->getTable());
        $clauses = [];

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

        // Modified columns
        foreach ($blueprint->getModifiedColumns() as $col) {
            $clauses[] = $this->compileModifyColumn($table, $col);
        }

        // Renamed columns
        foreach ($blueprint->getRenamedColumns() as $from => $to) {
            $clauses[] = $this->compileRenameColumn($table, $from, $to);
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
            $clauses[] = 'ALTER TABLE ' . $table . PHP_EOL
                . '    ' . implode(',' . PHP_EOL . '    ', $addClauses);
        }

        $suffix = $this->alterSuffix($blueprint);

        if ($suffix !== '') {
            $clauses = array_map(
                static fn(string $c) => preg_match('/^\s*ALTER TABLE\b/i', $c)
                ? rtrim($c) . $suffix
                : $c,
                $clauses,
            );
        }

        return $clauses;
    }

    public function compileDrop(string $table): string
    {
        return 'DROP TABLE ' . $this->quoteIdentifier($table);
    }

    public function compileDropIfExists(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table);
    }

    public function compileRename(string $from, string $to): string
    {
        return 'RENAME TABLE '
            . $this->quoteIdentifier($from)
            . ' TO '
            . $this->quoteIdentifier($to);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->quoteChar
            . str_replace($this->quoteChar, $this->quoteChar . $this->quoteChar, $identifier)
            . $this->quoteChar;
    }

    /**
     * Wrap a default value for emission in DDL.
     *
     * FIX: Uses an explicit allowlist of safe raw SQL expressions instead of
     * the previous regex `/^[A-Z_()]+$/` which treated any all-caps string
     * (e.g. the literal value 'ADMIN') as a raw expression and skipped quoting.
     */
    public function wrapDefault(mixed $value): string
    {
        if (is_null($value)) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // Only treat a string as a raw SQL expression when it exactly matches
        // a known safe expression. Everything else is quoted as a string literal.
        $upper = mb_strtoupper(trim((string) $value));
        if (in_array($upper, self::RAW_EXPRESSIONS, true)) {
            return (string) $value; // emit as-is
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

    // ── Column modification (default: MySQL/MariaDB syntax) ───────

    /**
     * Compile a MODIFY COLUMN statement.
     * Grammars that use different syntax (PG: ALTER COLUMN ... TYPE) override this.
     */
    protected function compileModifyColumn(string $quotedTable, ColumnDefinition $col): string
    {
        return "ALTER TABLE {$quotedTable} MODIFY COLUMN " . $this->compileColumn($col);
    }

    /**
     * Compile a RENAME COLUMN statement.
     * Grammars that use different syntax override this.
     */
    protected function compileRenameColumn(string $quotedTable, string $from, string $to): string
    {
        return "ALTER TABLE {$quotedTable} RENAME COLUMN "
            . $this->quoteIdentifier($from)
            . ' TO '
            . $this->quoteIdentifier($to);
    }

    // ── Post-create statements (e.g. triggers for onUpdateCurrentTimestamp) ──

    /**
     * Return additional SQL statements to execute after CREATE TABLE.
     *
     * Grammars that need post-create work (e.g. PostgreSQL triggers for
     * updated_at columns) override this method.
     *
     * @return string[]
     */
    public function compilePostCreate(Blueprint $blueprint): array
    {
        return [];
    }

    // ── CHECK constraints ─────────────────────────────────────────

    /**
     * Compile table-level CHECK constraints for the CREATE TABLE body.
     * Supported inline by every grammar this engine ships (MySQL 8.0.16+,
     * MariaDB 10.2+, PostgreSQL, SQLite, SQL Server).
     *
     * @return string[]
     */
    protected function compileChecks(Blueprint $blueprint): array
    {
        $clauses = [];

        foreach ($blueprint->getChecks() as $check) {
            $constraint = $check['name'] !== ''
                ? 'CONSTRAINT ' . $this->quoteIdentifier($check['name']) . ' '
                : '';
            $clauses[] = $constraint . 'CHECK (' . $check['expression'] . ')';
        }

        return $clauses;
    }

    // ── Table options ─────────────────────────────────────────────

    protected function compileTableOptions(Blueprint $blueprint): string
    {
        $options = [];

        if ($blueprint->getEngine() !== '') {
            $options[] = 'ENGINE=' . $blueprint->getEngine();
        }
        if ($blueprint->getCharset() !== '') {
            $options[] = 'DEFAULT CHARSET=' . $blueprint->getCharset();
        }
        if ($blueprint->getCollation() !== '') {
            $options[] = 'COLLATE=' . $blueprint->getCollation();
        }
        if ($blueprint->getRowFormat() !== '') {
            $options[] = 'ROW_FORMAT=' . $blueprint->getRowFormat();
        }
        if ($blueprint->getComment() !== '') {
            $options[] = "COMMENT='" . addslashes($blueprint->getComment()) . "'";
        }

        return empty($options) ? '' : ' ' . implode(' ', $options);
    }
    /**
     * Optional suffix appended to each ALTER TABLE statement (e.g. MySQL
     * ', ALGORITHM=INSTANT, LOCK=NONE'). Default: none. Overridden by
     * grammars that support online-DDL options.
     */
    protected function alterSuffix(Blueprint $blueprint): string
    {
        return '';
    }

    // ── Shared helpers ────────────────────────────────────────────

    /** @return string[] */
    protected function compileColumns(Blueprint $blueprint): array
    {
        $cols = [];

        foreach ($blueprint->getColumns() as $col) {
            $cols[] = $this->compileColumn($col);
        }

        // Standalone PRIMARY KEY clause for column-level primary keys.
        // An auto-increment primary column needs the explicit clause on MySQL,
        // PostgreSQL (SERIAL) and SQL Server (IDENTITY) — none of which imply a
        // PK from the auto-increment keyword. Grammars that inline the PK on the
        // column itself (SQLite: `INTEGER PRIMARY KEY AUTOINCREMENT`) report so
        // via inlinesAutoIncrementPrimaryKey() and are skipped here.
        foreach ($blueprint->getColumns() as $col) {
            if (!$col->isPrimary()) {
                continue;
            }
            if ($col->isAutoIncrement() && $this->inlinesAutoIncrementPrimaryKey()) {
                break;
            }
            $cols[] = 'PRIMARY KEY (' . $this->quoteIdentifier($col->getName()) . ')';
            break;
        }

        return $cols;
    }

    /**
     * Whether this grammar declares the PRIMARY KEY inline on an auto-increment
     * column definition (so compileColumns() must NOT add a standalone clause).
     * Default false (MySQL / PostgreSQL / SQL Server need the explicit clause);
     * SQLite overrides to true.
     */
    protected function inlinesAutoIncrementPrimaryKey(): bool
    {
        return false;
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
        }

        return implode(' ', array_filter($parts));
    }

    protected function autoIncrementKeyword(): string
    {
        return 'AUTO_INCREMENT';
    }

    /** @return string[] */
    protected function compileIndexes(Blueprint $blueprint): array
    {
        $out = [];
        foreach ($blueprint->getIndexes() as $idx) {
            $out[] = $this->compileIndex($idx);
        }
        return $out;
    }

    protected function compileIndex(IndexDefinition $idx): string
    {
        $cols = implode(', ', array_map([$this, 'quoteIdentifier'], $idx->getColumns()));
        $name = $this->quoteIdentifier($idx->getName());

        return match ($idx->getType()) {
            'unique' => "UNIQUE KEY {$name} ({$cols})",
            'primary' => "PRIMARY KEY ({$cols})",
            'fulltext' => "FULLTEXT KEY {$name} ({$cols})",
            default => "KEY {$name} ({$cols})",
        };
    }

    /** @return string[] */
    protected function compileForeignKeys(Blueprint $blueprint): array
    {
        $out = [];
        foreach ($blueprint->getForeignKeys() as $fk) {
            $out[] = $this->compileForeignKey($fk);
        }
        return $out;
    }

    protected function compileForeignKey(ForeignKeyDefinition $fk): string
    {
        $col = $this->quoteIdentifier($fk->getColumn());
        $ref = $this->quoteIdentifier($fk->getReferencedColumn());
        $on = $this->quoteIdentifier($fk->getReferencedTable());
        $name = $fk->getConstraintName() !== ''
            ? 'CONSTRAINT ' . $this->quoteIdentifier($fk->getConstraintName()) . ' '
            : '';

        $sql = "{$name}FOREIGN KEY ({$col}) REFERENCES {$on} ({$ref})";

        if ($fk->getOnDelete() !== '') {
            $sql .= ' ON DELETE ' . $fk->getOnDelete();
        }
        if ($fk->getOnUpdate() !== '') {
            $sql .= ' ON UPDATE ' . $fk->getOnUpdate();
        }

        // at the END of compileForeignKey(), before `return $sql;`
if ($this->supportsDeferrable && $fk->isDeferrable()) {
    $sql .= ' DEFERRABLE';
    $sql .= $fk->isInitiallyDeferred()
        ? ' INITIALLY DEFERRED'
        : ' INITIALLY IMMEDIATE';
}
        return $sql;
    }

    protected function compileDropIndex(string $quotedTable, string $indexName): string
    {
        return "ALTER TABLE {$quotedTable} DROP INDEX " . $this->quoteIdentifier($indexName);
    }

    protected function compileDropForeignKey(string $quotedTable, string $fkName): string
    {
        return "ALTER TABLE {$quotedTable} DROP FOREIGN KEY " . $this->quoteIdentifier($fkName);
    }
}
