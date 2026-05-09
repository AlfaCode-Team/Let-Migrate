<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema;

/**
 * Represents an index (PRIMARY, UNIQUE, or plain INDEX) on a table.
 */
final class IndexDefinition
{
    public const TYPE_PRIMARY = 'PRIMARY';
    public const TYPE_UNIQUE  = 'UNIQUE';
    public const TYPE_INDEX   = 'INDEX';

    /**
     * @param string[] $columns
     */
    private function __construct(
        private readonly string $type,
        private readonly array  $columns,
        private readonly string $name = '',
    ) {}

    /** @param string[] $columns */
    public static function primary(array $columns): self
    {
        return new self(self::TYPE_PRIMARY, $columns);
    }

    /** @param string[] $columns */
    public static function unique(array $columns, string $name = ''): self
    {
        return new self(self::TYPE_UNIQUE, $columns, $name);
    }

    /** @param string[] $columns */
    public static function index(array $columns, string $name = ''): self
    {
        return new self(self::TYPE_INDEX, $columns, $name);
    }

    public function getType(): string    { return $this->type; }

    /** @return string[] */
    public function getColumns(): array  { return $this->columns; }
    public function getName(): string    { return $this->name; }
}