<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Seeder\Factory;

/**
 * Cycles a fixed set of values across generated rows (Laravel factory
 * sequence parity): row 0 → values[0], row 1 → values[1], … wrapping.
 *
 *   new Sequence(['free', 'pro', 'enterprise'])
 *   // row0=free row1=pro row2=enterprise row3=free …
 *
 * A value may be a callable `fn(int $index): mixed` for computed cycles.
 */
final class Sequence
{
    /** @var array<int, mixed> */
    private array $values;

    public function __construct(mixed ...$values)
    {
        $this->values = array_values($values);
    }

    public function valueFor(int $index): mixed
    {
        if ($this->values === []) {
            return null;
        }

        $v = $this->values[$index % count($this->values)];

        return is_callable($v) ? $v($index) : $v;
    }

    public function count(): int
    {
        return count($this->values);
    }
}