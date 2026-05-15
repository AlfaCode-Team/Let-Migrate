<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Concrete schema builder.
 *
 * Receives a Grammar and a Driver, compiles Blueprint → DDL → executes on DB.
 */
final class SchemaBuilder implements SchemaBuilderInterface
{
    public function __construct(
        private readonly DatabaseDriverInterface $driver,
        private readonly GrammarInterface        $grammar,
    ) {}

    public function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        // Execute the main CREATE TABLE statement
        $this->driver->execute($this->grammar->compileCreate($blueprint));

        // For grammars that cannot inline indexes (e.g. SQLite), execute
        // separate CREATE INDEX statements after the table is created.
        if (method_exists($this->grammar, 'compileIndexStatements')) {
            foreach ($this->grammar->compileIndexStatements($blueprint) as $indexSql) {
                $this->driver->execute($indexSql);
            }
        }
    }

    public function table(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        foreach ($this->grammar->compileAlter($blueprint) as $sql) {
            $this->driver->execute($sql);
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

    public function hasTable(string $table): bool
    {
        return $this->driver->tableExists($table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->driver->columnExists($table, $column);
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
}
