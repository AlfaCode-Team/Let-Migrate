<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\MySQL;

use AlfaCode\LetMigrate\Schema\AbstractGrammar;
use AlfaCode\LetMigrate\Schema\Blueprint;

/**
 * MySQL / MariaDB DDL grammar.
 *
 * Generates MySQL-dialect CREATE TABLE, ALTER TABLE, and supporting DDL.
 * Differences from the base: ENGINE / CHARSET / COLLATE table options,
 * backtick identifier quoting, and AUTO_INCREMENT primary key syntax.
 */
final class MySQLGrammar extends AbstractGrammar
{
    protected string $quoteChar = '`';

    protected function compileTableOptions(Blueprint $blueprint): string
    {
        return sprintf(
            ' ENGINE=%s DEFAULT CHARSET=%s COLLATE=%s',
            $blueprint->getEngine(),
            $blueprint->getCharset(),
            $blueprint->getCollation(),
        );
    }

    public function compileCreateMigrationTable(string $tableName): string
    {
        $t = $this->quoteIdentifier($tableName);

        return "CREATE TABLE IF NOT EXISTS {$t} (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `migration`  VARCHAR(255) NOT NULL,
    `batch`      INT UNSIGNED NOT NULL DEFAULT 1,
    `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_migration` (`migration`)
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

    protected function compileDropIndex(string $quotedTable, string $indexName): string
    {
        return "ALTER TABLE {$quotedTable} DROP INDEX `{$indexName}`";
    }

    protected function compileDropForeignKey(string $quotedTable, string $fkName): string
    {
        return "ALTER TABLE {$quotedTable} DROP FOREIGN KEY `{$fkName}`";
    }
}