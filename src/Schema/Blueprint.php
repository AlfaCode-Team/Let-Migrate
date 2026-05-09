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
 */
final class Blueprint
{
    /** @var ColumnDefinition[] */
    private array $columns = [];

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

    private string $engine  = 'InnoDB';
    private string $charset = 'utf8mb4';
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
     * Adds `created_at` and `updated_at` DATETIME columns, both nullable.
     */
    public function timestamps(): void
    {
        $this->addColumn('created_at', 'DATETIME')->nullable()->default('CURRENT_TIMESTAMP');
        $this->addColumn('updated_at', 'DATETIME')->nullable()->default('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Adds a `deleted_at` DATETIME (soft-delete support).
     */
    public function softDeletes(): void
    {
        $this->addColumn('deleted_at', 'DATETIME')->nullable();
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

    /**
     * @param string[] $allowed
     */
    public function enum(string $name, array $allowed): ColumnDefinition
    {
        $values = implode(',', array_map(
            static fn(string $v) => "'{$v}'",
            $allowed,
        ));

        return $this->addColumn($name, "ENUM({$values})");
    }

    // ── Indexes ────────────────────────────────────────────────────

    /**
     * Add a composite primary key.
     *
     * @param string[] $columns
     */
    public function primary(array $columns): self
    {
        $this->indexes[] = IndexDefinition::primary($columns);

        return $this;
    }

    /**
     * Add a unique index.
     *
     * @param string[] $columns
     */
    public function unique(array $columns, string $name = ''): self
    {
        $this->indexes[] = IndexDefinition::unique($columns, $name);

        return $this;
    }

    /**
     * Add a plain index.
     *
     * @param string[] $columns
     */
    public function index(array $columns, string $name = ''): self
    {
        $this->indexes[] = IndexDefinition::index($columns, $name);

        return $this;
    }

    // ── Foreign keys ───────────────────────────────────────────────

    public function foreign(string $column): ForeignKeyDefinition
    {
        $fk = new ForeignKeyDefinition($column);
        $this->foreignKeys[] = $fk;

        return $fk;
    }

    // ── Drop helpers (for table() modifications) ───────────────────

    public function dropColumn(string ...$names): self
    {
        foreach ($names as $name) {
            $this->droppedColumns[] = $name;
        }

        return $this;
    }

    public function dropIndex(string $name): self
    {
        $this->droppedIndexes[] = $name;

        return $this;
    }

    public function dropForeign(string $name): self
    {
        $this->droppedForeignKeys[] = $name;

        return $this;
    }

    // ── Table-level options (MySQL / MariaDB) ─────────────────────

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

    // ── Accessors (used by SchemaBuilder / GrammarInterface) ──────

    public function getTable(): string { return $this->table; }

    /** @return ColumnDefinition[] */
    public function getColumns(): array { return $this->columns; }

    /** @return IndexDefinition[] */
    public function getIndexes(): array { return $this->indexes; }

    /** @return ForeignKeyDefinition[] */
    public function getForeignKeys(): array { return $this->foreignKeys; }

    /** @return string[] */
    public function getDroppedColumns(): array { return $this->droppedColumns; }

    /** @return string[] */
    public function getDroppedIndexes(): array { return $this->droppedIndexes; }

    /** @return string[] */
    public function getDroppedForeignKeys(): array { return $this->droppedForeignKeys; }

    public function getEngine(): string    { return $this->engine; }
    public function getCharset(): string   { return $this->charset; }
    public function getCollation(): string { return $this->collation; }

    // ── Private helpers ────────────────────────────────────────────

    private function addColumn(string $name, string $type): ColumnDefinition
    {
        $col = new ColumnDefinition($name, $type);
        $this->columns[] = $col;

        return $col;
    }
}