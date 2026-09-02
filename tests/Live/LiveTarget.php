<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Live;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\SchemaInspectorInterface;
use AlfaCode\LetMigrate\Driver\MySQL\MySQLDriver;
use AlfaCode\LetMigrate\Driver\MySQL\MySQLGrammar;
use AlfaCode\LetMigrate\Driver\MySQL\MySQLSchemaInspector;
use AlfaCode\LetMigrate\Driver\PostgreSQL\PostgreSQLDriver;
use AlfaCode\LetMigrate\Driver\PostgreSQL\PostgreSQLGrammar;
use AlfaCode\LetMigrate\Driver\PostgreSQL\PostgreSQLSchemaInspector;
use AlfaCode\LetMigrate\Driver\SQLServer\SQLServerDriver;
use AlfaCode\LetMigrate\Driver\SQLServer\SQLServerGrammar;
use AlfaCode\LetMigrate\Driver\SQLServer\SQLServerSchemaInspector;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteDriver;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteSchemaInspector;
use AlfaCode\LetMigrate\Schema\GrammarInterface;

/**
 * One real database this machine can prove the compiler against.
 *
 * ─── WHY THIS EXISTS ────────────────────────────────────────────────────────
 *
 * Every unit test in this package compiles SQL and inspects the string. That
 * cannot catch the only failure mode that matters here: SQL that is
 * well-formed, plausible, and refused by an engine. `ADD COLUMN` looks correct
 * on all four drivers and is a syntax error on SQL Server; `DEFAULT 1` on a
 * boolean is fine on three and a type error on PostgreSQL; libpq's `$1`
 * placeholder is accepted by PDO and silently matches NOTHING. A fake records
 * SQL, it does not execute it.
 *
 * ─── WHERE THE CONNECTIONS COME FROM ────────────────────────────────────────
 *
 * A URL per driver, from the environment. Nothing is committed, because the
 * servers a developer has are a property of that machine:
 *
 *     LETMIGRATE_DB_MYSQL=mysql://root:secret@127.0.0.1:3306
 *     LETMIGRATE_DB_PGSQL=pgsql://postgres:secret@127.0.0.1:5432
 *     LETMIGRATE_DB_SQLSRV=sqlsrv://sa:Secret_123@127.0.0.1:1433
 *
 * GROUND_DB_* is honoured as a fallback so a machine already set up for
 * `hkm ground migrate` needs no new configuration. SQLite needs none at all:
 * it is a file, and its PDO driver ships with PHP.
 *
 * ─── REACHABILITY IS PROBED, NEVER ASSUMED ──────────────────────────────────
 *
 * An unconfigured or unreachable driver makes its test SKIP with the reason.
 * It is never silently dropped and never counted as a pass — "3 of 4 engines
 * were not tested" is the single most important line such a run can print, and
 * it is worthless if a missing server looks like a green tick.
 */
final class LiveTarget
{
    /** Every driver the compiler supports, in the order a report should list them. */
    public const DRIVERS = ['sqlite', 'mysql', 'pgsql', 'sqlsrv'];

    private const ENV = [
        'mysql'  => ['LETMIGRATE_DB_MYSQL',  'GROUND_DB_MYSQL'],
        'pgsql'  => ['LETMIGRATE_DB_PGSQL',  'GROUND_DB_PGSQL'],
        'sqlsrv' => ['LETMIGRATE_DB_SQLSRV', 'GROUND_DB_SQLSRV'],
    ];

    /** The PDO driver each needs, for a precise "why not" message. */
    private const PDO_DRIVER = [
        'sqlite' => ['sqlite'],
        'mysql'  => ['mysql'],
        'pgsql'  => ['pgsql'],
        'sqlsrv' => ['sqlsrv', 'dblib'],   // SQLServerDriver falls back to dblib
    ];

    private function __construct(
        public readonly string                   $driverName,
        public readonly DatabaseDriverInterface  $driver,
        public readonly GrammarInterface         $grammar,
        public readonly SchemaInspectorInterface $inspector,
        public readonly string                   $database,
        private readonly ?\PDO                   $server,
        private readonly ?string                 $file,
    ) {}

