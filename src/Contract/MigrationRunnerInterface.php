<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

use AlfaCode\LetMigrate\MigrationResult;

/**
 * Low-level runner contract.
 *
 * MigrationRunner operates one level below MigrationService and wires the
 * repository, resolver, and schema together. External code should prefer
 * MigrationServiceInterface; MigrationRunnerInterface is an internal seam
 * used only by MigrationService.
 */
interface MigrationRunnerInterface
{
    /**
     * Apply all pending migrations.
     */
    public function run(): MigrationResult;

    /**
     * Reverse the last N batches.
     */
    public function rollback(int $steps = 1): MigrationResult;

    /**
     * Roll back ALL applied migrations.
     */
    public function reset(): MigrationResult;

    /**
     * Reset then re-run everything.
     */
    public function refresh(): MigrationResult;

    /**
     * Return per-migration status map.
     *
     * @return array<string, array{status: string, batch: int|null, applied_at: string|null}>
     */
    public function status(): array;

    /**
     * Return pending migrations keyed by filename.
     *
     * @return array<string, MigrationInterface>
     */
    public function pending(): array;

    /**
     * Migrate up or down until the database is exactly at $target
     * (Yii `migrate/to` parity).
     */
    public function migrateTo(string $target): MigrationResult;

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
    public function fresh(): MigrationResult;

    /**
     * Roll back the last $steps migrations and immediately re-apply
     * exactly those (Yii `migrate/redo` parity).
     *
     * Unlike rollback()+run(), this re-applies ONLY the migrations it
     * just reverted — it will not pull in other pending migrations.
     */
    public function redo(int $steps = 1): MigrationResult;
}
