<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema;

/**
 * Returned by Blueprint::foreignId(). Wraps the underlying BIGINT UNSIGNED
 * column definition and a back-reference to the Blueprint so that
 * ->constrained() can register a foreign key in one fluent expression —
 * the single most-loved Laravel migration ergonomic.
 *
 * Usage:
 *
 *   $t->foreignId('user_id')->constrained();
 *   $t->foreignId('user_id')->constrained('users', 'id');
 *   $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
 *   $t->foreignId('post_id')->constrained()->cascadeOnDelete();
 *
 * Column modifiers (nullable/default/…) must be chained BEFORE
 * ->constrained(); after constrained() the chain continues on the returned
 * ForeignKeyDefinition (cascadeOnDelete(), nullOnDelete(), name(), …) —
 * identical to Laravel's semantics.
 *
 * ColumnDefinition is `final`, so this is a composition proxy (not a
 * subclass): the proxied modifiers mutate the SAME ColumnDefinition
 * instance already registered in the Blueprint and return $this for
 * continued chaining.
 */
final class ForeignIdColumnDefinition
{
    public function __construct(
        private readonly Blueprint        $blueprint,
        private readonly ColumnDefinition $column,
        private readonly string           $columnName,
    ) {}

    // ── Proxied column modifiers (return $this to keep the chain) ──

    public function nullable(bool $value = true): self
    {
        $this->column->nullable($value);
        return $this;
    }

    public function notNull(): self
    {
        $this->column->notNull();
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->column->default($value);
        return $this;
    }

    public function unsigned(): self
    {
        $this->column->unsigned();
        return $this;
    }

    public function comment(string $text): self
    {
        $this->column->comment($text);
        return $this;
    }

    public function after(string $columnName): self
    {
        $this->column->after($columnName);
        return $this;
    }

    public function first(): self
    {
        $this->column->first();
        return $this;
    }

    public function unique(): self
    {
        $this->column->unique();
        return $this;
    }

    // ── The headline feature ──────────────────────────────────────

    /**
     * Add a foreign-key constraint for this column.
     *
     * When $table is omitted it is derived from the column name by
     * stripping a trailing `_id` and pluralising (user_id → users,
     * category_id → categories), matching Laravel's convention.
     *
     * Returns the ForeignKeyDefinition so the chain can continue with
     * cascadeOnDelete(), nullOnDelete(), name(), onUpdate(), etc.
     */
    public function constrained(string|null $table = null, string $column = 'id'): ForeignKeyDefinition
    {
        $referencedTable = $table ?? $this->guessTableName($this->columnName);

        return $this->blueprint
            ->foreign($this->columnName)
            ->references($column)
            ->on($referencedTable);
    }

    /**
     * Explicit alias when you want to be verbose about the relationship.
     */
    public function references(string $column): ForeignKeyDefinition
    {
        return $this->blueprint->foreign($this->columnName)->references($column);
    }

    // ── Accessor (so callers can still reach the raw column) ──────

    public function getColumnDefinition(): ColumnDefinition
    {
        return $this->column;
    }

    // ── Convention helper ─────────────────────────────────────────

    /**
     * user_id      → users
     * category_id  → categories
     * company_id   → companies
     * person_id    → people  (handled by the irregulars map)
     */
    private function guessTableName(string $columnName): string
    {
        $base = preg_replace('/_id$/', '', $columnName) ?? $columnName;

        return $this->pluralize($base);
    }

    private function pluralize(string $word): string
    {
        $irregulars = [
            'person' => 'people',
            'man'    => 'men',
            'woman'  => 'women',
            'child'  => 'children',
            'tooth'  => 'teeth',
            'foot'   => 'feet',
            'mouse'  => 'mice',
            'goose'  => 'geese',
        ];

        $lower = mb_strtolower($word);
        if (isset($irregulars[$lower])) {
            return $irregulars[$lower];
        }

        // …y (not preceded by a vowel) → …ies
        if (preg_match('/[^aeiou]y$/i', $word)) {
            return preg_replace('/y$/i', 'ies', $word) ?? $word . 's';
        }

        // …s, …x, …z, …ch, …sh → …es
        if (preg_match('/(s|x|z|ch|sh)$/i', $word)) {
            return $word . 'es';
        }

        return $word . 's';
    }
}