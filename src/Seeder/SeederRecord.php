<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Seeder;

/**
 * Immutable record of a seeder that has been executed and logged.
 */
final class SeederRecord
{
    public function __construct(
        public readonly string $seeder,
        public readonly int    $batch,
        public readonly string $seededAt,
    ) {}
}
