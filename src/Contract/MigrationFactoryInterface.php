<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

/**
 * Pluggable instantiation strategy for class-based migrations.
 *
 * Default behaviour (DefaultMigrationFactory) is `new $class()` — exactly
 * as before, zero behavioural change. A host app that needs data-backfill
 * migrations to receive services (logger, HTTP client, config, an ORM
 * entity manager, …) supplies a container-backed factory instead — the
 * Doctrine "inject services into migrations" capability, framework-
 * agnostic via PSR-11.
 *
 * Only the class-declaration migration form goes through the factory.
 * The `return new class implements MigrationInterface {}` form is already
 * an instance and is used as-is.
 */
interface MigrationFactoryInterface
{
    /**
     * @param class-string<MigrationInterface> $class
     */
    public function make(string $class): MigrationInterface;
}