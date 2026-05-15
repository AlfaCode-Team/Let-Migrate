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
 */
interface MigrationRepositoryInterface
{
    /**
     * Return every applied migration record, ordered ascending by batch then filename.
     *
     * @return MigrationRecord[]
     */
    public function all(): array;

    /**
     * Return the highest batch number already applied, or 0 if none.
     */
    public function lastBatch(): int;

    /**
     * Return all records belonging to the last batch (for rollback).
     *
     * @return MigrationRecord[]
     */
    public function lastBatchRecords(): array;

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
