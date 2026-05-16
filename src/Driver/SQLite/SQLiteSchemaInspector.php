<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\SQLite;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\SchemaInspectorInterface;
use AlfaCode\LetMigrate\Schema\Inspector\ColumnMeta;
use AlfaCode\LetMigrate\Schema\Inspector\ForeignKeyMeta;
use AlfaCode\LetMigrate\Schema\Inspector\IndexMeta;

/**
 * SQLite schema inspector.
 * Reads sqlite_master, PRAGMA table_info, PRAGMA index_list, PRAGMA foreign_key_list.
 */
final class SQLiteSchemaInspector implements SchemaInspectorInterface
{
    public function __construct(
        private readonly DatabaseDriverInterface $driver,
    ) {}

    public function getTables(): array
    {
        $rows = $this->driver->fetchAll(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        );

        return array_column($rows, 'name');
    }

    public function getColumns(string $table): array
    {
        $rows = $this->driver->fetchAll("PRAGMA table_info(\"{$table}\")");

        // Determine which columns are part of PK for composite key detection
        $pkCols = array_column(
            array_filter($rows, static fn($r) => (int) $r['pk'] > 0),
            'name',
        );

        return array_map(static fn(array $r) => ColumnMeta::fromRow([
            'name'           => $r['name'],
            'type'           => strtolower($r['type'] ?? 'text'),
            'nullable'       => !(bool) $r['notnull'],
            'default'        => $r['dflt_value'],
            'primary_key'    => (int) $r['pk'] > 0,
            // SQLite INTEGER PRIMARY KEY is implicitly autoincrement
            'auto_increment' => (int) $r['pk'] > 0 && strtoupper($r['type'] ?? '') === 'INTEGER',
            'length'         => null,
            'precision'      => null,
            'scale'          => null,
            'position'       => (int) $r['cid'] + 1,
        ]), $rows);
    }

    public function getIndexes(string $table): array
    {
        $list = $this->driver->fetchAll("PRAGMA index_list(\"{$table}\")");
        $result = [];

        // Primary key (rowid-based) does not appear in index_list for INTEGER PK
        $pkCols = array_column(
            array_filter(
                $this->driver->fetchAll("PRAGMA table_info(\"{$table}\")"),
                static fn($r) => (int) $r['pk'] > 0,
            ),
            'name',
        );

        if (!empty($pkCols)) {
            $result[] = IndexMeta::fromRow([
                'name'    => 'PRIMARY',
                'columns' => $pkCols,
                'primary' => true,
                'unique'  => true,
                'type'    => 'BTREE',
            ]);
        }

        foreach ($list as $idx) {
            $cols = $this->driver->fetchAll("PRAGMA index_info(\"{$idx['name']}\")");
            $result[] = IndexMeta::fromRow([
                'name'    => $idx['name'],
                'columns' => array_column($cols, 'name'),
                'primary' => (int) ($idx['origin'] ?? 0) === 1 || $idx['name'] === 'PRIMARY',
                'unique'  => (bool) $idx['unique'],
                'type'    => 'BTREE',
            ]);
        }

        return $result;
    }

    public function getForeignKeys(string $table): array
    {
        $rows = $this->driver->fetchAll("PRAGMA foreign_key_list(\"{$table}\")");

        return array_map(static fn(array $r) => ForeignKeyMeta::fromRow([
            'name'              => "fk_{$table}_{$r['from']}",
            'column'            => $r['from'],
            'referenced_table'  => $r['table'],
            'referenced_column' => $r['to'],
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
