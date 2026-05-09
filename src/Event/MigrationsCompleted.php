<?php
declare(strict_types=1);
namespace AlfaCode\LetMigrate\Event;

use AlfaCode\LetMigrate\MigrationResult;

final class MigrationsCompleted extends MigrationEvent
{
    public function __construct(
        public readonly MigrationResult $result,
    ) {
        parent::__construct();
    }
    public function getName(): string { return 'let_migrate.migrations_completed'; }
}