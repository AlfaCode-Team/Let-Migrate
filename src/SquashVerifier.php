<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\SchemaDiffer;

/**
 * Verifies that a squash is LOSSLESS: the schema produced by running the
 * squashed dump must be structurally identical to the schema produced by
 * running the original migrations.
 *
 * Reuses the Phase 2 SchemaDiffer as the comparison engine. The check is
 * bidirectional and destructive-inclusive (we want EXACT equality, not a
 * save-diff): before→after AND after→before must both be empty.
 *
 * Pure logic over two SchemaSnapshot arrays → unit-testable. The command
 * supplies them: BEFORE = snapshot of a DB migrated the old way, AFTER =
 * snapshot of a throwaway DB loaded from the squash dump.
 */
final class SquashVerifier
{
    public function __construct(
        private readonly SchemaDiffer $differ = new SchemaDiffer(),
    ) {}

    /**
     * @param array<string,mixed> $before pre-squash schema snapshot
     * @param array<string,mixed> $after  post-squash (dump-loaded) snapshot
     * @return array{
     *   verified: bool,
     *   forward: array<string,mixed>,
     *   reverse: array<string,mixed>,
     *   problems: string[]
     * }
     */
    public function verify(array $before, array $after): array
    {
        // destructive-inclusive (true) so DROPs/removals are not hidden —
        // we need exact structural equivalence in BOTH directions.
        $forward = $this->differ->diff($before, $after, true);
        $reverse = $this->differ->diff($after, $before, true);

        $problems = [];

        $this->collect($problems, $forward, 'present after squash but missing before / changed');
        $this->collect($problems, $reverse, 'present before squash but missing after / changed');

        return [
            'verified' => $forward['empty'] && $reverse['empty'],
            'forward'  => $forward,
            'reverse'  => $reverse,
            'problems' => $problems,
        ];
    }

    /**
     * Human-readable lines for the command layer.
     *
     * @param array{verified:bool,problems:string[]} $result
     * @return string[]
     */
    public function format(array $result): array
    {
        if ($result['verified']) {
            return ['✓ Squash verified — schema is identical to pre-squash.'];
        }

        $lines = ['✗ Squash verification FAILED — schema drift detected:'];
        foreach ($result['problems'] as $p) {
            $lines[] = '  - ' . $p;
        }
        $lines[] = 'Do NOT replace migrations with this squash.';
        return $lines;
    }

    /**
     * @param string[]            $bucket
     * @param array<string,mixed> $diff
     */
    private function collect(array &$bucket, array $diff, string $label): void
    {
        if ($diff['empty']) {
            return;
        }

        foreach ($diff['created'] as $t) {
            $bucket[] = "table '{$t}' {$label}";
        }
        foreach ($diff['dropped'] as $t) {
            $bucket[] = "table '{$t}' {$label}";
        }
        foreach (['columnsAdded', 'columnsRemoved', 'columnsChanged'] as $k) {
            foreach ($diff[$k] as $table => $cols) {
                $bucket[] = "table '{$table}' columns ["
                    . implode(', ', $cols) . "] {$label}";
            }
        }
    }
}