<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\MySQL;

use AlfaCode\LetMigrate\Schema\AbstractGrammar;
use AlfaCode\LetMigrate\Schema\Blueprint;
use AlfaCode\LetMigrate\Schema\ColumnDefinition;

/**
 * MySQL / MariaDB DDL grammar.
 *
 * @fixed compileColumn() — onUpdateCurrentTimestamp() is now emitted as an
 *        inline keyword (`ON UPDATE CURRENT_TIMESTAMP`) instead of baking
 *        a raw string into the default value.
 *
 * @added compileModifyColumn() — MySQL MODIFY COLUMN syntax.
 * @added compileRenameColumn() — MySQL RENAME COLUMN syntax (8.0+).
 */
final class MySQLGrammar extends AbstractGrammar
{
    protected string $quoteChar = '`';

    /**
     * MySQL compileColumn with proper ON UPDATE CURRENT_TIMESTAMP support.
     */
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

        // Emit ON UPDATE CURRENT_TIMESTAMP inline for MySQL — this is where it belongs.
        if ($col->hasOnUpdateCurrentTimestamp()) {
            $parts[] = 'ON UPDATE CURRENT_TIMESTAMP';
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

    /**
     * MySQL MODIFY COLUMN syntax.
     */
    protected function compileModifyColumn(string $quotedTable, ColumnDefinition $col): string
    {
        return "ALTER TABLE {$quotedTable} MODIFY COLUMN " . $this->compileColumn($col);
    }

    /**
     * MySQL RENAME COLUMN syntax (requires MySQL 8.0+ / MariaDB 10.5.2+).
     */
    protected function compileRenameColumn(string $quotedTable, string $from, string $to): string
    {
        return "ALTER TABLE {$quotedTable} RENAME COLUMN "
            . $this->quoteIdentifier($from)
            . ' TO '
            . $this->quoteIdentifier($to);
    }

    protected function autoIncrementKeyword(): string
    {
        return 'AUTO_INCREMENT';
    }
}
