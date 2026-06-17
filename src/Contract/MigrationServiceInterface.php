<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

use AlfaCode\LetMigrate\MigrationResult;

/**
 * The only public contract for all migration operations.
 *
 * External code (CLI commands, framework adapters, application code) must
 * depend ONLY on this interface — never on MigrationService or MigrationRunner.
 *
 * @updated Added baseline() — mark all pending migrations as applied without running them.
 * @updated Added captureSql() — run named migrations against CaptureDriver and return SQL.
 */
interface MigrationServiceInterface
{
    /**
     * Apply all pending migrations in lexicographic order.
     * Each migration runs in its own transaction (when transactional=true).
     */
    public function run(): MigrationResult;

    /**
     * Roll back the last N batches in reverse order.
     */
    public function rollback(int $steps = 1): MigrationResult;

    /**
     * Roll back every applied migration.
     */
    public function reset(): MigrationResult;

    /**
     * Roll back everything then re-apply all migrations.
     */
    public function refresh(): MigrationResult;
    /**
     * Migrate up or down until the database is exactly at $target.
     * Up if $target is pending; down (keeping $target) if already applied.
     */
    public function migrateTo(string $target): MigrationResult;

    /**
     * Mark all currently pending migration files as applied without executing
     * any up() method. Inserts tracking records in a single new batch.
     *
     * Use this when bringing an existing database under migration control:
     * the schema already matches what the migration files would create.
     *
     * The returned MigrationResult has $applied = list of baselined filenames
     * and $batch = the new batch number.
     */
    public function baseline(): MigrationResult;

    /**
     * Run the given migration filenames through a CaptureDriver — all DDL is
     * intercepted and buffered without touching the database.
     *
     * Returns [$capturedSqlStatements, $migratedFilenames]:
     *   - $capturedSqlStatements — string[] each SQL statement executed by up()
     *   - $migratedFilenames     — string[] the filenames that were processed
     *
     * Used by migrate:squash to collect SQL before writing the squash file.
     *
     * @param  string[] $filenames  bare filenames (without .php extension) to process
     * @return array{string[], string[]}
     */
    public function captureSql(array $filenames): array;

    /**
     * Return the run/pending status of every discovered migration.
     *
     * @return array<string, array{status: string, batch: int|null, applied_at: string|null}>
     */
    public function status(): array;

    /**
     * Return all unapplied MigrationInterface instances, keyed by filename.
     *
     * @return array<string, MigrationInterface>
     */
    public function pending(): array;

    /**
     * Return the configured migration file paths.
     *
     * @return string[]
     */
    public function paths(): array;

    /**
     * Roll back the last $steps migrations and re-apply exactly those.
     */
    public function redo(int $steps = 1): MigrationResult;

    /**
     * Drop every table then re-run all migrations from scratch.
     */
    public function fresh(): MigrationResult;

    /**
     * Create the migration tracking table if it does not yet exist.
     * Idempotent — safe to call on every boot.
     */
    public function install(): void;

    /**
     * Return true when the migration tracking table already exists.
     */
    public function isInstalled(): bool;

    /**
     * Return the event dispatcher so CLI commands can wire progress callbacks.
     */
    public function events(): \AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
}
