<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\MigrationRepositoryInterface;
use AlfaCode\LetMigrate\Contract\MigrationResolverInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 — MigrationRunner::fresh() (Laravel migrate:fresh parity).
 *
 *   • drops EVERY table with FK checks disabled (no ordering needed)
 *   • re-enables FK checks even if a drop throws
 *   • then re-runs all migrations from scratch
 *   • empty DB → just runs
 *   • --pretend → does not drop
 */
final class MigrateFreshTest extends TestCase
{
    /** @return array<string, MigrationInterface> */
    private function migrations(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $out[$n] = new class implements MigrationInterface {
                public function up($schema): void {}
                public function down($schema): void {}
            };
        }
        return $out;
    }

    private function build(
        array $tables,
        array $all,
        array &$dropped,
        array &$fkEvents,
        array &$logged,
        bool $pretend = false,
    ): MigrationRunner {
        $applied = [];

        $repo = $this->createStub(MigrationRepositoryInterface::class);
        $repo->method('ensureTable')->willReturnCallback(fn() => null);
        $repo->method('appliedFilenames')->willReturnCallback(
            fn() => array_values($applied),
        );
        $repo->method('lastBatch')->willReturn(0);
        $repo->method('all')->willReturn([]);
        $repo->method('log')->willReturnCallback(
            function (string $f) use (&$applied, &$logged) {
                $applied[] = $f;
                $logged[]  = $f;
            },
        );

        $resolver = $this->createStub(MigrationResolverInterface::class);
        $resolver->method('resolve')->willReturn($all);

        $driver = $this->createStub(DatabaseDriverInterface::class);
        $driver->method('listTables')->willReturn($tables);
        $driver->method('beginTransaction')->willReturnCallback(fn() => null);
        $driver->method('commit')->willReturnCallback(fn() => null);
        $driver->method('rollback')->willReturnCallback(fn() => null);

        $schema = $this->createStub(SchemaBuilderInterface::class);
        $schema->method('getDriver')->willReturn($driver);
        $schema->method('disableForeignKeyChecks')->willReturnCallback(
            function () use (&$fkEvents) { $fkEvents[] = 'off'; },
        );
        $schema->method('enableForeignKeyChecks')->willReturnCallback(
            function () use (&$fkEvents) { $fkEvents[] = 'on'; },
        );
        $schema->method('dropIfExists')->willReturnCallback(
            function (string $t) use (&$dropped) { $dropped[] = $t; },
        );

        return new MigrationRunner(
            repository:    $repo,
            resolver:      $resolver,
            schema:        $schema,
            pretend:       $pretend,
            transactional: true,
        );
    }

    public function test_fresh_wipes_all_tables_then_reruns(): void
    {
        $dropped = $fk = $logged = [];
        $runner = $this->build(
            tables: ['users', 'posts', 'let_migrations'],
            all:    $this->migrations(['m1', 'm2']),
            dropped: $dropped, fkEvents: $fk, logged: $logged,
        );

        $runner->fresh();

        $this->assertSame(['users', 'posts', 'let_migrations'], $dropped);
        $this->assertSame(['m1', 'm2'], $logged, 'migrations re-applied after wipe');
    }

    public function test_fk_checks_toggled_around_wipe(): void
    {
        $dropped = $fk = $logged = [];
        $runner = $this->build(
            tables: ['a', 'b'],
            all:    $this->migrations(['m1']),
            dropped: $dropped, fkEvents: $fk, logged: $logged,
        );

        $runner->fresh();

        $this->assertSame(['off', 'on'], $fk, 'FK checks disabled then re-enabled');
    }

    public function test_fk_checks_reenabled_even_if_drop_throws(): void
    {
        $dropped = $fk = $logged = [];
        $applied = [];

        $repo = $this->createStub(MigrationRepositoryInterface::class);
        $repo->method('ensureTable')->willReturnCallback(fn() => null);
        $repo->method('appliedFilenames')->willReturn([]);
        $repo->method('lastBatch')->willReturn(0);
        $repo->method('all')->willReturn([]);
        $repo->method('log')->willReturnCallback(fn() => null);

        $resolver = $this->createStub(MigrationResolverInterface::class);
        $resolver->method('resolve')->willReturn([]);

        $driver = $this->createStub(DatabaseDriverInterface::class);
        $driver->method('listTables')->willReturn(['boom']);

        $schema = $this->createStub(SchemaBuilderInterface::class);
        $schema->method('getDriver')->willReturn($driver);
        $schema->method('disableForeignKeyChecks')->willReturnCallback(
            function () use (&$fk) { $fk[] = 'off'; },
        );
        $schema->method('enableForeignKeyChecks')->willReturnCallback(
            function () use (&$fk) { $fk[] = 'on'; },
        );
        $schema->method('dropIfExists')->willThrowException(new \RuntimeException('drop failed'));

        $runner = new MigrationRunner(
            repository: $repo, resolver: $resolver, schema: $schema, transactional: true,
        );

        try {
            $runner->fresh();
            $this->fail('expected the drop exception to propagate');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(['off', 'on'], $fk, 'FK checks must be re-enabled via finally');
    }

    public function test_empty_database_just_runs(): void
    {
        $dropped = $fk = $logged = [];
        $runner = $this->build(
            tables: [],
            all:    $this->migrations(['m1']),
            dropped: $dropped, fkEvents: $fk, logged: $logged,
        );

        $runner->fresh();

        $this->assertSame([], $dropped);
        $this->assertSame(['m1'], $logged);
    }

    public function test_pretend_does_not_drop(): void
    {
        $dropped = $fk = $logged = [];
        $runner = $this->build(
            tables: ['users'],
            all:    $this->migrations(['m1']),
            dropped: $dropped, fkEvents: $fk, logged: $logged,
            pretend: true,
        );

        $runner->fresh();

        $this->assertSame([], $dropped, 'pretend must not drop tables');
    }
}