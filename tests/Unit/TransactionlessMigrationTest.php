<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\MigrationRepositoryInterface;
use AlfaCode\LetMigrate\Contract\MigrationResolverInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Contract\TransactionlessMigrationInterface;
use AlfaCode\LetMigrate\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 — per-migration transaction opt-out
 * (TransactionlessMigrationInterface, e.g. PG CREATE INDEX CONCURRENTLY).
 */
final class TransactionlessMigrationTest extends TestCase
{
    private function plain(): MigrationInterface
    {
        return new class implements MigrationInterface {
            public function up($s): void {}
            public function down($s): void {}
        };
    }

    private function txless(): MigrationInterface
    {
        return new class implements MigrationInterface, TransactionlessMigrationInterface {
            public function up($s): void {}
            public function down($s): void {}
        };
    }

    /**
     * @param array<string,MigrationInterface> $pending
     * @return array{0:MigrationRunner,1:array} runner, txLog
     */
    private function make(array $pending, bool $allOrNothing): array
    {
        $txLog = [];

        $repo = $this->createStub(MigrationRepositoryInterface::class);
        $repo->method('ensureTable')->willReturnCallback(fn() => null);
        $repo->method('appliedFilenames')->willReturn([]);
        $repo->method('lastBatch')->willReturn(0);
        $repo->method('all')->willReturn([]);
        $repo->method('log')->willReturnCallback(fn() => null);

        $resolver = $this->createStub(MigrationResolverInterface::class);
        $resolver->method('resolve')->willReturn($pending);

        $driver = $this->createStub(DatabaseDriverInterface::class);
        $driver->method('beginTransaction')->willReturnCallback(
            function () use (&$txLog) { $txLog[] = 'begin'; },
        );
        $driver->method('commit')->willReturnCallback(
            function () use (&$txLog) { $txLog[] = 'commit'; },
        );
        $driver->method('rollback')->willReturnCallback(
            function () use (&$txLog) { $txLog[] = 'rollback'; },
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

        return [$runner, &$txLog];
    }

    public function test_transactionless_migration_runs_with_no_transaction(): void
    {
        // not all_or_nothing: plain → per-migration tx; txless → none
        [$runner, $txLog] = $this->make([
            'a_plain' => $this->plain(),
            'b_txless' => $this->txless(),
            'c_plain' => $this->plain(),
        ], allOrNothing: false);

        $runner->run();

        // a_plain: begin/commit ; b_txless: NOTHING ; c_plain: begin/commit
        $this->assertSame(
            ['begin', 'commit', 'begin', 'commit'],
            $txLog,
            'txless migration must not be wrapped in a transaction',
        );
    }

    public function test_all_or_nothing_splits_batch_around_txless(): void
    {
        // batch tx opens; on hitting txless it commits, runs txless
        // outside, reopens; commits remainder at the end.
        [$runner, $txLog] = $this->make([
            'a_plain'  => $this->plain(),
            'b_txless' => $this->txless(),
            'c_plain'  => $this->plain(),
        ], allOrNothing: true);

        $runner->run();

        // begin(batch) → [a inside] → commit(before txless) → [b outside]
        // → begin(reopen) → [c inside] → commit(final)
        $this->assertSame(
            ['begin', 'commit', 'begin', 'commit'],
            $txLog,
        );
    }

    public function test_all_txless_no_transactions_opened(): void
    {
        [$runner, $txLog] = $this->make([
            'a_txless' => $this->txless(),
            'b_txless' => $this->txless(),
        ], allOrNothing: false);

        $runner->run();

        $this->assertSame([], $txLog, 'no transactions for an all-txless batch');
    }

    public function test_all_or_nothing_all_txless_opens_then_immediately_commits(): void
    {
        // batch tx opens, first migration is txless → commit immediately,
        // run it, reopen; second txless → commit, run, reopen; final commit.
        [$runner, $txLog] = $this->make([
            'a_txless' => $this->txless(),
            'b_txless' => $this->txless(),
        ], allOrNothing: true);

        $runner->run();

        // begin → commit(before a) → begin(reopen) → commit(before b)
        // → begin(reopen) → commit(final)
        $this->assertSame(
            ['begin', 'commit', 'begin', 'commit', 'begin', 'commit'],
            $txLog,
        );
        // every begin is paired with a commit, none left dangling
        $this->assertSame(
            count(array_filter($txLog, fn($x) => $x === 'begin')),
            count(array_filter($txLog, fn($x) => $x === 'commit')),
        );
    }
}