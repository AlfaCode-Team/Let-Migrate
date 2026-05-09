<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

use AlfaCode\LetMigrate\Migration\MigrationRecord;

/**
 * Persists the list of applied migrations so the runner can distinguish
 * pending from completed migrations across runs.
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
     * Return all records that belong to the last batch (for rollback).
     *
     * @return MigrationRecord[]
     */
    public function lastBatchRecords(): array;

    /**
     * Return all applied migration filenames.
     *
     * @return string[]
     */
    public function appliedFilenames(): array;

    /**
     * Persist a newly applied migration.
     */
    public function log(string $filename, int $batch): void;

    /**
     * Remove a migration record (used during rollback).
     */
    public function remove(string $filename): void;

    /**
     * Create the tracking table if it does not yet exist.
     * Called automatically on first run.
     */
    public function ensureTable(): void;

    /**
     * Return true when the repository table already exists.
     */
    public function repositoryExists(): bool;
}