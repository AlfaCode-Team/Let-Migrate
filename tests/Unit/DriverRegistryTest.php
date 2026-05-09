<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Driver\MySQL\MySQLGrammar;
use AlfaCode\LetMigrate\Driver\PostgreSQL\PostgreSQLGrammar;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteDriver;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\Driver\SQLServer\SQLServerGrammar;
use AlfaCode\LetMigrate\Exception\LetMigrateException;
use AlfaCode\LetMigrate\Registry\DriverRegistry;
use AlfaCode\LetMigrate\Schema\SchemaBuilder;
use PHPUnit\Framework\TestCase;

final class DriverRegistryTest extends TestCase
{
    // ── fromConfig — driver resolution ────────────────────────────

    public function test_from_config_resolves_sqlite_driver(): void
    {
        $registry = DriverRegistry::fromConfig([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'paths'    => [sys_get_temp_dir()],
        ]);

        $this->assertSame('sqlite', $registry->driver()->getName());
    }

    public function test_from_config_resolves_mysql_grammar(): void
    {
        // We cannot connect to a real MySQL in unit tests, but grammar resolution
        // (which is stateless) is safe to assert.
        $registry = DriverRegistry::fromConfig([
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'database' => 'test',
            'username' => 'root',
            'password' => '',
            'paths'    => [],
        ]);

        $this->assertInstanceOf(MySQLGrammar::class, $registry->grammar());
    }

    public function test_from_config_resolves_postgresql_grammar(): void
    {
        $registry = DriverRegistry::fromConfig([
            'driver'   => 'pgsql',
            'host'     => '127.0.0.1',
            'database' => 'test',
            'username' => 'postgres',
            'password' => '',
            'paths'    => [],
        ]);

        $this->assertInstanceOf(PostgreSQLGrammar::class, $registry->grammar());
    }

    public function test_from_config_resolves_sqlserver_grammar(): void
    {
        $registry = DriverRegistry::fromConfig([
            'driver'   => 'sqlsrv',
            'host'     => '127.0.0.1',
            'database' => 'test',
            'username' => 'sa',
            'password' => '',
            'paths'    => [],
        ]);

        $this->assertInstanceOf(SQLServerGrammar::class, $registry->grammar());
    }

    public function test_driver_name_aliases_are_accepted(): void
    {
        $aliases = ['mariadb', 'postgres', 'postgresql', 'sqlserver', 'mssql'];

        foreach ($aliases as $alias) {
            $registry = DriverRegistry::fromConfig([
                'driver'   => $alias,
                'host'     => '127.0.0.1',
                'database' => 'test',
                'username' => 'root',
                'password' => '',
                'paths'    => [],
            ]);

            $this->assertNotNull($registry->grammar(), "Grammar must resolve for alias '{$alias}'");
        }
    }

    public function test_unknown_driver_throws_exception(): void
    {
        $this->expectException(LetMigrateException::class);
        $this->expectExceptionMessageMatches('/Unsupported driver/i');

        DriverRegistry::fromConfig([
            'driver'   => 'oracle',
            'database' => 'test',
            'paths'    => [],
        ]);
    }

    public function test_missing_driver_key_throws_exception(): void
    {
        $this->expectException(LetMigrateException::class);
        $this->expectExceptionMessageMatches("/driver.*required/i");

        DriverRegistry::fromConfig(['database' => 'test', 'paths' => []]);
    }

    // ── fromDriverAndGrammar ──────────────────────────────────────

    public function test_from_driver_and_grammar_builds_correctly(): void
    {
        $driver   = new SQLiteDriver(':memory:');
        $grammar  = new SQLiteGrammar();
        $registry = DriverRegistry::fromDriverAndGrammar($driver, $grammar);

        $this->assertSame($driver,  $registry->driver());
        $this->assertSame($grammar, $registry->grammar());
    }

    // ── schemaBuilder ─────────────────────────────────────────────

    public function test_schema_builder_returns_schema_builder_instance(): void
    {
        $registry = DriverRegistry::fromDriverAndGrammar(
            new SQLiteDriver(':memory:'),
            new SQLiteGrammar(),
        );

        $this->assertInstanceOf(SchemaBuilder::class, $registry->schemaBuilder());
    }

    public function test_schema_builder_returns_new_instance_each_call(): void
    {
        $registry = DriverRegistry::fromDriverAndGrammar(
            new SQLiteDriver(':memory:'),
            new SQLiteGrammar(),
        );

        $this->assertNotSame($registry->schemaBuilder(), $registry->schemaBuilder());
    }

    // ── Custom driver extension ───────────────────────────────────

    public function test_extend_driver_registers_custom_factory(): void
    {
        $customDriver = new SQLiteDriver(':memory:');

        DriverRegistry::extendDriver('mydb', static fn($cfg) => $customDriver);

        $registry = DriverRegistry::fromConfig([
            'driver'   => 'mydb',
            'database' => ':memory:',
            'paths'    => [],
        ]);

        $this->assertSame($customDriver, $registry->driver());
    }

    public function test_extend_grammar_registers_custom_factory(): void
    {
        $customGrammar = new SQLiteGrammar();

        DriverRegistry::extendGrammar('mydb2', static fn($cfg) => $customGrammar);

        $registry = DriverRegistry::fromConfig([
            'driver'   => 'mydb2',
            'database' => ':memory:',
            'paths'    => [],
        ]);

        $this->assertSame($customGrammar, $registry->grammar());
    }

    // ── supportedDrivers ─────────────────────────────────────────

    public function test_supported_drivers_includes_core_names(): void
    {
        $supported = DriverRegistry::supportedDrivers();

        foreach (['mysql', 'pgsql', 'sqlite', 'sqlsrv'] as $driver) {
            $this->assertContains($driver, $supported, "'{$driver}' should be in supported drivers list");
        }
    }
}
