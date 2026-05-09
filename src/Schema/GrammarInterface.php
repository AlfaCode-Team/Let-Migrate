<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema;

/**
 * Translates a Blueprint into driver-specific DDL strings.
 *
 * Each database driver provides its own Grammar implementation (MySQLGrammar,
 * PostgreSQLGrammar, etc.) that overrides dialect-specific rules.
 */
interface GrammarInterface
{
    /**
     * Compile a CREATE TABLE statement from a Blueprint.
     */
    public function compileCreate(Blueprint $blueprint): string;

    /**
     * Compile one or more ALTER TABLE statements from a Blueprint.
     *
     * @return string[]
     */
    public function compileAlter(Blueprint $blueprint): array;

    /**
     * Compile a DROP TABLE statement.
     */
    public function compileDrop(string $table): string;

    /**
     * Compile a DROP TABLE IF EXISTS statement.
     */
    public function compileDropIfExists(string $table): string;

    /**
     * Compile a RENAME TABLE statement.
     */
    public function compileRename(string $from, string $to): string;

    /**
     * Quote an identifier according to the driver's quoting style.
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Wrap a raw default value expression (e.g. CURRENT_TIMESTAMP) that
     * must not be quoted as a string literal.
     */
    public function wrapDefault(mixed $value): string;

    /**
     * Compile the SQL to create the migration tracking table.
     */
    public function compileCreateMigrationTable(string $tableName): string;

    /**
     * Statement to disable foreign key constraint checks (driver-specific).
     */
    public function compileForeignKeyChecksOff(): string;

    /**
     * Statement to re-enable foreign key constraint checks.
     */
    public function compileForeignKeyChecksOn(): string;
}