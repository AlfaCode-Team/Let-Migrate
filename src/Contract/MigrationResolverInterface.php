<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

/**
 * Discovers and instantiates migration classes from the filesystem.
 *
 * The default implementation is Resolver\FilesystemMigrationResolver.
 * Custom implementations allow migrations to be loaded from a database,
 * a remote store, or an in-memory array (useful in tests).
 */
interface MigrationResolverInterface
{
    /**
     * Return all discovered migration instances, keyed by their canonical filename
     * (e.g. "2024_01_01_000001_create_users_table"), sorted ascending.
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
