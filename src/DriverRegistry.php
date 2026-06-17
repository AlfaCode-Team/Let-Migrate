<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\SchemaInspectorInterface;
use AlfaCode\LetMigrate\Driver\MySQL\MySQLDriver;
use AlfaCode\LetMigrate\Driver\MySQL\MySQLGrammar;
use AlfaCode\LetMigrate\Driver\MySQL\MySQLSchemaInspector;
use AlfaCode\LetMigrate\Driver\PostgreSQL\PostgreSQLDriver;
use AlfaCode\LetMigrate\Driver\PostgreSQL\PostgreSQLGrammar;
use AlfaCode\LetMigrate\Driver\PostgreSQL\PostgreSQLSchemaInspector;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteDriver;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteSchemaInspector;
use AlfaCode\LetMigrate\Driver\SQLServer\SQLServerDriver;
use AlfaCode\LetMigrate\Driver\SQLServer\SQLServerGrammar;
use AlfaCode\LetMigrate\Driver\SQLServer\SQLServerSchemaInspector;
use AlfaCode\LetMigrate\Exception\LetMigrateException;
use AlfaCode\LetMigrate\Schema\GrammarInterface;
use AlfaCode\LetMigrate\Schema\SchemaBuilder;

/**
 * Central registry that resolves database drivers and grammars by name.
 *
 * ────────────────────────────────────────────────────────────────────
 * FIX SUMMARY
 * ────────────────────────────────────────────────────────────────────
 * • U-04: the constructor now stores the raw $config array. Previously
 *         makeInspector() read $this->config which was never declared,
 *         causing a fatal "Undefined property" on PostgreSQL / SQL Server
 *         and whenever fromDriverAndGrammar() was used.
 * • U-05: schemaBuilder() now wires makeInspector() into SchemaBuilder so
 *         hasTable()/hasColumn() use the richer inspector. makeInspector()
 *         is wrapped so a driver without an inspector does not break the
 *         builder (null is passed instead).
 */
final class DriverRegistry
{
    /** @var array<string, callable(array<string,mixed>): DatabaseDriverInterface> */
    private static array $customDrivers = [];

    /** @var array<string, callable(array<string,mixed>): GrammarInterface> */
    private static array $customGrammars = [];

    /**
     * @param array<string, mixed> $config  raw connection config (U-04)
     */
    private function __construct(
        private readonly string                  $driverKey,
        private readonly DatabaseDriverInterface  $driver,
        private readonly GrammarInterface         $grammar,
        private readonly array                    $config = [],
    ) {
    }

    // ── Factory methods ───────────────────────────────────────────

    /**
     * Build a registry from a configuration array.
     *
     * @param array<string, mixed> $config
     *
     * @throws LetMigrateException for unknown or missing driver key
     */
    public static function fromConfig(array $config): self
    {
        $driverName = mb_strtolower((string) ($config['driver'] ?? ''));

        if ($driverName === '') {
            throw new LetMigrateException(
                "DriverRegistry: 'driver' key is required in config.",
            );
        }

        return new self(
            $driverName,
            self::resolveDriver($driverName, $config),
            self::resolveGrammar($driverName, $config),
            $config,
        );
    }

    /**
     * Build from a pre-constructed driver + grammar pair.
     * Useful in tests and when you manage the connection lifecycle yourself.
     *
     * @param array<string, mixed> $config  optional — lets makeInspector()
     *                                       read a 'schema' key in tests
     */
    public static function fromDriverAndGrammar(
        string                  $driverKey,
        DatabaseDriverInterface $driver,
        GrammarInterface        $grammar,
        array                   $config = [],
    ): self {
        return new self($driverKey, $driver, $grammar, $config);
    }

    // ── Extension points ──────────────────────────────────────────

    /**
     * Register a custom driver factory for a new driver name.
     *
     * @param callable(array<string,mixed>): DatabaseDriverInterface $factory
     */
    public static function extendDriver(string $name, callable $factory): void
    {
        self::$customDrivers[mb_strtolower($name)] = $factory;
    }

    /**
     * Register a custom grammar factory for a driver name.
     *
     * @param callable(array<string,mixed>): GrammarInterface $factory
     */
    public static function extendGrammar(string $name, callable $factory): void
    {
        self::$customGrammars[mb_strtolower($name)] = $factory;
    }

    // ── Accessors ─────────────────────────────────────────────────

    public function driver(): DatabaseDriverInterface
    {
        return $this->driver;
    }

    public function grammar(): GrammarInterface
    {
        return $this->grammar;
    }

