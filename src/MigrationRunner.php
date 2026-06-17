<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\MigrationRepositoryInterface;
use AlfaCode\LetMigrate\Contract\MigrationResolverInterface;
use AlfaCode\LetMigrate\Contract\MigrationRunnerInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use AlfaCode\LetMigrate\Event\MigrationFailed;
use AlfaCode\LetMigrate\Event\MigrationFinished;
use AlfaCode\LetMigrate\Event\MigrationsCompleted;
use AlfaCode\LetMigrate\Event\MigrationStarted;
use AlfaCode\LetMigrate\Exception\MigrationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrates the full migration lifecycle.
 *
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  INTERNAL COMPONENT                                         ║
 * ║                                                             ║
 * ║  MigrationRunner is an internal orchestrator used ONLY by   ║
 * ║  MigrationService.  Application code must depend on         ║
 * ║  MigrationServiceInterface — not on this class directly.    ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * ────────────────────────────────────────────────────────────────────
 * FIX SUMMARY (S-05)
 * ────────────────────────────────────────────────────────────────────
 * A new $transactional constructor flag (default true, preserving prior
 * behaviour) is now respected. When false, migrations run WITHOUT an
 * explicit BEGIN/COMMIT/ROLLBACK wrapper — required for engines whose DDL
 * is non-transactional, or when the caller manages transactions itself.
 * The flag is read from MigrationConfig::$transactional by the factory.
 */
final class MigrationRunner implements MigrationRunnerInterface
{
    public function __construct(
        private readonly MigrationRepositoryInterface $repository,
        private readonly MigrationResolverInterface   $resolver,
        private readonly SchemaBuilderInterface       $schema,
        private readonly MigrationEventDispatcher     $events = new MigrationEventDispatcher(),
        private readonly LoggerInterface              $logger = new NullLogger(),
        private readonly bool                         $pretend = false,
        private readonly bool                         $transactional = true,
        private readonly bool                         $allOrNothing = false,
        private readonly \AlfaCode\LetMigrate\BreakpointStore|null $breakpoints = null,
    ) {}

    /**
     * Abort if any migration about to be rolled back has a breakpoint set
     * (Phinx-style production safety rail). No-op when no BreakpointStore
     * is configured — fully opt-in, zero behaviour change otherwise.
     *
     * @param string[] $toRollback
     *
     * @throws MigrationException
     */
    private function guardBreakpoints(array $toRollback): void
    {
        if ($this->breakpoints === null) {
            return;
        }

        $blocked = $this->breakpoints->blocking($toRollback);

        if ($blocked !== []) {
            throw new MigrationException(
                'Rollback blocked by breakpoint(s): ' . implode(', ', $blocked)
                . '. Clear the breakpoint (migrate:breakpoint --unset) to proceed.',
            );
        }
    }

    // ── Run ───────────────────────────────────────────────────────

