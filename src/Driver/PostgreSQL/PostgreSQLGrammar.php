<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Driver\PostgreSQL;

use AlfaCode\LetMigrate\Schema\AbstractGrammar;
use AlfaCode\LetMigrate\Schema\Blueprint;
use AlfaCode\LetMigrate\Schema\ColumnDefinition;
use AlfaCode\LetMigrate\Schema\IndexDefinition;

/**
 * PostgreSQL DDL grammar.
 *
 * Key differences from MySQL:
 * - Double-quote identifier quoting
 * - SERIAL / BIGSERIAL for auto-increment (not AUTO_INCREMENT)
 * - No ENGINE / CHARSET / COLLATE table options
 * - DISABLE / ENABLE TRIGGER ALL for FK checks
 * - DROP INDEX is standalone (not ALTER TABLE … DROP INDEX)
 *
 * @fixed timestamps() — updated_at no longer receives 'ON UPDATE CURRENT_TIMESTAMP'
 *        as a raw default string. compilePostCreate() now emits a proper
 *        CREATE OR REPLACE TRIGGER for every column where hasOnUpdateCurrentTimestamp()
 *        is true, so the grammar is fully PostgreSQL-correct.
 *
 * @added compileModifyColumn() — uses 'ALTER COLUMN ... TYPE' + SET DEFAULT syntax.
 * @added compileRenameColumn() — uses PostgreSQL 'RENAME COLUMN' syntax.
 */
final class PostgreSQLGrammar extends AbstractGrammar
{
    protected string $quoteChar = '"';

    protected bool $supportsDeferrable = true;
    /**
     * PostgreSQL will not accept an integer default on a BOOLEAN column.
     *
     * The base grammar emits a bool default as `1` / `0`, which MySQL accepts
     * (its BOOLEAN is TINYINT(1)) and SQLite accepts (it is dynamically typed).
     * PostgreSQL is strict about it:
     *
     *     ERROR: column "show_phone" is of type boolean but default
     *            expression is of type integer
     *
     * so `$t->boolean('x')->default(true)` — correct, portable, documented use
     * of the fluent API — produced DDL that only failed on this one driver, and
     * only once someone actually ran it against Postgres. Emit the keywords.
     *
     * SQL Server keeps the base behaviour deliberately: its BIT type takes
     * 1 / 0 and rejects TRUE / FALSE.
     */
    public function wrapDefault(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        return parent::wrapDefault($value);
    }

    public function compileDropIfExists(string $table): string
    {
        return "DROP TABLE IF EXISTS {$this->quoteIdentifier($table)} CASCADE";
    }

    public function compileRename(string $from, string $to): string
    {
        return "ALTER TABLE {$this->quoteIdentifier($from)} RENAME TO {$this->quoteIdentifier($to)}";
    }

    public function compileForeignKeyChecksOff(): string
    {
        return 'SET session_replication_role = replica';
    }

    public function compileForeignKeyChecksOn(): string
    {
        return 'SET session_replication_role = DEFAULT';
    }

    public function compileCreateMigrationTable(string $tableName): string
    {
        $t = $this->quoteIdentifier($tableName);

        return "CREATE TABLE IF NOT EXISTS {$t} (
    \"id\"         SERIAL       NOT NULL,
    \"migration\"  VARCHAR(255) NOT NULL,
    \"batch\"      INTEGER      NOT NULL DEFAULT 1,
    \"applied_at\" TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (\"id\"),
    UNIQUE (\"migration\")
)";
    }

    /**
     * Emit CREATE OR REPLACE TRIGGER for every column that has
     * onUpdateCurrentTimestamp(). PostgreSQL has no inline ON UPDATE syntax.
     *
     * For each such column on a given table we:
     *  1. Create a reusable trigger function (named set_{table}_{column}_updated)
     *  2. Attach it as a BEFORE UPDATE trigger.
     *
     * @return string[]
     */
    public function compilePostCreate(Blueprint $blueprint): array
    {
        $statements = [];
        $table = $blueprint->getTable();

        foreach ($blueprint->getColumns() as $col) {
            if (!$col->hasOnUpdateCurrentTimestamp()) {
                continue;
            }

            $colName = $col->getName();
            $fnName = $this->quoteIdentifier("set_{$table}_{$colName}_updated");
            $trgName = $this->quoteIdentifier("trg_{$table}_{$colName}_updated");
            $tbl = $this->quoteIdentifier($table);
            $quotedCol = $this->quoteIdentifier($colName);

            $statements[] = <<<SQL
                CREATE OR REPLACE FUNCTION {$fnName}()
                RETURNS TRIGGER LANGUAGE plpgsql AS $$
                BEGIN
                    NEW.{$quotedCol} = NOW();
                    RETURN NEW;
                END;
                $$
                SQL;

            $statements[] = <<<SQL
                CREATE OR REPLACE TRIGGER {$trgName}
                BEFORE UPDATE ON {$tbl}
                FOR EACH ROW EXECUTE FUNCTION {$fnName}()
                SQL;
        }

        return $statements;
    }

