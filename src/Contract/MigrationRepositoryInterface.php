<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

use AlfaCode\LetMigrate\MigrationRecord;

/**
 * Persists the list of applied migrations so the runner can distinguish
 * pending from completed migrations across runs.
 *
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  INTERNAL CONTRACT — SERVICE BOUNDARY                       ║
 * ║                                                             ║
 * ║  Only MigrationService (via MigrationServiceFactory) may   ║
 * ║  hold a reference to this interface.  All other code must   ║
 * ║  depend on MigrationServiceInterface.                       ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * ────────────────────────────────────────────────────────────────────
 * FIX SUMMARY
 * ────────────────────────────────────────────────────────────────────
 * The corrected MigrationService delegates to MigrationRunner and no
 * longer calls the missing methods getLastBatches()/getAll()/
 * getLastBatchNumber(). They are nevertheless added here as thin,
 * well-defined convenience accessors so any other caller (and the
 * DatabaseMigrationRepository implementation) has a single, consistent
 * contract — and so the previously-broken call sites are now valid if
 * re-introduced.
 */
interface MigrationRepositoryInterface
{
    /**
     * Return every applied migration record, ordered ascending by batch
     * then filename.
     *
     * @return MigrationRecord[]
     */
    public function all(): array;

    /**
     * Alias of all() — explicit name used by some callers (M-02).
     *
     * @return MigrationRecord[]
     */
    public function getAll(): array;

    /**
     * Return the highest batch number already applied, or 0 if none.
     */
    public function lastBatch(): int;

    /**
     * Alias of lastBatch() — explicit name used by some callers (M-03).
     */
    public function getLastBatchNumber(): int;

    /**
     * Return all records belonging to the last batch (for rollback).
     *
     * @return MigrationRecord[]
     */
    public function lastBatchRecords(): array;

    /**
     * Return all records belonging to the last N batches, newest first
     * (M-01). Used by multi-step rollback.
     *
     * @return MigrationRecord[]
     */
    public function getLastBatches(int $steps): array;

    /**
     * Return all applied migration filenames in ascending order.
     *
     * @return string[]
     */
    public function appliedFilenames(): array;

    /**
     * Persist a newly applied migration in the given batch.
     */
    public function log(string $filename, int $batch): void;

    /**
     * Remove a migration record (used during rollback).
     */
    public function remove(string $filename): void;

    /**
     * Create the tracking table if it does not yet exist.
     * Idempotent — safe to call on every run.
     */
    public function ensureTable(): void;

    /**
     * Return true when the tracking table already exists in the database.
     */
    public function repositoryExists(): bool;
}