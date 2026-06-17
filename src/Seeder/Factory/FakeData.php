<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Seeder\Factory;

/**
 * Locale-aware fake-data provider — no Faker / external package required.
 *
 * Locales: 'en' (default), 'es', 'fr'. Unknown locales fall back to 'en'.
 * Deterministic helpers (uniqueEmail/sequenceInt) take the row index so
 * generated datasets are reproducible per row.
 */
final class FakeData
{
    /** @var array<string, array{first:string[], last:string[]}> */
    private const NAMES = [
        'en' => [
            'first' => ['Alice', 'Bob', 'Carol', 'David', 'Eve', 'Frank', 'Grace', 'Henry', 'Ivy', 'Jack'],
            'last'  => ['Smith', 'Johnson', 'Brown', 'Taylor', 'Wilson', 'Davies', 'Evans', 'Walker'],
        ],
        'es' => [
            'first' => ['Lucía', 'Mateo', 'Sofía', 'Hugo', 'Martina', 'Diego', 'Valentina', 'Álvaro'],
            'last'  => ['García', 'Fernández', 'Rodríguez', 'López', 'Martínez', 'Sánchez', 'Pérez'],
        ],
        'fr' => [
            'first' => ['Léa', 'Gabriel', 'Emma', 'Louis', 'Jade', 'Raphaël', 'Alice', 'Arthur'],
            'last'  => ['Martin', 'Bernard', 'Dubois', 'Thomas', 'Robert', 'Richard', 'Petit'],
        ],
    ];

    public function __construct(private readonly string $locale = 'en') {}

    private function pool(): array
    {
        return self::NAMES[$this->locale] ?? self::NAMES['en'];
    }

    public function firstName(): string
    {
        $p = $this->pool()['first'];
        return $p[array_rand($p)];
    }

    public function lastName(): string
    {
        $p = $this->pool()['last'];
        return $p[array_rand($p)];
    }

    public function name(): string
    {
        return $this->firstName() . ' ' . $this->lastName();
    }

    /** Deterministic, collision-free per row index. */
    public function uniqueEmail(int $index): string
    {
        return 'user_' . ($index + 1) . '_'
            . substr(md5((string) $index), 0, 6) . '@example.test';
    }

    public function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    public function int(int $min = 1, int $max = 1000): int
    {
        return random_int($min, $max);
    }

    public function bool(): bool
    {
        return (bool) random_int(0, 1);
    }

    public function sentence(int $words = 8): string
    {
        static $w = ['lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur',
            'adipiscing', 'elit', 'sed', 'tempor', 'labore', 'magna'];
        $out = [];
        for ($i = 0; $i < max(1, $words); $i++) {
            $out[] = $w[array_rand($w)];
        }
        return ucfirst(implode(' ', $out)) . '.';
    }

    public function dateTime(int $maxDaysAgo = 730): string
    {
        return date('Y-m-d H:i:s', strtotime('-' . random_int(0, $maxDaysAgo) . ' days'));
    }

    public function getLocale(): string
    {
        return $this->locale;
    }
}