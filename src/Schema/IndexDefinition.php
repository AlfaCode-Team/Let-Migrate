<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema;

/**
 * Represents an index (PRIMARY, UNIQUE, INDEX, or FULLTEXT) on a table.
 *
 * ────────────────────────────────────────────────────────────────────
 * FIX / FEATURE SUMMARY
 * ────────────────────────────────────────────────────────────────────
 * • NF-05: type CONSTANTS are lowercase so factory output matches the
 *   grammar comparisons (`$idx->getType() === 'primary'|'unique'|'index'`).
 *   Part 2 of NF-05 is in Blueprint — see PATCHES-phase0-extra.md.
 * • Phase 1: added TYPE_FULLTEXT + fullText() factory so Blueprint can
 *   declare full-text indexes (MySQL FULLTEXT, PostgreSQL GIN/tsvector,
 *   graceful plain-index fallback on SQLite / SQL Server).
 */
final class IndexDefinition
{
    public const TYPE_PRIMARY = 'primary';

    public const TYPE_UNIQUE = 'unique';

    public const TYPE_INDEX = 'index';

    public const TYPE_FULLTEXT = 'fulltext';

    /**
     * @param string[] $columns
     */
    private function __construct(
        private readonly string $type,
        private readonly array  $columns,
        private readonly string $name = '',
    ) {}

    // ── PostgreSQL-advanced options (Phase 3) ─────────────────────
    // Ignored by MySQL/SQLite/SQL Server grammars; only PostgreSQL's
    // compileIndexStatements() honours them.

    private string $using       = '';   // GIN | GIST | BRIN | HASH | BTREE
    private string $where       = '';   // partial-index predicate
    private string $expression  = '';   // expression index (raw SQL)
    private bool   $concurrently = false;

    /** Index method: 'GIN', 'GIST', 'BRIN', 'HASH', 'BTREE'. */
    public function using(string $method): self
    {
        $this->using = mb_strtoupper($method);
        return $this;
    }

    /** Partial index predicate, e.g. "deleted_at IS NULL". */
    public function where(string $predicate): self
    {
        $this->where = $predicate;
        return $this;
    }

    /** Expression index, e.g. "lower(email)" (replaces the column list). */
    public function expression(string $expr): self
    {
        $this->expression = $expr;
        return $this;
    }

    /** Build with CREATE INDEX CONCURRENTLY (PostgreSQL, outside a tx). */
    public function concurrently(bool $value = true): self
    {
        $this->concurrently = $value;
        return $this;
    }

    public function getUsing(): string
    {
        return $this->using;
    }

    public function getWhere(): string
    {
        return $this->where;
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function isConcurrent(): bool
    {
        return $this->concurrently;
    }

    /** @param string[] $columns */
    public static function primary(array $columns, string $name = ''): self
    {
        return new self(self::TYPE_PRIMARY, $columns, $name);
    }

    /** @param string[] $columns */
    public static function unique(array $columns, string $name = ''): self
    {
        return new self(self::TYPE_UNIQUE, $columns, $name);
    }

    /** @param string[] $columns */
    public static function index(array $columns, string $name = ''): self
    {
        return new self(self::TYPE_INDEX, $columns, $name);
    }

    /** @param string[] $columns */
    public static function fullText(array $columns, string $name = ''): self
    {
        return new self(self::TYPE_FULLTEXT, $columns, $name);
    }

    public function getType(): string
    {
        return $this->type;
    }

    /** @return string[] */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getName(): string
    {
        return $this->name;
    }
}