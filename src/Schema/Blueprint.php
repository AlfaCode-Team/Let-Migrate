<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema;

/**
 * Fluent table blueprint.
 *
 * Migrations use this object to define columns, indexes, and constraints in a
 * driver-agnostic way. The SchemaBuilder translates it into the correct DDL.
 *
 * Usage inside a migration:
 *
 *   $schema->create('users', static function (Blueprint $t): void {
 *       $t->id();
 *       $t->string('email', 191)->unique()->notNull();
 *       $t->string('password');
 *       $t->boolean('is_active')->default(true);
 *       $t->timestamps();
 *   });
 *
 * @fixed timestamps() — no longer inlines 'ON UPDATE CURRENT_TIMESTAMP' as a raw
 *        default string. Uses ColumnDefinition::onUpdateCurrentTimestamp() instead
 *        so each Grammar can emit the correct dialect-specific DDL (MySQL inline
 *        keyword vs PostgreSQL trigger).
 *
 * @added modifyColumn() — modify an existing column's type/constraints in ALTER mode.
 * @added renameColumn() — rename a column across all four drivers.
 */
final class Blueprint
{
    /** @var ColumnDefinition[] */
    private array $columns = [];

    /** @var ColumnDefinition[] */
    private array $modifiedColumns = [];

    /** @var array<string, string> from => to */
    private array $renamedColumns = [];

    /** @var IndexDefinition[] */
    private array $indexes = [];

    /** @var ForeignKeyDefinition[] */
    private array $foreignKeys = [];

    /** @var string[] */
    private array $droppedColumns = [];

    /** @var string[] */
    private array $droppedIndexes = [];

    /** @var string[] */
    private array $droppedForeignKeys = [];

    private string $engine    = 'InnoDB';
    private string $charset   = 'utf8mb4';
    private string $collation = 'utf8mb4_unicode_ci';

    public function __construct(private readonly string $table) {}

