<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema;

/**
 * Applies an optional global table-name prefix (e.g. "app_") so multiple
 * applications can share one database, or to namespace tables.
 *
 * Idempotent: prefixing an already-prefixed name is a no-op, so it is
 * safe to call repeatedly (SchemaBuilder may apply it at several points).
 */
final class TablePrefixer
{
    public function __construct(
        private readonly string $prefix = '',
    ) {}

    public function prefix(string $table): string
    {
        if ($this->prefix === '' || $table === '') {
            return $table;
        }

        if (str_starts_with($table, $this->prefix)) {
            return $table; // already prefixed — idempotent
        }

        return $this->prefix . $table;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function isEnabled(): bool
    {
        return $this->prefix !== '';
    }
}