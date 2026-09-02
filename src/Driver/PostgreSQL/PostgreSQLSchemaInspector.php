<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\PostgreSQL;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\SchemaInspectorInterface;
use AlfaCode\LetMigrate\Schema\Inspector\ColumnMeta;
use AlfaCode\LetMigrate\Schema\Inspector\ForeignKeyMeta;
use AlfaCode\LetMigrate\Schema\Inspector\IndexMeta;

/**
 * PostgreSQL schema inspector.
 * Reads information_schema + pg_constraint / pg_indexes.
 */
final class PostgreSQLSchemaInspector implements SchemaInspectorInterface
{
    public function __construct(
        private readonly DatabaseDriverInterface $driver,
        private readonly string $schema = 'public',
    ) {}

    public function getTables(): array
    {
        $rows = $this->driver->fetchAll(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = ? AND table_type = 'BASE TABLE'
             ORDER BY table_name",
            [$this->schema],
        );

        return array_column($rows, 'table_name');
    }

    public function getColumns(string $table): array
    {
        $rows = $this->driver->fetchAll(
            "SELECT
                c.column_name                     AS name,
                c.udt_name                        AS type,
                c.is_nullable                     AS is_nullable,
                c.column_default                  AS default_val,
                c.character_maximum_length        AS length,
                c.numeric_precision               AS precision,
                c.numeric_scale                   AS scale,
                c.ordinal_position                AS position,
                COALESCE(pk.is_pk, false)         AS is_pk
             FROM information_schema.columns c
             LEFT JOIN (
                SELECT kcu.column_name, true AS is_pk
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                  ON tc.constraint_name = kcu.constraint_name
                 AND tc.table_schema    = kcu.table_schema
                WHERE tc.constraint_type = 'PRIMARY KEY'
                  AND tc.table_schema    = ?
                  AND tc.table_name      = ?
             ) pk ON pk.column_name = c.column_name
             WHERE c.table_schema = ? AND c.table_name = ?
             ORDER BY c.ordinal_position",
            // FOUR placeholders, not two. `?` is positional and cannot be
            // reused the way libpq's $1 can, so schema/table are bound twice,
            // in the order the placeholders appear above.
            [$this->schema, $table, $this->schema, $table],
        );

        return array_map(static fn(array $r) => ColumnMeta::fromRow([
            'name'           => $r['name'],
            'type'           => $r['type'],
            'nullable'       => $r['is_nullable'] === 'YES',
            'default'        => $r['default_val'],
            'primary_key'    => (bool) ($r['is_pk'] ?? false),
            'auto_increment' => str_contains((string) ($r['default_val'] ?? ''), 'nextval'),
            'length'         => $r['length'],
            'precision'      => $r['precision'],
            'scale'          => $r['scale'],
            'position'       => (int) $r['position'],
        ]), $rows);
    }

    public function getIndexes(string $table): array
    {
        $rows = $this->driver->fetchAll(
            "SELECT indexname AS name, indexdef AS def
             FROM pg_indexes
             WHERE schemaname = ? AND tablename = ?",
            [$this->schema, $table],
        );

        // Also get primary / unique from pg_constraint for cleaner metadata
        $constraints = $this->driver->fetchAll(
            "SELECT conname AS name, contype AS type,
                    array_to_string(ARRAY(
                        SELECT attname FROM pg_attribute
                        WHERE attrelid = c.conrelid AND attnum = ANY(c.conkey)
                    ), ',') AS cols
             FROM pg_constraint c
             JOIN pg_class t ON t.oid = c.conrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             WHERE n.nspname = ? AND t.relname = ?
               AND c.contype IN ('p','u')",
            [$this->schema, $table],
        );

        $result = [];
        foreach ($constraints as $r) {
            $result[] = IndexMeta::fromRow([
                'name'    => $r['name'],
                'columns' => explode(',', $r['cols']),
                'primary' => $r['type'] === 'p',
                'unique'  => in_array($r['type'], ['p', 'u'], true),
                'type'    => 'BTREE',
            ]);
        }

        return $result;
    }

    public function getForeignKeys(string $table): array
    {
        $rows = $this->driver->fetchAll(
            "SELECT
                c.conname                                AS name,
                a.attname                                AS col,
                ref_t.relname                            AS ref_table,
                ref_a.attname                            AS ref_col,
                CASE c.confdeltype
                    WHEN 'a' THEN 'NO ACTION'
                    WHEN 'r' THEN 'RESTRICT'
                    WHEN 'c' THEN 'CASCADE'
                    WHEN 'n' THEN 'SET NULL'
                    WHEN 'd' THEN 'SET DEFAULT'
                END AS on_delete,
                CASE c.confupdtype
                    WHEN 'a' THEN 'NO ACTION'
                    WHEN 'r' THEN 'RESTRICT'
                    WHEN 'c' THEN 'CASCADE'
                    WHEN 'n' THEN 'SET NULL'
                    WHEN 'd' THEN 'SET DEFAULT'
                END AS on_update
             FROM pg_constraint c
             JOIN pg_class t       ON t.oid = c.conrelid
             JOIN pg_namespace n   ON n.oid = t.relnamespace
             JOIN pg_attribute a   ON a.attrelid = t.oid AND a.attnum = c.conkey[1]
             JOIN pg_class ref_t   ON ref_t.oid = c.confrelid
             JOIN pg_attribute ref_a ON ref_a.attrelid = ref_t.oid AND ref_a.attnum = c.confkey[1]
             WHERE c.contype = 'f' AND n.nspname = ? AND t.relname = ?",
            [$this->schema, $table],
        );

        return array_map(static fn(array $r) => ForeignKeyMeta::fromRow([
            'name'              => $r['name'],
            'column'            => $r['col'],
            'referenced_table'  => $r['ref_table'],
            'referenced_column' => $r['ref_col'],
            'on_delete'         => $r['on_delete'] !== 'NO ACTION' ? $r['on_delete'] : null,
            'on_update'         => $r['on_update'] !== 'NO ACTION' ? $r['on_update'] : null,
        ]), $rows);
    }

    public function tableExists(string $table): bool
    {
        return $this->driver->tableExists($table);
    }

    public function columnExists(string $table, string $column): bool
    {
        return $this->driver->columnExists($table, $column);
    }
}
