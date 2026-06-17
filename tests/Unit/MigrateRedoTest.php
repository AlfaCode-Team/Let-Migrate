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
 * Phase 1 — MigrationRunner::redo() (Yii migrate/redo parity).
 *
 * Key property: redo re-applies ONLY the set it just rolled back — it
 * must not pull in other pending migrations.
 */
final class MigrateRedoTest extends TestCase
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

    /**
     * Repo stub backed by a mutable applied list so rollback() (remove)
     * then re-apply (log) behave realistically across the redo() call.
     */
    private function runner(array $all, array $appliedInit, array &$log, array &$removed): MigrationRunner
    {
        $applied = $appliedInit;

        $repo = $this->createStub(MigrationRepositoryInterface::class);
        $repo->method('ensureTable')->willReturnCallback(fn() => null);
        $repo->method('appliedFilenames')->willReturnCallback(
            function () use (&$applied) { return array_values($applied); },
        );
        $repo->method('lastBatch')->willReturnCallback(
            function () use (&$applied) { return $applied === [] ? 0 : 1; },
        );
        $repo->method('all')->willReturnCallback(
            function () use (&$applied) {
                return array_map(
                    static fn($m) => (object) ['migration' => $m, 'batch' => 1],
                    array_values($applied),
                );
            },
        );
        $repo->method('log')->willReturnCallback(
            function (string $f, int $b) use (&$applied, &$log) {
                $applied[] = $f;
                $log[] = $f;
            },
        );
        $repo->method('remove')->willReturnCallback(
            function (string $f) use (&$applied, &$removed) {
                $applied = array_values(array_filter($applied, static fn($x) => $x !== $f));
                $removed[] = $f;
            },
        );

        $resolver = $this->createStub(MigrationResolverInterface::class);
        $resolver->method('resolve')->willReturn($all);

        $driver = $this->createStub(DatabaseDriverInterface::class);
        $driver->method('beginTransaction')->willReturnCallback(fn() => null);
        $driver->method('commit')->willReturnCallback(fn() => null);
        $driver->method('rollback')->willReturnCallback(fn() => null);

        $schema = $this->createStub(SchemaBuilderInterface::class);
        $schema->method('getDriver')->willReturn($driver);

        return new MigrationRunner(
            repository:    $repo,
            resolver:      $resolver,
            schema:        $schema,
            transactional: true,
        );
    }

    public function test_redo_single_rolls_back_then_reapplies_last(): void
    {
        $all = $this->migrations(['m1', 'm2', 'm3']);
        $log = [];
        $removed = [];
        $runner = $this->runner($all, ['m1', 'm2', 'm3'], $log, $removed);

        $runner->redo();

        $this->assertSame(['m3'], $removed, 'rolled back last only');
        $this->assertSame(['m3'], $log, 're-applied exactly the rolled-back one');
    }

    public function test_redo_multi_step_reapplies_ascending(): void
    {
        $all = $this->migrations(['m1', 'm2', 'm3']);
        $log = [];
        $removed = [];
        $runner = $this->runner($all, ['m1', 'm2', 'm3'], $log, $removed);

        $runner->redo(2);

        // rolled back newest-first; re-applied ascending
        $this->assertSame(['m3', 'm2'], $removed);
        $this->assertSame(['m2', 'm3'], $log);
    }

    public function test_redo_does_not_pull_in_other_pending(): void
    {
        // m4 is pending (never applied). redo() must NOT apply it.
        $all = $this->migrations(['m1', 'm2', 'm3', 'm4']);
        $log = [];
        $removed = [];
        $runner = $this->runner($all, ['m1', 'm2', 'm3'], $log, $removed);

        $runner->redo();

        $this->assertNotContains('m4', $log, 'redo must not apply unrelated pending m4');
        $this->assertSame(['m3'], $log);
    }

    public function test_redo_on_empty_db_is_noop(): void
    {
        $all = $this->migrations(['m1', 'm2']);
        $log = [];
        $removed = [];
        $runner = $this->runner($all, [], $log, $removed);

        $runner->redo();

        $this->assertSame([], $removed);
        $this->assertSame([], $log);
    }
}