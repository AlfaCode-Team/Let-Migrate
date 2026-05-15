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
     * @return array<string, array{status: string, batch: int|null}>
     */
    public function status(): array;

    /**
     * Return pending migrations keyed by filename.
     *
     * @return array<string, MigrationInterface>
     */
    public function pending(): array;
}