    public function run(): MigrationResult
    {
        $this->repository->ensureTable();

        $pending = $this->pending();

        if (empty($pending)) {
            $this->logger->info('[LetMigrate] Nothing to migrate.');

            return MigrationResult::empty();
        }

        // Phase 3: reorder so any DependentMigrationInterface runs after
        // its declared dependencies (timestamp order preserved otherwise).
        $pending = $this->orderByDependencies($pending);

        $batch   = $this->repository->lastBatch() + 1;
        $applied = [];

        // Phase 3 — all_or_nothing: wrap the WHOLE batch in ONE
        // transaction. When on, per-migration transactions are NOT used
        // (the outer transaction owns atomicity). NOTE: on MySQL, DDL
        // causes an implicit commit, so true batch atomicity only holds on
        // PostgreSQL / SQLite — documented behaviour, matching Doctrine.
        $useBatchTx = $this->allOrNothing
            && !$this->pretend
            && $this->transactional;

        $driver = $this->schema->getDriver();

        $batchTxOpen = false;
        if ($useBatchTx) {
            $driver->beginTransaction();
            $batchTxOpen = true;
        }

        try {
            foreach ($pending as $filename => $migration) {
                $this->events->dispatch(new MigrationStarted($filename, 'up'));
                $this->logger->info("[LetMigrate] Migrating: {$filename}");

                // Phase 3: a migration may opt out of ALL transactions
                // (e.g. PostgreSQL CREATE INDEX CONCURRENTLY, which cannot
                // run inside a transaction block).
                $isTxless = $migration instanceof
                    \AlfaCode\LetMigrate\Contract\TransactionlessMigrationInterface;

                try {
                    if ($this->pretend) {
                        $this->logger->info("[LetMigrate] [PRETEND] Would run: {$filename}");
                    } elseif ($isTxless) {
                        // Must run with NO open transaction. If a batch tx
                        // is open, commit it first (atomicity is split
                        // around this migration — unavoidable & documented),
                        // run directly, then reopen a fresh batch tx so the
                        // remaining migrations stay grouped.
                        if ($batchTxOpen) {
                            $driver->commit();
                            $batchTxOpen = false;
                            $this->logger->warning(
                                "[LetMigrate] all_or_nothing interrupted: '{$filename}' "
                                . 'runs without a transaction (batch committed up to here).',
                            );
                        }
                        $this->applyUp($filename, $migration, $batch);
                        if ($useBatchTx) {
                            $driver->beginTransaction();
                            $batchTxOpen = true;
                        }
                    } elseif ($batchTxOpen) {
                        // outer transaction owns atomicity — run directly
                        $this->applyUp($filename, $migration, $batch);
                    } else {
                        $this->execute(
                            fn() => $this->applyUp($filename, $migration, $batch),
                        );
                    }

                    $applied[] = $filename;
                    $this->events->dispatch(new MigrationFinished($filename, 'up'));
                    $this->logger->info("[LetMigrate] Migrated:  {$filename}");

                } catch (\Throwable $e) {
                    $this->events->dispatch(new MigrationFailed($filename, 'up', $e));
                    $this->logger->error("[LetMigrate] Failed:    {$filename} — {$e->getMessage()}");

                    throw new MigrationException(
                        "Migration '{$filename}' failed: {$e->getMessage()}",
                        (int) $e->getCode(),
                        $e,
                    );
                }
            }

            if ($batchTxOpen) {
                $driver->commit();
                $batchTxOpen = false;
            }
        } catch (\Throwable $e) {
            if ($batchTxOpen) {
                $driver->rollback();
                $this->logger->error(
                    '[LetMigrate] all_or_nothing: batch rolled back — no migrations applied.',
                );
            }

            throw $e instanceof MigrationException
                ? $e
                : new MigrationException($e->getMessage(), (int) $e->getCode(), $e);
        }

        $result = new MigrationResult(applied: $applied, rolledBack: [], batch: $batch);
        $this->events->dispatch(new MigrationsCompleted($result));
        $this->logger->info(
            sprintf('[LetMigrate] %d migration(s) applied in batch %d.', count($applied), $batch),
        );

        return $result;
    }

    // ── Rollback ──────────────────────────────────────────────────

    public function rollback(int $steps = 1): MigrationResult
    {
        $this->repository->ensureTable();

        $toRollback = $this->lastApplied($steps);

        if (empty($toRollback)) {
            $this->logger->info('[LetMigrate] Nothing to roll back.');

            return MigrationResult::empty();
        }

        $this->guardBreakpoints($toRollback);

        $all        = $this->resolver->resolve();
        $rolledBack = [];

        foreach ($toRollback as $filename) {
            if (!isset($all[$filename])) {
                throw new MigrationException(
                    "Cannot roll back '{$filename}': migration file not found in any registered path.",
                );
            }

            $migration = $all[$filename];
            $this->events->dispatch(new MigrationStarted($filename, 'down'));
            $this->logger->info("[LetMigrate] Rolling back: {$filename}");

            try {
                if (!$this->pretend) {
                    $this->execute(
                        fn() => $this->applyDown($filename, $migration),
                    );
                } else {
                    $this->logger->info("[LetMigrate] [PRETEND] Would roll back: {$filename}");
                }

                $rolledBack[] = $filename;
                $this->events->dispatch(new MigrationFinished($filename, 'down'));
                $this->logger->info("[LetMigrate] Rolled back: {$filename}");

            } catch (\Throwable $e) {
                $this->events->dispatch(new MigrationFailed($filename, 'down', $e));
                $this->logger->error("[LetMigrate] Rollback failed: {$filename} — {$e->getMessage()}");

                throw new MigrationException(
                    "Rollback of '{$filename}' failed: {$e->getMessage()}",
                    (int) $e->getCode(),
                    $e,
                );
            }
        }

        $result = new MigrationResult(applied: [], rolledBack: $rolledBack, batch: 0);
        $this->events->dispatch(new MigrationsCompleted($result));
        $this->logger->info(
            sprintf('[LetMigrate] %d migration(s) rolled back.', count($rolledBack)),
        );

        return $result;
    }

