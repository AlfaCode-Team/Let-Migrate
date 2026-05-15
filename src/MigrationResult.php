<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

/**
 * Immutable result returned after run(), rollback(), reset(), or refresh().
 */
final readonly class MigrationResult
{
    /**
     * @param string[] $applied
     * @param string[] $rolledBack
     */
    public function __construct(
        public array $applied,
        public array $rolledBack,
        public int   $batch,
    ) {}

    public static function empty(): self
    {
        return new self(applied: [], rolledBack: [], batch: 0);
    }

    public function appliedCount(): int
    {
        return count($this->applied);
    }

    public function rolledBackCount(): int
    {
        return count($this->rolledBack);
    }

    public function isEmpty(): bool
    {
        return $this->appliedCount() === 0 && $this->rolledBackCount() === 0;
    }

    public function summary(): string
    {
        if ($this->isEmpty()) {
            return 'Nothing to migrate.';
        }

        $parts = [];

        if ($this->appliedCount() > 0) {
            $parts[] = sprintf(
                '%d migration(s) applied in batch %d.',
                $this->appliedCount(),
                $this->batch,
            );
        }

        if ($this->rolledBackCount() > 0) {
            $parts[] = sprintf('%d migration(s) rolled back.', $this->rolledBackCount());
        }

        return implode(' ', $parts);
    }
}