    // ── Convenience: auto-incrementing primary key ─────────────────

    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->bigInteger($name)->unsigned()->autoIncrement()->primary();
    }

    public function uuid(string $name = 'id'): ColumnDefinition
    {
        return $this->char($name, 36)->primary();
    }

    // ── Numeric types ──────────────────────────────────────────────

    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'TINYINT');
    }

    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'SMALLINT');
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'INT');
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'BIGINT');
    }

    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn($name, "DECIMAL({$precision},{$scale})");
    }

    public function float(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn($name, "FLOAT({$precision},{$scale})");
    }

    public function double(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'DOUBLE');
    }

    // ── String types ───────────────────────────────────────────────

    public function char(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn($name, "CHAR({$length})");
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn($name, "VARCHAR({$length})");
    }

    public function tinyText(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'TINYTEXT');
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'TEXT');
    }

    public function mediumText(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'MEDIUMTEXT');
    }

    public function longText(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'LONGTEXT');
    }

    // ── Date / time types ─────────────────────────────────────────

    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'DATE');
    }

    public function time(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'TIME');
    }

    public function dateTime(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'DATETIME');
    }

    public function timestamp(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'TIMESTAMP');
    }

    public function year(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'YEAR');
    }

    /**
     * Add `created_at` and `updated_at` columns.
     *
     * FIX: updated_at no longer embeds 'ON UPDATE CURRENT_TIMESTAMP' as a raw
     * default string. Uses onUpdateCurrentTimestamp() modifier so each Grammar
     * emits the correct dialect (MySQL inline vs PostgreSQL trigger).
     */
    public function timestamps(): void
    {
        $this->addColumn('created_at', 'DATETIME')
            ->nullable()
            ->default('CURRENT_TIMESTAMP');

        $this->addColumn('updated_at', 'DATETIME')
            ->nullable()
            ->default('CURRENT_TIMESTAMP')
            ->onUpdateCurrentTimestamp();
    }

    /**
     * Add a `deleted_at` DATETIME column (soft-delete support).
     */
    public function softDeletes(string $column = 'deleted_at'): void
    {
        $this->addColumn($column, 'DATETIME')->nullable();
    }

    // ── Boolean ────────────────────────────────────────────────────

    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'TINYINT(1)');
    }

    // ── JSON / Binary ──────────────────────────────────────────────

    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'JSON');
    }

    public function binary(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'BLOB');
    }

    // ── Enum ───────────────────────────────────────────────────────

    /** @param string[] $allowed */
    public function enum(string $name, array $allowed): ColumnDefinition
    {
        $values = implode(',', array_map(
            static fn(string $v) => "'{$v}'",
            $allowed,
        ));

        return $this->addColumn($name, "ENUM({$values})");
    }

    // ── Indexes ────────────────────────────────────────────────────

    /** @param string[] $columns */
    public function primary(array $columns, string $name = ''): void
    {
        $this->indexes[] = new IndexDefinition(
            $columns,
            $name ?: 'PRIMARY',
            'primary',
        );
    }

    /** @param string[] $columns */
    public function unique(array $columns, string $name = ''): void
    {
        $this->indexes[] = new IndexDefinition(
            $columns,
            $name ?: 'uq_' . implode('_', $columns),
            'unique',
        );
    }

    /** @param string[] $columns */
    public function index(array $columns, string $name = ''): void
    {
        $this->indexes[] = new IndexDefinition(
            $columns,
            $name ?: 'idx_' . implode('_', $columns),
            'index',
        );
    }

    // ── Foreign keys ───────────────────────────────────────────────

    public function foreign(string $column): ForeignKeyDefinition
    {
        $fk = new ForeignKeyDefinition($column);
        $this->foreignKeys[] = $fk;

        return $fk;
    }

    // ── ALTER: column modification (Tier 1 addition) ──────────────

    /**
     * Modify an existing column's definition.
     *
     * Usage (in a $schema->table() callback):
     *
     *   $t->modifyColumn('email', fn(ColumnDefinition $c) =>
     *       $c->string(320)->unique()->notNull()
     *   );
     *
     * The callback receives a fresh ColumnDefinition pre-populated with the
     * column name. Callers must re-specify all desired attributes (type,
     * nullability, default, etc.) because grammars emit the full column DDL.
     *
     * Note: SQLite does not support MODIFY COLUMN. The SQLiteGrammar will
     * implement the recreate-table pattern automatically.
     *
     * @param callable(ColumnDefinition): ColumnDefinition $callback
     */
    public function modifyColumn(string $name, callable $callback): void
    {
        $col = $callback(new ColumnDefinition($name, ''));
        $this->modifiedColumns[] = $col;
    }

    /**
     * Rename an existing column.
     *
     * Supported by all four drivers (SQLite ≥ 3.25.0, MySQL ≥ 8.0, PG, SQL Server).
     */
    public function renameColumn(string $from, string $to): void
    {
        $this->renamedColumns[$from] = $to;
    }

    // ── ALTER: drop ───────────────────────────────────────────────

    public function dropColumn(string $name): void
    {
        $this->droppedColumns[] = $name;
    }

    public function dropIndex(string $name): void
    {
        $this->droppedIndexes[] = $name;
    }

    public function dropForeign(string $name): void
    {
        $this->droppedForeignKeys[] = $name;
    }

    // ── Table-level options ───────────────────────────────────────

    public function engine(string $engine): self
    {
        $this->engine = $engine;
        return $this;
    }

    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    public function collation(string $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    // ── Accessors ─────────────────────────────────────────────────

    public function getTable(): string
    {
        return $this->table;
    }

    /** @return ColumnDefinition[] */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /** @return ColumnDefinition[] */
    public function getModifiedColumns(): array
    {
        return $this->modifiedColumns;
    }

    /** @return array<string, string> */
    public function getRenamedColumns(): array
    {
        return $this->renamedColumns;
    }

    /** @return IndexDefinition[] */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /** @return ForeignKeyDefinition[] */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    /** @return string[] */
    public function getDroppedColumns(): array
    {
        return $this->droppedColumns;
    }

    /** @return string[] */
    public function getDroppedIndexes(): array
    {
        return $this->droppedIndexes;
    }

    /** @return string[] */
    public function getDroppedForeignKeys(): array
    {
        return $this->droppedForeignKeys;
    }

    public function getEngine(): string
    {
        return $this->engine;
    }

    public function getCharset(): string
    {
        return $this->charset;
    }

    public function getCollation(): string
    {
        return $this->collation;
    }

    // ── Internal helpers ──────────────────────────────────────────

    private function addColumn(string $name, string $type): ColumnDefinition
    {
        $col = new ColumnDefinition($name, $type);
        $this->columns[] = $col;

        return $col;
    }
}