    // ── Reset ─────────────────────────────────────────────────────

    public function reset(): MigrationResult
    {
        $this->repository->ensureTable();
        $applied = array_reverse($this->repository->appliedFilenames());
        $steps   = count($applied);

        return $steps > 0 ? $this->rollback($steps) : MigrationResult::empty();
    }

    // ── Refresh ───────────────────────────────────────────────────

    public function refresh(): MigrationResult
    {
        $this->reset();

        return $this->run();
    }

    // ── Redo (Phase 1) ────────────────────────────────────────────

    /**
     * Roll back the last $steps migrations and immediately re-apply
     * exactly those (Yii `migrate/redo` parity).
     *
     * Unlike rollback()+run(), this re-applies ONLY the migrations it
     * just reverted — it will not pull in other pending migrations.
     */
    public function redo(int $steps = 1): MigrationResult
    {
        $this->repository->ensureTable();

        // Capture the exact set that will be rolled back (newest-first)
        // BEFORE we roll back, so we can re-apply precisely that set.
        $target = $this->lastApplied($steps);

        if ($target === []) {
            $this->logger->info('[LetMigrate] Nothing to redo.');

            return MigrationResult::empty();
        }

        $this->rollback($steps);

        $all     = $this->resolver->resolve();
        $batch   = $this->repository->lastBatch() + 1;
        $applied = [];

        // Re-apply ascending (target is newest-first → reverse it).
        foreach (array_reverse($target) as $filename) {
            if (!isset($all[$filename])) {
                // Migration file vanished between rollback and redo —
                // skip rather than fatal; report what we could redo.
                $this->logger->warning(
                    "[LetMigrate] redo: '{$filename}' no longer resolvable — skipped.",
                );
                continue;
            }

            $migration = $all[$filename];
            $this->events->dispatch(new MigrationStarted($filename, 'up'));
            $this->logger->info("[LetMigrate] Redoing: {$filename}");

            try {
                if (!$this->pretend) {
                    $this->execute(fn() => $this->applyUp($filename, $migration, $batch));
                } else {
                    $this->logger->info("[LetMigrate] [PRETEND] Would redo: {$filename}");
                }
                $applied[] = $filename;
                $this->events->dispatch(new MigrationFinished($filename, 'up'));
            } catch (\Throwable $e) {
                $this->events->dispatch(new MigrationFailed($filename, 'up', $e));

                throw new MigrationException(
                    "Redo of '{$filename}' failed: {$e->getMessage()}",
                    (int) $e->getCode(),
                    $e,
                );
            }
        }

        $result = new MigrationResult(
            applied:    $applied,
            rolledBack: $target,
            batch:      $batch,
        );
        $this->events->dispatch(new MigrationsCompleted($result));

        return $result;
    }

    // ── Fresh (Phase 1) ───────────────────────────────────────────

    /**
     * Drop EVERY table in the database, then re-run all migrations from
     * scratch (Laravel `migrate:fresh` parity = db:wipe + migrate).
     *
     * Foreign-key checks are disabled for the duration of the wipe, so no
     * dependency ordering is required — every table (including the
     * migration tracking table) is dropped unconditionally; run() then
     * recreates the tracking table via its own ensureTable().
     *
     * Seeding (`--seed`) is intentionally NOT handled here — the runner
     * has no seeder. The command layer invokes the SeederRunner after
     * fresh() returns (see PATCHES-phase1-fresh.md).
     */
    public function fresh(): MigrationResult
    {
        $this->repository->ensureTable();

        $driver = $this->schema->getDriver();
        $tables = $driver->listTables();

        if ($tables !== []) {
            $this->logger->info(
                sprintf('[LetMigrate] Dropping %d table(s) for fresh.', count($tables)),
            );

            $this->schema->disableForeignKeyChecks();
            try {
                foreach ($tables as $table) {
                    if (!$this->pretend) {
                        $this->schema->dropIfExists($table);
                    } else {
                        $this->logger->info("[LetMigrate] [PRETEND] Would drop: {$table}");
                    }
                }
            } finally {
                $this->schema->enableForeignKeyChecks();
            }
        }

        // run() re-creates the tracking table and applies everything.
        return $this->run();
    }

    // ── Migrate to a specific target (Phase 1) ────────────────────

