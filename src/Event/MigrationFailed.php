<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Event;

final class MigrationFailed extends MigrationEvent
{
    public function __construct(
        public readonly string     $migration,
        public readonly string     $direction,
        public readonly \Throwable $exception,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'let_migrate.migration_failed';
    }
}
