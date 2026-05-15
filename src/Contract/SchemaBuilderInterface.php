<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

use AlfaCode\LetMigrate\Schema\Blueprint;

/**
 * Fluent schema builder surface exposed to migrations.
 *
 * Migrations receive this interface in their up() / down() methods so they
 * remain driver-agnostic — the concrete builder translates Blueprint calls
 * into the correct DDL for the active driver.
 */
interface SchemaBuilderInterface
{
    /**
     * Create a new table defined by a Blueprint callback.
     *
     * @param callable(Blueprint): void $callback
     */
    public function create(string $table, callable $callback): void;

    /**
     * Modify an existing table.
     *
     * @param callable(Blueprint): void $callback
     */
    public function table(string $table, callable $callback): void;

    /**
     * Drop a table (throws if it does not exist).
     */
    public function drop(string $table): void;

    /**
     * Drop a table only when it exists — safe for rollbacks.
     */
    public function dropIfExists(string $table): void;

    /**
     * Rename a table.
     */
    public function rename(string $from, string $to): void;

    /**
     * Return true when the table exists in the current schema.
     */
    public function hasTable(string $table): bool;

    /**
     * Return true when the column exists on the table.
     */
    public function hasColumn(string $table, string $column): bool;

    /**
     * Execute a raw SQL statement directly through the driver.
     * Use sparingly — prefer Blueprint methods where possible.
     */
    public function raw(string $sql, array $bindings = []): void;

    /**
     * Enable or disable foreign-key constraint checks.
     * Useful when dropping tables that have FK relationships.
     */
    public function disableForeignKeyChecks(): void;

    public function enableForeignKeyChecks(): void;

    /**
     * Return the underlying driver, for advanced use.
     */
    public function getDriver(): DatabaseDriverInterface;
}
