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
 * Phase 3 — all_or_nothing (Doctrine parity).
 *
 *   off (default): one transaction PER migration (unchanged behaviour)
 *   on:            ONE transaction for the WHOLE batch; any failure rolls
 *                  the entire batch back and records nothing as applied.
 */
final class AllOrNothingTest extends TestCase
{
    private function migration(bool $fail = false): MigrationInterface
    {
        return new class($fail) implements MigrationInterface {
            public function __construct(private bool $fail) {}
            public function up($s): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('boom');
                }
            }
            public function down($s): void {}
        };
    }

    /**
     * The two recorders are ArrayObjects, NOT arrays, and that is load-bearing.
     *
     * `[$runner, $txLog] = $this->make(...)` copies the array element by VALUE
     * at the moment of destructuring — before run() is ever called — so a
     * plain array recorder handed back this way is a SNAPSHOT of the empty
     * list, and stays empty however many times the closures append to the
     * local inside make(). Binding it with `&` does not help either: PHP
     * cannot take a reference to a function's return value. An object is a
     * handle, so the caller and the closures see the same thing.
     *
     * @param array<string,MigrationInterface> $pending
     * @return array{0:MigrationRunner,1:\ArrayObject,2:\ArrayObject} runner, txLog, logged
     */
    private function make(array $pending, bool $allOrNothing): array
    {
        $txLog  = new \ArrayObject();
        $logged = new \ArrayObject();

        $repo = $this->createStub(MigrationRepositoryInterface::class);
        $repo->method('ensureTable')->willReturnCallback(fn() => null);
        $repo->method('appliedFilenames')->willReturn([]);
        $repo->method('lastBatch')->willReturn(0);
        $repo->method('all')->willReturn([]);
        $repo->method('log')->willReturnCallback(
            function (string $f) use ($logged) { $logged->append($f); },
        );

        $resolver = $this->createStub(MigrationResolverInterface::class);
        $resolver->method('resolve')->willReturn($pending);

        // The stub must TRACK whether a transaction is open, not just record
        // the calls. MigrationRunner guards its commit with
        // `if ($driver->inTransaction())` — because DDL implicitly commits on
        // MySQL and SQL Server, closing the transaction underneath it — so a
        // stub whose inTransaction() answers the type-default `false` models
        // no real driver and silently swallows every commit.
        $open = new \ArrayObject(['tx' => false]);

        $driver = $this->createStub(DatabaseDriverInterface::class);
        $driver->method('beginTransaction')->willReturnCallback(
            function () use ($txLog, $open) { $txLog->append('begin'); $open['tx'] = true; },
        );
        $driver->method('commit')->willReturnCallback(
            function () use ($txLog, $open) { $txLog->append('commit'); $open['tx'] = false; },
        );
        $driver->method('rollback')->willReturnCallback(
            function () use ($txLog, $open) { $txLog->append('rollback'); $open['tx'] = false; },
        );
        $driver->method('inTransaction')->willReturnCallback(
            static fn(): bool => (bool) $open['tx'],
        );

        $schema = $this->createStub(SchemaBuilderInterface::class);
        $schema->method('getDriver')->willReturn($driver);

        $runner = new MigrationRunner(
            repository:    $repo,
            resolver:      $resolver,
            schema:        $schema,
            transactional: true,
            allOrNothing:  $allOrNothing,
        );

        return [$runner, $txLog, $logged];
    }

    public function test_default_uses_one_transaction_per_migration(): void
    {
        [$runner, $txLog] = $this->make([
            'm1' => $this->migration(),
            'm2' => $this->migration(),
            'm3' => $this->migration(),
        ], allOrNothing: false);

        $runner->run();

        // 3 migrations → 3 begin/commit pairs
        $this->assertSame(
            ['begin', 'commit', 'begin', 'commit', 'begin', 'commit'],
            $txLog->getArrayCopy(),
        );
    }

    public function test_all_or_nothing_uses_single_batch_transaction(): void
    {
        [$runner, $txLog, $logged] = $this->make([
            'm1' => $this->migration(),
            'm2' => $this->migration(),
            'm3' => $this->migration(),
        ], allOrNothing: true);

        $runner->run();

        // exactly one begin + one commit for the whole batch
        $this->assertSame(['begin', 'commit'], $txLog->getArrayCopy());
        $this->assertSame(['m1', 'm2', 'm3'], $logged->getArrayCopy());
    }

    public function test_all_or_nothing_rolls_back_entire_batch_on_failure(): void
    {
        // m2 fails → whole batch must roll back, nothing recorded applied
        [$runner, $txLog, $logged] = $this->make([
            'm1' => $this->migration(),
            'm2' => $this->migration(fail: true),
            'm3' => $this->migration(),
        ], allOrNothing: true);

        try {
            $runner->run();
            $this->fail('expected MigrationException');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('m2', $e->getMessage());
        }

        $this->assertSame(['begin', 'rollback'], $txLog->getArrayCopy());
        // m1 was logged before m2 failed, but the single rollback undoes
        // the DB transaction; the repository->log() calls happened inside
        // it, so from the DB's perspective nothing persisted. The stub
        // records the call ordering — we assert no commit occurred:
        $this->assertNotContains('commit', $txLog->getArrayCopy());
    }

    public function test_pretend_disables_batch_transaction(): void
    {
        $txLog  = [];
        $repo   = $this->createStub(MigrationRepositoryInterface::class);
        $repo->method('ensureTable')->willReturnCallback(fn() => null);
        $repo->method('appliedFilenames')->willReturn([]);
        $repo->method('lastBatch')->willReturn(0);
        $repo->method('all')->willReturn([]);

        $resolver = $this->createStub(MigrationResolverInterface::class);
        $resolver->method('resolve')->willReturn(['m1' => $this->migration()]);

        $driver = $this->createStub(DatabaseDriverInterface::class);
        $driver->method('beginTransaction')->willReturnCallback(
            function () use ($txLog) { $txLog->append('begin'); },
        );

        $schema = $this->createStub(SchemaBuilderInterface::class);
        $schema->method('getDriver')->willReturn($driver);

        $runner = new MigrationRunner(
            repository: $repo, resolver: $resolver, schema: $schema,
            pretend: true, transactional: true, allOrNothing: true,
        );

        $runner->run();

        $this->assertSame([], $txLog, 'pretend must not open a transaction');
    }
}