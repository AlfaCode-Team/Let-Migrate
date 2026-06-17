<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\MigrationLinter;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 — migration linting ("safe migrations" guard).
 */
final class MigrationLinterTest extends TestCase
{
    private MigrationLinter $linter;

    protected function setUp(): void
    {
        $this->linter = new MigrationLinter();
    }

    public function test_safe_sql_has_no_findings(): void
    {
        $f = $this->linter->lint([
            'CREATE TABLE users (id BIGINT)',
            'ALTER TABLE users ADD COLUMN email VARCHAR(255)',
            'CREATE INDEX idx ON users (email)',
        ]);

        $this->assertSame([], $f);
        $this->assertFalse($this->linter->hasDanger([
            'CREATE TABLE x (id INT)',
        ]));
    }

    public function test_drop_table_is_danger(): void
    {
        $f = $this->linter->lint(['DROP TABLE users']);
        $this->assertCount(1, $f);
        $this->assertSame('danger', $f[0]['severity']);
        $this->assertTrue($this->linter->hasDanger(['DROP TABLE users']));
    }

    public function test_drop_column_is_danger(): void
    {
        $this->assertTrue(
            $this->linter->hasDanger(['ALTER TABLE users DROP COLUMN email']),
        );
    }

    public function test_truncate_is_danger(): void
    {
        $this->assertTrue($this->linter->hasDanger(['TRUNCATE TABLE logs']));
    }

    public function test_delete_without_where_is_danger_but_with_where_is_safe(): void
    {
        $this->assertTrue($this->linter->hasDanger(['DELETE FROM logs']));
        $this->assertFalse($this->linter->hasDanger(
            ["DELETE FROM logs WHERE created_at < '2020-01-01'"],
        ));
    }

    public function test_drop_index_is_warning_not_danger(): void
    {
        $f = $this->linter->lint(['DROP INDEX idx_email']);
        $this->assertCount(1, $f);
        $this->assertSame('warning', $f[0]['severity']);
        $this->assertFalse($this->linter->hasDanger(['DROP INDEX idx_email']));
    }

    public function test_set_not_null_is_warning(): void
    {
        $f = $this->linter->lint([
            'ALTER TABLE users ALTER COLUMN email SET NOT NULL',
        ]);
        $this->assertSame('warning', $f[0]['severity']);
    }

    public function test_multiple_statements_accumulate_findings(): void
    {
        $f = $this->linter->lint([
            'CREATE TABLE a (id INT)',          // safe
            'DROP TABLE b',                     // danger
            'DROP INDEX i',                     // warning
            'TRUNCATE c',                       // danger
        ]);

        $this->assertCount(3, $f);
        $dangers = array_filter($f, fn($x) => $x['severity'] === 'danger');
        $this->assertCount(2, $dangers);
    }

    public function test_format_produces_readable_lines(): void
    {
        $clean = $this->linter->format([]);
        $this->assertStringContainsString('No destructive operations', $clean[0]);

        $lines = $this->linter->format($this->linter->lint(['DROP TABLE users']));
        $text  = implode("\n", $lines);
        $this->assertStringContainsString('DANGER', $text);
        $this->assertStringContainsString('DROP TABLE', $text);
    }

    public function test_long_sql_is_truncated_in_output(): void
    {
        $long = 'DROP TABLE users /* ' . str_repeat('x', 300) . ' */';
        $f = $this->linter->lint([$long]);
        $this->assertLessThanOrEqual(121, mb_strlen($f[0]['sql']));
    }
}