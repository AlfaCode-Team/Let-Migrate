<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\SchemaDiffer;
use AlfaCode\LetMigrate\Schema\Inspector\ColumnMeta;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 — migrate:diff (SchemaDiffer pure logic).
 *
 * Key behaviour: SAVE-DIFF MODE (default) never emits destructive
 * operations — answers Doctrine's toSaveSql().
 */
final class SchemaDifferTest extends TestCase
{
    private function col(string $name, string $type, bool $nullable = false): ColumnMeta
    {
        return ColumnMeta::fromRow([
            'name' => $name, 'type' => $type, 'nullable' => $nullable,
        ]);
    }

    private function table(array $cols): array
    {
        $columns = [];
        foreach ($cols as $c) {
            $columns[$c->name] = $c;
        }
        return ['columns' => $columns, 'indexes' => [], 'fks' => []];
    }

    public function test_identical_schemas_produce_empty_diff(): void
    {
        $snap = ['users' => $this->table([$this->col('id', 'bigint')])];

        $d = (new SchemaDiffer())->diff($snap, $snap);

        $this->assertTrue($d['empty']);
        $this->assertSame([], $d['created']);
    }

    public function test_added_table_detected(): void
    {
        $from = ['users' => $this->table([$this->col('id', 'bigint')])];
        $to   = $from + ['posts' => $this->table([$this->col('id', 'bigint')])];

        $d = (new SchemaDiffer())->diff($from, $to);

        $this->assertFalse($d['empty']);
        $this->assertSame(['posts'], $d['created']);
    }

    public function test_dropped_table_hidden_in_save_diff_mode(): void
    {
        $from = [
            'users' => $this->table([$this->col('id', 'bigint')]),
            'legacy' => $this->table([$this->col('id', 'bigint')]),
        ];
        $to = ['users' => $this->table([$this->col('id', 'bigint')])];

        // save-diff (default): no destructive drop
        $safe = (new SchemaDiffer())->diff($from, $to);
        $this->assertSame([], $safe['dropped']);

        // --force: drop surfaces
        $forced = (new SchemaDiffer())->diff($from, $to, true);
        $this->assertSame(['legacy'], $forced['dropped']);
    }

    public function test_added_and_removed_columns(): void
    {
        $from = ['users' => $this->table([
            $this->col('id', 'bigint'),
            $this->col('old', 'varchar'),
        ])];
        $to = ['users' => $this->table([
            $this->col('id', 'bigint'),
            $this->col('email', 'varchar'),
        ])];

        $safe = (new SchemaDiffer())->diff($from, $to);
        $this->assertSame(['users' => ['email']], $safe['columnsAdded']);
        $this->assertSame([], $safe['columnsRemoved']); // save-diff hides removal

        $forced = (new SchemaDiffer())->diff($from, $to, true);
        $this->assertSame(['users' => ['old']], $forced['columnsRemoved']);
    }

    public function test_changed_column_detected_by_type_and_nullable(): void
    {
        $from = ['t' => $this->table([$this->col('a', 'varchar', false)])];
        $to   = ['t' => $this->table([$this->col('a', 'text', true)])];

        $d = (new SchemaDiffer())->diff($from, $to);
        $this->assertSame(['t' => ['a']], $d['columnsChanged']);
    }

    public function test_render_empty_diff(): void
    {
        $differ = new SchemaDiffer();
        $snap   = ['t' => $this->table([$this->col('id', 'bigint')])];
        $d      = $differ->diff($snap, $snap);

        $src = $differ->render($d, $snap, '2024_diff');
        $this->assertStringContainsString('No schema differences detected', $src);
        $this->assertStringContainsString('implements MigrationInterface', $src);
    }

    public function test_render_added_table_and_column(): void
    {
        $differ = new SchemaDiffer();
        $from = ['users' => $this->table([$this->col('id', 'bigint')])];
        $to   = [
            'users' => $this->table([$this->col('id', 'bigint'), $this->col('email', 'varchar')]),
            'posts' => $this->table([$this->col('id', 'bigint')]),
        ];

        $d   = $differ->diff($from, $to);
        $src = $differ->render($d, $to, '2024_diff');

        $this->assertStringContainsString("\$schema->create('posts'", $src);
        $this->assertStringContainsString("\$schema->table('users'", $src);
        $this->assertStringContainsString("\$table->addColumn('email'", $src);
        // down() reverses the additions
        $this->assertStringContainsString("\$schema->dropIfExists('posts');", $src);
        $this->assertStringContainsString("\$table->dropColumn('email');", $src);
    }

    public function test_save_diff_render_has_no_destructive_ops(): void
    {
        $differ = new SchemaDiffer();
        $from = [
            'users'  => $this->table([$this->col('id', 'bigint'), $this->col('tmp', 'varchar')]),
            'legacy' => $this->table([$this->col('id', 'bigint')]),
        ];
        $to = ['users' => $this->table([$this->col('id', 'bigint')])];

        $safe = $differ->render($differ->diff($from, $to), $to, '2024_diff');

        $this->assertStringNotContainsString('dropIfExists(\'legacy\')', $safe);
        $this->assertStringNotContainsString("dropColumn('tmp')", $safe);
    }
}