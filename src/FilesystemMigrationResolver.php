<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\MigrationResolverInterface;
use AlfaCode\LetMigrate\Exception\MigrationException;

/**
 * Discovers migration classes from one or more filesystem directories.
 *
 * Conventions
 * ───────────
 * • Filenames must match:  YYYY_MM_DD_NNNNNN_description.php
 * • Each file must return an object implementing MigrationInterface, or
 *   declare a class whose name maps to the studly-cased filename.
 * • Files are sorted lexicographically so the timestamp prefix enforces order.
 * • Multiple paths are supported and merged before sorting.
 *
 * ────────────────────────────────────────────────────────────────────
 * FIX SUMMARY (M-04 / M-05)
 * ────────────────────────────────────────────────────────────────────
 * • resolveAll(): explicit alias of resolve() to satisfy the updated
 *   MigrationResolverInterface and any caller using the old name.
 * • resolveNames(): returns a filename-filtered subset of resolve(),
 *   preserving sort order.
 */
final class FilesystemMigrationResolver implements MigrationResolverInterface
{
    /** @var string[] */
    private array $paths = [];

    private readonly \AlfaCode\LetMigrate\Contract\MigrationFactoryInterface $factory;

    /**
     * @param string[] $paths
     */
    public function __construct(
        array $paths = [],
        \AlfaCode\LetMigrate\Contract\MigrationFactoryInterface|null $factory = null,
    ) {
        $this->factory = $factory
            ?? new \AlfaCode\LetMigrate\DefaultMigrationFactory();

        foreach ($paths as $path) {
            $this->addPath($path);
        }
    }

    public function addPath(string $path): void
    {
        $real = realpath($path);

        if ($real === false || !is_dir($real)) {
            throw new MigrationException(
                "Migration path does not exist or is not a directory: {$path}",
            );
        }

        if (!in_array($real, $this->paths, true)) {
            $this->paths[] = $real;
        }
    }

    public function paths(): array
    {
        return $this->paths;
    }

    public function resolve(): array
    {
        $files = $this->discoverFiles();
        ksort($files);

        $migrations = [];

        foreach ($files as $filename => $filePath) {
            $migrations[$filename] = $this->loadFile($filePath);
        }

        return $migrations;
    }

    /**
     * M-04: explicit alias of resolve().
     *
     * @return array<string, MigrationInterface>
     */
    public function resolveAll(): array
    {
        return $this->resolve();
    }

    /**
     * M-05: resolve only the named migrations (by filename key),
     * preserving sort order.
     *
     * @param  string[]                          $filenames
     * @return array<string, MigrationInterface>
     */
    public function resolveNames(array $filenames): array
    {
        return array_filter(
            $this->resolve(),
            static fn(string $k): bool => in_array($k, $filenames, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    // ── Private helpers ───────────────────────────────────────────

    /**
     * @return array<string, string> filename (no ext) => absolute file path
     */
    private function discoverFiles(): array
    {
        $files = [];

        foreach ($this->paths as $path) {
            $iterator = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);

            foreach ($iterator as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                    continue;
                }

                $filename = $fileInfo->getBasename('.php');

                if (!$this->looksLikeMigration($filename)) {
                    continue;
                }

                if (isset($files[$filename])) {
                    throw new MigrationException(
                        "Duplicate migration filename '{$filename}' found in multiple paths.",
                    );
                }

                $files[$filename] = $fileInfo->getPathname();
            }
        }

        return $files;
    }

    private function looksLikeMigration(string $filename): bool
    {
        return (bool) preg_match('/^\d{4}_\d{2}_\d{2}_\d+_.+$/', $filename)
            || (bool) preg_match('/^\d+_.+$/', $filename);
    }

    private function loadFile(string $filePath): MigrationInterface
    {
        $before = get_declared_classes();
        $result = require $filePath;
        $after  = get_declared_classes();

        if ($result instanceof MigrationInterface) {
            return $result;
        }

        if ($result !== null && !is_object($result)) {
            throw new MigrationException(
                "Migration file '{$filePath}' must return an object implementing MigrationInterface or null.",
            );
        }

        $newClasses = array_values(array_diff($after, $before));

        foreach (array_reverse($newClasses) as $class) {
            $ref = new \ReflectionClass($class);

            if ($ref->isInstantiable() && $ref->implementsInterface(MigrationInterface::class)) {
                // Phase 3: route construction through the factory so a
                // container-backed factory can inject services into
                // data-backfill migrations. Default factory == new $class().
                return $this->factory->make($class);
            }
        }

        throw new MigrationException(
            "Migration file '{$filePath}' did not define a class implementing MigrationInterface.",
        );
    }
}