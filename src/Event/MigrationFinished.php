<?php
declare(strict_types=1);
namespace AlfaCode\LetMigrate\Event;

final class MigrationFinished extends MigrationEvent
{
    public function __construct(
        public readonly string $migration,
        public readonly string $direction,
    ) {
        parent::__construct();
    }
    public function getName(): string { return 'let_migrate.migration_finished'; }
}