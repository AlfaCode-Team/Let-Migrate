<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Advisory deploy lock — guarantees only ONE migration runner proceeds at
 * a time across concurrent deploy workers (blue/green, autoscaled CI
 * runners, k8s rollouts). Neither Phinx nor Doctrine ships this.
 *
 * Strategy per driver (chosen via DatabaseDriverInterface::getName()):
 *   • pgsql  : pg_try_advisory_lock(key)  polled until timeout
 *   • mysql  : GET_LOCK(name, timeout)
 *   • sqlsrv : sp_getapplock / sp_releaseapplock
 *   • sqlite : no-op (single-writer by nature) — returns true, logs notice
 *
 * The lock NAME is namespaced (default: the tracking table) so every
 * deploy runner for the same application contends on the same lock while
 * different apps/databases don't interfere.
 *
 * Lock-strategy selection is unit-tested here with a fake driver; the
 * actual cross-process blocking behaviour is validated on the live
 * matrix (it cannot be exercised without real concurrent connections).
 */
final class DeployLock
{
    private bool $held = false;

    public function __construct(
        private readonly DatabaseDriverInterface $driver,
        private readonly string                  $name = 'let_migrate_deploy',
        private readonly LoggerInterface         $logger = new NullLogger(),
    ) {}

    /** Stable 32-bit key for pg advisory locks. */
    private function key(): int
    {
        return (int) sprintf('%u', crc32($this->name));
    }

    private function dialect(): string
    {
        $n = strtolower($this->driver->getName());

        return match (true) {
            str_contains($n, 'pgsql'), str_contains($n, 'postgres') => 'pgsql',
            str_contains($n, 'mysql'), str_contains($n, 'maria')    => 'mysql',
            str_contains($n, 'sqlsrv'), str_contains($n, 'mssql'),
            str_contains($n, 'sqlserver')                           => 'sqlsrv',
            str_contains($n, 'sqlite')                              => 'sqlite',
            default                                                 => 'unsupported',
        };
    }

    /**
     * Acquire the lock, waiting up to $timeoutSeconds. Returns true if
     * acquired, false on timeout.
     */
    public function acquire(int $timeoutSeconds = 10): bool
    {
        switch ($this->dialect()) {
            case 'pgsql':
                $deadline = time() + max(0, $timeoutSeconds);
                do {
                    $row = $this->driver->fetchOne(
                        'SELECT pg_try_advisory_lock(?) AS locked',
                        [$this->key()],
                    );
                    if ($this->truthy($row['locked'] ?? false)) {
                        return $this->held = true;
                    }
                    if (time() >= $deadline) {
                        return false;
                    }
                    sleep(1);
                } while (true);

            case 'mysql':
                $row = $this->driver->fetchOne(
                    'SELECT GET_LOCK(?, ?) AS locked',
                    [$this->name, $timeoutSeconds],
                );
                return $this->held = ((string) ($row['locked'] ?? '0') === '1');

            case 'sqlsrv':
                // sp_getapplock returns >= 0 on success
                $row = $this->driver->fetchOne(
                    "DECLARE @r INT; EXEC @r = sp_getapplock "
                    . "@Resource = ?, @LockMode = 'Exclusive', "
                    . "@LockOwner = 'Session', @LockTimeout = ?; "
                    . 'SELECT @r AS rc',
                    [$this->name, $timeoutSeconds * 1000],
                );
                return $this->held = ((int) ($row['rc'] ?? -1) >= 0);

            case 'sqlite':
                $this->logger->notice(
                    '[LetMigrate] SQLite has no advisory locks; '
                    . 'deploy lock is a no-op (single-writer engine).',
                );
                return $this->held = true;

            default:
                $this->logger->warning(
                    '[LetMigrate] Deploy lock unsupported for driver "'
                    . $this->driver->getName() . '" — proceeding without a lock.',
                );
                return $this->held = true;
        }
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }

        try {
            switch ($this->dialect()) {
                case 'pgsql':
                    $this->driver->execute('SELECT pg_advisory_unlock(?)', [$this->key()]);
                    break;
                case 'mysql':
                    $this->driver->execute('SELECT RELEASE_LOCK(?)', [$this->name]);
                    break;
                case 'sqlsrv':
                    $this->driver->execute(
                        "EXEC sp_releaseapplock @Resource = ?, @LockOwner = 'Session'",
                        [$this->name],
                    );
                    break;
                // sqlite / unsupported: nothing to release
            }
        } finally {
            $this->held = false;
        }
    }

    public function isHeld(): bool
    {
        return $this->held;
    }

    /**
     * Run $callback while holding the lock; always releases (even on
     * throw). Throws RuntimeException if the lock can't be acquired.
     *
     * @template T
     * @param  callable():T $callback
     * @return T
     */
    public function withLock(callable $callback, int $timeoutSeconds = 10): mixed
    {
        if (!$this->acquire($timeoutSeconds)) {
            throw new \RuntimeException(
                "Could not acquire deploy lock '{$this->name}' within "
                . "{$timeoutSeconds}s — another migration run is in progress.",
            );
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1'
            || $v === 't' || $v === 'true';
    }
}