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
use AlfaCode\LetMigrate\MigrationRecord;
use AlfaCode\LetMigrate\MigrationResult;
use AlfaCode\LetMigrate\MigrationRunner;
use AlfaCode\LetMigrate\Schema\SchemaBuilder;

/**
 * Concrete service — the SOLE owner of MigrationRepositoryInterface.
 *
 * The repository property is private readonly with no public accessor.
 * All access to tracking-table data flows through this class.
 *
 * @updated baseline() — mark pending files as applied without running up().
 * @updated captureSql() — run named migrations through CaptureDriver, return buffered SQL.
 */
final class MigrationService implements MigrationServiceInterface
{
    public function __construct(
        private readonly MigrationRunnerInterface    $runner,
        private readonly MigrationRepositoryInterface $repository,
        private readonly MigrationResolverInterface  $resolver,
        private readonly SchemaBuilder               $schemaBuilder,
        private readonly MigrationEventDispatcher    $dispatcher,
        private readonly array                       $paths,
    ) {}

    // ── Core operations ───────────────────────────────────────────

    public function run(): MigrationResult
    {
        $this->repository->ensureTable();
        $pending = $this->pending();

        if (empty($pending)) {
            return MigrationResult::empty();
        }

        return $this->runner->run($pending, $this->nextBatch());
    }

    public function rollback(int $steps = 1): MigrationResult
    {
        $this->repository->ensureTable();

        $batches = $this->repository->getLastBatches($steps);

        if (empty($batches)) {
            return MigrationResult::empty();
        }

        $migrations = $this->resolver->resolveNames(
            array_column($batches, 'migration'),
        );

        return $this->runner->rollback($migrations, $batches);
    }

    public function reset(): MigrationResult
    {
        $this->repository->ensureTable();

        $all = $this->repository->getAll();

        if (empty($all)) {
            return MigrationResult::empty();
        }

        $migrations = $this->resolver->resolveNames(
            array_column($all, 'migration'),
        );

        return $this->runner->rollback($migrations, $all);
    }

    public function refresh(): MigrationResult
    {
        $this->reset();

        return $this->run();
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

        $batch    = $this->nextBatch();
        $baselined = [];

        foreach (array_keys($pending) as $filename) {
            $this->repository->log($filename, $batch);
            $baselined[] = $filename;
        }

        return new MigrationResult(
            applied:    $baselined,
            rolledBack: [],
            batch:      $batch,
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
     * @return array{string[], string[]}  [$capturedSql, $processedFilenames]
     */
    public function captureSql(array $filenames): array
    {
        $allMigrations = $this->resolver->resolveAll();

        // Filter to the requested filenames, preserving sort order
        $toCapture = array_filter(
            $allMigrations,
            static fn(string $k) => in_array($k, $filenames, true),
            ARRAY_FILTER_USE_KEY,
        );

        if (empty($toCapture)) {
            return [[], []];
        }

        $realDriver  = $this->schemaBuilder->getDriver();
        $captureDriver = new CaptureDriver($realDriver);

        // Temporarily run all up() methods against the capture driver
        foreach ($toCapture as $filename => $migration) {
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

    // ── Status / query ────────────────────────────────────────────

    public function status(): array
    {
        $this->repository->ensureTable();

        $applied = [];
        foreach ($this->repository->getAll() as $record) {
            $applied[$record['migration']] = [
                'status' => 'applied',
                'batch'  => (int) $record['batch'],
            ];
        }

        $all = $this->resolver->resolveAll();
        $result = [];

        foreach (array_keys($all) as $filename) {
            $result[$filename] = $applied[$filename] ?? [
                'status' => 'pending',
                'batch'  => null,
            ];
        }

        return $result;
    }

    public function pending(): array
    {
        $this->repository->ensureTable();

        $applied = array_column($this->repository->getAll(), 'migration');
        $all     = $this->resolver->resolveAll();

        return array_filter(
            $all,
            static fn(string $k) => !in_array($k, $applied, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function paths(): array
    {
        return $this->paths;
    }

    public function events(): MigrationEventDispatcher
    {
        return $this->dispatcher;
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function nextBatch(): int
    {
        $last = $this->repository->getLastBatchNumber();

        return $last + 1;
    }
}