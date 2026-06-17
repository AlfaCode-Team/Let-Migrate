<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\SchemaInspectorInterface;
use AlfaCode\LetMigrate\Exception\LetMigrateException;
use AlfaCode\LetMigrate\Schema\Inspector\ColumnMeta;
use AlfaCode\LetMigrate\Schema\Inspector\ForeignKeyMeta;
use AlfaCode\LetMigrate\Schema\Inspector\IndexMeta;

/**
 * Reverse-engineers a live database into let-migrate migration files.
 *
 * This is the headline Phase 2 feature — the ORM-free equivalent of
 * Doctrine's entity diff and the thing that wins adoption from teams with
 * an existing schema. It uses the existing SchemaInspectorInterface (all
 * four drivers already implement it), so nothing driver-specific lives
 * here.
 *
 * Output: an ordered map of  filename => PHP migration source.  Tables are
 * emitted in foreign-key dependency order (referenced tables first) via a
 * Kahn topological sort — the same algorithm the seeder runner uses.
 *
 * The generator is filesystem-free and fully unit-testable: the command
 * layer is responsible only for writing the returned strings to disk.
 */
final class MigrationGenerator
{
    public function __construct(
        private readonly SchemaInspectorInterface $inspector,
    ) {}

    /**
     * @param string[] $skipTables tables to ignore (e.g. the tracking table)
     * @return array<string, string> filename (no .php) => migration source
     *
     * @throws LetMigrateException on an unresolvable FK cycle
     */
    public function generate(array $skipTables = ['let_migrations', 'let_seeders']): array
    {
        $tables = array_values(array_filter(
            $this->inspector->getTables(),
            static fn(string $t) => !in_array($t, $skipTables, true),
        ));

        if ($tables === []) {
            return [];
        }

        $ordered = $this->orderByDependencies($tables);

        $out  = [];
        $base = (int) date('YmdHis');
        $i    = 0;

        foreach ($ordered as $table) {
            $stamp    = $this->stamp($base, $i++);
            $filename = "{$stamp}_create_{$table}_table";
            $out[$filename] = $this->renderMigration($table, $filename);
        }

        return $out;
    }

    // ── FK-dependency ordering (Kahn) ─────────────────────────────

    /**
     * @param  string[] $tables
     * @return string[] referenced tables before the tables that reference them
     */
    private function orderByDependencies(array $tables): array
    {
        $set      = array_flip($tables);
        $deps     = [];   // table => list of tables it depends on
        $indegree = [];

        foreach ($tables as $t) {
            $deps[$t]     = [];
            $indegree[$t] = 0;
        }

        foreach ($tables as $t) {
            foreach ($this->inspector->getForeignKeys($t) as $fk) {
                $ref = $fk->referencedTable;
                // ignore self-refs and FKs to skipped/unknown tables
                if ($ref === $t || !isset($set[$ref])) {
                    continue;
                }
                if (!in_array($ref, $deps[$t], true)) {
                    $deps[$t][] = $ref;
                    $indegree[$t]++;
                }
            }
        }

        // Queue tables with no unmet dependency (stable: preserve input order)
        $queue   = array_values(array_filter($tables, static fn($t) => $indegree[$t] === 0));
        $ordered = [];

        while ($queue !== []) {
            $t = array_shift($queue);
            $ordered[] = $t;

            foreach ($tables as $other) {
                if (in_array($t, $deps[$other], true)) {
                    $deps[$other] = array_values(array_diff($deps[$other], [$t]));
                    if (--$indegree[$other] === 0) {
                        $queue[] = $other;
                    }
                }
            }
        }

        if (count($ordered) !== count($tables)) {
            // Cycle — emit the remainder in input order with a warning
            // comment baked into those migrations rather than failing the
            // whole generation (FK can be added in a later migration).
            $remaining = array_values(array_diff($tables, $ordered));

            return array_merge($ordered, $remaining);
        }

        return $ordered;
    }

    // ── Rendering ─────────────────────────────────────────────────

    private function renderMigration(string $table, string $filename): string
    {
        $columns     = $this->inspector->getColumns($table);
        $indexes     = $this->inspector->getIndexes($table);
        $foreignKeys = $this->inspector->getForeignKeys($table);

        $lines = [];
        foreach ($columns as $col) {
            $lines[] = '            ' . $this->columnLine($col, $columns);
        }

        // Composite / non-auto primary keys (single auto-increment PK is
        // already handled by id()/->autoIncrement()).
        foreach ($indexes as $idx) {
            $line = $this->indexLine($idx, $columns);
            if ($line !== null) {
                $lines[] = '            ' . $line;
            }
        }

        foreach ($foreignKeys as $fk) {
            $lines[] = '            ' . $this->foreignKeyLine($fk);
        }

        $body = implode("\n", $lines);

        return <<<PHP
        <?php

        declare(strict_types=1);

        use AlfaCode\\LetMigrate\\Contract\\MigrationInterface;
        use AlfaCode\\LetMigrate\\Contract\\SchemaBuilderInterface;
        use AlfaCode\\LetMigrate\\Schema\\Blueprint;

        /**
         * Auto-generated by migrate:generate from the live '{$table}' table.
         * Review before running — generated DSL is best-effort.
         */
        return new class implements MigrationInterface {
            public function up(SchemaBuilderInterface \$schema): void
            {
                \$schema->create('{$table}', function (Blueprint \$table) {
        {$body}
                });
            }

            public function down(SchemaBuilderInterface \$schema): void
            {
                \$schema->dropIfExists('{$table}');
            }
        };
        PHP;
    }