    /**
     * Migrate UP or DOWN until the database is exactly at $target.
     *
     * Semantics (Yii `migrate/to` parity):
     *   • $target not yet applied  → apply every pending migration with
     *     order ≤ $target, in ascending order (migrate up to & incl. it).
     *   • $target already applied  → roll back every applied migration
     *     ordered AFTER $target, in descending order ($target stays
     *     applied).
     *   • $target is the current high-water mark → no-op.
     *
     * @throws MigrationException if $target is not a known migration.
     */
    public function migrateTo(string $target): MigrationResult
    {
        $this->repository->ensureTable();

        $all = $this->resolver->resolve();

        if (!isset($all[$target])) {
            throw new MigrationException(
                "migrate:to target '{$target}' was not found among resolved migrations.",
            );
        }

        $ordered   = array_keys($all);
        $targetIdx = (int) array_search($target, $ordered, true);
        $applied   = array_flip($this->repository->appliedFilenames());

        // ── Direction: UP (target not yet applied) ──
        if (!isset($applied[$target])) {
            $batch   = $this->repository->lastBatch() + 1;
            $applied2 = [];

            foreach ($ordered as $i => $filename) {
                if ($i > $targetIdx) {
                    break;
                }
                if (isset($applied[$filename])) {
                    continue;
                }

                $migration = $all[$filename];
                $this->events->dispatch(new MigrationStarted($filename, 'up'));
                $this->logger->info("[LetMigrate] Migrating: {$filename}");

                try {
                    if (!$this->pretend) {
                        $this->execute(fn() => $this->applyUp($filename, $migration, $batch));
                    } else {
                        $this->logger->info("[LetMigrate] [PRETEND] Would run: {$filename}");
                    }
                    $applied2[] = $filename;
                    $this->events->dispatch(new MigrationFinished($filename, 'up'));
                } catch (\Throwable $e) {
                    $this->events->dispatch(new MigrationFailed($filename, 'up', $e));

                    throw new MigrationException(
                        "Migration '{$filename}' failed during migrate:to: {$e->getMessage()}",
                        (int) $e->getCode(),
                        $e,
                    );
                }
            }

            $result = new MigrationResult(applied: $applied2, rolledBack: [], batch: $batch);
            $this->events->dispatch(new MigrationsCompleted($result));

            return $result;
        }

        // ── Direction: DOWN (target applied → roll back everything after it) ──
        $rolledBack = [];

        foreach (array_reverse($ordered, true) as $i => $filename) {
            if ($i <= $targetIdx) {
                break; // reached target — keep it and everything before it
            }
            if (!isset($applied[$filename])) {
                continue;
            }

            $migration = $all[$filename];
            $this->events->dispatch(new MigrationStarted($filename, 'down'));
            $this->logger->info("[LetMigrate] Rolling back: {$filename}");

            try {
                if (!$this->pretend) {
                    $this->execute(fn() => $this->applyDown($filename, $migration));
                } else {
                    $this->logger->info("[LetMigrate] [PRETEND] Would roll back: {$filename}");
                }
                $rolledBack[] = $filename;
                $this->events->dispatch(new MigrationFinished($filename, 'down'));
            } catch (\Throwable $e) {
                $this->events->dispatch(new MigrationFailed($filename, 'down', $e));

                throw new MigrationException(
                    "Rollback of '{$filename}' failed during migrate:to: {$e->getMessage()}",
                    (int) $e->getCode(),
                    $e,
                );
            }
        }

        $result = new MigrationResult(applied: [], rolledBack: $rolledBack, batch: 0);
        $this->events->dispatch(new MigrationsCompleted($result));

        return $result;
    }

    // ── Status ────────────────────────────────────────────────────

    public function status(): array
    {
        $this->repository->ensureTable();

        $all     = $this->resolver->resolve();
        $records = [];

        foreach ($this->repository->all() as $record) {
            $records[$record->migration] = $record;
        }

        $status = [];

        foreach (array_keys($all) as $filename) {
            if (isset($records[$filename])) {
                $status[$filename] = [
                    'status'     => 'applied',
                    'batch'      => $records[$filename]->batch,
                    'applied_at' => $records[$filename]->appliedAt,
                ];
            } else {
                $status[$filename] = [
                    'status'     => 'pending',
                    'batch'      => null,
                    'applied_at' => null,
                ];
            }
        }

        return $status;
    }

    // ── Pending ───────────────────────────────────────────────────

