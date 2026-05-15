<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Event;

final class MigrationStarted extends MigrationEvent
{
    public function __construct(
        public readonly string $migration,
        public readonly string $direction,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'let_migrate.migration_started';
    }
}
