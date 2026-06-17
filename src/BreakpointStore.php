<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;

/**
 * Persists "breakpoints" — a Phinx-style safety rail. A migration with a
 * breakpoint set CANNOT be rolled back (rollback/reset abort before
 * crossing it) unless explicitly forced.
 *
 * Deliberately isolated: it uses its OWN tiny table and never touches the
 * migration tracking table's schema (so existing installs need no meta
 * migration). The feature is entirely opt-in — if no BreakpointStore is
 * injected into the runner, behaviour is exactly as before (zero BC).
 *
 * Table: {prefix}let_breakpoints (single column `migration`).
 */
final class BreakpointStore
{
    public function __construct(
        private readonly DatabaseDriverInterface $driver,
        private readonly string                  $table = 'let_breakpoints',
    ) {}

    public function ensureTable(): void
    {
        if ($this->driver->tableExists($this->table)) {
            return;
        }

        $t = $this->driver->quoteIdentifier($this->table);
        // Portable DDL: a single short identifier column, primary key.
        $this->driver->execute(
            "CREATE TABLE {$t} (migration VARCHAR(255) NOT NULL PRIMARY KEY)",
        );
    }

    public function set(string $migration): void
    {
        $this->ensureTable();
        if ($this->isSet($migration)) {
            return; // idempotent
        }
        $this->driver->insert($this->table, ['migration' => $migration]);
    }

    public function clear(string $migration): void
    {
        $this->ensureTable();
        $this->driver->delete($this->table, ['migration' => $migration]);
    }

    public function isSet(string $migration): bool
    {
        $this->ensureTable();
        $t   = $this->driver->quoteIdentifier($this->table);
        $row = $this->driver->fetchOne(
            "SELECT migration FROM {$t} WHERE migration = ?",
            [$migration],
        );

        return $row !== null && $row !== [];
    }

    /**
     * @return string[]
     */
    public function all(): array
    {
        $this->ensureTable();
        $t    = $this->driver->quoteIdentifier($this->table);
        $rows = $this->driver->fetchAll("SELECT migration FROM {$t}");

        return array_values(array_map(
            static fn(array $r) => (string) ($r['migration'] ?? ''),
            $rows,
        ));
    }

    /**
     * Of the given filenames, which have a breakpoint set.
     *
     * @param  string[] $filenames
     * @return string[]
     */
    public function blocking(array $filenames): array
    {
        if ($filenames === []) {
            return [];
        }
        $set = array_flip($this->all());

        return array_values(array_filter(
            $filenames,
            static fn(string $f) => isset($set[$f]),
        ));
    }
}