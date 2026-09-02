<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Contract\SchemaInspectorInterface;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\Exception\MigrationException;
use AlfaCode\LetMigrate\Schema\Inspector\ColumnMeta;

/**
 * Concrete schema builder.
 *
 * Receives a Grammar, a Driver, and optionally a SchemaInspector.
 * Compiles Blueprint → DDL → executes on DB.
 *
 * ────────────────────────────────────────────────────────────────────
 * FIX SUMMARY
 * ────────────────────────────────────────────────────────────────────
 * • M-08: added getGrammar() accessor (MigrationService::captureSql()
 *         depends on it).
 * • S-01: table() now detects SQLite + modified columns and uses
 *         compileRecreateTable(), executing each statement individually
 *         instead of feeding a multi-statement string to PDO::exec()
 *         (which silently runs only the first statement and corrupts
 *         the table).
 */
final class SchemaBuilder implements SchemaBuilderInterface
{
    private readonly TablePrefixer $prefixer;

    public function __construct(
        private readonly DatabaseDriverInterface       $driver,
        private readonly GrammarInterface              $grammar,
        private readonly SchemaInspectorInterface|null $inspector = null,
        string                                         $tablePrefix = '',
    ) {
        $this->prefixer = new TablePrefixer($tablePrefix);
    }

    /**
     * Prefix a table name (idempotent) and, for create()/table(), also
     * prefix every foreign-key referenced table so FK targets resolve to
     * the prefixed physical tables.
     */
    private function prefixBlueprintForeignKeys(Blueprint $blueprint): void
    {
        if (!$this->prefixer->isEnabled()) {
            return;
        }
        foreach ($blueprint->getForeignKeys() as $fk) {
            $fk->applyTablePrefix($this->prefixer->getPrefix());
        }
    }

    public function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($this->prefixer->prefix($table));
        $callback($blueprint);
        $this->prefixBlueprintForeignKeys($blueprint);

        // 1. Main CREATE TABLE
        $this->driver->execute($this->grammar->compileCreate($blueprint));

        // 2. Separate CREATE INDEX statements (e.g. SQLite, PostgreSQL regular indexes)
        if (method_exists($this->grammar, 'compileIndexStatements')) {
            foreach ($this->grammar->compileIndexStatements($blueprint) as $indexSql) {
                $this->driver->execute($indexSql);
            }
        }

