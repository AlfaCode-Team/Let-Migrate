<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\SchemaInspectorInterface;
use AlfaCode\LetMigrate\Schema\Inspector\ColumnMeta;
use AlfaCode\LetMigrate\Schema\Inspector\ForeignKeyMeta;
use AlfaCode\LetMigrate\Schema\Inspector\IndexMeta;

/**
 * Captures a SchemaInspectorInterface into a normalized, comparable
 * in-memory structure so SchemaDiffer can diff two schemas without
 * touching a database (pure, fully unit-testable).
 *
 * Shape:
 *   [
 *     '<table>' => [
 *        'columns' => ['<col>' => ColumnMeta, …],
 *        'indexes' => ['<idx>' => IndexMeta, …],
 *        'fks'     => ['<fk>'  => ForeignKeyMeta, …],
 *     ],
 *     …
 *   ]
 */
final class SchemaSnapshot
{
    /**
     * @param string[] $skipTables
     * @return array<string, array{
     *     columns: array<string, ColumnMeta>,
     *     indexes: array<string, IndexMeta>,
     *     fks: array<string, ForeignKeyMeta>
     * }>
     */
    public static function capture(
        SchemaInspectorInterface $inspector,
        array $skipTables = ['let_migrations', 'let_seeders'],
    ): array {
        $snapshot = [];

        foreach ($inspector->getTables() as $table) {
            if (in_array($table, $skipTables, true)) {
                continue;
            }

            $columns = [];
            foreach ($inspector->getColumns($table) as $c) {
                $columns[$c->name] = $c;
            }

            $indexes = [];
            foreach ($inspector->getIndexes($table) as $idx) {
                $indexes[$idx->name] = $idx;
            }

            $fks = [];
            foreach ($inspector->getForeignKeys($table) as $fk) {
                $fks[$fk->name] = $fk;
            }

            $snapshot[$table] = [
                'columns' => $columns,
                'indexes' => $indexes,
                'fks'     => $fks,
            ];
        }

        return $snapshot;
    }
}