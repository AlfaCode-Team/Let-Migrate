<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

use AlfaCode\LetMigrate\Schema\Inspector\ColumnMeta;
use AlfaCode\LetMigrate\Schema\Inspector\ForeignKeyMeta;
use AlfaCode\LetMigrate\Schema\Inspector\IndexMeta;

/**
 * Contract for reading the live structure of a database.
 *
 * Each driver provides its own implementation that queries the appropriate
 * system catalogue (information_schema, sqlite_master, PRAGMA, sys.*).
 *
 * Usage:
 *
 *   $inspector = $engine->inspect();
 *   $tables    = $inspector->getTables();
 *   $columns   = $inspector->getColumns('users');
 *
 * All methods work against the schema / database that the active driver
 * connection is pointed at. Multi-schema support is handled by the driver
 * constructor (e.g. PostgreSQLDriver's $schema parameter).
 */
interface SchemaInspectorInterface
{
    /**
     * Return all user-created table names in the current schema, sorted alphabetically.
     *
     * @return string[]
     */
    public function getTables(): array;

    /**
     * Return metadata for every column in the given table, ordered by position.
     *
     * @return ColumnMeta[]
     *
     * @throws \AlfaCode\LetMigrate\Exception\LetMigrateException when the table does not exist
     */
    public function getColumns(string $table): array;

    /**
     * Return metadata for every index on the given table.
     *
     * @return IndexMeta[]
     */
    public function getIndexes(string $table): array;

    /**
     * Return metadata for every foreign key constraint on the given table.
     *
     * @return ForeignKeyMeta[]
     */
    public function getForeignKeys(string $table): array;

    /**
     * Return true when a table with the given name exists in the current schema.
     */
    public function tableExists(string $table): bool;

    /**
     * Return true when the named column exists in the given table.
     */
    public function columnExists(string $table, string $column): bool;
}
