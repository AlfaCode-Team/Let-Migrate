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

    // ── Accessors ─────────────────────────────────────────────────

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
}
