<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Contract\SchemaInspectorInterface;
use AlfaCode\LetMigrate\MigrationGenerator;
use AlfaCode\LetMigrate\Schema\Inspector\ColumnMeta;
use AlfaCode\LetMigrate\Schema\Inspector\ForeignKeyMeta;
use AlfaCode\LetMigrate\Schema\Inspector\IndexMeta;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 — migrate:generate (reverse-engineer a live DB).
 *
 * Filesystem-free: a stub inspector feeds Meta DTOs; we assert the
 * generated PHP source.
 */
final class MigrationGeneratorTest extends TestCase
{
    /**
     * @param array<string,ColumnMeta[]>     $columns
     * @param array<string,IndexMeta[]>      $indexes
     * @param array<string,ForeignKeyMeta[]> $fks
     */
    private function inspector(array $tables, array $columns, array $indexes = [], array $fks = []): SchemaInspectorInterface
    {
        $i = $this->createStub(SchemaInspectorInterface::class);
        $i->method('getTables')->willReturn($tables);
        $i->method('getColumns')->willReturnCallback(fn(string $t) => $columns[$t] ?? []);
        $i->method('getIndexes')->willReturnCallback(fn(string $t) => $indexes[$t] ?? []);
        $i->method('getForeignKeys')->willReturnCallback(fn(string $t) => $fks[$t] ?? []);
        return $i;
    }

    private function col(array $o): ColumnMeta
    {
        return ColumnMeta::fromRow($o + ['name' => 'x', 'type' => 'varchar']);
    }

    public function test_empty_database_yields_nothing(): void
    {
        $gen = new MigrationGenerator($this->inspector([], []));
        $this->assertSame([], $gen->generate());
    }

    public function test_skips_tracking_tables(): void
    {
        $gen = new MigrationGenerator($this->inspector(
            ['let_migrations', 'let_seeders'],
            [],
        ));
        $this->assertSame([], $gen->generate());
    }

    public function test_generates_id_shortcut_and_typed_columns(): void
    {
        $cols = [
            $this->col(['name' => 'id', 'type' => 'bigint', 'primary_key' => true, 'auto_increment' => true, 'nullable' => false]),
            $this->col(['name' => 'email', 'type' => 'varchar', 'length' => 255, 'nullable' => false]),
            $this->col(['name' => 'age', 'type' => 'int', 'nullable' => true]),
            $this->col(['name' => 'active', 'type' => 'tinyint', 'length' => 1, 'nullable' => false, 'default' => '1']),
            $this->col(['name' => 'meta', 'type' => 'json', 'nullable' => true]),
        ];

        $gen = new MigrationGenerator($this->inspector(['users'], ['users' => $cols]));
        $files = $gen->generate();

        $this->assertCount(1, $files);
        $src = array_values($files)[0];

        $this->assertStringContainsString("\$schema->create('users'", $src);
        $this->assertStringContainsString('$table->id();', $src);
        $this->assertStringContainsString("\$table->string('email', 255)", $src);
        $this->assertStringContainsString("\$table->integer('age')->nullable()", $src);
        $this->assertStringContainsString("\$table->boolean('active')", $src);
        $this->assertStringContainsString("->default(1)", $src);
        $this->assertStringContainsString("\$table->json('meta')->nullable()", $src);
        $this->assertStringContainsString("\$schema->dropIfExists('users');", $src);
        $this->assertStringContainsString('implements MigrationInterface', $src);
    }

    public function test_unknown_type_falls_back_to_raw_addColumn(): void
    {
        $cols = [$this->col(['name' => 'geom', 'type' => 'geometry', 'nullable' => true])];
        $gen  = new MigrationGenerator($this->inspector(['places'], ['places' => $cols]));

        $src = array_values($gen->generate())[0];
        $this->assertStringContainsString("\$table->addColumn('geom', 'GEOMETRY')", $src);
        $this->assertStringContainsString("review: native type 'geometry'", $src);
    }

    public function test_foreign_keys_and_indexes_emitted(): void
    {
        $cols = [
            $this->col(['name' => 'id', 'type' => 'bigint', 'primary_key' => true, 'auto_increment' => true]),
            $this->col(['name' => 'user_id', 'type' => 'bigint']),
            $this->col(['name' => 'slug', 'type' => 'varchar', 'length' => 120]),
        ];
        $idx = [
            new IndexMeta('PRIMARY', ['id'], true, true),
            new IndexMeta('uq_slug', ['slug'], false, true),
            new IndexMeta('idx_user', ['user_id'], false, false),
        ];
        $fk = [
            new ForeignKeyMeta('fk_posts_user', 'user_id', 'users', 'id', 'CASCADE', null),
        ];

        $gen = new MigrationGenerator($this->inspector(
            ['posts'], ['posts' => $cols], ['posts' => $idx], ['posts' => $fk],
        ));
        $src = array_values($gen->generate())[0];

        // PK on auto-increment id is NOT re-emitted (id() covers it)
        $this->assertStringNotContainsString("\$table->primary(['id'])", $src);
        $this->assertStringContainsString("\$table->unique(['slug'], 'uq_slug');", $src);
        $this->assertStringContainsString("\$table->index(['user_id'], 'idx_user');", $src);
        $this->assertStringContainsString(
            "\$table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');",
            $src,
        );
    }

    public function test_tables_ordered_by_fk_dependency(): void
    {
        // posts → users : users must be generated first
        $gen = new MigrationGenerator($this->inspector(
            ['posts', 'users'],
            [
                'posts' => [$this->col(['name' => 'id', 'type' => 'bigint'])],
                'users' => [$this->col(['name' => 'id', 'type' => 'bigint'])],
            ],
            [],
            [
                'posts' => [new ForeignKeyMeta('fk', 'user_id', 'users', 'id', null, null)],
                'users' => [],
            ],
        ));

        $names = array_keys($gen->generate());
        $usersPos = $this->indexOfContaining($names, 'users');
        $postsPos = $this->indexOfContaining($names, 'posts');

        $this->assertLessThan($postsPos, $usersPos, 'users must be generated before posts');
    }

    public function test_fk_cycle_does_not_throw(): void
    {
        // a → b and b → a : must still produce 2 files (best-effort order)
        $gen = new MigrationGenerator($this->inspector(
            ['a', 'b'],
            [
                'a' => [$this->col(['name' => 'id', 'type' => 'bigint'])],
                'b' => [$this->col(['name' => 'id', 'type' => 'bigint'])],
            ],
            [],
            [
                'a' => [new ForeignKeyMeta('fk1', 'b_id', 'b', 'id', null, null)],
                'b' => [new ForeignKeyMeta('fk2', 'a_id', 'a', 'id', null, null)],
            ],
        ));

        $files = $gen->generate();
        $this->assertCount(2, $files);
    }

    /** @param string[] $names */
    private function indexOfContaining(array $names, string $needle): int
    {
        foreach ($names as $i => $n) {
            if (str_contains($n, $needle)) {
                return $i;
            }
        }
        return -1;
    }
}