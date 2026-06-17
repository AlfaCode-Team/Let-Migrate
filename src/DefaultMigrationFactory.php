<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\MigrationFactoryInterface;
use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Exception\MigrationException;

/**
 * The default factory: plain `new $class()`. Identical to the previous
 * reflection `newInstance()` behaviour, so existing zero-dependency
 * migrations are completely unaffected.
 */
final class DefaultMigrationFactory implements MigrationFactoryInterface
{
    public function make(string $class): MigrationInterface
    {
        if (!class_exists($class)) {
            throw new MigrationException("Migration class '{$class}' not found.");
        }

        $migration = new $class();

        if (!$migration instanceof MigrationInterface) {
            throw new MigrationException(
                "Class '{$class}' must implement MigrationInterface.",
            );
        }

        return $migration;
    }
}