<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Driver\MySQL\MySQLDriver;
use AlfaCode\LetMigrate\Driver\MySQL\MySQLGrammar;
use AlfaCode\LetMigrate\Driver\PostgreSQL\PostgreSQLDriver;
use AlfaCode\LetMigrate\Driver\PostgreSQL\PostgreSQLGrammar;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteDriver;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\Driver\SQLServer\SQLServerDriver;
use AlfaCode\LetMigrate\Driver\SQLServer\SQLServerGrammar;
use AlfaCode\LetMigrate\Exception\LetMigrateException;
use AlfaCode\LetMigrate\Schema\GrammarInterface;
use AlfaCode\LetMigrate\Schema\SchemaBuilder;

/**
 * Central registry that resolves drivers and grammars by name.
 *
 * Supports built-in drivers (mysql, pgsql, sqlite, sqlsrv) and
 * custom drivers registered at runtime.
 *
 * Usage:
 *
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
 *   $schema = $registry->schemaBuilder();
 *
 *   // Register a custom driver
 *   DriverRegistry::extend('mydb', fn($cfg) => new MyCustomDriver($cfg));
 */
final class DriverRegistry
{
    /** @var array<string, callable(array<string,mixed>): DatabaseDriverInterface> */
    private static array $customDrivers = [];

    /** @var array<string, callable(array<string,mixed>): GrammarInterface> */
    private static array $customGrammars = [];

    private function __construct(
        private readonly DatabaseDriverInterface $driver,
        private readonly GrammarInterface        $grammar,
    ) {}

    // ── Factory ───────────────────────────────────────────────────

    /**
     * Build a registry from a configuration array.
     *
     * Required keys: driver, database
     * Optional keys depend on the driver (host, port, username, password, …)
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $driverName = strtolower((string) ($config['driver'] ?? ''));

        if ($driverName === '') {
            throw new LetMigrateException("DriverRegistry: 'driver' key is required in config.");
        }

        $driver  = self::resolveDriver($driverName, $config);
        $grammar = self::resolveGrammar($driverName, $config);

        return new self($driver, $grammar);
    }

    /**
     * Build directly from a driver + grammar pair (useful in tests).
     */
    public static function fromDriverAndGrammar(
        DatabaseDriverInterface $driver,
        GrammarInterface        $grammar,
    ): self {
        return new self($driver, $grammar);
    }

    // ── Extension point ───────────────────────────────────────────

    /**
     * Register a custom driver factory.
     *
     * @param callable(array<string,mixed>): DatabaseDriverInterface $factory
     */
    public static function extendDriver(string $name, callable $factory): void
    {
        self::$customDrivers[strtolower($name)] = $factory;
    }

    /**
     * Register a custom grammar factory.
     *
     * @param callable(array<string,mixed>): GrammarInterface $factory
     */
    public static function extendGrammar(string $name, callable $factory): void
    {
        self::$customGrammars[strtolower($name)] = $factory;
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

    public function schemaBuilder(): SchemaBuilderInterface
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
                "Unsupported driver '{$name}'. Supported: " . implode(', ', self::supportedDrivers()),
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
            'mysql', 'mariadb'               => new MySQLGrammar(),
            'pgsql', 'postgres', 'postgresql' => new PostgreSQLGrammar(),
            'sqlite'                          => new SQLiteGrammar(),
            'sqlsrv', 'sqlserver', 'mssql'   => new SQLServerGrammar(),
            default                           => new MySQLGrammar(),
        };
    }
}
