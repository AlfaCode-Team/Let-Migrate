<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\DeployLock;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 — advisory deploy lock (concurrent-deploy safety).
 *
 * The strategy chosen per driver name is asserted here; real cross-process
 * blocking is a live-matrix concern.
 */
final class DeployLockTest extends TestCase
{
    /**
     * @param string $name driver name
     * @param array<string,mixed>|null $fetchReturn what fetchOne returns
     */
    private function driver(string $name, array|null $fetchReturn, array &$sql): DatabaseDriverInterface
    {
        return new class($name, $fetchReturn, $sql) implements DatabaseDriverInterface {
            public function __construct(
                private string $n,
                private array|null $ret,
                public array &$sql,
            ) {}
            public function getName(): string { return $this->n; }
            public function execute(string $s, array $b = []): int { $this->sql[] = $s; return 0; }
            public function fetchOne(string $s, array $b = []): array|null
            {
                $this->sql[] = $s;
                return $this->ret;
            }
            public function fetchAll(string $s, array $b = []): array { return []; }
            public function insert(string $t, array $d): int { return 1; }
            public function update(string $t, array $d, array $w = []): int { return 1; }
            public function delete(string $t, array $w): int { return 1; }
            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollback(): void {}
            public function inTransaction(): bool { return false; }
            public function tableExists(string $t): bool { return false; }
            public function columnExists(string $t, string $c): bool { return false; }
            public function listColumns(string $t): array { return []; }
            public function listTables(): array { return []; }
            public function quoteIdentifier(string $i): string { return $i; }
        
    /**
     * @inheritDoc
     */
    public function getPlatformName(): string {
        return $this->n;
    }
};
    }

    public function test_postgres_uses_advisory_lock(): void
    {
        $sql = [];
        $d = $this->driver('pgsql', ['locked' => true], $sql);
        $lock = new DeployLock($d, 'app');

        $this->assertTrue($lock->acquire(5));
        $this->assertTrue($lock->isHeld());
        $lock->release();

        $joined = implode(' | ', $sql);
        $this->assertStringContainsString('pg_try_advisory_lock', $joined);
        $this->assertStringContainsString('pg_advisory_unlock', $joined);
    }

    public function test_mysql_uses_get_lock(): void
    {
        $sql = [];
        $d = $this->driver('mysql', ['locked' => '1'], $sql);
        $lock = new DeployLock($d, 'app');

        $this->assertTrue($lock->acquire(3));
        $lock->release();

        $joined = implode(' | ', $sql);
        $this->assertStringContainsString('GET_LOCK', $joined);
        $this->assertStringContainsString('RELEASE_LOCK', $joined);
    }

    public function test_mysql_timeout_returns_false(): void
    {
        $sql = [];
        $d = $this->driver('mysql', ['locked' => '0'], $sql); // 0 = timeout
        $lock = new DeployLock($d, 'app');

        $this->assertFalse($lock->acquire(1));
        $this->assertFalse($lock->isHeld());
    }

    public function test_sqlite_is_noop_but_succeeds(): void
    {
        $sql = [];
        $d = $this->driver('sqlite', null, $sql);
        $lock = new DeployLock($d, 'app');

        $this->assertTrue($lock->acquire());
        $lock->release();
        // no lock SQL issued for sqlite
        $this->assertSame([], $sql);
    }

    public function test_sqlserver_uses_sp_getapplock(): void
    {
        $sql = [];
        $d = $this->driver('sqlsrv', ['rc' => 0], $sql);
        $lock = new DeployLock($d, 'app');

        $this->assertTrue($lock->acquire(2));
        $lock->release();

        $joined = implode(' | ', $sql);
        $this->assertStringContainsString('sp_getapplock', $joined);
        $this->assertStringContainsString('sp_releaseapplock', $joined);
    }

    public function test_with_lock_runs_callback_and_releases(): void
    {
        $sql = [];
        $d = $this->driver('mysql', ['locked' => '1'], $sql);
        $lock = new DeployLock($d, 'app');

        $ran = false;
        $out = $lock->withLock(function () use (&$ran) {
            $ran = true;
            return 'done';
        });

        $this->assertTrue($ran);
        $this->assertSame('done', $out);
        $this->assertFalse($lock->isHeld(), 'lock released after withLock');
        $this->assertStringContainsString('RELEASE_LOCK', implode(' ', $sql));
    }

    public function test_with_lock_releases_even_on_exception(): void
    {
        $sql = [];
        $d = $this->driver('mysql', ['locked' => '1'], $sql);
        $lock = new DeployLock($d, 'app');

        try {
            $lock->withLock(function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('exception should propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertFalse($lock->isHeld());
        $this->assertStringContainsString('RELEASE_LOCK', implode(' ', $sql));
    }

    public function test_with_lock_throws_when_unacquirable(): void
    {
        $sql = [];
        $d = $this->driver('mysql', ['locked' => '0'], $sql); // never acquires
        $lock = new DeployLock($d, 'app');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not acquire deploy lock/');

        $lock->withLock(fn() => 'never', 1);
    }

    public function test_unsupported_driver_proceeds_without_lock(): void
    {
        $sql = [];
        $d = $this->driver('oracle', null, $sql);
        $lock = new DeployLock($d, 'app');

        // proceeds (true) so migrations aren't blocked on an unknown driver
        $this->assertTrue($lock->acquire());
        $this->assertSame([], $sql);
    }
}