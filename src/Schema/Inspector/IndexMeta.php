<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema\Inspector;

/**
 * Immutable metadata for a single database index.
 *
 * S-04: ForeignKeyMeta was previously declared in this same file, which
 * breaks PSR-4 autoloading (one class per file, file name == class name).
 * It now lives in its own file: ForeignKeyMeta.php.
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