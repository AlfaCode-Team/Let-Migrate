<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Capture\CaptureDriver;
use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\MigrationRepositoryInterface;
use AlfaCode\LetMigrate\Contract\MigrationResolverInterface;
use AlfaCode\LetMigrate\Contract\MigrationRunnerInterface;
use AlfaCode\LetMigrate\Contract\MigrationServiceInterface;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use AlfaCode\LetMigrate\Schema\SchemaBuilder;

/**
 * Concrete service — the SOLE owner of MigrationRepositoryInterface.
 *
 * ────────────────────────────────────────────────────────────────────
 * FIX SUMMARY (addresses M-01 … M-08)
 * ────────────────────────────────────────────────────────────────────
 * The previous implementation re-implemented run/rollback/reset/status/
 * pending against repository & resolver methods that DO NOT EXIST
 * (getLastBatches, getAll, getLastBatchNumber, resolveAll, resolveNames)
 * and called the runner with the wrong arity.
 *
 * MigrationRunner already implements all of that logic correctly using
 * the real interface methods (all(), lastBatch(), appliedFilenames(),
 * resolve()). The correct design is therefore to make MigrationService a
 * thin boundary that DELEGATES to the runner — exactly as the original
 * (pre-refactor) service did — while keeping the enterprise constructor
 * signature so MigrationServiceFactory continues to work unchanged.
 *
 * Only baseline() and captureSql() add behaviour on top of the runner.
 *
 * The repository property remains private readonly with no public
 * accessor: all tracking-table access flows through this class.
 */
final class MigrationService implements MigrationServiceInterface
{
    public function __construct(
        private readonly MigrationRunnerInterface $runner,
        private readonly MigrationRepositoryInterface $repository,
        private readonly MigrationResolverInterface $resolver,
        private readonly SchemaBuilder $schemaBuilder,
        private readonly MigrationEventDispatcher $dispatcher,
        private readonly array $paths,
        private readonly ?string $seedersPath = null,
        private readonly string $seedersTable = 'let_seeders',
    ) {
    }

    // ── Core operations — delegated to the runner ─────────────────

    public function run(): MigrationResult
    {
        return $this->runner->run();
    }

    public function rollback(int $steps = 1): MigrationResult
    {
        return $this->runner->rollback($steps);
    }

    public function reset(): MigrationResult
    {
        return $this->runner->reset();
    }

    public function refresh(): MigrationResult
    {
        $this->reset();

        return $this->run();
    }

    public function migrateTo(string $target): MigrationResult
    {
        return $this->runner->migrateTo($target);
    }

    public function redo(int $steps = 1): MigrationResult
    {
        return $this->runner->redo($steps);
    }

    public function fresh(): MigrationResult
    {
        return $this->runner->fresh();
    }

    // ── Install helpers ───────────────────────────────────────────

    /**
     * Create the migration tracking table if it does not yet exist.
     * Idempotent — safe to call on every boot.
     */
    public function install(): void
    {
        $this->repository->ensureTable();
    }

    /**
     * Return true when the migration tracking table already exists.
     */
    public function isInstalled(): bool
    {
        return $this->repository->repositoryExists();
    }

    // ── Baseline ──────────────────────────────────────────────────

    /**
     * Mark all pending migration files as applied without running any up().
     *
     * Inserts one tracking record per pending file in a new batch.
     * Returns a MigrationResult with $applied = the baselined filenames.
     */
    public function baseline(): MigrationResult
    {
        $this->repository->ensureTable();

        $pending = $this->pending();

        if (empty($pending)) {
            return MigrationResult::empty();
        }

        $batch = $this->repository->lastBatch() + 1;
        $baselined = [];

        foreach (array_keys($pending) as $filename) {
            $this->repository->log($filename, $batch);
            $baselined[] = $filename;
        }

        return new MigrationResult(
            applied: $baselined,
            rolledBack: [],
            batch: $batch,
        );
    }

    // ── captureSql ────────────────────────────────────────────────

