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
 * • Filenames must be PHP files matching the pattern:
 *     YYYY_MM_DD_NNNNNN_description.php
 *   e.g. 2024_01_15_000001_create_users_table.php
 *
 * • Each file must return a class that implements MigrationInterface,
 *   OR contain a class whose name matches the studly-cased filename.
 *
 * • Files are sorted lexicographically — the timestamp prefix ensures
 *   correct execution order.
 *
 * • Multiple paths are supported. Files from all paths are merged and
 *   sorted together before resolving.
 */
final class FilesystemMigrationResolver implements MigrationResolverInterface
{
    /** @var string[] */
    private array $paths = [];

    /** @param string[] $paths */
    public function __construct(array $paths = [])
    {
        foreach ($paths as $path) {
            $this->addPath($path);
        }
    }

    public function addPath(string $path): void
    {
        $real = realpath($path);

        if ($real === false || !is_dir($real)) {
            throw new MigrationException("Migration path does not exist or is not a directory: {$path}");
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
        ksort($files); // lexicographic sort by filename key

        $migrations = [];

        foreach ($files as $filename => $filePath) {
            $instance = $this->loadFile($filePath);
            $migrations[$filename] = $instance;
        }

        return $migrations;
    }

    // ── Private helpers ───────────────────────────────────────────

    /**
     * Collect all migration files from all registered paths.
     *
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

    /**
     * A valid migration filename starts with YYYY_MM_DD_ or a numeric prefix.
     */
    private function looksLikeMigration(string $filename): bool
    {
        return (bool) preg_match('/^\d{4}_\d{2}_\d{2}_\d+_.+$/', $filename)
            || (bool) preg_match('/^\d+_.+$/', $filename);
    }

    /**
     * Require the file and resolve the MigrationInterface instance from it.
     *
     * The file may:
     *   1. return a new instance directly (return new MyMigration();)
     *   2. declare a class — we instantiate it by guessing the class name
     */
    private function loadFile(string $filePath): MigrationInterface
    {
        // Snapshot classes before include to detect newly defined class.
        // Use require (not require_once) so multiple resolver instances can each
        // get a fresh return value from the same file in the same process.
        $before = get_declared_classes();
        $result = require $filePath;
        $after  = get_declared_classes();

        // If the file returned an instance directly, use it
        if ($result instanceof MigrationInterface) {
            return $result;
        }

        // Otherwise find the new class declared by this file
        $newClasses = array_values(array_diff($after, $before));

        foreach (array_reverse($newClasses) as $class) {
            $ref = new \ReflectionClass($class);
            if ($ref->isInstantiable() && $ref->implementsInterface(MigrationInterface::class)) {
                return $ref->newInstance();
            }
        }

        throw new MigrationException(
            "Migration file '{$filePath}' did not define a class implementing MigrationInterface.",
        );
    }
}