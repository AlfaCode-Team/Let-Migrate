<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Exception;

/**
 * Thrown when a migration file fails during up() or down().
 */
final class MigrationException extends LetMigrateException {}
