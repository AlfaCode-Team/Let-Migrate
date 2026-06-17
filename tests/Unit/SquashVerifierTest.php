<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\SquashVerifier;
use AlfaCode\LetMigrate\Schema\Inspector\ColumnMeta;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 — squash + verify (lossless-squash guarantee).
 */
final class SquashVerifierTest extends TestCase
{
    private function col(string $n, string $t): ColumnMeta
    {
        return ColumnMeta::fromRow(['name' => $n, 'type' => $t]);
    }

    private function table(array $cols): array
    {
        $c = [];
        foreach ($cols as $x) {
            $c[$x->name] = $x;
        }
        return ['columns' => $c, 'indexes' => [], 'fks' => []];
    }

    public function test_identical_schemas_verify(): void
    {
        $snap = [
            'users' => $this->table([$this->col('id', 'bigint'), $this->col('email', 'varchar')]),
            'posts' => $this->table([$this->col('id', 'bigint')]),
        ];

        $r = (new SquashVerifier())->verify($snap, $snap);

        $this->assertTrue($r['verified']);
        $this->assertSame([], $r['problems']);
    }

    public function test_missing_table_after_squash_fails(): void
    {
        $before = [
            'users' => $this->table([$this->col('id', 'bigint')]),
            'posts' => $this->table([$this->col('id', 'bigint')]),
        ];
        $after = ['users' => $this->table([$this->col('id', 'bigint')])]; // posts lost

        $r = (new SquashVerifier())->verify($before, $after);

        $this->assertFalse($r['verified']);
        $this->assertNotEmpty($r['problems']);
        $this->assertStringContainsString('posts', implode(' ', $r['problems']));
    }

    public function test_extra_table_after_squash_fails(): void
    {
        $before = ['users' => $this->table([$this->col('id', 'bigint')])];
        $after  = $before + ['ghost' => $this->table([$this->col('id', 'bigint')])];

        $r = (new SquashVerifier())->verify($before, $after);

        $this->assertFalse($r['verified']);
        $this->assertStringContainsString('ghost', implode(' ', $r['problems']));
    }

    public function test_changed_column_type_fails(): void
    {
        $before = ['t' => $this->table([$this->col('a', 'varchar')])];
        $after  = ['t' => $this->table([$this->col('a', 'text')])];

        $r = (new SquashVerifier())->verify($before, $after);

        $this->assertFalse($r['verified']);
        $this->assertStringContainsString('a', implode(' ', $r['problems']));
    }

    public function test_missing_column_after_squash_fails(): void
    {
        $before = ['t' => $this->table([$this->col('id', 'bigint'), $this->col('extra', 'varchar')])];
        $after  = ['t' => $this->table([$this->col('id', 'bigint')])];

        $r = (new SquashVerifier())->verify($before, $after);

        $this->assertFalse($r['verified']);
        $this->assertStringContainsString('extra', implode(' ', $r['problems']));
    }

    public function test_format_pass_and_fail_messages(): void
    {
        $v = new SquashVerifier();

        $snap = ['t' => $this->table([$this->col('id', 'bigint')])];
        $pass = $v->format($v->verify($snap, $snap));
        $this->assertStringContainsString('verified', $pass[0]);

        $before = ['t' => $this->table([$this->col('id', 'bigint')]), 'x' => $this->table([])];
        $after  = ['t' => $this->table([$this->col('id', 'bigint')])];
        $failLines = $v->format($v->verify($before, $after));
        $text = implode("\n", $failLines);
        $this->assertStringContainsString('FAILED', $text);
        $this->assertStringContainsString('Do NOT replace migrations', $text);
    }
}