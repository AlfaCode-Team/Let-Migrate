<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema\Inspector;

/**
 * Immutable metadata for a single foreign key constraint.
 *
 * S-04: extracted from IndexMeta.php into its own file so Composer's PSR-4
 * autoloader can locate it (class name must match the file name).
 */
final class ForeignKeyMeta
{
    public function __construct(
        /** Constraint name. */
        public readonly string      $name,

        /** Local column holding the foreign key value. */
        public readonly string      $column,

        /** Referenced table name. */
        public readonly string      $referencedTable,

        /** Referenced column name. */
        public readonly string      $referencedColumn,

        /** ON DELETE action (e.g. 'CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION'). */
        public readonly string|null $onDelete,

        /** ON UPDATE action. */
        public readonly string|null $onUpdate,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            name:             (string) ($row['name']              ?? ''),
            column:           (string) ($row['column']            ?? ''),
            referencedTable:  (string) ($row['referenced_table']  ?? ''),
            referencedColumn: (string) ($row['referenced_column'] ?? ''),
            onDelete:         isset($row['on_delete']) ? (string) $row['on_delete'] : null,
            onUpdate:         isset($row['on_update']) ? (string) $row['on_update'] : null,
        );
    }
}