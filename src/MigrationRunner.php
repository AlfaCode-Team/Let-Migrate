<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\MigrationRepositoryInterface;
use AlfaCode\LetMigrate\Contract\MigrationResolverInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use AlfaCode\LetMigrate\Event\MigrationFailed;
use AlfaCode\LetMigrate\Event\MigrationFinished;
use AlfaCode\LetMigrate\Event\MigrationStarted;
use AlfaCode\LetMigrate\Event\MigrationsCompleted;
use AlfaCode\LetMigrate\Exception\MigrationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrates the full migration lifecycle.
 *
 * Responsibilities
 * ────────────────
 * 1. Ensure the tracking table exists (idempotent).
 * 2. Resolve all migration files from configured paths.
 * 3. Diff against the repository to find pending migrations.
 * 4. Run each pending migration inside its own transaction.
 * 5. Record each applied migration with a monotonically increasing batch number.
 * 6. Emit lifecycle events (started / finished / failed / completed).
 *
 * Rollback reverses the last N batches in reverse filename order, wrapping
 * each rollback in its own transaction so a partial failure is recoverable.
 *
 * Usage:
 *
 *   $runner = new MigrationRunner($repository, $resolver, $schema);
 *   $result = $runner->run();   // MigrationResult
 *   echo $result->summary();
 *
 *   $result = $runner->rollback(steps: 1);
 */
final class MigrationRunner
{
    public function __construct(
        private readonly MigrationRepositoryInterface $repository,
        private readonly MigrationResolverInterface   $resolver,
        private readonly SchemaBuilderInterface       $schema,
        private readonly MigrationEventDispatcher     $events  = new MigrationEventDispatcher(),
        private readonly LoggerInterface              $logger  = new NullLogger(),
        private readonly bool                         $pretend = false,
    ) {}

    // ── Run ───────────────────────────────────────────────────────

    /**
     * Apply all pending migrations.
     *
     * @throws MigrationException
     */
    public function run(): MigrationResult
    {
        $this->repository->ensureTable();

        $pending = $this->pending();

        if (empty($pending)) {
            $this->logger->info('[LetMigrate] Nothing to migrate.');

            return MigrationResult::empty();
        }

        $batch   = $this->repository->lastBatch() + 1;
        $applied = [];
        $failed  = null;

        foreach ($pending as $filename => $migration) {
            $this->events->dispatch(new MigrationStarted($filename, 'up'));
            $this->logger->info("[LetMigrate] Migrating: {$filename}");

            try {
                if (!$this->pretend) {
                    $driver = $this->schema->getDriver();
                    $driver->beginTransaction();

                    try {
                        $migration->up($this->schema);
                        $this->repository->log($filename, $batch);
                        $driver->commit();
                    } catch (\Throwable $e) {
                        $driver->rollback();
                        throw $e;
                    }
                } else {
                    $this->logger->info("[LetMigrate] [PRETEND] Would run: {$filename}");
                }

                $applied[] = $filename;
                $this->events->dispatch(new MigrationFinished($filename, 'up'));
                $this->logger->info("[LetMigrate] Migrated:  {$filename}");

            } catch (\Throwable $e) {
                $failed = $e;
                $this->events->dispatch(new MigrationFailed($filename, 'up', $e));
                $this->logger->error("[LetMigrate] Failed:    {$filename} — {$e->getMessage()}");

                throw new MigrationException(
                    "Migration '{$filename}' failed: {$e->getMessage()}",
                    (int) $e->getCode(),
                    $e,
                );
            }
        }

        $result = new MigrationResult(applied: $applied, rolledBack: [], batch: $batch);
        $this->events->dispatch(new MigrationsCompleted($result));
        $this->logger->info(sprintf('[LetMigrate] %d migration(s) applied in batch %d.', count($applied), $batch));

        return $result;
    }

    // ── Rollback ──────────────────────────────────────────────────

    /**
     * Reverse the last N batches.
     *
     * @throws MigrationException
     */
    public function rollback(int $steps = 1): MigrationResult
    {
        $this->repository->ensureTable();

        $toRollback = $this->lastApplied($steps);

        if (empty($toRollback)) {
            $this->logger->info('[LetMigrate] Nothing to roll back.');

            return MigrationResult::empty();
        }

        $all          = $this->resolver->resolve();
        $rolledBack   = [];

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
                    $driver = $this->schema->getDriver();
                    $driver->beginTransaction();

                    try {
                        $migration->down($this->schema);
                        $this->repository->remove($filename);
                        $driver->commit();
                    } catch (\Throwable $e) {
                        $driver->rollback();
                        throw $e;
                    }
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
        $this->logger->info(sprintf('[LetMigrate] %d migration(s) rolled back.', count($rolledBack)));

        return $result;
    }

    // ── Reset ─────────────────────────────────────────────────────

    /**
     * Roll back ALL applied migrations (in reverse order).
     */
    public function reset(): MigrationResult
    {
        $this->repository->ensureTable();
        $applied = array_reverse($this->repository->appliedFilenames());
        $steps   = count($applied);

        return $steps > 0 ? $this->rollback($steps) : MigrationResult::empty();
    }

    // ── Refresh ───────────────────────────────────────────────────

    /**
     * Reset then re-run all migrations (destructive — use in dev only).
     */
    public function refresh(): MigrationResult
    {
        $this->reset();

        return $this->run();
    }

    // ── Status ────────────────────────────────────────────────────

    /**
     * Return a status map of all discovered migrations.
     *
     * @return array<string, array{status: string, batch: int|null}>
     */
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
                    'status' => 'applied',
                    'batch'  => $records[$filename]->batch,
                ];
            } else {
                $status[$filename] = [
                    'status' => 'pending',
                    'batch'  => null,
                ];
            }
        }

        return $status;
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Return all pending (not yet applied) migrations, sorted by filename.
     *
     * @return array<string, \AlfaCode\LetMigrate\Contract\MigrationInterface>
     */
    public function pending(): array
    {
        $all     = $this->resolver->resolve();
        $applied = array_flip($this->repository->appliedFilenames());

        return array_filter($all, static fn($_, $filename) => !isset($applied[$filename]), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Return the last N applied migration filenames in rollback order (newest first).
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

        // Group into batches and take $steps batches from the end
        $grouped = [];
        foreach ($all as $filename) {
            $batch             = $batches[$filename] ?? 0;
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