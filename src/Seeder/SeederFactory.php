<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Seeder;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;

/**
 * Generates a SeederInterface implementation that inserts N fake rows into a
 * table — without requiring Faker or any external package.
 *
 * Built-in generators (specify by type string in the $definition array):
 *   'id'          → auto-incrementing integer (1, 2, 3 …)
 *   'name'        → random first + last name combination
 *   'first_name'  → random first name
 *   'last_name'   → random last name
 *   'email'       → safe unique fake email (e.g. user_4@example.test)
 *   'username'    → slugified name + random suffix
 *   'password'    → bcrypt hash of 'password' (fixed, intentional for dev)
 *   'uuid'        → RFC 4122 v4 UUID string
 *   'bool'        → random 0 or 1
 *   'int'         → random integer 1–1000
 *   'float'       → random float 1.00–999.99
 *   'text'        → random Lorem Ipsum sentence
 *   'slug'        → url-safe random slug
 *   'url'         → https://example.test/random-path
 *   'ip'          → random IPv4 address
 *   'date'        → YYYY-MM-DD within the last 2 years
 *   'datetime'    → YYYY-MM-DD HH:MM:SS within the last 2 years
 *   'now'         → current datetime (same for all rows in a batch)
 *   'null'        → always NULL
 *   callable      → called with (int $rowIndex) → mixed
 *   mixed         → used as a literal value for every row
 *
 * Usage:
 *
 *   $seeder = SeederFactory::make('users', [
 *       'name'       => 'name',
 *       'email'      => 'email',
 *       'password'   => 'password',
 *       'is_active'  => 1,
 *       'role'       => static fn($i) => $i === 0 ? 'admin' : 'user',
 *       'created_at' => 'datetime',
 *   ], count: 50);
 *
 *   // Returns a SeederInterface — use with SeederRunner or directly:
 *   $seeder->run($db);
 */
final class SeederFactory
{
    /**
     * Create a SeederInterface that inserts $count fake rows into $table.
     *
     * @param string                          $table      target table name
     * @param array<string, string|callable|mixed> $definition column => generator type or callable or literal
     * @param int                             $count      number of rows to insert
     * @param string[]                        $dependencies seeder names this depends on
     */
    public static function make(
        string $table,
        array  $definition,
        int    $count        = 10,
        array  $dependencies = [],
    ): SeederInterface {
        return new class ($table, $definition, $count, $dependencies) implements SeederInterface
        {
            public function __construct(
                private readonly string $table,
                private readonly array  $definition,
                private readonly int    $count,
                private readonly array  $deps,
            ) {}

            public function run(DatabaseDriverInterface $db): void
            {
                $now = date('Y-m-d H:i:s');

                for ($i = 0; $i < $this->count; $i++) {
                    $row = [];

                    foreach ($this->definition as $column => $generator) {
                        $row[$column] = SeederFactory::generate($generator, $i, $now);
                    }

                    $db->insert($this->table, $row);
                }
            }

            public function getDependencies(): array
            {
                return $this->deps;
            }
        };
    }

    /**
     * Resolve a single column value for a given row index.
     *
     * @internal Called by the anonymous class returned from make().
     */
    public static function generate(mixed $generator, int $index, string $now): mixed
    {
        if (is_callable($generator)) {
            return $generator($index);
        }

        if (!is_string($generator)) {
            return $generator; // literal value
        }

        return match ($generator) {
            'id'         => $index + 1,
            'name'       => self::randomFirstName() . ' ' . self::randomLastName(),
            'first_name' => self::randomFirstName(),
            'last_name'  => self::randomLastName(),
            'email'      => 'user_' . ($index + 1) . '_' . substr(md5((string) $index), 0, 4) . '@example.test',
            'username'   => strtolower(self::randomFirstName()) . '_' . rand(100, 9999),
            'password'   => password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
            'uuid'       => self::uuid4(),
            'bool'       => (int) (bool) rand(0, 1),
            'int'        => rand(1, 1000),
            'float'      => round(rand(100, 99999) / 100, 2),
            'text'       => self::loremSentence(),
            'slug'       => self::randomSlug($index),
            'url'        => 'https://example.test/' . self::randomSlug($index),
            'ip'         => rand(1, 254) . '.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(1, 254),
            'date'       => date('Y-m-d', strtotime('-' . rand(0, 730) . ' days')),
            'datetime'   => date('Y-m-d H:i:s', strtotime('-' . rand(0, 730) . ' days -' . rand(0, 86399) . ' seconds')),
            'now'        => $now,
            'null'       => null,
            default      => $generator, // treat as literal string
        };
    }

    // ── Internal generators ───────────────────────────────────────

    private static function randomFirstName(): string
    {
        static $names = [
            'Alice', 'Bob', 'Carol', 'David', 'Eve', 'Frank', 'Grace', 'Henry',
            'Irene', 'Jack', 'Karen', 'Liam', 'Maria', 'Noah', 'Olivia', 'Peter',
            'Quinn', 'Rachel', 'Samuel', 'Tara', 'Uma', 'Victor', 'Wendy', 'Xander',
            'Yasmin', 'Zara', 'Aaron', 'Beth', 'Charles', 'Diana',
        ];

        return $names[array_rand($names)];
    }

    private static function randomLastName(): string
    {
        static $names = [
            'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller',
            'Davis', 'Wilson', 'Anderson', 'Taylor', 'Thomas', 'Jackson', 'White',
            'Harris', 'Martin', 'Thompson', 'Moore', 'Lewis', 'Walker', 'Hall',
            'Allen', 'Young', 'King', 'Wright', 'Scott', 'Green', 'Baker', 'Adams',
        ];

        return $names[array_rand($names)];
    }

    private static function uuid4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function loremSentence(): string
    {
        static $words = [
            'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing',
            'elit', 'sed', 'do', 'eiusmod', 'tempor', 'incididunt', 'ut', 'labore',
            'et', 'dolore', 'magna', 'aliqua', 'enim', 'ad', 'minim', 'veniam',
            'quis', 'nostrud', 'exercitation', 'ullamco', 'laboris', 'nisi',
        ];

        $count    = rand(6, 14);
        $selected = [];
        for ($i = 0; $i < $count; $i++) {
            $selected[] = $words[array_rand($words)];
        }

        return ucfirst(implode(' ', $selected)) . '.';
    }

    private static function randomSlug(int $index): string
    {
        static $adjectives = ['quick', 'bright', 'dark', 'new', 'old', 'fresh', 'cool', 'warm'];
        static $nouns      = ['fox', 'sky', 'sea', 'bird', 'wolf', 'tree', 'rock', 'star'];

        return $adjectives[array_rand($adjectives)]
            . '-'
            . $nouns[array_rand($nouns)]
            . '-'
            . ($index + 1);
    }
}
