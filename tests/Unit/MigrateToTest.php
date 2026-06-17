<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\MigrationRepositoryInterface;
use AlfaCode\LetMigrate\Contract\MigrationResolverInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Exception\MigrationException;
use AlfaCode\LetMigrate\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 — MigrationRunner::migrateTo() (Yii migrate/to parity).
 *
 *   ordered: [m1, m2, m3, m4, m5]   applied: [m1, m2, m3]
 *     to m5  → up    (apply m4, m5)
 *     to m1  → down  (roll back m3, m2; keep m1)
 *     to m3  → no-op
 *     to m2  → down  (roll back m3 only)
 *     to zzz → MigrationException
 */
final class MigrateToTest extends TestCase
{
    /** @return array<string, MigrationInterface> */
    private function migrations(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $out[$n] = new class implements MigrationInterface {
                public array $calls = [];
                public function up($schema): void { $this->calls[] = 'up'; }
                public function down($schema): void { $this->calls[] = 'down'; }
            };
        }
        return $out;
    }

    private function runner(array $all, array $applied): array
    {
        $logged  = [];
        $removed = [];

        $repo = $this->createStub(MigrationRepositoryInterface::class);
        $repo->method('ensureTable')->willReturnCallback(fn() => null);
        $repo->method('appliedFilenames')->willReturn($applied);
        $repo->method('lastBatch')->willReturn($applied === [] ? 0 : 1);
        $repo->method('log')->willReturnCallback(
            function (string $f, int $b) use (&$logged) { $logged[] = $f; },
        );
        $repo->method('remove')->willReturnCallback(
            function (string $f) use (&$removed) { $removed[] = $f; },
        );

        $resolver = $this->createStub(MigrationResolverInterface::class);
        $resolver->method('resolve')->willReturn($all);

        $driver = $this->createStub(DatabaseDriverInterface::class);
        $driver->method('beginTransaction')->willReturnCallback(fn() => null);
        $driver->method('commit')->willReturnCallback(fn() => null);
        $driver->method('rollback')->willReturnCallback(fn() => null);

        $schema = $this->createStub(SchemaBuilderInterface::class);
        $schema->method('getDriver')->willReturn($driver);

        $runner = new MigrationRunner(
            repository:    $repo,
            resolver:      $resolver,
            schema:        $schema,
            transactional: true,
        );

        return [$runner, &$logged, &$removed];
    }

    public function test_migrate_to_up_applies_pending_through_target(): void
    {
        $all = $this->migrations(['m1', 'm2', 'm3', 'm4', 'm5']);
        [$runner, $logged] = $this->runner($all, ['m1', 'm2', 'm3']);

        $result = $runner->migrateTo('m5');

        $this->assertSame(['m4', 'm5'], $logged);
        $this->assertSame(['m4', 'm5'], $result->applied ?? $result->getApplied());
    }

    public function test_migrate_to_down_rolls_back_after_target(): void
    {
        $all = $this->migrations(['m1', 'm2', 'm3', 'm4', 'm5']);
        [$runner, , $removed] = $this->runner($all, ['m1', 'm2', 'm3']);

        $runner->migrateTo('m1');

        // roll back everything after m1, newest first
        $this->assertSame(['m3', 'm2'], $removed);
    }

    public function test_migrate_to_current_is_noop(): void
    {
        $all = $this->migrations(['m1', 'm2', 'm3', 'm4', 'm5']);
        [$runner, $logged, $removed] = $this->runner($all, ['m1', 'm2', 'm3']);

        $runner->migrateTo('m3');

        $this->assertSame([], $logged);
        $this->assertSame([], $removed);
    }

    public function test_migrate_to_partial_down(): void
    {
        $all = $this->migrations(['m1', 'm2', 'm3', 'm4', 'm5']);
        [$runner, , $removed] = $this->runner($all, ['m1', 'm2', 'm3']);

        $runner->migrateTo('m2');

        $this->assertSame(['m3'], $removed);
    }

    public function test_unknown_target_throws(): void
    {
        $all = $this->migrations(['m1', 'm2']);
        [$runner] = $this->runner($all, ['m1']);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/not found among resolved/');

        $runner->migrateTo('does_not_exist');
    }

    public function test_migrate_to_up_from_empty_db(): void
    {
        $all = $this->migrations(['m1', 'm2', 'm3']);
        [$runner, $logged] = $this->runner($all, []);

        $runner->migrateTo('m2');

        $this->assertSame(['m1', 'm2'], $logged);
    }
}