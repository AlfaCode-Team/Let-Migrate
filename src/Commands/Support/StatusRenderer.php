<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Commands\Support;

use AlfaCode\LetMigrate\Contract\MigrationServiceInterface;

/**
 * Presenter for `migrate:status`.
 *
 * MigrationServiceInterface::status() answers with the raw shape
 *
 *     ['<migration>' => ['status' => string, 'batch' => ?int, 'applied_at' => ?string]]
 *
 * which is right for a machine and wrong for a person: nulls where a value is
 * simply not applicable yet, no counts, and no indication of WHICH database
 * was asked. This turns it into the three renderings a command needs — a
 * normalised data array, aligned table lines, and JSON — from one source, so
 * the human and the `--json` consumer can never disagree about what is applied.
 *
 * Mirrors JsonResultPresenter: a structured toData() core plus wrappers.
 *
 * Schema (stable contract):
 *
 *   { "total": int, "applied": int, "pending": int,
 *     "rows": [ { "migration": string, "status": string, "batch": string,
 *                 "applied_at": string, "connection": string } ] }
 *
 * Every row value is a STRING, including batch: this feeds a table, and a
 * column that is sometimes int and sometimes '—' is a formatting bug waiting
 * to happen at the call site.
 */
final class StatusRenderer
{
    /** Stands in for a value that does not exist yet, rather than an empty cell. */
    private const NOT_APPLICABLE = '—';

    public function __construct(
        private readonly MigrationServiceInterface $service,
        private readonly string                    $connection = 'default',
    ) {}

    /**
     * @return array{total: int, applied: int, pending: int, rows: list<array{
     *   migration: string, status: string, batch: string,
     *   applied_at: string, connection: string
     * }>}
     */
    public function toData(): array
    {
        $rows    = [];
        $applied = 0;

        foreach ($this->service->status() as $migration => $info) {
            $status = (string) ($info['status'] ?? 'pending');

            if ($status === 'applied') {
                $applied++;
            }

            // `?? null` on BOTH keys is deliberate: an older status shape omits
            // applied_at entirely, and reading a missing key would warn rather
            // than render the dash this column exists to show.
            $rows[] = [
                'migration'  => (string) $migration,
                'status'     => $status,
                'batch'      => $this->orDash($info['batch'] ?? null),
                'applied_at' => $this->orDash($info['applied_at'] ?? null),
                'connection' => $this->connection,
            ];
        }

        return [
            'total'   => count($rows),
            'applied' => $applied,
            'pending' => count($rows) - $applied,
            'rows'    => $rows,
        ];
    }

    /**
     * Aligned table lines, ready to print one per line.
     *
     * @return list<string>
     */
    public function toTableLines(): array
    {
        $data = $this->toData();

        if ($data['rows'] === []) {
            // Not an empty table with headers: "no migrations" and "none of the
            // migrations you have are applied" are different situations, and a
            // header row over nothing reads like the second.
            return ['No migrations discovered.'];
        }

        $headers = ['Migration', 'Status', 'Batch', 'Applied At', 'Connection'];
        $widths  = array_map('mb_strlen', $headers);

        foreach ($data['rows'] as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i], mb_strlen((string) $cell));
            }
        }

        $line = static function (array $cells) use ($widths): string {
            $out = [];
            foreach (array_values($cells) as $i => $cell) {
                // mb_str_pad is PHP 8.3+; pad by the multibyte deficit so a
                // column holding an em-dash still lines up.
                $cell  = (string) $cell;
                $out[] = $cell . str_repeat(' ', max(0, $widths[$i] - mb_strlen($cell)));
            }

            return rtrim(implode('  ', $out));
        };

        $lines = [
            $line($headers),
            $line(array_map(static fn(int $w): string => str_repeat('-', $w), $widths)),
        ];

        foreach ($data['rows'] as $row) {
            $lines[] = $line($row);
        }

        $lines[] = '';
        $lines[] = sprintf(
            '%d total · %d applied · %d pending',
            $data['total'],
            $data['applied'],
            $data['pending'],
        );
        $lines[] = 'connection: ' . $this->connection;

        return $lines;
    }

    /** The same data, for `--json`. */
    public function toJson(): string
    {
        return json_encode(
            $this->toData(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '{}';
    }

    private function orDash(mixed $value): string
    {
        return $value === null || $value === '' ? self::NOT_APPLICABLE : (string) $value;
    }
}
