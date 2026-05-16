<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Seeder;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Exception\LetMigrateException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Discovers, orders, and executes database seeders.
 *
 * Execution order is determined by topological sort of SeederInterface::getDependencies().
 * Circular dependency chains are detected and raise a LetMigrateException.
 *
 * Usage:
 *
 *   $runner = new SeederRunner(
 *       driver:     $driver,
 *       repository: new SeederRepository($driver, $grammar),
 *       paths:      [__DIR__ . '/seeders'],
 *   );
 *
 *   $runner->run();            // run all pending seeders
 *   $runner->run(force: true); // re-run even if already seeded
 *   $runner->fresh();          // deleteAll tracking records + run all
 */
final class SeederRunner
{
    public function __construct(
        private readonly DatabaseDriverInterface $driver,
        private readonly SeederRepository        $repository,
        /** @var string[] */
        private readonly array                   $paths,
        private readonly LoggerInterface         $logger = new NullLogger(),
    ) {}

    // ── Public API ────────────────────────────────────────────────

    /**
     * Run all pending seeders (those not yet recorded in the tracking table).
     *
     * @param bool $force When true, re-run all seeders regardless of history.
     *
     * @return string[] Names of seeders that were executed.
     */
    public function run(bool $force = false): array
    {
        $this->repository->ensureTable();

        $all     = $this->resolve();
        $ran     = $force ? [] : array_flip($this->repository->ranNames());
        $pending = array_filter($all, static fn($_, string $k) => !isset($ran[$k]), ARRAY_FILTER_USE_BOTH);

        if (empty($pending)) {
            $this->logger->info('[LetMigrate:Seeder] Nothing to seed.');
            return [];
        }

        $ordered = $this->topologicalSort($pending);
        $batch   = $this->repository->lastBatch() + 1;
        $seeded  = [];

        foreach ($ordered as $name => $seeder) {
            $this->logger->info("[LetMigrate:Seeder] Seeding: {$name}");

            try {
                $seeder->run($this->driver);
                $this->repository->log($name, $batch);
                $seeded[] = $name;
                $this->logger->info("[LetMigrate:Seeder] Seeded:  {$name}");
            } catch (\Throwable $e) {
                $this->logger->error("[LetMigrate:Seeder] Failed:  {$name} — {$e->getMessage()}");
                throw new LetMigrateException(
                    "Seeder '{$name}' failed: {$e->getMessage()}",
                    (int) $e->getCode(),
                    $e,
                );
            }
        }

        return $seeded;
    }

    /**
     * Clear all seeder tracking records then re-run every seeder.
     *
     * @return string[] Names of seeders that were executed.
     */
    public function fresh(): array
    {
        $this->repository->ensureTable();
        $this->repository->deleteAll();
        $this->logger->info('[LetMigrate:Seeder] Cleared seeder history — re-running all seeders.');

        return $this->run(force: true);
    }

    /**
     * Return status information for every discovered seeder.
     *
     * @return array<string, array{status: string, batch: int|null, seeded_at: string|null}>
     */
    public function status(): array
    {
        $this->repository->ensureTable();

        $records = [];
        foreach ($this->repository->all() as $record) {
            $records[$record->seeder] = $record;
        }

        $all    = $this->resolve();
        $result = [];

        foreach (array_keys($all) as $name) {
            if (isset($records[$name])) {
                $result[$name] = [
                    'status'    => 'run',
                    'batch'     => $records[$name]->batch,
                    'seeded_at' => $records[$name]->seededAt,
                ];
            } else {
                $result[$name] = [
                    'status'    => 'pending',
                    'batch'     => null,
                    'seeded_at' => null,
                ];
            }
        }

        return $result;
    }

    // ── Discovery ─────────────────────────────────────────────────

    /**
     * Discover all seeder files from the configured paths.
     * Files are sorted lexicographically within each path.
     *
     * @return array<string, SeederInterface> basename => instance
     */
    public function resolve(): array
    {
        $seeders = [];

        foreach ($this->paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $files = glob(rtrim($path, '/') . '/*.php') ?: [];
            sort($files);

            foreach ($files as $file) {
                $name    = basename($file, '.php');
                $seeder  = require $file;

                if (!$seeder instanceof SeederInterface) {
                    throw new LetMigrateException(
                        "Seeder file '{$file}' must return an instance of SeederInterface.",
                    );
                }

                $seeders[$name] = $seeder;
            }
        }

        return $seeders;
    }

    // ── Topological sort ──────────────────────────────────────────

    /**
     * Sort seeders so that all dependencies of a seeder appear before it.
     *
     * Uses Kahn's algorithm (BFS-based topological sort).
     * Raises LetMigrateException when a circular dependency is detected.
     *
     * @param array<string, SeederInterface> $seeders
     *
     * @return array<string, SeederInterface> ordered
     */
    private function topologicalSort(array $seeders): array
    {
        // Build adjacency and in-degree maps
        $inDegree  = array_fill_keys(array_keys($seeders), 0);
        $dependents = array_fill_keys(array_keys($seeders), []); // who depends on X

        foreach ($seeders as $name => $seeder) {
            foreach ($seeder->getDependencies() as $dep) {
                if (!isset($seeders[$dep])) {
                    // Dependency not in pending set — skip silently (may have already run)
                    continue;
                }

                $inDegree[$name]++;
                $dependents[$dep][] = $name;
            }
        }

        // Start queue with nodes that have no incoming edges
        $queue  = array_keys(array_filter($inDegree, static fn(int $d) => $d === 0));
        $sorted = [];

        while (!empty($queue)) {
            $current           = array_shift($queue);
            $sorted[$current]  = $seeders[$current];

            foreach ($dependents[$current] as $dependent) {
                $inDegree[$dependent]--;

                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        if (count($sorted) !== count($seeders)) {
            $remaining = implode(', ', array_keys(array_diff_key($seeders, $sorted)));
            throw new LetMigrateException(
                "Circular dependency detected among seeders: {$remaining}",
            );
        }

        return $sorted;
    }
}
