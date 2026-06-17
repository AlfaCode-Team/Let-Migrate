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

    private string $engine = 'InnoDB';
    private string $charset = 'utf8mb4';
    private string $collation = 'utf8mb4_unicode_ci';

    private string $algorithm = '';
    private string $lock = '';

    public function __construct(private readonly string $table)
    {
    }

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
    /**
     * ULID — 26-char Crockford base32 identifier (sortable UUID alt).
     */
    public function ulid(string $name = 'id'): ColumnDefinition
    {
        return $this->addColumn($name, 'CHAR(26)');
    }

    /**
     * IPv4/IPv6 address. VARCHAR(45) is IPv6-safe and portable across
     * all four drivers (PostgreSQL INET is not portable to MySQL/SQLite).
     */
    public function ipAddress(string $name = 'ip_address'): ColumnDefinition
    {
        return $this->addColumn($name, 'VARCHAR(45)');
    }

    /**
     * MAC address — VARCHAR(17) ("AA:BB:CC:DD:EE:FF").
     */
    public function macAddress(string $name = 'mac_address'): ColumnDefinition
    {
        return $this->addColumn($name, 'VARCHAR(17)');
    }

    /**
     * Laravel-style remember-me token column.
     */
    public function rememberToken(): ColumnDefinition
    {
        return $this->addColumn('remember_token', 'VARCHAR(100)')->nullable();
    }

    /**
     * MySQL SET column. Non-MySQL grammars map SET(...) to a string type
     * (see PATCH P1C-4) so this is portable, degrading to a plain
     * string/text column elsewhere.
     *
     * @param string[] $allowed
     */
    public function set(string $name, array $allowed): ColumnDefinition
    {
        $values = implode(',', array_map(
            static fn(string $v): string => "'{$v}'",
            $allowed,
        ));

        return $this->addColumn($name, "SET({$values})");
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn($name, "VARCHAR({$length})");
    }

    public function tinyText(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'TINYTEXT');
    }
    /**
     * Full-text index. MySQL → FULLTEXT KEY; PostgreSQL → GIN on
     * to_tsvector(...); SQLite / SQL Server → graceful plain-index
     * fallback (documented; FTS there needs engine-specific setup).
     *
     * @param string[] $columns
     */
    public function fullText(array $columns, string $name = ''): void
    {
        $this->indexes[] = IndexDefinition::fullText(
            $columns,
            $name ?: 'ft_' . implode('_', $columns),
        );
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

    // ── Polymorphic ────────────────────────────────────────────────

    /**
     * Add `{name}_id` (UNSIGNED BIGINT) + `{name}_type` (VARCHAR) and a
     * composite index, for polymorphic relations (Laravel parity).
     */
    public function morphs(string $name): void
    {
        $this->addColumn("{$name}_id", 'BIGINT')->unsigned();
        $this->addColumn("{$name}_type", 'VARCHAR(255)');
        $this->index(["{$name}_type", "{$name}_id"], "idx_{$name}");
    }

    /**
     * Nullable variant of morphs().
     */
    public function nullableMorphs(string $name): void
    {
        $this->addColumn("{$name}_id", 'BIGINT')->unsigned()->nullable();
        $this->addColumn("{$name}_type", 'VARCHAR(255)')->nullable();
        $this->index(["{$name}_type", "{$name}_id"], "idx_{$name}");
    }

    /**
     * UUID-keyed polymorphic columns: `{name}_id` CHAR(36) + `{name}_type`.
     */
    public function uuidMorphs(string $name): void
    {
        $this->addColumn("{$name}_id", 'CHAR(36)');
        $this->addColumn("{$name}_type", 'VARCHAR(255)');
        $this->index(["{$name}_type", "{$name}_id"], "idx_{$name}");
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

    /**
     * MEDIUMINT (MySQL). PostgreSQL/SQL Server/SQLite grammars already
     * map MEDIUMINT → INTEGER/INT.
     */
    public function mediumInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'MEDIUMINT');
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
    public function primary(array $columns, string $name = ''): IndexDefinition
    {
        $idx = IndexDefinition::primary(
            $columns,
            $name ?: 'PRIMARY',
        );
        $this->indexes[] = $idx;
        return $idx;
    }

    /** @param string[] $columns */
    public function unique(array $columns, string $name = ''): IndexDefinition
    {
         $idx = IndexDefinition::unique(
            $columns,
            $name ?: 'uq_' . implode('_', $columns),
        );

        $this->indexes[] = $idx;
        return $idx;
    }

    /** @param string[] $columns */
    public function index(array $columns, string $name = ''): IndexDefinition
    {
        $idx = IndexDefinition::index(
            $columns,
            $name ?: 'idx_' . implode('_', $columns),
        );

        $this->indexes[] = $idx;
        return $idx;
    }

    // ── Foreign keys ───────────────────────────────────────────────

    public function foreign(string $column): ForeignKeyDefinition
    {
        $fk = new ForeignKeyDefinition($column);
        $this->foreignKeys[] = $fk;

        return $fk;
    }
    /**
     * Create an UNSIGNED BIGINT column intended to hold a foreign key.
     *
     * Chain ->constrained() to add the FK in one expression:
     *
     *   $t->foreignId('user_id')->constrained();
     *   $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
     *
     * Returns a ForeignIdColumnDefinition (proxy). Column modifiers chain
     * first; constrained() then returns a ForeignKeyDefinition.
     */
    public function foreignId(string $name): ForeignIdColumnDefinition
    {
        $column = $this->addColumn($name, 'BIGINT')->unsigned();

        return new ForeignIdColumnDefinition($this, $column, $name);
    }

    /**
     * Convenience: foreignId named "{singular}_id" for a related table.
     *
     *   $t->foreignIdFor('users');            // creates user_id
     *   $t->foreignIdFor('users', 'owner_id');// creates owner_id
     */
    public function foreignIdFor(string $table, string|null $column = null): ForeignIdColumnDefinition
    {
        $name = $column ?? rtrim($table, 's') . '_id';

        return $this->foreignId($name);
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

    /**
     * MySQL ALTER algorithm: 'INPLACE' | 'INSTANT' | 'COPY'.
     * Affects ALTER TABLE only; ignored by non-MySQL grammars.
     */
    public function algorithm(string $algorithm): self
    {
        $this->algorithm = strtoupper($algorithm);
        return $this;
    }

    /** MySQL ALTER lock level: 'NONE' | 'SHARED' | 'EXCLUSIVE' | 'DEFAULT'. */
    public function lock(string $lock): self
    {
        $this->lock = strtoupper($lock);
        return $this;
    }

    /** Shorthand for the fastest online change: ALGORITHM=INSTANT. */
    public function instant(): self
    {
        $this->algorithm = 'INSTANT';
        return $this;
    }

    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    public function getLock(): string
    {
        return $this->lock;
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
