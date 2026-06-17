<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

/**
 * Static safety analysis of the SQL a migration WOULD run (obtained via
 * the existing captureSql() — no execution, no DB needed).
 *
 * Powers the "safe migrations" reputation: a CI/deploy step can refuse to
 * proceed when a migration performs a destructive operation unless it was
 * explicitly authorised with --force.
 *
 * Severities:
 *   'danger'  — irreversible data loss (DROP TABLE/COLUMN, TRUNCATE,
 *               DELETE without WHERE)
 *   'warning' — risky but sometimes intended (DROP INDEX, DROP
 *               CONSTRAINT, NOT NULL added without default)
 */
final class MigrationLinter
{
    /** @var array<int, array{pattern:string, severity:string, message:string}> */
    private const RULES = [
        ['pattern' => '/\bDROP\s+TABLE\b/i',                'severity' => 'danger',  'message' => 'DROP TABLE — irreversible data loss'],
        ['pattern' => '/\bDROP\s+COLUMN\b/i',               'severity' => 'danger',  'message' => 'DROP COLUMN — irreversible data loss'],
        ['pattern' => '/\bTRUNCATE\b/i',                    'severity' => 'danger',  'message' => 'TRUNCATE — wipes all rows'],
        ['pattern' => '/\bDELETE\s+FROM\b(?!.*\bWHERE\b)/is','severity' => 'danger', 'message' => 'DELETE without WHERE — wipes all rows'],
        ['pattern' => '/\bDROP\s+(INDEX|CONSTRAINT)\b/i',   'severity' => 'warning', 'message' => 'DROP INDEX/CONSTRAINT — schema regression risk'],
        ['pattern' => '/\bALTER\s+TABLE\b.*\bSET\s+NOT\s+NULL\b/is', 'severity' => 'warning', 'message' => 'SET NOT NULL — fails if existing rows are NULL'],
        ['pattern' => '/\bDROP\s+TYPE\b/i',                 'severity' => 'warning', 'message' => 'DROP TYPE — dependent columns will break'],
    ];

    /**
     * Lint a list of SQL statements.
     *
     * @param  string[] $statements
     * @return array<int, array{severity:string, message:string, sql:string}>
     */
    public function lint(array $statements): array
    {
        $findings = [];

        foreach ($statements as $sql) {
            $sql = trim($sql);
            if ($sql === '') {
                continue;
            }
            foreach (self::RULES as $rule) {
                if (preg_match($rule['pattern'], $sql) === 1) {
                    $findings[] = [
                        'severity' => $rule['severity'],
                        'message'  => $rule['message'],
                        'sql'      => $this->truncate($sql),
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * @param string[] $statements
     */
    public function hasDanger(array $statements): bool
    {
        foreach ($this->lint($statements) as $f) {
            if ($f['severity'] === 'danger') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array{severity:string, message:string, sql:string}> $findings
     * @return string[]
     */
    public function format(array $findings): array
    {
        if ($findings === []) {
            return ['✓ No destructive operations detected.'];
        }

        $lines = [];
        foreach ($findings as $f) {
            $tag = $f['severity'] === 'danger' ? '✗ DANGER ' : '⚠ WARNING';
            $lines[] = "{$tag}: {$f['message']}";
            $lines[] = "          {$f['sql']}";
        }
        return $lines;
    }

    private function truncate(string $sql, int $max = 120): string
    {
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;
        return mb_strlen($sql) > $max ? mb_substr($sql, 0, $max - 1) . '…' : $sql;
    }
}