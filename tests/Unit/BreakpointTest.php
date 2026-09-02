<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\MigrationRepositoryInterface;
use AlfaCode\LetMigrate\Contract\MigrationResolverInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Exception\MigrationException;
use AlfaCode\LetMigrate\MigrationRecord;
use AlfaCode\LetMigrate\BreakpointStore;
use AlfaCode\LetMigrate\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 — breakpoints (Phinx-style rollback safety rail).
 */
final class BreakpointTest extends TestCase
{
    /** In-memory driver backing the breakpoint table. */
    private function memoryDriver(): DatabaseDriverInterface
    {
        return new class implements DatabaseDriverInterface {
            public array $bp = []; // migration => true
            public bool $created = false;

            public function getName(): string { return 'fake'; }
            public function execute(string $s, array $b = []): int
            {
                if (str_contains($s, 'CREATE TABLE')) { $this->created = true; }
                return 0;
            }
            public function fetchOne(string $s, array $b = []): array|null
            {
                $m = $b[0] ?? null;
                return ($m !== null && isset($this->bp[$m])) ? ['migration' => $m] : null;
            }
            public function fetchAll(string $s, array $b = []): array
            {
                return array_map(
                    static fn($m) => ['migration' => $m],
                    array_keys($this->bp),
                );
            }
            public function insert(string $t, array $d): int
            {
                $this->bp[$d['migration']] = true;
                return 1;
            }
            public function update(string $t, array $d, array $w = []): int { return 1; }
            public function delete(string $t, array $w): int
            {
                unset($this->bp[$w['migration']]);
                return 1;
            }
            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollback(): void {}
            public function inTransaction(): bool { return false; }
            public function tableExists(string $t): bool { return $this->created; }
            public function columnExists(string $t, string $c): bool { return true; }
            public function listColumns(string $t): array { return []; }
            public function listTables(): array { return []; }
            public function quoteIdentifier(string $i): string { return "\"{$i}\""; }
        
    /**
     * @inheritDoc
     */
    public function getPlatformName(): string {
        return 'fake';
    }
};
    }

    public function test_store_set_isset_clear_idempotent(): void
    {
        $store = new BreakpointStore($this->memoryDriver());

        $this->assertFalse($store->isSet('m1'));
        $store->set('m1');
        $store->set('m1'); // idempotent
        $this->assertTrue($store->isSet('m1'));
        $this->assertSame(['m1'], $store->all());

        $store->clear('m1');
        $this->assertFalse($store->isSet('m1'));
        $this->assertSame([], $store->all());
    }

    public function test_blocking_filters_to_breakpointed(): void
    {
        $store = new BreakpointStore($this->memoryDriver());
        $store->set('m2');

        $this->assertSame(
            ['m2'],
            $store->blocking(['m1', 'm2', 'm3']),
        );
        $this->assertSame([], $store->blocking([]));
    }

    private function runner(BreakpointStore|null $bp): MigrationRunner
    {
        $migration = new class implements MigrationInterface {
            public function up($s): void {}
            public function down($s): void {}
        };

        $repo = $this->createStub(MigrationRepositoryInterface::class);
        $repo->method('ensureTable')->willReturnCallback(fn() => null);
        $repo->method('appliedFilenames')->willReturn(['m1', 'm2', 'm3']);
        $repo->method('lastBatch')->willReturn(3);

        // One migration per BATCH. MigrationRunner::lastApplied() counts steps
        // as BATCHES, not as individual migrations, so without these records
        // every migration falls into batch 0 and "roll back one step" means
        // roll back all three — which made a breakpoint anywhere look like it
        // blocked everything, and hid whether the guard scopes correctly.
        $repo->method('all')->willReturn([
            new MigrationRecord(1, 'm1', 1, '2024-01-01 00:00:00'),
            new MigrationRecord(2, 'm2', 2, '2024-01-02 00:00:00'),
            new MigrationRecord(3, 'm3', 3, '2024-01-03 00:00:00'),
        ]);
        $repo->method('remove')->willReturnCallback(fn() => null);

        $resolver = $this->createStub(MigrationResolverInterface::class);
        $resolver->method('resolve')->willReturn([
            'm1' => $migration, 'm2' => $migration, 'm3' => $migration,
        ]);

        $driver = $this->createStub(DatabaseDriverInterface::class);
        $driver->method('beginTransaction')->willReturnCallback(fn() => null);
        $driver->method('commit')->willReturnCallback(fn() => null);
        $driver->method('rollback')->willReturnCallback(fn() => null);

        $schema = $this->createStub(SchemaBuilderInterface::class);
        $schema->method('getDriver')->willReturn($driver);

        return new MigrationRunner(
            repository: $repo, resolver: $resolver, schema: $schema,
            transactional: true, allOrNothing: false, breakpoints: $bp,
        );
    }

    public function test_rollback_proceeds_without_breakpoint_store(): void
    {
        // null store → feature off → behaves exactly as before
        $result = $this->runner(null)->rollback(1);
        $this->assertNotNull($result);
    }

    public function test_rollback_blocked_by_breakpoint(): void
    {
        $store = new BreakpointStore($this->memoryDriver());
        $store->set('m3'); // m3 would be rolled back first (newest)

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/blocked by breakpoint.*m3/');

        $this->runner($store)->rollback(1);
    }

    public function test_rollback_allowed_when_breakpoint_elsewhere(): void
    {
        $store = new BreakpointStore($this->memoryDriver());
        $store->set('m1'); // not in the 1-step rollback set (m3 only)

        // should NOT throw — m1 isn't being rolled back
        $result = $this->runner($store)->rollback(1);
        $this->assertNotNull($result);
    }

    public function test_reset_is_also_guarded(): void
    {
        // reset() rolls back ALL → m1 (breakpointed) is included → blocked
        $store = new BreakpointStore($this->memoryDriver());
        $store->set('m1');

        $this->expectException(MigrationException::class);
        $this->runner($store)->reset();
    }
}