    /**
     * Why this driver cannot be tested here, or null when it can.
     *
     * Returned as a STRING rather than thrown so the caller can skip with the
     * reason attached — a skip that does not say why is indistinguishable from
     * a test nobody wrote.
     */
    public static function unavailableReason(string $driver): ?string
    {
        $pdo = array_intersect(self::PDO_DRIVER[$driver] ?? [], \PDO::getAvailableDrivers());

        if ($pdo === []) {
            return sprintf(
                'no PDO driver for %s (need one of: %s; have: %s)',
                $driver,
                implode(', ', self::PDO_DRIVER[$driver] ?? []),
                implode(', ', \PDO::getAvailableDrivers()),
            );
        }

        if ($driver === 'sqlite') {
            return null;   // a file — always available
        }

        if (self::url($driver) === null) {
            return sprintf('%s not configured (set %s)', $driver, self::ENV[$driver][0]);
        }

        return null;
    }

    /** The configured URL for a driver, or null. */
    private static function url(string $driver): ?string
    {
        foreach (self::ENV[$driver] ?? [] as $var) {
            $value = getenv($var);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Connect and create a scratch database to work in.
     *
     * A SCRATCH database, never an existing one: these tests create, alter and
     * drop tables, and pointing them at a database someone cares about is a
     * mistake that only has to be made once.
     *
     * @throws \RuntimeException when the server is configured but not answering
     */
    public static function create(string $driver): self
    {
        $name = 'lm_live_' . bin2hex(random_bytes(4));

        if ($driver === 'sqlite') {
            $file = sys_get_temp_dir() . '/' . $name . '.sqlite';
            $d    = new SQLiteDriver($file);
            $g    = new SQLiteGrammar();

            return new self('sqlite', $d, $g, new SQLiteSchemaInspector($d), $file, null, $file);
        }

        $url = self::url($driver);
        $p   = parse_url((string) $url) ?: [];

        $host = $p['host'] ?? '127.0.0.1';
        $port = (int) ($p['port'] ?? match ($driver) {
            'mysql' => 3306, 'pgsql' => 5432, 'sqlsrv' => 1433,
        });
        $user = rawurldecode((string) ($p['user'] ?? ''));
        $pass = rawurldecode((string) ($p['pass'] ?? ''));

        [$serverDsn, $adminDb] = match ($driver) {
            'mysql'  => ["mysql:host={$host};port={$port}", null],
            'pgsql'  => ["pgsql:host={$host};port={$port};dbname=postgres", 'postgres'],
            'sqlsrv' => [
                in_array('sqlsrv', \PDO::getAvailableDrivers(), true)
                    ? "sqlsrv:Server={$host},{$port};Database=master"
                    : "dblib:host={$host}:{$port};dbname=master",
                'master',
            ],
        };

        try {
            $server = new \PDO($serverDsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 3,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf('%s configured at %s:%d but not answering: %s', $driver, $host, $port, $e->getMessage()),
                0,
                $e,
            );
        }

        $quoted = $driver === 'mysql' ? "`{$name}`" : "\"{$name}\"";
        $server->exec("CREATE DATABASE {$quoted}");

        [$d, $g, $i] = match ($driver) {
            'mysql' => (static function () use ($host, $port, $name, $user, $pass) {
                $d = new MySQLDriver($host, $port, $name, $user, $pass);

                return [$d, new MySQLGrammar(), new MySQLSchemaInspector($d)];
            })(),
            'pgsql' => (static function () use ($host, $port, $name, $user, $pass) {
                $d = new PostgreSQLDriver($host, $port, $name, $user, $pass);

                return [$d, new PostgreSQLGrammar(), new PostgreSQLSchemaInspector($d, 'public')];
            })(),
            'sqlsrv' => (static function () use ($host, $port, $name, $user, $pass) {
                $d = new SQLServerDriver($host, $port, $name, $user, $pass);

                return [$d, new SQLServerGrammar(), new SQLServerSchemaInspector($d, 'dbo')];
            })(),
        };

        return new self($driver, $d, $g, $i, $name, $server, null);
    }

    /** Drop the scratch database / file. Never throws — teardown must not mask a failure. */
    public function destroy(): void
    {
        try {
            if ($this->file !== null) {
                if (is_file($this->file)) {
                    unlink($this->file);
                }

                return;
            }

            $quoted = $this->driverName === 'mysql' ? "`{$this->database}`" : "\"{$this->database}\"";

            // PostgreSQL refuses to drop a database that still has a session
            // attached, and the driver holds its connection open with no
            // disconnect() on the contract — so terminate the sessions as part
            // of the DROP. WITH (FORCE) is PostgreSQL 13+.
            $extra = $this->driverName === 'pgsql' ? ' WITH (FORCE)' : '';

            $this->server?->exec("DROP DATABASE {$quoted}{$extra}");
        } catch (\Throwable) {
            // A leaked scratch database is noise, not a test result.
        }
    }
}
