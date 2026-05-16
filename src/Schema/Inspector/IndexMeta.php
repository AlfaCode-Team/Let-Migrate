<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema\Inspector;

/**
 * Immutable metadata for a single database index.
 */
final class IndexMeta
{
    public function __construct(
        /** Index name as stored in the database. */
        public readonly string $name,

        /** Column names covered by this index, in key sequence order. */
        public readonly array  $columns,

        /** Whether this is the PRIMARY KEY index. */
        public readonly bool   $primary,

        /** Whether the index enforces uniqueness. */
        public readonly bool   $unique,

        /** Index type as reported by the driver (e.g. 'BTREE', 'HASH'). */
        public readonly string $type = 'BTREE',
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            name:    (string) ($row['name']    ?? ''),
            columns: (array)  ($row['columns'] ?? []),
            primary: (bool)   ($row['primary'] ?? false),
            unique:  (bool)   ($row['unique']  ?? false),
            type:    (string) ($row['type']    ?? 'BTREE'),
        );
    }
}

/**
 * Immutable metadata for a single foreign key constraint.
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
            name:             (string)       ($row['name']              ?? ''),
            column:           (string)       ($row['column']            ?? ''),
            referencedTable:  (string)       ($row['referenced_table']  ?? ''),
            referencedColumn: (string)       ($row['referenced_column'] ?? ''),
            onDelete:         isset($row['on_delete']) ? (string) $row['on_delete'] : null,
            onUpdate:         isset($row['on_update']) ? (string) $row['on_update'] : null,
        );
    }
}
