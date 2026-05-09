<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

/**
 * Discovers and instantiates migration classes from the filesystem.
 */
interface MigrationResolverInterface
{
    /**
     * Return all migration instances found in the configured paths, keyed by
     * their canonical filename (e.g. "2024_01_01_000001_create_users_table").
     *
     * Results are sorted in the order they should be applied (filename ascending).
     *
     * @return array<string, MigrationInterface>
     */
    public function resolve(): array;

    /**
     * Add a directory to search for migration files.
     */
    public function addPath(string $path): void;

    /**
     * Return all registered migration paths.
     *
     * @return string[]
     */
    public function paths(): array;
}