    /**
     * Resolve the correct SchemaInspectorInterface for the active driver.
     *
     * Mapping:
     *   mysql / mariadb  → MySQLSchemaInspector
     *   pgsql            → PostgreSQLSchemaInspector (config['schema'] ?? 'public')
     *   sqlite           → SQLiteSchemaInspector
     *   sqlsrv           → SQLServerSchemaInspector (config['schema'] ?? 'dbo')
     */
    public function makeInspector(): SchemaInspectorInterface
    {
        $driver = $this->driver();
        $key    = $this->driverKey;
        $schema = (string) ($this->config['schema'] ?? '');

        return match ($key) {
            'mysql', 'mariadb' =>
                new MySQLSchemaInspector($driver),

            'pgsql', 'postgres', 'postgresql' =>
                new PostgreSQLSchemaInspector($driver, $schema !== '' ? $schema : 'public'),

            'sqlite' =>
                new SQLiteSchemaInspector($driver),

            'sqlsrv', 'sqlserver', 'mssql' =>
                new SQLServerSchemaInspector($driver, $schema !== '' ? $schema : 'dbo'),

            default => throw new LetMigrateException(
                "No SchemaInspector available for driver '{$key}'.",
            ),
        };
    }

    /**
     * Create a new SchemaBuilder wired to this registry's driver + grammar
     * AND the matching schema inspector (U-05). Returns a fresh instance on
     * every call. If the driver has no inspector, null is passed so the
     * builder still works (falling back to raw driver queries).
     */
    public function schemaBuilder(): SchemaBuilder
    {
        try {
            $inspector = $this->makeInspector();
        } catch (LetMigrateException) {
            $inspector = null;
        }

        $prefix = (string) ($this->config['prefix'] ?? '');

        return new SchemaBuilder($this->driver, $this->grammar, $inspector, $prefix);
    }

    // ── Supported driver names ────────────────────────────────────

    /** @return string[] */
    public static function supportedDrivers(): array
    {
        return array_unique(array_merge(
            ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql', 'sqlite', 'sqlsrv', 'sqlserver', 'mssql'],
            array_keys(self::$customDrivers),
        ));
    }

    // ── Private resolution ────────────────────────────────────────

    /** @param array<string, mixed> $config */
    private static function resolveDriver(string $name, array $config): DatabaseDriverInterface
    {
        if (isset(self::$customDrivers[$name])) {
            return (self::$customDrivers[$name])($config);
        }

        return match ($name) {
            'mysql', 'mariadb' => new MySQLDriver(
                host:     (string) ($config['host']     ?? '127.0.0.1'),
                port:     (int)    ($config['port']     ?? 3306),
                database: (string) ($config['database'] ?? ''),
                username: (string) ($config['username'] ?? 'root'),
                password: (string) ($config['password'] ?? ''),
                charset:  (string) ($config['charset']  ?? 'utf8mb4'),
            ),

            'pgsql', 'postgres', 'postgresql' => new PostgreSQLDriver(
                host:     (string) ($config['host']     ?? '127.0.0.1'),
                port:     (int)    ($config['port']     ?? 5432),
                database: (string) ($config['database'] ?? ''),
                username: (string) ($config['username'] ?? 'postgres'),
                password: (string) ($config['password'] ?? ''),
                schema:   (string) ($config['schema']   ?? 'public'),
            ),

            'sqlite' => new SQLiteDriver(
                path: (string) ($config['database'] ?? ':memory:'),
            ),

            'sqlsrv', 'sqlserver', 'mssql' => new SQLServerDriver(
                host:     (string) ($config['host']     ?? '127.0.0.1'),
                port:     (int)    ($config['port']     ?? 1433),
                database: (string) ($config['database'] ?? ''),
                username: (string) ($config['username'] ?? 'sa'),
                password: (string) ($config['password'] ?? ''),
                schema:   (string) ($config['schema']   ?? 'dbo'),
            ),

            default => throw new LetMigrateException(
                "Unsupported driver '{$name}'. Supported: "
                . implode(', ', self::supportedDrivers()),
            ),
        };
    }

    /** @param array<string, mixed> $config */
    private static function resolveGrammar(string $name, array $config): GrammarInterface
    {
        if (isset(self::$customGrammars[$name])) {
            return (self::$customGrammars[$name])($config);
        }

        return match ($name) {
            'mysql', 'mariadb'                  => new MySQLGrammar(),
            'pgsql', 'postgres', 'postgresql'   => new PostgreSQLGrammar(),
            'sqlite'                            => new SQLiteGrammar(),
            'sqlsrv', 'sqlserver', 'mssql'      => new SQLServerGrammar(),
            default                             => new MySQLGrammar(),
        };
    }
}