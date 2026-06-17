<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema\Inspector;

/**
 * Immutable metadata for a single database column, returned by SchemaInspectorInterface::getColumns().
 */
final class ColumnMeta
{
    public function __construct(
        /** Column name as stored in the database. */
        public readonly string       $name,

        /** Native database type string (e.g. 'varchar', 'int', 'timestamp'). */
        public readonly string       $type,

        /** Whether the column allows NULL values. */
        public readonly bool         $nullable,

        /** The column's DEFAULT expression as a raw string, or null when none. */
        public readonly string|null  $default,

        /** Whether this column is part of the PRIMARY KEY. */
        public readonly bool         $primaryKey,

        /** Whether this column has an AUTO_INCREMENT / SERIAL / IDENTITY attribute. */
        public readonly bool         $autoIncrement,

        /** Maximum character length (VARCHAR / CHAR); null for non-character types. */
        public readonly int|null     $length,

        /** Numeric precision (DECIMAL / FLOAT); null when not applicable. */
        public readonly int|null     $precision,

        /** Numeric scale (DECIMAL); null when not applicable. */
        public readonly int|null     $scale,

        /** Column ordinal position (1-based) within the table. */
        public readonly int          $position,

        /** Optional COMMENT text; empty string when none. */
        public readonly string       $comment = '',
    ) {}

    /**
     * Build from a raw information_schema row (normalized key names).
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            name:          (string)  ($row['name']           ?? ''),
            type:          (string)  ($row['type']           ?? ''),
            nullable:      (bool)    ($row['nullable']       ?? true),
            default:       isset($row['default']) ? (string) $row['default'] : null,
            primaryKey:    (bool)    ($row['primary_key']    ?? false),
            autoIncrement: (bool)    ($row['auto_increment'] ?? false),
            length:        isset($row['length'])    ? (int) $row['length']    : null,
            precision:     isset($row['precision']) ? (int) $row['precision'] : null,
            scale:         isset($row['scale'])     ? (int) $row['scale']     : null,
            position:      (int)     ($row['position']       ?? 0),
            comment:       (string)  ($row['comment']        ?? ''),
        );
    }
}
