<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

use AlfaCode\LetMigrate\MigrationResult;

/**
 * The public-facing service contract for all migration operations.
 *
 * This is the ONLY interface external code (commands, controllers, CLI tools)
 * should depend on. The concrete implementation (MigrationService) is the
 * single authorised holder of a MigrationRepositoryInterface reference —
 * nothing else in the application may access the repository directly.
 *
 * Dependency graph enforced by this layer:
 *
 *   External Code
 *       ↓  (depends only on this interface)
 *   MigrationServiceInterface
 *       ↓  (single concrete implementation)
 *   MigrationService  ← sole repository owner
 *       ↓
 *   MigrationRepositoryInterface  (private, sealed inside service)
 */
interface MigrationServiceInterface
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
     * Roll back ALL applied migrations (destructive — dev / test only).
     */
    public function reset(): MigrationResult;

    /**
     * Reset then re-run all migrations (destructive — dev / test only).
     */
    public function refresh(): MigrationResult;

    /**
     * Return status of every discovered migration.
     *
     * @return array<string, array{status: string, batch: int|null}>
     */
    public function status(): array;

    /**
     * Return migrations not yet applied.
     *
     * @return array<string, MigrationInterface>
     */
    public function pending(): array;

    /**
     * Expose the event dispatcher so callers can register lifecycle listeners
     * without needing a reference to the runner or repository.
     */
    public function events(): \AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
}
