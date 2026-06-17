<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Contract\SchemaInspectorInterface;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;

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

        // ── S-01: SQLite cannot ALTER/MODIFY a column in place ──
        // When the blueprint contains modified columns and we are on
        // SQLite, use the full recreate-table workaround and execute
        // each statement on its own — never as a joined multi-statement
        // string (PDO::exec() would run only the first statement).
        if (
            $this->grammar instanceof SQLiteGrammar
            && method_exists($blueprint, 'getModifiedColumns')
            && !empty($blueprint->getModifiedColumns())
        ) {
            $existing  = $this->inspector?->getColumns($table) ?? [];
            $oldNames  = array_map(
                static fn($c) => is_object($c) ? $c->name : (string) $c,
                $existing,
            );
            $newNames  = array_map(
                static fn($c) => $c->getName(),
                $blueprint->getColumns(),
            );

            // Columns that survive into the new table = intersection of
            // old names and new names, preserving the new table order.
            $carryNames = array_values(array_intersect($newNames, $oldNames));
            if ($carryNames === []) {
                // Fall back to copying by the new column list when no
                // inspector is available to tell us the old columns.
                $carryNames = $newNames;
            }

            $statements = $this->grammar->compileRecreateTable(
                $blueprint,
                $carryNames,
                $carryNames,
            );

            $this->driver->execute($this->grammar->compileForeignKeyChecksOff());

            try {
                foreach ($statements as $sql) {
                    $this->driver->execute($sql);
                }
            } finally {
                $this->driver->execute($this->grammar->compileForeignKeyChecksOn());
            }

            // Post-alter statements still apply (e.g. triggers).
            foreach ($this->grammar->compilePostCreate($blueprint) as $postSql) {
                $this->driver->execute($postSql);
            }

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