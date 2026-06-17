<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

/**
 * Discovers and instantiates migration classes from the filesystem.
 *
 * The default implementation is FilesystemMigrationResolver.
 * Custom implementations allow migrations to be loaded from a database,
 * a remote store, or an in-memory array (useful in tests).
 *
 * ────────────────────────────────────────────────────────────────────
 * FIX SUMMARY
 * ────────────────────────────────────────────────────────────────────
 * • M-04: resolveAll() added as an explicit alias of resolve() so callers
 *         that used the (previously undefined) resolveAll() keep working.
 * • M-05: resolveNames() added so callers can resolve a filtered subset
 *         by filename key.
 *
 * NOTE: the corrected MigrationService no longer calls resolveAll()/
 * resolveNames() (it delegates to the runner), but these methods are kept
 * on the contract because migrate:squash and external callers may rely on
 * them, and they make the resolver API complete and unambiguous.
 */
interface MigrationResolverInterface
{
    /**
     * Return all discovered migration instances, keyed by their canonical
     * filename (e.g. "2024_01_01_000001_create_users_table"), sorted
     * ascending.
     *
     * @return array<string, MigrationInterface>
     */
    public function resolve(): array;

    /**
     * Alias of resolve() — returns every discovered migration.
     *
     * @return array<string, MigrationInterface>
     */
    public function resolveAll(): array;

    /**
     * Resolve only the named migrations (filtered by filename key),
     * preserving sort order.
     *
     * @param  string[]                          $filenames
     * @return array<string, MigrationInterface>
     */
    public function resolveNames(array $filenames): array;

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