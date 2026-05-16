<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema;

/**
 * Fluent column definition returned by Blueprint column methods.
 *
 * Chain modifiers to express constraints:
 *
 *   $t->string('email', 191)->notNull()->unique()->after('name');
 *   $t->dateTime('updated_at')->onUpdateCurrentTimestamp();
 *
 * @fixed onUpdateCurrentTimestamp() — replaces the raw string
 *        'ON UPDATE CURRENT_TIMESTAMP' that was baked into Blueprint::timestamps().
 *        Grammars inspect $col->hasOnUpdateCurrentTimestamp() and emit the
 *        correct dialect (MySQL inline keyword vs PostgreSQL trigger).
 */
final class ColumnDefinition
{
    private bool        $nullable              = false;
    private bool        $unsigned              = false;
    private bool        $autoIncrement         = false;
    private bool        $isPrimary             = false;
    private bool        $isUnique              = false;
    private mixed       $defaultValue          = null;
    private bool        $hasDefault            = false;
    private string      $comment               = '';
    private string|null $after                 = null;
    private bool        $first                 = false;
    private string|null $charset               = null;
    private string|null $collation             = null;
    private bool        $onUpdateCurrentTs     = false;

    public function __construct(
        private readonly string $name,
        private readonly string $type,
    ) {}

    // ── Modifiers ─────────────────────────────────────────────────

    public function nullable(bool $value = true): self
    {
        $this->nullable = $value;
        return $this;
    }

    public function notNull(): self
    {
        $this->nullable = false;
        return $this;
    }

    public function unsigned(): self
    {
        $this->unsigned = true;
        return $this;
    }

    public function autoIncrement(): self
    {
        $this->autoIncrement = true;
        return $this;
    }

    public function primary(): self
    {
        $this->isPrimary = true;
        return $this;
    }

    public function unique(): self
    {
        $this->isUnique = true;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->defaultValue = $value;
        $this->hasDefault   = true;
        return $this;
    }

    public function comment(string $text): self
    {
        $this->comment = $text;
        return $this;
    }

    public function after(string $column): self
    {
        $this->after = $column;
        return $this;
    }

    public function first(): self
    {
        $this->first = true;
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
     * Mark this column as auto-updating to the current timestamp on every UPDATE.
     *
     * Grammar-aware: MySQL emits `ON UPDATE CURRENT_TIMESTAMP` inline.
     * PostgreSQL emits a separate trigger (handled via Grammar::compilePostCreate).
     * SQLite and SQL Server use application-level timestamps — a PHPDoc note is added.
     *
     * Replaces the old approach of setting default('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP')
     * which passed an invalid raw string through PostgreSQL grammar.
     */
    public function onUpdateCurrentTimestamp(): self
    {
        $this->onUpdateCurrentTs = true;
        return $this;
    }

    // ── Accessors ─────────────────────────────────────────────────

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isUnsigned(): bool
    {
        return $this->unsigned;
    }

    public function isAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function isUnique(): bool
    {
        return $this->isUnique;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function getDefault(): mixed
    {
        return $this->defaultValue;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function getAfter(): string|null
    {
        return $this->after;
    }

    public function isFirst(): bool
    {
        return $this->first;
    }

    public function getCharset(): string|null
    {
        return $this->charset;
    }

    public function getCollation(): string|null
    {
        return $this->collation;
    }

    /**
     * Whether this column should auto-update to the current timestamp on row UPDATE.
     * Grammars read this flag and emit the correct dialect-specific DDL.
     */
    public function hasOnUpdateCurrentTimestamp(): bool
    {
        return $this->onUpdateCurrentTs;
    }
}
