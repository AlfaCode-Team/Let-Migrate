<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Seeder\Factory\EntityFactory;
use AlfaCode\LetMigrate\Seeder\Factory\FakeData;
use AlfaCode\LetMigrate\Seeder\Factory\Sequence;
use AlfaCode\LetMigrate\Seeder\SeederInterface;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 — seeder factory system (states, sequences, relationships,
 * locale-aware fake data).
 */
final class SeederEntityFactoryTest extends TestCase
{
    public function test_basic_definition_and_count(): void
    {
        $rows = EntityFactory::for('users')
            ->definition(fn(FakeData $f, int $i) => [
                'id'    => $i + 1,
                'email' => $f->uniqueEmail($i),
                'role'  => 'user',
            ])
            ->count(5)
            ->raw();

        $this->assertCount(5, $rows);
        $this->assertSame(1, $rows[0]['id']);
        $this->assertSame('user', $rows[0]['role']);
        // unique emails
        $emails = array_column($rows, 'email');
        $this->assertCount(5, array_unique($emails));
    }

    public function test_state_overrides_attributes(): void
    {
        $rows = EntityFactory::for('users')
            ->definition(fn(FakeData $f, int $i) => ['role' => 'user', 'active' => 1])
            ->count(3)
            ->state(['role' => 'admin'])
            ->raw();

        foreach ($rows as $r) {
            $this->assertSame('admin', $r['role']);
            $this->assertSame(1, $r['active']);
        }
    }

    public function test_callable_state_receives_row_and_index(): void
    {
        $rows = EntityFactory::for('users')
            ->definition(fn(FakeData $f, int $i) => ['n' => $i])
            ->count(4)
            ->state(fn(array $row, int $i) => ['even' => $i % 2 === 0])
            ->raw();

        $this->assertTrue($rows[0]['even']);
        $this->assertFalse($rows[1]['even']);
    }

    public function test_sequence_cycles_values(): void
    {
        $rows = EntityFactory::for('subs')
            ->definition(fn(FakeData $f, int $i) => ['id' => $i + 1])
            ->count(5)
            ->sequence('plan', new Sequence('free', 'pro'))
            ->raw();

        $this->assertSame(
            ['free', 'pro', 'free', 'pro', 'free'],
            array_column($rows, 'plan'),
        );
    }

    public function test_sequence_supports_callable_values(): void
    {
        $rows = EntityFactory::for('t')
            ->definition(fn(FakeData $f, int $i) => [])
            ->count(3)
            ->sequence('label', new Sequence(fn(int $i) => "row-{$i}"))
            ->raw();

        $this->assertSame(['row-0', 'row-1', 'row-2'], array_column($rows, 'label'));
    }

    public function test_locale_changes_name_pool(): void
    {
        $es = new FakeData('es');
        $this->assertSame('es', $es->getLocale());
        // Spanish surname pool is disjoint from the English one for the
        // sample sets; assert the generated last name is from 'es'.
        $esLast = [];
        for ($i = 0; $i < 30; $i++) {
            $esLast[$es->lastName()] = true;
        }
        foreach (array_keys($esLast) as $name) {
            $this->assertContains($name, ['García', 'Fernández', 'Rodríguez',
                'López', 'Martínez', 'Sánchez', 'Pérez']);
        }
    }

    public function test_unknown_locale_falls_back_to_en(): void
    {
        $f = new FakeData('xx');
        $n = $f->firstName();
        $this->assertContains($n, ['Alice', 'Bob', 'Carol', 'David', 'Eve',
            'Frank', 'Grace', 'Henry', 'Ivy', 'Jack']);
    }

    public function test_with_parent_links_foreign_key(): void
    {
        $users = EntityFactory::for('users')
            ->definition(fn(FakeData $f, int $i) => ['id' => 100 + $i, 'name' => $f->name()])
            ->count(2);

        $posts = EntityFactory::for('posts')
            ->definition(fn(FakeData $f, int $i) => ['id' => $i + 1, 'title' => 't'])
            ->count(4)
            ->withParent('user_id', $users)
            ->raw();

        // child i links to parent (i mod 2): ids 100,101,100,101
        $this->assertSame([100, 101, 100, 101], array_column($posts, 'user_id'));
    }

    public function test_as_seeder_inserts_rows_and_parents_first(): void
    {
        $inserted = [];

        $db = new class($inserted) implements DatabaseDriverInterface {
            public function __construct(public array &$ins) {}
            public function getName(): string { return 'fake'; }
            public function execute(string $s, array $b = []): int { return 0; }
            public function fetchOne(string $s, array $b = []): array|null { return null; }
            public function fetchAll(string $s, array $b = []): array { return []; }
            public function insert(string $t, array $d): int { $this->ins[] = $t; return 1; }
            public function update(string $t, array $d, array $w = []): int { return 1; }
            public function delete(string $t, array $w): int { return 1; }
            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollback(): void {}
            public function inTransaction(): bool { return false; }
            public function tableExists(string $t): bool { return true; }
            public function columnExists(string $t, string $c): bool { return true; }
            public function listColumns(string $t): array { return []; }
            public function listTables(): array { return []; }
            public function quoteIdentifier(string $i): string { return $i; }
        
    /**
     * @inheritDoc
     */
    public function getPlatformName(): string {
        return 'fake';
    }
};

        $users = EntityFactory::for('users')
            ->definition(fn(FakeData $f, int $i) => ['id' => $i + 1])
            ->count(2);

        $seeder = EntityFactory::for('posts')
            ->definition(fn(FakeData $f, int $i) => ['id' => $i + 1])
            ->count(3)
            ->withParent('user_id', $users)
            ->dependsOn('SomeOtherSeeder')
            ->asSeeder();

        $this->assertInstanceOf(SeederInterface::class, $seeder);
        $this->assertSame(['SomeOtherSeeder'], $seeder->getDependencies());

        $seeder->run($db);

        // parents (users x2) inserted before children (posts x3)
        $this->assertSame(
            ['users', 'users', 'posts', 'posts', 'posts'],
            $db->ins,
        );
    }
}