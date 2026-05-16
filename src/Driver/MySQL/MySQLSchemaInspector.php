<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\MySQL;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\SchemaInspectorInterface;
use AlfaCode\LetMigrate\Schema\Inspector\ColumnMeta;
use AlfaCode\LetMigrate\Schema\Inspector\ForeignKeyMeta;
use AlfaCode\LetMigrate\Schema\Inspector\IndexMeta;

/**
 * MySQL / MariaDB schema inspector.
 * Reads information_schema.COLUMNS, STATISTICS, and KEY_COLUMN_USAGE.
 */
final class MySQLSchemaInspector implements SchemaInspectorInterface
{
    public function __construct(
        private readonly DatabaseDriverInterface $driver,
    ) {}

    public function getTables(): array
    {
        $rows = $this->driver->fetchAll(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME",
        );

        return array_column($rows, 'TABLE_NAME');
    }

    public function getColumns(string $table): array
    {
        $rows = $this->driver->fetchAll(
            "SELECT
                COLUMN_NAME           AS name,
                DATA_TYPE             AS type,
                IS_NULLABLE           AS is_nullable,
                COLUMN_DEFAULT        AS `default`,
                COLUMN_KEY            AS col_key,
                EXTRA                 AS extra,
                CHARACTER_MAXIMUM_LENGTH AS length,
                NUMERIC_PRECISION     AS `precision`,
                NUMERIC_SCALE         AS scale,
                ORDINAL_POSITION      AS position,
                COLUMN_COMMENT        AS comment
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION",
            [$table],
        );

        return array_map(static fn(array $r) => ColumnMeta::fromRow([
            'name'           => $r['name'],
            'type'           => $r['type'],
            'nullable'       => $r['is_nullable'] === 'YES',
            'default'        => $r['default'],
            'primary_key'    => $r['col_key'] === 'PRI',
            'auto_increment' => str_contains((string) $r['extra'], 'auto_increment'),
            'length'         => $r['length'],
            'precision'      => $r['precision'],
            'scale'          => $r['scale'],
            'position'       => (int) $r['position'],
            'comment'        => $r['comment'] ?? '',
        ]), $rows);
    }

    public function getIndexes(string $table): array
    {
        $rows = $this->driver->fetchAll(
            "SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, INDEX_TYPE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX",
            [$table],
        );

        // Group columns by index name
        $grouped = [];
        foreach ($rows as $r) {
            $name = $r['INDEX_NAME'];
            $grouped[$name]['columns'][]  = $r['COLUMN_NAME'];
            $grouped[$name]['non_unique'] = (int) $r['NON_UNIQUE'];
            $grouped[$name]['type']       = $r['INDEX_TYPE'] ?? 'BTREE';
        }

        $result = [];
        foreach ($grouped as $name => $data) {
            $result[] = IndexMeta::fromRow([
                'name'    => $name,
                'columns' => $data['columns'],
                'primary' => $name === 'PRIMARY',
                'unique'  => $data['non_unique'] === 0,
                'type'    => $data['type'],
            ]);
        }

        return $result;
    }

    public function getForeignKeys(string $table): array
    {
        $rows = $this->driver->fetchAll(
            "SELECT
                kcu.CONSTRAINT_NAME       AS name,
                kcu.COLUMN_NAME           AS `column`,
                kcu.REFERENCED_TABLE_NAME AS referenced_table,
                kcu.REFERENCED_COLUMN_NAME AS referenced_column,
                rc.DELETE_RULE            AS on_delete,
                rc.UPDATE_RULE            AS on_update
             FROM information_schema.KEY_COLUMN_USAGE kcu
             JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
              AND rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
             WHERE kcu.TABLE_SCHEMA = DATABASE()
               AND kcu.TABLE_NAME = ?
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL",
            [$table],
        );

        return array_map(static fn(array $r) => ForeignKeyMeta::fromRow([
            'name'              => $r['name'],
            'column'            => $r['column'],
            'referenced_table'  => $r['referenced_table'],
            'referenced_column' => $r['referenced_column'],
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
