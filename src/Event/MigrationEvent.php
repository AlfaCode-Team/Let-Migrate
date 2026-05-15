<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Event;

/**
 * Base class for all LetMigrate lifecycle events.
 * Stamped with the UTC datetime of creation.
 */
abstract class MigrationEvent
{
    public readonly string $occurredAt;

    public function __construct()
    {
        $this->occurredAt = date('Y-m-d H:i:s');
    }

    abstract public function getName(): string;
}
