<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema;

/**
 * Fluent foreign key definition.
 *
 *   $t->foreign('edition_id')
 *       ->references('id')
 *       ->on('vote_editions')
 *       ->onDelete('CASCADE')
 *       ->onUpdate('RESTRICT');
 *
 * ────────────────────────────────────────────────────────────────────
 * FIX SUMMARY (NF-04 — belt & suspenders)
 * ────────────────────────────────────────────────────────────────────
 * AbstractGrammar::compileForeignKey() historically called getReferences(),
 * getOn() and getName() — which did not exist (the real accessors are
 * getReferencedColumn(), getReferencedTable(), getConstraintName()), so
 * every FK compile fataled. PATCHES-phase1 P1-1 fixes the grammar to call
 * the correct names; additionally, the three short aliases below are now
 * declared here so BOTH naming styles resolve and the bug cannot recur in
 * any overriding grammar that still uses the old names.
 */
final class ForeignKeyDefinition
{
    private string $referencedColumn = 'id';

    private string $referencedTable = '';

    private string $onDelete = 'RESTRICT';

    private string $onUpdate = 'RESTRICT';

    private string $constraintName = '';

    public function __construct(private readonly string $column) {}

    public function references(string $column): self
    {
        $this->referencedColumn = $column;

        return $this;
    }

    public function on(string $table): self
    {
        $this->referencedTable = $table;

        return $this;
    }

    /**
     * Idempotently prefix the referenced table (used by SchemaBuilder when
     * a global table prefix is configured, so FK targets resolve to the
     * prefixed physical tables). Safe to call once per compile.
     */
    public function applyTablePrefix(string $prefix): void
    {
        if (
            $prefix === ''
            || $this->referencedTable === ''
            || str_starts_with($this->referencedTable, $prefix)
        ) {
            return;
        }

        $this->referencedTable = $prefix . $this->referencedTable;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = mb_strtoupper($action);

        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = mb_strtoupper($action);

        return $this;
    }

    public function name(string $constraintName): self
    {
        $this->constraintName = $constraintName;

        return $this;
    }

    public function cascadeOnDelete(): self
    {
        return $this->onDelete('CASCADE');
    }

    public function nullOnDelete(): self
    {
        return $this->onDelete('SET NULL');
    }

    public function restrictOnDelete(): self
    {
        return $this->onDelete('RESTRICT');
    }

    public function cascadeOnUpdate(): self
    {
        return $this->onUpdate('CASCADE');
    }

    // ── PostgreSQL deferrable constraints (Phase 3) ───────────────
    // Honoured by grammars that advertise deferrable support (PostgreSQL).
    // MySQL ignores these (it parses but does not enforce DEFERRABLE).

    private bool $deferrable        = false;
    private bool $initiallyDeferred = false;

    public function deferrable(bool $value = true): self
    {
        $this->deferrable = $value;
        return $this;
    }

    public function initiallyDeferred(bool $value = true): self
    {
        $this->deferrable        = $this->deferrable || $value;
        $this->initiallyDeferred = $value;
        return $this;
    }

    public function isDeferrable(): bool
    {
        return $this->deferrable;
    }

    public function isInitiallyDeferred(): bool
    {
        return $this->initiallyDeferred;
    }

    // ── Canonical accessors ───────────────────────────────────────

    public function getColumn(): string
    {
        return $this->column;
    }

    public function getReferencedColumn(): string
    {
        return $this->referencedColumn;
    }

    public function getReferencedTable(): string
    {
        return $this->referencedTable;
    }

    public function getOnDelete(): string
    {
        return $this->onDelete;
    }

    public function getOnUpdate(): string
    {
        return $this->onUpdate;
    }

    public function getConstraintName(): string
    {
        return $this->constraintName;
    }

    // ── NF-04 aliases (so old grammar call-sites also resolve) ────

    /** Alias of getReferencedColumn(). */
    public function getReferences(): string
    {
        return $this->referencedColumn;
    }

    /** Alias of getReferencedTable(). */
    public function getOn(): string
    {
        return $this->referencedTable;
    }

    /** Alias of getConstraintName(). */
    public function getName(): string
    {
        return $this->constraintName;
    }
}