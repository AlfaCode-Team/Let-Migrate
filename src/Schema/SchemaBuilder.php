<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Contract\SchemaInspectorInterface;

/**
 * Concrete schema builder.
 *
 * Receives a Grammar, a Driver, and optionally a SchemaInspector.
 * Compiles Blueprint → DDL → executes on DB.
 *
 * @updated create() now calls grammar->compilePostCreate() after the table
 *          is created, so PostgreSQL can emit UPDATE triggers for columns
 *          that have onUpdateCurrentTimestamp().
 *
 * @updated hasTable() and hasColumn() now route through SchemaInspectorInterface
 *          when one is available, falling back to the driver for back-compat.
 */
final class SchemaBuilder implements SchemaBuilderInterface
{
    public function __construct(
        private readonly DatabaseDriverInterface      $driver,
        private readonly GrammarInterface             $grammar,
        private readonly SchemaInspectorInterface|null $inspector = null,
    ) {}

    public function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

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
        $blueprint = new Blueprint($table);
        $callback($blueprint);

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
        $this->driver->execute($this->grammar->compileDrop($table));
    }

    public function dropIfExists(string $table): void
    {
        $this->driver->execute($this->grammar->compileDropIfExists($table));
    }

    public function rename(string $from, string $to): void
    {
        $this->driver->execute($this->grammar->compileRename($from, $to));
    }

    /**
     * Check table existence — routes through inspector when available.
     */
    public function hasTable(string $table): bool
    {
        return $this->inspector !== null
            ? $this->inspector->tableExists($table)
            : $this->driver->tableExists($table);
    }

    /**
     * Check column existence — routes through inspector when available.
     */
    public function hasColumn(string $table, string $column): bool
    {
        return $this->inspector !== null
            ? $this->inspector->columnExists($table, $column)
            : $this->driver->columnExists($table, $column);
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

    public function getInspector(): SchemaInspectorInterface|null
    {
        return $this->inspector;
    }
}