        // 3. Post-create statements (e.g. PostgreSQL ON UPDATE triggers for updated_at)
        foreach ($this->grammar->compilePostCreate($blueprint) as $postSql) {
            $this->driver->execute($postSql);
        }
    }

    public function table(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($this->prefixer->prefix($table));
        $callback($blueprint);
        $this->prefixBlueprintForeignKeys($blueprint);

        // ── SQLite cannot ALTER/MODIFY a column in place ──
        //
        // SQLite's documented workaround is to rebuild the table: create a new
        // one with the desired shape, copy the rows across, drop the original
        // and rename. That needs the COMPLETE table definition — and an ALTER
        // blueprint holds only the DELTA. Handing the delta straight to
        // compileRecreateTable() compiled `CREATE TABLE "__tmp_users" ()` and
        // died on "near \")\": syntax error", because a modifyColumn-only
        // blueprint has no getColumns() at all.
        //
        // The missing half lives in the database, so the inspector supplies it:
        // read the existing columns and indexes, apply the delta on top, and
        // rebuild from the union. Dropping a table drops its indexes with it,
        // so those are carried across too or they vanish silently.
        if (
            $this->grammar instanceof SQLiteGrammar
            && !empty($blueprint->getModifiedColumns())
        ) {
            $this->recreateSqliteTable($table, $blueprint);

            return;
        }

        // ── Standard ALTER path (MySQL, PostgreSQL, SQL Server) ──
        foreach ($this->grammar->compileAlter($blueprint) as $sql) {
            $this->driver->execute($sql);
        }

        // Post-alter: e.g. new columns with onUpdateCurrentTimestamp on PostgreSQL
        foreach ($this->grammar->compilePostCreate($blueprint) as $postSql) {
            $this->driver->execute($postSql);
        }
    }

    /**
     * Rebuild a SQLite table so a column can be modified.
     *
     * @param string    $table     unprefixed table name (as the caller wrote it)
     * @param Blueprint $delta     the ALTER blueprint — only what changed
     */
    private function recreateSqliteTable(string $table, Blueprint $delta): void
    {
        if ($this->inspector === null) {
            throw new MigrationException(sprintf(
                'Modifying the column(s) %s on "%s" requires rebuilding the table, '
                . 'which needs the existing definition — but this SchemaBuilder was '
                . 'constructed without a SchemaInspector. Pass a SQLiteSchemaInspector, '
                . 'or branch on the driver in the migration.',
                implode(', ', array_map(
                    static fn(ColumnDefinition $c): string => $c->getName(),
                    $delta->getModifiedColumns(),
                )),
                $table,
            ));
        }

        $physical = $this->prefixer->prefix($table);
        $existing = $this->inspector->getColumns($table);

        if ($existing === []) {
            throw new MigrationException(sprintf(
                'Cannot modify a column on "%s": the table reports no columns, so the '
                . 'rebuild SQLite requires has nothing to copy. Does the table exist?',
                $table,
            ));
        }

        $modified = [];
        foreach ($delta->getModifiedColumns() as $col) {
            $modified[$col->getName()] = $col;
        }

        $dropped = array_flip($delta->getDroppedColumns());
        $renamed = $delta->getRenamedColumns();

        $full       = new Blueprint($physical);
        $carryOld   = [];   // names to SELECT from the original table
        $carryNew   = [];   // names to INSERT into the rebuilt one

        foreach ($existing as $meta) {
            if (isset($dropped[$meta->name])) {
                continue;
            }

            $newName = $renamed[$meta->name] ?? $meta->name;

            // A modified column is respelled by the migration; everything else
            // is carried over exactly as the database reports it.
            $full->addColumnDefinition(
                isset($modified[$meta->name])
                    ? $modified[$meta->name]
                    : $this->columnFromMeta($meta, $newName),
            );

            $carryOld[] = $meta->name;
            $carryNew[] = $newName;
        }

        // Columns this same ALTER adds. They have no data to copy, so they stay
        // out of the INSERT column list — a NOT NULL one without a default will
        // be refused by SQLite, which is correct and worth surfacing.
        foreach ($delta->getColumns() as $col) {
            $full->addColumnDefinition($col);
        }

        // Indexes: the ones already on the table, plus any this ALTER declares.
        // Skip SQLite's auto-created ones — they belong to a UNIQUE/PK
        // constraint that the rebuilt CREATE TABLE re-declares itself.
        $keep = array_flip($carryNew);
        foreach ($this->inspector->getIndexes($table) as $idx) {
            if ($idx->primary || str_starts_with($idx->name, 'sqlite_autoindex')) {
                continue;
            }

            $cols = array_values(array_filter(
                array_map(static fn(string $c): string => $renamed[$c] ?? $c, $idx->columns),
                static fn(string $c): bool => isset($keep[$c]),
            ));

            if ($cols === [] || count($cols) !== count($idx->columns)) {
                continue;   // the index lost a column to this ALTER
            }

            $idx->unique
                ? $full->unique($cols, $idx->name)
                : $full->index($cols, $idx->name);
        }

        foreach ($delta->getIndexes() as $idx) {
            $full->addIndexDefinition($idx);
        }

        $statements = $this->grammar->compileRecreateTable($full, $carryOld, $carryNew);

        $this->driver->execute($this->grammar->compileForeignKeyChecksOff());

        try {
            foreach ($statements as $sql) {
                $this->driver->execute($sql);
            }
        } finally {
            $this->driver->execute($this->grammar->compileForeignKeyChecksOn());
        }

        foreach ($this->grammar->compilePostCreate($full) as $postSql) {
            $this->driver->execute($postSql);
        }
    }

    /**
     * Rebuild a ColumnDefinition from what the database reports.
     *
     * The DEFAULT arrives as a raw SQL literal — PRAGMA hands back `'active'`
     * WITH its quotes — so it is unwrapped back to a PHP value here. Passing
     * the literal through untouched would re-quote it on the way out and turn
     * `'active'` into `'\'active\''` on every rebuild.
     */
    private function columnFromMeta(ColumnMeta $meta, string $name): ColumnDefinition
    {
        $col = new ColumnDefinition($name, strtoupper($meta->type) ?: 'TEXT');

        $meta->nullable ? $col->nullable() : $col->notNull();

        if ($meta->primaryKey) {
            $col->primary();
        }

        if ($meta->autoIncrement) {
            $col->autoIncrement();
        }

        if ($meta->default !== null) {
            $col->default($this->unwrapSqlLiteral($meta->default));
        }

        return $col;
    }

    /** `'active'` → `active`, `0` → `0`, `CURRENT_TIMESTAMP` → itself. */
    private function unwrapSqlLiteral(string $literal): mixed
    {
        $trimmed = trim($literal);

        if (strlen($trimmed) >= 2 && str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'")) {
            return str_replace("''", "'", substr($trimmed, 1, -1));
        }

        if (is_numeric($trimmed)) {
            return str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;
        }

        return $trimmed;   // CURRENT_TIMESTAMP and friends — wrapDefault allowlists them
    }

    public function drop(string $table): void
    {
        $this->driver->execute(
            $this->grammar->compileDrop($this->prefixer->prefix($table)),
        );
    }

    public function dropIfExists(string $table): void
    {
        $this->driver->execute(
            $this->grammar->compileDropIfExists($this->prefixer->prefix($table)),
        );
    }

    public function rename(string $from, string $to): void
    {
        $this->driver->execute($this->grammar->compileRename(
            $this->prefixer->prefix($from),
            $this->prefixer->prefix($to),
        ));
    }

    /**
     * Check table existence — routes through inspector when available.
     */
    public function hasTable(string $table): bool
    {
        $t = $this->prefixer->prefix($table);

        return $this->inspector !== null
            ? $this->inspector->tableExists($t)
            : $this->driver->tableExists($t);
    }

    /**
     * Check column existence — routes through inspector when available.
     */
    public function hasColumn(string $table, string $column): bool
    {
        $t = $this->prefixer->prefix($table);

        return $this->inspector !== null
            ? $this->inspector->columnExists($t, $column)
            : $this->driver->columnExists($t, $column);
    }

    public function raw(string $sql, array $bindings = []): void
    {
        $this->driver->execute($sql, $bindings);
    }

    public function disableForeignKeyChecks(): void
    {
        $this->driver->execute($this->grammar->compileForeignKeyChecksOff());
    }

    public function enableForeignKeyChecks(): void
    {
        $this->driver->execute($this->grammar->compileForeignKeyChecksOn());
    }

    public function getDriver(): DatabaseDriverInterface
    {
        return $this->driver;
    }

    /**
     * M-08: expose the grammar so MigrationService::captureSql() can build
     * a CaptureDriver-backed SchemaBuilder with the same grammar.
     */
    public function getGrammar(): GrammarInterface
    {
        return $this->grammar;
    }

    public function getInspector(): SchemaInspectorInterface|null
    {
        return $this->inspector;
    }
}