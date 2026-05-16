<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\SQLServer;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\SchemaInspectorInterface;
use AlfaCode\LetMigrate\Schema\Inspector\ColumnMeta;
use AlfaCode\LetMigrate\Schema\Inspector\ForeignKeyMeta;
use AlfaCode\LetMigrate\Schema\Inspector\IndexMeta;

/**
 * SQL Server schema inspector.
 * Reads INFORMATION_SCHEMA + sys.foreign_keys + sys.indexes.
 */
final class SQLServerSchemaInspector implements SchemaInspectorInterface
{
    public function __construct(
        private readonly DatabaseDriverInterface $driver,
        private readonly string $schema = 'dbo',
    ) {}

    public function getTables(): array
    {
        $rows = $this->driver->fetchAll(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME",
            [$this->schema],
        );

        return array_column($rows, 'TABLE_NAME');
    }

    public function getColumns(string $table): array
    {
        $rows = $this->driver->fetchAll(
            "SELECT
                c.COLUMN_NAME                     AS name,
                c.DATA_TYPE                       AS type,
                c.IS_NULLABLE                     AS is_nullable,
                c.COLUMN_DEFAULT                  AS default_val,
                c.CHARACTER_MAXIMUM_LENGTH        AS length,
                c.NUMERIC_PRECISION               AS precision,
                c.NUMERIC_SCALE                   AS scale,
                c.ORDINAL_POSITION                AS position,
                COLUMNPROPERTY(
                    OBJECT_ID(c.TABLE_SCHEMA + '.' + c.TABLE_NAME),
                    c.COLUMN_NAME, 'IsIdentity'
                )                                 AS is_identity,
                CASE WHEN pk.COLUMN_NAME IS NOT NULL THEN 1 ELSE 0 END AS is_pk
             FROM INFORMATION_SCHEMA.COLUMNS c
             LEFT JOIN (
                 SELECT kcu.COLUMN_NAME
                 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                 JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                   ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                  AND tc.TABLE_SCHEMA    = kcu.TABLE_SCHEMA
                 WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
                   AND tc.TABLE_SCHEMA   = ?
                   AND tc.TABLE_NAME     = ?
             ) pk ON pk.COLUMN_NAME = c.COLUMN_NAME
             WHERE c.TABLE_SCHEMA = ? AND c.TABLE_NAME = ?
             ORDER BY c.ORDINAL_POSITION",
            [$this->schema, $table, $this->schema, $table],
        );

        return array_map(static fn(array $r) => ColumnMeta::fromRow([
            'name'           => $r['name'],
            'type'           => $r['type'],
            'nullable'       => $r['is_nullable'] === 'YES',
            'default'        => $r['default_val'],
            'primary_key'    => (bool) $r['is_pk'],
            'auto_increment' => (bool) $r['is_identity'],
            'length'         => $r['length'],
            'precision'      => $r['precision'],
            'scale'          => $r['scale'],
            'position'       => (int) $r['position'],
        ]), $rows);
    }

    public function getIndexes(string $table): array
    {
        $rows = $this->driver->fetchAll(
            "SELECT
                i.name                  AS idx_name,
                i.is_primary_key        AS is_pk,
                i.is_unique             AS is_unique,
                i.type_desc             AS idx_type,
                c.name                  AS col_name
             FROM sys.indexes i
             JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
             JOIN sys.columns c        ON c.object_id  = i.object_id AND c.column_id = ic.column_id
             JOIN sys.tables t         ON t.object_id  = i.object_id
             JOIN sys.schemas s        ON s.schema_id  = t.schema_id
             WHERE s.name = ? AND t.name = ? AND i.name IS NOT NULL
             ORDER BY i.name, ic.key_ordinal",
            [$this->schema, $table],
        );

        $grouped = [];
        foreach ($rows as $r) {
            $name = $r['idx_name'];
            $grouped[$name]['columns'][]  = $r['col_name'];
            $grouped[$name]['is_pk']      = (bool) $r['is_pk'];
            $grouped[$name]['is_unique']  = (bool) $r['is_unique'];
            $grouped[$name]['type']       = $r['idx_type'] ?? 'NONCLUSTERED';
        }

        $result = [];
        foreach ($grouped as $name => $data) {
            $result[] = IndexMeta::fromRow([
                'name'    => $name,
                'columns' => $data['columns'],
                'primary' => $data['is_pk'],
                'unique'  => $data['is_unique'],
                'type'    => $data['type'],
            ]);
        }

        return $result;
    }

    public function getForeignKeys(string $table): array
    {
        $rows = $this->driver->fetchAll(
            "SELECT
                fk.name                         AS fk_name,
                COL_NAME(fkc.parent_object_id,
                         fkc.parent_column_id)  AS col,
                OBJECT_NAME(fk.referenced_object_id) AS ref_table,
                COL_NAME(fkc.referenced_object_id,
                         fkc.referenced_column_id)   AS ref_col,
                fk.delete_referential_action_desc AS on_delete,
                fk.update_referential_action_desc AS on_update
             FROM sys.foreign_keys fk
             JOIN sys.foreign_key_columns fkc
               ON fkc.constraint_object_id = fk.object_id
             JOIN sys.tables t ON t.object_id = fk.parent_object_id
             JOIN sys.schemas s ON s.schema_id = t.schema_id
             WHERE s.name = ? AND t.name = ?",
            [$this->schema, $table],
        );

        return array_map(static fn(array $r) => ForeignKeyMeta::fromRow([
            'name'              => $r['fk_name'],
            'column'            => $r['col'],
            'referenced_table'  => $r['ref_table'],
            'referenced_column' => $r['ref_col'],
            'on_delete'         => $r['on_delete'] !== 'NO_ACTION' ? str_replace('_', ' ', $r['on_delete']) : null,
            'on_update'         => $r['on_update'] !== 'NO_ACTION' ? str_replace('_', ' ', $r['on_update']) : null,
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
