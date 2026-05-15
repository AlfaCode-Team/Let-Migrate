<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Integration;

use AlfaCode\LetMigrate\Driver\SQLite\SQLiteDriver;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\Schema\Blueprint;
use AlfaCode\LetMigrate\Schema\SchemaBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Exercises SchemaBuilder against an in-memory SQLite database.
 * All tests in this file prove that Blueprint → Grammar → SQL → PDO
 * works end-to-end without needing MySQL, PostgreSQL, or SQL Server.
 */
final class SchemaBuilderSQLiteTest extends TestCase
{
    private SQLiteDriver  $driver;

    private SchemaBuilder $schema;

    protected function setUp(): void
    {
        $this->driver = new SQLiteDriver(':memory:');
        $this->schema = new SchemaBuilder($this->driver, new SQLiteGrammar());
    }

    // ── create ────────────────────────────────────────────────────

    public function test_create_table_creates_physical_table(): void
    {
        $this->schema->create('users', static function (Blueprint $t): void {
            $t->id();
            $t->string('email');
        });

        $this->assertTrue($this->driver->tableExists('users'));
    }

    public function test_create_table_defines_expected_columns(): void
    {
        $this->schema->create('products', static function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->decimal('price', 8, 2);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        $columns = $this->driver->listColumns('products');
        $this->assertContains('id', $columns);
        $this->assertContains('name', $columns);
        $this->assertContains('price', $columns);
        $this->assertContains('is_active', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }

    public function test_create_table_allows_inserting_rows(): void
    {
        $this->schema->create('tags', static function (Blueprint $t): void {
            $t->id();
            $t->string('label');
        });

        $id = $this->driver->insert('tags', ['label' => 'php']);
        $row = $this->driver->fetchOne('SELECT * FROM "tags" WHERE "id" = ?', [1]);

        $this->assertSame(1, $id);
        $this->assertSame('php', $row['label']);
    }

    // ── drop / dropIfExists ───────────────────────────────────────

    public function test_drop_table_removes_table(): void
    {
        $this->schema->create('tmp', static function (Blueprint $t): void {
            $t->id();
        });
        $this->schema->drop('tmp');

        $this->assertFalse($this->driver->tableExists('tmp'));
    }

    public function test_drop_if_exists_does_not_throw_when_table_absent(): void
    {
        $this->schema->dropIfExists('nonexistent_table');

        $this->assertTrue(true); // no exception = pass
    }

    // ── hasTable / hasColumn ──────────────────────────────────────

    public function test_has_table_returns_false_for_missing_table(): void
    {
        $this->assertFalse($this->schema->hasTable('ghost'));
    }

    public function test_has_table_returns_true_after_create(): void
    {
        $this->schema->create('items', static function (Blueprint $t): void {
            $t->id();
        });

        $this->assertTrue($this->schema->hasTable('items'));
    }

    public function test_has_column_returns_true_for_existing_column(): void
    {
        $this->schema->create('orders', static function (Blueprint $t): void {
            $t->id();
            $t->string('status');
        });

        $this->assertTrue($this->schema->hasColumn('orders', 'status'));
    }

    public function test_has_column_returns_false_for_missing_column(): void
    {
        $this->schema->create('events', static function (Blueprint $t): void {
            $t->id();
        });

        $this->assertFalse($this->schema->hasColumn('events', 'nonexistent_col'));
    }

    // ── rename ────────────────────────────────────────────────────

    public function test_rename_table_changes_table_name(): void
    {
        $this->schema->create('old_name', static function (Blueprint $t): void {
            $t->id();
        });
        $this->schema->rename('old_name', 'new_name');

        $this->assertFalse($this->driver->tableExists('old_name'));
        $this->assertTrue($this->driver->tableExists('new_name'));
    }

    // ── raw ───────────────────────────────────────────────────────

    public function test_raw_executes_arbitrary_sql(): void
    {
        $this->schema->create('counters', static function (Blueprint $t): void {
            $t->id();
            $t->integer('value')->default(0);
        });

        $this->driver->insert('counters', ['value' => 10]);
        $this->schema->raw('UPDATE "counters" SET "value" = 99 WHERE "id" = 1');

        $row = $this->driver->fetchOne('SELECT "value" FROM "counters" WHERE "id" = 1');
        $this->assertSame(99, (int) $row['value']);
    }

    // ── FK checks ─────────────────────────────────────────────────

    public function test_disable_and_enable_fk_checks_do_not_throw(): void
    {
        $this->schema->disableForeignKeyChecks();
        $this->schema->enableForeignKeyChecks();

        $this->assertTrue(true);
    }

    // ── nullable columns ─────────────────────────────────────────

    public function test_nullable_column_accepts_null_value(): void
    {
        $this->schema->create('profiles', static function (Blueprint $t): void {
            $t->id();
            $t->text('bio')->nullable();
        });

        $id = $this->driver->insert('profiles', ['bio' => null]);
        $row = $this->driver->fetchOne('SELECT "bio" FROM "profiles" WHERE "id" = ?', [$id]);

        $this->assertNull($row['bio']);
    }

    // ── default values ────────────────────────────────────────────

    public function test_default_value_is_applied_on_insert(): void
    {
        $this->schema->create('settings', static function (Blueprint $t): void {
            $t->id();
            $t->boolean('enabled')->default(true);
        });

        $this->driver->execute('INSERT INTO "settings" ("id") VALUES (NULL)');
        $row = $this->driver->fetchOne('SELECT "enabled" FROM "settings" LIMIT 1');

        $this->assertSame(1, (int) $row['enabled']);
    }

    // ── transaction rollback ──────────────────────────────────────

    public function test_rolled_back_transaction_reverts_table_creation(): void
    {
        $this->driver->beginTransaction();
        $this->schema->create('temp_table', static function (Blueprint $t): void {
            $t->id();
        });
        $this->driver->rollback();

        $this->assertFalse($this->driver->tableExists('temp_table'));
    }

    // ── getDriver ────────────────────────────────────────────────

    public function test_get_driver_returns_same_driver_instance(): void
    {
        $this->assertSame($this->driver, $this->schema->getDriver());
    }

    // ── listTables / listColumns ──────────────────────────────────

    public function test_list_tables_returns_created_tables(): void
    {
        $this->schema->create('foo', static function (Blueprint $t): void {
            $t->id();
        });
        $this->schema->create('bar', static function (Blueprint $t): void {
            $t->id();
        });

        $tables = $this->driver->listTables();
        $this->assertContains('foo', $tables);
        $this->assertContains('bar', $tables);
    }

    public function test_list_columns_returns_all_defined_columns(): void
    {
        $this->schema->create('invoices', static function (Blueprint $t): void {
            $t->id();
            $t->string('number');
            $t->decimal('total');
            $t->dateTime('issued_at');
        });

        $columns = $this->driver->listColumns('invoices');
        $this->assertContains('id', $columns);
        $this->assertContains('number', $columns);
        $this->assertContains('total', $columns);
        $this->assertContains('issued_at', $columns);
    }
}