    public function pending(): array
    {
        $all     = $this->resolver->resolve();
        $applied = array_flip($this->repository->appliedFilenames());

        return array_filter(
            $all,
            static fn($_, string $filename) => !isset($applied[$filename]),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    // ── Event bus accessor (used by MigrationService) ─────────────

    public function getEvents(): MigrationEventDispatcher
    {
        return $this->events;
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * S-05: run $work inside a transaction only when transactional=true.
     * When false, $work runs directly and the caller/engine is responsible
     * for atomicity.
     */
    private function execute(callable $work): void
    {
        if (!$this->transactional) {
            $work();

            return;
        }

        $driver = $this->schema->getDriver();
        $driver->beginTransaction();

        try {
            $work();
            $driver->commit();
        } catch (\Throwable $e) {
            $driver->rollback();

            throw $e;
        }
    }

    private function applyUp(string $filename, $migration, int $batch): void
    {
        $migration->up($this->schema);
        $this->repository->log($filename, $batch);
    }

    private function applyDown(string $filename, $migration): void
    {
        $migration->down($this->schema);
        $this->repository->remove($filename);
    }

    /**
     * Topologically sort pending migrations so any migration implementing
     * DependentMigrationInterface runs AFTER its declared dependencies.
     *
     * - Stable: original (timestamp) order is the tie-breaker, so behaviour
     *   is unchanged for the common case of no declared dependencies.
     * - Dependencies not present in the pending set (already applied or
     *   unknown) are ignored — they don't block.
     * - A dependency cycle raises MigrationException naming the cycle.
     *
     * @param  array<string, \AlfaCode\LetMigrate\Contract\MigrationInterface> $pending
     * @return array<string, \AlfaCode\LetMigrate\Contract\MigrationInterface>
     */
    private function orderByDependencies(array $pending): array
    {
        $names    = array_keys($pending);
        $position = array_flip($names); // stable tie-breaker
        $indegree = [];
        $deps     = [];

        foreach ($names as $name) {
            $deps[$name]     = [];
            $indegree[$name] = 0;
        }

        foreach ($pending as $name => $migration) {
            if (!$migration instanceof \AlfaCode\LetMigrate\Contract\DependentMigrationInterface) {
                continue;
            }
            foreach ($migration->dependsOn() as $dep) {
                // ignore deps that aren't pending (already applied / unknown)
                if (!array_key_exists($dep, $pending) || $dep === $name) {
                    continue;
                }
                if (!in_array($dep, $deps[$name], true)) {
                    $deps[$name][] = $dep;
                    $indegree[$name]++;
                }
            }
        }

        // Kahn — ready queue kept in original order for determinism
        $ready = array_values(array_filter(
            $names,
            static fn(string $n) => $indegree[$n] === 0,
        ));
        usort($ready, static fn($a, $b) => $position[$a] <=> $position[$b]);

        $orderedNames = [];
        while ($ready !== []) {
            $n = array_shift($ready);
            $orderedNames[] = $n;

            $unlocked = [];
            foreach ($names as $other) {
                if (in_array($n, $deps[$other], true)) {
                    $deps[$other] = array_values(array_diff($deps[$other], [$n]));
                    if (--$indegree[$other] === 0) {
                        $unlocked[] = $other;
                    }
                }
            }
            if ($unlocked !== []) {
                usort($unlocked, static fn($a, $b) => $position[$a] <=> $position[$b]);
                foreach ($unlocked as $u) {
                    $ready[] = $u;
                }
                usort($ready, static fn($a, $b) => $position[$a] <=> $position[$b]);
            }
        }

        if (count($orderedNames) !== count($names)) {
            $cycle = array_values(array_diff($names, $orderedNames));

            throw new MigrationException(
                'Circular migration dependency detected involving: '
                . implode(', ', $cycle),
            );
        }

        $result = [];
        foreach ($orderedNames as $n) {
            $result[$n] = $pending[$n];
        }

        return $result;
    }

    /**
     * Return the last N applied migration filenames in rollback order
     * (newest first).
     *
     * @return string[]
     */
    private function lastApplied(int $steps): array
    {
        $all     = array_reverse($this->repository->appliedFilenames());
        $batches = [];

        foreach ($this->repository->all() as $record) {
            $batches[$record->migration] = $record->batch;
        }

        $grouped = [];

        foreach ($all as $filename) {
            $batch = $batches[$filename] ?? 0;
            $grouped[$batch][] = $filename;
        }

        krsort($grouped);

        $selected = [];
        $count    = 0;

        foreach ($grouped as $batchFiles) {
            if ($count >= $steps) {
                break;
            }

            foreach ($batchFiles as $f) {
                $selected[] = $f;
            }

            $count++;
        }

        return $selected;
    }
}