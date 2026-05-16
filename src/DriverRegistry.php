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
 * Supports built-in drivers (mysql, pgsql, sqlite, sqlsrv) and custom
 * drivers registered at runtime via extendDriver() / extendGrammar().
 *
 * Usage
 * ─────
 *   // From a config array
 *   $registry = DriverRegistry::fromConfig([
 *       'driver'   => 'mysql',
 *       'host'     => '127.0.0.1',
 *       'port'     => 3306,
 *       'database' => 'my_app',
 *       'username' => 'root',
 *       'password' => 'secret',
 *   ]);
 *
 *   // Pre-built driver + grammar pair (useful in tests)
 *   $registry = DriverRegistry::fromDriverAndGrammar($driver, $grammar);
 *
 *   // Register a custom driver
 *   DriverRegistry::extendDriver('mydb', fn($cfg) => new MyDriver($cfg));
 * 
 *   @updated makeInspector() — resolves the correct SchemaInspectorInterface
 *          implementation for the active driver and returns it. Called by
 *          LetMigrate::inspect() and by SchemaBuilder when one is needed.
 *
 */
final class DriverRegistry
{
    /** @var array<string, callable(array<string,mixed>): DatabaseDriverInterface> */
    private static array $customDrivers = [];

    /** @var array<string, callable(array<string,mixed>): GrammarInterface> */
    private static array $customGrammars = [];

    private function __construct(
        private readonly string $driverKey,
        private readonly DatabaseDriverInterface $driver,
        private readonly GrammarInterface $grammar,
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
        );
    }

    /**
     * Build from a pre-constructed driver + grammar pair.
     * Useful in tests and when you manage the connection lifecycle yourself.
     */
    public static function fromDriverAndGrammar(
        string $driverKey,
        DatabaseDriverInterface $driver,
        GrammarInterface $grammar,
    ): self {
        return new self($driverKey, $driver, $grammar);
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
     *   pgsql            → PostgreSQLSchemaInspector (schema from config['schema'] ?? 'public')
     *   sqlite           → SQLiteSchemaInspector
     *   sqlsrv           → SQLServerSchemaInspector (schema from config['schema'] ?? 'dbo')
     *
     * Called by LetMigrate::inspect() and SchemaBuilder when an inspector is needed.
     */
    public function makeInspector(): SchemaInspectorInterface
    {
        $driver = $this->driver();
        $key = $this->driverKey;
        $schema = (string) ($this->config['schema'] ?? '');

        return match ($key) {
            'mysql', 'mariadb' =>
            new MySQLSchemaInspector($driver),

            'pgsql', 'postgresql' =>
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
     * Create a new SchemaBuilder wired to this registry's driver + grammar.
     * Returns a fresh instance on every call.
     */
    public function schemaBuilder(): SchemaBuilder
    {
        return new SchemaBuilder($this->driver, $this->grammar);
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
                host: (string) ($config['host'] ?? '127.0.0.1'),
                port: (int) ($config['port'] ?? 3306),
                database: (string) ($config['database'] ?? ''),
                username: (string) ($config['username'] ?? 'root'),
                password: (string) ($config['password'] ?? ''),
                charset: (string) ($config['charset'] ?? 'utf8mb4'),
            ),

            'pgsql', 'postgres', 'postgresql' => new PostgreSQLDriver(
                host: (string) ($config['host'] ?? '127.0.0.1'),
                port: (int) ($config['port'] ?? 5432),
                database: (string) ($config['database'] ?? ''),
                username: (string) ($config['username'] ?? 'postgres'),
                password: (string) ($config['password'] ?? ''),
                schema: (string) ($config['schema'] ?? 'public'),
            ),

            'sqlite' => new SQLiteDriver(
                path: (string) ($config['database'] ?? ':memory:'),
            ),

            'sqlsrv', 'sqlserver', 'mssql' => new SQLServerDriver(
                host: (string) ($config['host'] ?? '127.0.0.1'),
                port: (int) ($config['port'] ?? 1433),
                database: (string) ($config['database'] ?? ''),
                username: (string) ($config['username'] ?? 'sa'),
                password: (string) ($config['password'] ?? ''),
                schema: (string) ($config['schema'] ?? 'dbo'),
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
            'mysql', 'mariadb' => new MySQLGrammar(),
            'pgsql', 'postgres', 'postgresql' => new PostgreSQLGrammar(),
            'sqlite' => new SQLiteGrammar(),
            'sqlsrv', 'sqlserver', 'mssql' => new SQLServerGrammar(),
            default => new MySQLGrammar(),
        };
    }
}
