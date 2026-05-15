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
        $after = get_declared_classes();

        if ($result instanceof MigrationInterface) {
            return $result;
        }

        if ($result !== null && !is_object($result)) {
            throw new MigrationException(
                "Migration file '{$filePath}' must return an object implementing MigrationInterface or null."
            );
        }

        $newClasses = array_values(array_diff($after, $before));

        foreach (array_reverse($newClasses) as $class) {
            $ref = new \ReflectionClass($class);

            if ($ref->isInstantiable() && $ref->implementsInterface(MigrationInterface::class)) {
                /** @var MigrationInterface $obj */
                $obj = $ref->newInstance();
                return $obj;
            }
        }

        throw new MigrationException(
            "Migration file '{$filePath}' did not define a class implementing MigrationInterface."
        );
    }
}
