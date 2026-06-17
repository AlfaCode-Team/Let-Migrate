<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\MigrationFactoryInterface;
use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Exception\MigrationException;
use Psr\Container\ContainerInterface;

/**
 * Resolves migrations through a PSR-11 container so data-backfill
 * migrations can declare constructor dependencies (logger, HTTP client,
 * config, ORM entity manager, …). This is the framework-agnostic
 * equivalent of Doctrine's custom migration factory — Laravel and Symfony
 * both expose PSR-11 containers, so it works everywhere with no coupling.
 *
 *   final class BackfillUserSlugs implements MigrationInterface
 *   {
 *       public function __construct(private SlugService $slugs) {}
 *       public function up(SchemaBuilderInterface $s): void { … $this->slugs … }
 *       public function down(SchemaBuilderInterface $s): void {}
 *   }
 *
 * Wire it: pass a ContainerMigrationFactory into MigrationServiceFactory
 * (see PATCHES-phase3-di.md). If the container cannot resolve the class it
 * falls back to a plain `new $class()` so mixed projects keep working.
 */
final class ContainerMigrationFactory implements MigrationFactoryInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function make(string $class): MigrationInterface
    {
        $migration = $this->container->has($class)
            ? $this->container->get($class)
            : $this->fallback($class);

        if (!$migration instanceof MigrationInterface) {
            throw new MigrationException(
                "Container returned a non-MigrationInterface for '{$class}'.",
            );
        }

        return $migration;
    }

    private function fallback(string $class): object
    {
        if (!class_exists($class)) {
            throw new MigrationException("Migration class '{$class}' not found.");
        }

        return new $class();
    }
}