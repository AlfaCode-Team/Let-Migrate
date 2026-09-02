<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\SQLite;

use AlfaCode\LetMigrate\Exception\MigrationException;
use AlfaCode\LetMigrate\Schema\AbstractGrammar;
use AlfaCode\LetMigrate\Schema\Blueprint;
use AlfaCode\LetMigrate\Schema\ColumnDefinition;

/**
 * SQLite DDL grammar.
 *
 * SQLite has severely limited ALTER TABLE support:
 *  - No MODIFY COLUMN / ALTER COLUMN TYPE
 *  - No DROP COLUMN (before 3.35.0)
 *  - No RENAME COLUMN (before 3.25.0)
 *  - No ADD CONSTRAINT after table creation
 *  - Indexes must be separate CREATE INDEX statements
 *
 * @fixed compileModifyColumn() — overrides AbstractGrammar to use the standard
 *        SQLite recreate-table pattern instead of emitting MODIFY COLUMN which
 *        SQLite does not support. Pattern:
 *          1. CREATE TABLE __tmp_<name> with new column schema
 *          2. INSERT INTO __tmp_<name> SELECT <matching old columns>
 *          3. DROP TABLE <name>
 *          4. ALTER TABLE __tmp_<name> RENAME TO <name>
 *
 * @fixed compileRenameColumn() — uses RENAME COLUMN (SQLite ≥ 3.25.0).
 *
 * @fixed compileDropIndex() — SQLite DROP INDEX is standalone, not ALTER TABLE.
 */
final class SQLiteGrammar extends AbstractGrammar
{
    protected string $quoteChar = '"';

    // ── CREATE TABLE ──────────────────────────────────────────────

