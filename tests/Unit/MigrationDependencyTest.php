<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\DependentMigrationInterface;
use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\MigrationRepositoryInterface;
use AlfaCode\LetMigrate\Contract\MigrationResolverInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Exception\MigrationException;
use AlfaCode\LetMigrate\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 — inter-migration dependencies (Doctrine @dependsOn parity).
 *
 *  • plain migrations keep timestamp order (no BC change)
 *  • a DependentMigrationInterface runs AFTER its declared deps even when
 *    its own timestamp is earlier
 *  • deps not in the pending set are ignored
 *  • cycles raise MigrationException
 */
final class MigrationDependencyTest extends TestCase
{
    private function plain(): MigrationInterface
    {
        return new class implements MigrationInterface {
            public function up($s): void {}
            public function down($s): void {}
        };
    }

    private function dependent(array $deps): MigrationInterface
    {
        return new class($deps) implements MigrationInterface, DependentMigrationInterface {
            public function __construct(private array $deps) {}
            public function dependsOn(): array { return $this->deps; }
            public function up($s): void {}
            public function down($s): void {}
        };
    }

    /**
     * @param array<string,MigrationInterface> $resolved insertion order = timestamp order
     */
    private function runRecording(array $resolved): array
    {
        $logged = [];

        $repo = $this->createStub(MigrationRepositoryInterface::class);
        $repo->method('ensureTable')->willReturnCallback(fn() => null);
        $repo->method('appliedFilenames')->willReturn([]);
        $repo->method('lastBatch')->willReturn(0);
        $repo->method('all')->willReturn([]);
        $repo->method('log')->willReturnCallback(
            function (string $f) use (&$logged) { $logged[] = $f; },
        );

        $resolver = $this->createStub(MigrationResolverInterface::class);
        $resolver->method('resolve')->willReturn($resolved);

        $driver = $this->createStub(DatabaseDriverInterface::class);
        $driver->method('beginTransaction')->willReturnCallback(fn() => null);
        $driver->method('commit')->willReturnCallback(fn() => null);
        $driver->method('rollback')->willReturnCallback(fn() => null);

        $schema = $this->createStub(SchemaBuilderInterface::class);
        $schema->method('getDriver')->willReturn($driver);

        $runner = new MigrationRunner(
            repository: $repo, resolver: $resolver, schema: $schema, transactional: true,
        );
        $runner->run();

        return $logged;
    }

    public function test_plain_migrations_keep_timestamp_order(): void
    {
        $order = $this->runRecording([
            '2024_01_01_a' => $this->plain(),
            '2024_01_02_b' => $this->plain(),
            '2024_01_03_c' => $this->plain(),
        ]);

        $this->assertSame(['2024_01_01_a', '2024_01_02_b', '2024_01_03_c'], $order);
    }

    public function test_dependency_runs_after_its_target_despite_earlier_timestamp(): void
    {
        // 'a' has the EARLIEST timestamp but depends on 'c' (latest) →
        // expected order: b? … specifically c before a.
        $order = $this->runRecording([
            '2024_01_01_a' => $this->dependent(['2024_01_03_c']),
            '2024_01_02_b' => $this->plain(),
            '2024_01_03_c' => $this->plain(),
        ]);

        $posA = array_search('2024_01_01_a', $order, true);
        $posC = array_search('2024_01_03_c', $order, true);
        $this->assertLessThan($posA, $posC, 'c must run before a');
        $this->assertCount(3, $order);
    }

    public function test_unknown_or_applied_dependency_is_ignored(): void
    {
        // depends on something not in the pending set → no effect, no error
        $order = $this->runRecording([
            '2024_01_01_a' => $this->dependent(['2023_already_applied']),
            '2024_01_02_b' => $this->plain(),
        ]);

        $this->assertSame(['2024_01_01_a', '2024_01_02_b'], $order);
    }

    public function test_chain_dependency_order(): void
    {
        // c → b → a   (each depends on the previous); timestamps reversed
        $order = $this->runRecording([
            '2024_03_c' => $this->dependent(['2024_02_b']),
            '2024_02_b' => $this->dependent(['2024_01_a']),
            '2024_01_a' => $this->plain(),
        ]);

        $this->assertSame(['2024_01_a', '2024_02_b', '2024_03_c'], $order);
    }

    public function test_cycle_throws(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/[Cc]ircular migration dependency/');

        $this->runRecording([
            '2024_x_a' => $this->dependent(['2024_x_b']),
            '2024_x_b' => $this->dependent(['2024_x_a']),
        ]);
    }
}