    protected function autoIncrementKeyword(): string
    {
        return ''; // PostgreSQL uses SERIAL / BIGSERIAL types instead
    }

    protected function compileColumn(ColumnDefinition $col): string
    {
        $type = $col->getType();

        if ($col->isAutoIncrement()) {
            $type = str_contains(mb_strtoupper($type), 'BIGINT') ? 'BIGSERIAL' : 'SERIAL';
        } else {
            $type = $this->mapType($type);
        }

        $parts = [
            $this->quoteIdentifier($col->getName()),
            $type,
        ];

        $parts[] = $col->isNullable() ? 'NULL' : 'NOT NULL';

        if ($col->hasDefault() && !$col->isAutoIncrement()) {
            $parts[] = 'DEFAULT ' . $this->wrapDefault($col->getDefault());
        }

        if ($col->getComment() !== '') {
            // PostgreSQL COMMENT is a separate statement; store for post-create
            // (omitted here for brevity — add compileComments() if needed)
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * PostgreSQL MODIFY COLUMN syntax:
     *   ALTER COLUMN col TYPE new_type
     *   ALTER COLUMN col SET DEFAULT ...
     *   ALTER COLUMN col DROP DEFAULT
     *   ALTER COLUMN col SET NOT NULL / DROP NOT NULL
     */
    protected function compileModifyColumn(string $quotedTable, ColumnDefinition $col): string
    {
        $qcol = $this->quoteIdentifier($col->getName());
        $newType = $this->mapType($col->getType());
        $clauses = [];

        $clauses[] = "ALTER TABLE {$quotedTable} ALTER COLUMN {$qcol} TYPE {$newType}";

        if ($col->hasDefault()) {
            $clauses[] = "ALTER TABLE {$quotedTable} ALTER COLUMN {$qcol} SET DEFAULT "
                . $this->wrapDefault($col->getDefault());
        } else {
            $clauses[] = "ALTER TABLE {$quotedTable} ALTER COLUMN {$qcol} DROP DEFAULT";
        }

        $clauses[] = $col->isNullable()
            ? "ALTER TABLE {$quotedTable} ALTER COLUMN {$qcol} DROP NOT NULL"
            : "ALTER TABLE {$quotedTable} ALTER COLUMN {$qcol} SET NOT NULL";

        return implode(";\n", $clauses);
    }

    /**
     * PostgreSQL RENAME COLUMN syntax.
     */
    protected function compileRenameColumn(string $quotedTable, string $from, string $to): string
    {
        return "ALTER TABLE {$quotedTable} RENAME COLUMN "
            . $this->quoteIdentifier($from)
            . ' TO '
            . $this->quoteIdentifier($to);
    }

    /**
     * DROP INDEX is standalone in PostgreSQL (not ALTER TABLE … DROP INDEX).
     */
    protected function compileDropIndex(string $quotedTable, string $indexName): string
    {
        return 'DROP INDEX IF EXISTS ' . $this->quoteIdentifier($indexName);
    }

    protected function compileDropForeignKey(string $quotedTable, string $fkName): string
    {
        return "ALTER TABLE {$quotedTable} DROP CONSTRAINT IF EXISTS " . $this->quoteIdentifier($fkName);
    }

    protected function compileTableOptions(Blueprint $blueprint): string
    {
        return ''; // PostgreSQL has no ENGINE / CHARSET / COLLATE options
    }

    /** @return string[] */
    protected function compileIndexes(Blueprint $blueprint): array
    {
        // PostgreSQL inlines UNIQUE in CREATE TABLE; standalone CREATE INDEX for regular indexes
        $out = [];
        foreach ($blueprint->getIndexes() as $idx) {
            if ($idx->getType() === 'primary') {
                $out[] = $this->compileIndex($idx);
            } elseif ($idx->getType() === 'unique') {
                $out[] = $this->compileIndex($idx);
            }
            // regular indexes emitted as CREATE INDEX after table creation
        }
        return $out;
    }

    /**
     * Return standalone CREATE INDEX statements for regular (non-unique) indexes.
     * Called by SchemaBuilder::create() after the table is created.
     *
     * @return string[]
     */
    public function compileIndexStatements(Blueprint $blueprint): array
    {
        $table = $this->quoteIdentifier($blueprint->getTable());
        $out = [];

        foreach ($blueprint->getIndexes() as $idx) {
            $type = $idx->getType();
            if ($type !== 'index' && $type !== 'fulltext') {
                continue;
            }

            $name = $this->quoteIdentifier($idx->getName());
            $concurrent = $idx->isConcurrent() ? 'CONCURRENTLY ' : '';
            $using = $idx->getUsing();
            $expr = $idx->getExpression();
            $where = $idx->getWhere();

            // FULLTEXT → GIN(to_tsvector(...)) (Phase 1 behaviour preserved)
            if ($type === 'fulltext') {
                $exprCols = implode(" || ' ' || ", array_map(
                    fn($c) => "coalesce(" . $this->quoteIdentifier($c) . ",'')",
                    $idx->getColumns(),
                ));
                $out[] = "CREATE INDEX {$concurrent}{$name} ON {$table} "
                    . "USING GIN (to_tsvector('simple', {$exprCols}))";
                continue;
            }

            // Target: expression index OR column list
            if ($expr !== '') {
                $target = "({$expr})";
            } else {
                $cols = implode(', ', array_map([$this, 'quoteIdentifier'], $idx->getColumns()));
                $target = "({$cols})";
            }

            $usingClause = $using !== '' ? " USING {$using}" : '';
            $whereClause = $where !== '' ? " WHERE {$where}" : '';

            $out[] = "CREATE INDEX {$concurrent}{$name} ON {$table}"
                . $usingClause . ' ' . $target . $whereClause;
        }

        return $out;
    }

    protected function compileIndex(IndexDefinition $idx): string
    {
        $cols = implode(', ', array_map([$this, 'quoteIdentifier'], $idx->getColumns()));
        $name = $this->quoteIdentifier($idx->getName());

        return match ($idx->getType()) {
            'unique' => "UNIQUE ({$cols})",
            'primary' => "PRIMARY KEY ({$cols})",
            'fulltext' => '',           // handled in compileIndexStatements()
            default => "KEY {$name} ({$cols})",
        };
    }

    private function mapType(string $type): string
    {
        $upper = mb_strtoupper(trim($type));

        return match (true) {
            str_starts_with($upper, 'TINYINT(1)') => 'BOOLEAN',
            str_starts_with($upper, 'TINYINT'),
            str_starts_with($upper, 'SMALLINT') => 'SMALLINT',
            str_starts_with($upper, 'MEDIUMINT'),
            str_starts_with($upper, 'INT') => 'INTEGER',
            str_starts_with($upper, 'BIGINT') => 'BIGINT',
            str_starts_with($upper, 'FLOAT'),
            str_starts_with($upper, 'DOUBLE') => 'DOUBLE PRECISION',
            str_starts_with($upper, 'DECIMAL'),
            str_starts_with($upper, 'NUMERIC') => $type, // keep precision/scale
            str_starts_with($upper, 'TINYTEXT'),
            str_starts_with($upper, 'MEDIUMTEXT'),
            str_starts_with($upper, 'LONGTEXT'),
            str_starts_with($upper, 'TEXT') => 'TEXT',
            str_starts_with($upper, 'CHAR') => $type,
            str_starts_with($upper, 'VARCHAR') => $type,
            str_starts_with($upper, 'DATETIME'),
            str_starts_with($upper, 'TIMESTAMP') => 'TIMESTAMP',
            str_starts_with($upper, 'DATE') => 'DATE',
            str_starts_with($upper, 'TIME') => 'TIME',
            str_starts_with($upper, 'YEAR') => 'SMALLINT',
            str_starts_with($upper, 'BLOB'),
            str_starts_with($upper, 'BINARY') => 'BYTEA',
            str_starts_with($upper, 'JSON') => 'JSONB',
            str_starts_with($upper, 'ENUM') => 'TEXT',
            str_starts_with($upper, 'SET') => 'TEXT',
            default => $type,
        };
    }
}