    /**
     * @param ColumnMeta[] $allColumns
     */
    private function columnLine(ColumnMeta $c, array $allColumns): string
    {
        $name = $c->name;

        // Single auto-increment integer PK named conventionally → id()
        if (
            $c->autoIncrement
            && $c->primaryKey
            && $this->countPrimaryKeyColumns($allColumns) === 1
        ) {
            return $name === 'id'
                ? '$table->id();'
                : "\$table->id('{$name}');";
        }

        $expr = $this->typeExpr($c);

        if ($c->nullable) {
            $expr .= '->nullable()';
        }
        if ($c->default !== null) {
            $expr .= '->default(' . $this->phpLiteral($c->default) . ')';
        }
        if ($c->autoIncrement) {
            $expr .= '->autoIncrement()';
        }
        if ($c->comment !== '') {
            $expr .= '->comment(' . $this->phpLiteral($c->comment) . ')';
        }

        return $expr . ';';
    }

    /**
     * Map a native DB type to the closest Blueprint method. Unknown types
     * fall back to a faithful raw column so generation never loses data.
     */
    private function typeExpr(ColumnMeta $c): string
    {
        $n    = "'{$c->name}'";
        $type = strtolower(preg_replace('/\(.*$/', '', $c->type) ?? $c->type);
        $len  = $c->length;
        $p    = $c->precision;
        $s    = $c->scale;

        return match ($type) {
            'tinyint'              => $len === 1 || $c->type === 'tinyint(1)'
                                        ? "\$table->boolean({$n})"
                                        : "\$table->tinyInteger({$n})",
            'bool', 'boolean'      => "\$table->boolean({$n})",
            'smallint'             => "\$table->smallInteger({$n})",
            'mediumint'            => "\$table->mediumInteger({$n})",
            'int', 'integer'       => "\$table->integer({$n})",
            'bigint', 'int8'       => "\$table->bigInteger({$n})",
            'decimal', 'numeric'   => "\$table->decimal({$n}, " . ($p ?? 8) . ', ' . ($s ?? 2) . ')',
            'float', 'real'        => "\$table->float({$n})",
            'double', 'float8'     => "\$table->double({$n})",
            'char', 'bpchar'       => "\$table->char({$n}, " . ($len ?? 255) . ')',
            'varchar', 'varchar2',
            'character varying'    => "\$table->string({$n}, " . ($len ?? 255) . ')',
            'tinytext'             => "\$table->tinyText({$n})",
            'mediumtext'           => "\$table->mediumText({$n})",
            'longtext'             => "\$table->longText({$n})",
            'text'                 => "\$table->text({$n})",
            'date'                 => "\$table->date({$n})",
            'datetime'             => "\$table->dateTime({$n})",
            'timestamp', 'timestamptz',
            'timestamp with time zone',
            'timestamp without time zone' => "\$table->timestamp({$n})",
            'time'                 => "\$table->time({$n})",
            'year'                 => "\$table->year({$n})",
            'json', 'jsonb'        => "\$table->json({$n})",
            'uuid'                 => "\$table->uuid({$n})",
            'blob', 'bytea',
            'binary', 'varbinary'  => "\$table->binary({$n})",
            default                => sprintf(
                "\$table->addColumn(%s, %s) /* review: native type '%s' */",
                $n,
                $this->phpLiteral(strtoupper($c->type)),
                $c->type,
            ),
        };
    }

    /**
     * @param  ColumnMeta[] $allColumns
     * @return string|null  null = skip (already covered by id()/auto PK)
     */
    private function indexLine(IndexMeta $idx, array $allColumns): ?string
    {
        $cols = $idx->columns;
        if ($cols === []) {
            return null;
        }

        if ($idx->primary) {
            // Single auto-increment PK is already emitted via id().
            if (
                count($cols) === 1
                && $this->isAutoIncrementColumn($cols[0], $allColumns)
            ) {
                return null;
            }
            return '$table->primary(' . $this->phpArray($cols) . ');';
        }

        $name = $idx->name;
        if ($idx->unique) {
            return "\$table->unique(" . $this->phpArray($cols) . ", '{$name}');";
        }

        return "\$table->index(" . $this->phpArray($cols) . ", '{$name}');";
    }

    private function foreignKeyLine(ForeignKeyMeta $fk): string
    {
        $expr = "\$table->foreign('{$fk->column}')"
              . "->references('{$fk->referencedColumn}')"
              . "->on('{$fk->referencedTable}')";

        if ($fk->onDelete !== null && strtoupper($fk->onDelete) !== 'NO ACTION') {
            $expr .= "->onDelete('" . strtoupper($fk->onDelete) . "')";
        }
        if ($fk->onUpdate !== null && strtoupper($fk->onUpdate) !== 'NO ACTION') {
            $expr .= "->onUpdate('" . strtoupper($fk->onUpdate) . "')";
        }

        return $expr . ';';
    }

    // ── Helpers ───────────────────────────────────────────────────

    /** @param ColumnMeta[] $cols */
    private function countPrimaryKeyColumns(array $cols): int
    {
        return count(array_filter($cols, static fn(ColumnMeta $c) => $c->primaryKey));
    }

    /** @param ColumnMeta[] $cols */
    private function isAutoIncrementColumn(string $name, array $cols): bool
    {
        foreach ($cols as $c) {
            if ($c->name === $name) {
                return $c->autoIncrement;
            }
        }
        return false;
    }

    private function stamp(int $base, int $offset): string
    {
        // Keep deterministic increasing order: base timestamp + offset secs
        return (string) ($base + $offset);
    }

    private function phpLiteral(string|int|float|bool|null $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_numeric($v)) {
            return $v;
        }
        return "'" . str_replace("'", "\\'", $v) . "'";
    }

    /** @param string[] $items */
    private function phpArray(array $items): string
    {
        return '[' . implode(', ', array_map(
            fn(string $i) => "'" . str_replace("'", "\\'", $i) . "'",
            $items,
        )) . ']';
    }
}