    public function compileCreateMigrationTable(string $tableName): string
    {
        $t = $this->quoteIdentifier($tableName);

        return "CREATE TABLE IF NOT EXISTS {$t} (
    \"id\"         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    \"migration\"  TEXT    NOT NULL UNIQUE,
    \"batch\"      INTEGER NOT NULL DEFAULT 1,
    \"applied_at\" TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
)";
    }

    /**
     * SQLite cannot add a FOREIGN KEY to a table that already exists.
     *
     * There is no `ALTER TABLE ... ADD CONSTRAINT` in SQLite at all: a foreign
     * key can only be declared inside `CREATE TABLE`. Compiling one anyway
     * produces `near "FOREIGN": syntax error`, which names the token SQLite
     * choked on and nothing about why — so the reader goes looking for a typo
     * in a statement that is perfectly good ANSI SQL and simply cannot exist
     * here.
     *
     * Rewriting it automatically is not available at this layer. The 12-step
     * rebuild is implemented (compileRecreateTable), but it needs the COMPLETE
     * desired table definition, and an ALTER blueprint holds only the delta —
     * recovering the rest means introspecting the live table, which a grammar,
     * being a pure SQL compiler with no connection, cannot do.
     *
     * So this fails early and says what to do instead. The migration is the
     * thing that has to change: declare the key in the CREATE TABLE that makes
     * the column, or branch on the driver.
     */
    public function compileAlter(Blueprint $blueprint): array
    {
        if ($blueprint->getForeignKeys() !== []) {
            $keys = [];

            foreach ($blueprint->getForeignKeys() as $fk) {
                $keys[] = sprintf('%s -> %s', $fk->getColumn(), $fk->getReferencedTable());
            }

            throw new MigrationException(sprintf(
                'SQLite cannot add a foreign key to the existing table "%s" (%s). '
                . 'Declare it in the CREATE TABLE that creates the column, or skip it for this driver.',
                $blueprint->getTable(),
                implode(', ', $keys),
            ));
        }

        return parent::compileAlter($blueprint);
    }

    public function compileRename(string $from, string $to): string
    {
        return "ALTER TABLE {$this->quoteIdentifier($from)} RENAME TO {$this->quoteIdentifier($to)}";
    }

    public function compileForeignKeyChecksOff(): string
    {
        return 'PRAGMA foreign_keys = OFF';
    }

    public function compileForeignKeyChecksOn(): string
    {
        return 'PRAGMA foreign_keys = ON';
    }

    /**
     * SQLite declares the PK inline as `INTEGER PRIMARY KEY AUTOINCREMENT`,
     * so AbstractGrammar must not add a standalone PRIMARY KEY clause.
     */
    protected function inlinesAutoIncrementPrimaryKey(): bool
    {
        return true;
    }

    // ── Indexes must be separate statements in SQLite ─────────────

    /** @return string[] */
    protected function compileIndexes(Blueprint $blueprint): array
    {
        // Only PRIMARY KEY can be inline in CREATE TABLE for SQLite.
        // UNIQUE and regular indexes are emitted as standalone CREATE [UNIQUE] INDEX.
        $inline = [];
        foreach ($blueprint->getIndexes() as $idx) {
            if ($idx->getType() === 'primary') {
                $cols    = implode(', ', array_map([$this, 'quoteIdentifier'], $idx->getColumns()));
                $inline[] = "PRIMARY KEY ({$cols})";
            }
        }
        return $inline;
    }

    /**
     * Return standalone CREATE INDEX statements for UNIQUE and regular indexes.
     * Called by SchemaBuilder::create() after the table is created.
     *
     * @return string[]
     */
    public function compileIndexStatements(Blueprint $blueprint): array
    {
        $table = $this->quoteIdentifier($blueprint->getTable());
        $out   = [];

        foreach ($blueprint->getIndexes() as $idx) {
            if ($idx->getType() === 'primary') {
                continue; // already inlined
            }

            $cols   = implode(', ', array_map([$this, 'quoteIdentifier'], $idx->getColumns()));
            $name   = $this->quoteIdentifier($idx->getName());
            $unique = $idx->getType() === 'unique' ? 'UNIQUE ' : '';
            $out[]  = "CREATE {$unique}INDEX IF NOT EXISTS {$name} ON {$table} ({$cols})";
        }

        return $out;
    }

    protected function compileTableOptions(Blueprint $blueprint): string
    {
        return ''; // SQLite has no ENGINE / CHARSET / COLLATE options
    }

    // ── MODIFY COLUMN — recreate-table pattern ────────────────────

    /**
     * SQLite does not support MODIFY COLUMN / ALTER COLUMN TYPE.
     *
     * This override implements the standard recreate-table workaround:
     *   1. Create __tmp_<table> with the new column schema
     *   2. INSERT INTO __tmp_<table> SELECT <old columns that still exist>
     *   3. DROP TABLE <table>
     *   4. ALTER TABLE __tmp_<table> RENAME TO <table>
     *
     * The caller (AbstractGrammar::compileAlter) receives a multi-statement
     * string joined by semicolons. SchemaBuilder::table() must execute each
     * statement separately — split on ";\n".
     *
     * NOTE: Foreign key checks must be OFF for this pattern. SchemaBuilder
     * should call disableForeignKeyChecks() before and enableForeignKeyChecks()
     * after the ALTER TABLE session when modifyColumn() is used on SQLite.
     */
    /**
     * A column cannot be modified from the grammar alone.
     *
     * SQLite has no `ALTER TABLE ... MODIFY/ALTER COLUMN`; the documented
     * workaround is to rebuild the table, and a rebuild needs the COMPLETE
     * definition. A grammar is a pure SQL compiler with no connection, and the
     * ALTER blueprint it is handed holds only the DELTA — so the other columns,
     * the ones that must survive, are not knowable here.
     *
     * This used to return a four-statement string joined by ';' that recreated
     * the table with ONLY the modified column: every other column and its data
     * dropped, in a single clause that most drivers would refuse to prepare
     * anyway. Refusing is strictly better than a plausible-looking statement
     * whose effect is data loss.
     *
     * The supported route is SchemaBuilder::table(), which reads the existing
     * definition from a SchemaInspector, applies the delta, and drives
     * compileRecreateTable() with the full picture.
     */
    protected function compileModifyColumn(string $quotedTable, ColumnDefinition $col): string
    {
        throw new MigrationException(sprintf(
            'SQLite cannot modify the column "%s" on %s in place, and rebuilding the table '
            . 'needs its full definition, which a grammar cannot read. Route this through '
            . 'SchemaBuilder::table() with a SchemaInspector, or call compileRecreateTable() '
            . 'with the complete Blueprint.',
            $col->getName(),
            trim($quotedTable, '"'),
        ));
    }

    /**
     * Full recreate-table: use the complete Blueprint to create a new table
     * with all columns (including modified ones), copy all data, drop old, rename.
     *
     * This is the preferred method when SchemaBuilder detects SQLite + modifyColumn.
     * AbstractGrammar::compileAlter() should detect the SQLite grammar and call this
     * instead of the single-column compileModifyColumn().
     *
     * @param Blueprint            $newBlueprint   the full desired table definition
     * @param string[]             $oldColumnNames  column names from the original table (for SELECT)
     * @param string[]             $newColumnNames  column names in the new table (for INSERT)
     *
     * @return string[]  statements to execute in order
     */
    public function compileRecreateTable(
        Blueprint $newBlueprint,
        array     $oldColumnNames,
        array     $newColumnNames,
    ): array {
        $table    = $this->quoteIdentifier($newBlueprint->getTable());
        $rawTable = $newBlueprint->getTable();
        $tmpTable = $this->quoteIdentifier("__tmp_{$rawTable}");

        
        $oldCols = implode(', ', array_map([$this, 'quoteIdentifier'], $oldColumnNames));
        $newCols = implode(', ', array_map([$this, 'quoteIdentifier'], $newColumnNames));

        // Compile full CREATE TABLE for the tmp table using the new blueprint
        $createTmp = $this->compileCreate($newBlueprint);
        $createTmp = str_replace(
            $this->quoteIdentifier($rawTable),
            $tmpTable,
            $createTmp,
        );

        $statements = [
            $createTmp,
            "INSERT INTO {$tmpTable} ({$newCols}) SELECT {$oldCols} FROM {$table}",
            "DROP TABLE {$table}",
            "ALTER TABLE {$tmpTable} RENAME TO {$this->quoteIdentifier($rawTable)}",
        ];

        // Re-create standalone indexes on the renamed table
        foreach ($this->compileIndexStatements($newBlueprint) as $idxSql) {
            $statements[] = $idxSql;
        }

        return $statements;
    }

    // ── RENAME COLUMN (SQLite ≥ 3.25.0) ──────────────────────────

    protected function compileRenameColumn(string $quotedTable, string $from, string $to): string
    {
        return "ALTER TABLE {$quotedTable} RENAME COLUMN "
            . $this->quoteIdentifier($from)
            . ' TO '
            . $this->quoteIdentifier($to);
    }

    // ── DROP INDEX is standalone in SQLite ────────────────────────

    protected function compileDropIndex(string $quotedTable, string $indexName): string
    {
        return 'DROP INDEX IF EXISTS ' . $this->quoteIdentifier($indexName);
    }

    protected function compileDropForeignKey(string $quotedTable, string $fkName): string
    {
        // SQLite cannot drop individual FK constraints — requires full recreate-table.
        // Return a comment so SchemaBuilder can detect and warn the caller.
        return "-- SQLite: cannot drop FK '{$fkName}' without recreating the table";
    }

    // ── Column type mapping ───────────────────────────────────────

    protected function compileColumn(ColumnDefinition $col): string
    {
        $type = $this->mapType($col->getType());

        $parts = [
            $this->quoteIdentifier($col->getName()),
            $type,
        ];

        $parts[] = $col->isNullable() ? '' : 'NOT NULL';

        if ($col->isAutoIncrement() && $col->isPrimary()) {
            // INTEGER PRIMARY KEY is SQLite's implicit rowid alias — AUTOINCREMENT is optional
            // but explicit here for clarity. Declared inline, so
            // inlinesAutoIncrementPrimaryKey() returns true to suppress the
            // standalone clause AbstractGrammar::compileColumns() would add.
            return $this->quoteIdentifier($col->getName()) . ' INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT';
        }

        if ($col->hasDefault()) {
            $parts[] = 'DEFAULT ' . $this->wrapDefault($col->getDefault());
        }

        // NOTE: a standalone "PRIMARY KEY (col)" clause is emitted by
        // AbstractGrammar::compileColumns() for non-autoincrement primary
        // columns. Emitting an inline "PRIMARY KEY" here too would make SQLite
        // reject the table with "more than one primary key".

        if ($col->isUnique()) {
            $parts[] = 'UNIQUE';
        }

        return implode(' ', array_filter($parts));
    }

    private function mapType(string $type): string
    {
        $upper = mb_strtoupper(trim($type));

        // SQLite uses affinity types
        return match (true) {
            str_starts_with($upper, 'TINYINT(1)')                  => 'INTEGER',
            str_starts_with($upper, 'TINYINT'),
            str_starts_with($upper, 'SMALLINT'),
            str_starts_with($upper, 'MEDIUMINT'),
            str_starts_with($upper, 'BIGINT'),
            str_starts_with($upper, 'INT')                         => 'INTEGER',
            str_starts_with($upper, 'FLOAT'),
            str_starts_with($upper, 'DOUBLE'),
            str_starts_with($upper, 'DECIMAL'),
            str_starts_with($upper, 'NUMERIC')                     => 'REAL',
            str_starts_with($upper, 'CHAR'),
            str_starts_with($upper, 'VARCHAR'),
            str_starts_with($upper, 'TINYTEXT'),
            str_starts_with($upper, 'MEDIUMTEXT'),
            str_starts_with($upper, 'LONGTEXT'),
            str_starts_with($upper, 'TEXT'),
            str_starts_with($upper, 'ENUM'),
            str_starts_with($upper, 'JSON'),
            str_starts_with($upper, 'DATETIME'),
            str_starts_with($upper, 'TIMESTAMP'),
            str_starts_with($upper, 'DATE'),
            str_starts_with($upper, 'TIME'),
            str_starts_with($upper, 'YEAR')                        => 'TEXT',
            str_starts_with($upper, 'BLOB'),
            str_starts_with($upper, 'BINARY')                      => 'BLOB',
             str_starts_with($upper, 'SET')                         => 'TEXT',
            default                                                => 'TEXT',
        };
    }
}