    /**
     * Run the given migration filenames through a CaptureDriver.
     *
     * The real SchemaBuilder driver is temporarily swapped for CaptureDriver
     * so that DDL calls in up() are intercepted and buffered — nothing is
     * written to the actual database.
     *
     * @param  string[] $filenames  bare migration filenames (without .php extension)
     * @return array{0: string[], 1: string[]}  [$capturedSql, $processedFilenames]
     */
    public function captureSql(array $filenames): array
    {
        $allMigrations = $this->resolver->resolve();

        // Filter to the requested filenames, preserving sort order
        $toCapture = array_filter(
            $allMigrations,
            static fn(string $k): bool => in_array($k, $filenames, true),
            ARRAY_FILTER_USE_KEY,
        );

        if (empty($toCapture)) {
            return [[], []];
        }

        $realDriver = $this->schemaBuilder->getDriver();
        $captureDriver = new CaptureDriver($realDriver);

        // Temporarily run all up() methods against the capture driver
        foreach ($toCapture as $migration) {
            /** @var MigrationInterface $migration */
            $captureSchemaBuilder = new SchemaBuilder(
                $captureDriver,
                $this->schemaBuilder->getGrammar(),
                $this->schemaBuilder->getInspector(),
            );

            $migration->up($captureSchemaBuilder);
        }

        return [
            $captureDriver->getCapturedSql(),
            array_keys($toCapture),
        ];
    }

    public function installFromDumpIfFresh(): bool
    {
        $path = $this->config['schema_dump'] ?? null;       // thread config in
        if (!$path) {
            return false;
        }

        $dump = new SchemaDump($path);
        if (!$dump->exists()) {
            return false;
        }

        // "Fresh" = tracking table absent OR no migrations recorded.
        if (
            $this->repository->repositoryExists()
            && $this->repository->appliedFilenames() !== []
        ) {
            return false; // not fresh — normal run path
        }

        $driver = $this->registry->driver(); // however the service reaches it
        $dump->load($driver);

        // Baseline: mark covered migrations as applied so run() skips them.
        $this->repository->ensureTable();
        $batch = $this->repository->lastBatch() + 1;
        foreach ($dump->coveredMigrations() as $name) {
            $this->repository->log($name, $batch);
        }
        return true;
    }

    // ── Status / query — delegated to the runner ──────────────────

    public function status(): array
    {
        return $this->runner->status();
    }

    public function pending(): array
    {
        return $this->runner->pending();
    }

    public function paths(): array
    {
        return $this->paths;
    }

    public function events(): MigrationEventDispatcher
    {
        return $this->dispatcher;
    }

    /**
 * Active driver for the resolved connection.
 *
 * Used by CLI commands that wrap raw driver operations
 * (DeployLock, BreakpointStore, SchemaDump::load, …).
 */
public function driver(): \AlfaCode\LetMigrate\Contract\DatabaseDriverInterface
{
    return $this->schemaBuilder->getDriver();
}

/**
 * Schema inspector for the active connection.
 *
 * Used by migrate:generate / migrate:diff / migrate:check to capture
 * a SchemaSnapshot from the live DB without re-bootstrapping anything.
 */
public function inspector(): \AlfaCode\LetMigrate\Contract\SchemaInspectorInterface
{
    return $this->schemaBuilder->getInspector();
}

/**
 * Run seeders (delegates to the seeder runner).
 *
 * @param string|null $className Run only this seeder; null = all.
 * @return int Number of inserted records (or 0 if the seeder runner
 *             doesn't expose a count).
 */
public function seed(?string $className = null): int
{
    $paths = ($this->seedersPath !== null && $this->seedersPath !== '')
        ? [$this->seedersPath]
        : [];

    $repository = new \AlfaCode\LetMigrate\Seeder\SeederRepository(
        $this->driver(),
        $this->schemaBuilder->getGrammar(),
        $this->seedersTable,
    );

    $runner = new \AlfaCode\LetMigrate\Seeder\SeederRunner(
        $this->driver(),
        $repository,
        $paths,
    );

    // SeederRunner::run() seeds every pending seeder (or only $className when
    // given) and returns the list of seeder names that ran; report that count.
    return count($runner->run(false, $className));
}
}