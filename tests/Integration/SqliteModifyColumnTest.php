<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Integration;

use AlfaCode\LetMigrate\Driver\SQLite\SQLiteDriver;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteSchemaInspector;
use AlfaCode\LetMigrate\Exception\MigrationException;
use AlfaCode\LetMigrate\Schema\ColumnDefinition;
use AlfaCode\LetMigrate\Schema\SchemaBuilder;
use PHPUnit\Framework\TestCase;

/**
 * modifyColumn() on SQLite, executed against a real database.
 *
 * SQLite cannot alter a column in place; the documented workaround is to
 * rebuild the table. That needs the COMPLETE definition, and an ALTER
 * blueprint holds only the delta — so the rebuild is assembled from what the
 * SchemaInspector reports plus the delta on top.
 *
 * These run real DDL against a real file, because the failure this replaces
 * was a compiler producing `CREATE TABLE "__tmp_users" ()`: syntactically
 * plausible, and refused only by an engine. A fake records SQL, it does not
 * execute it.
 */
final class SqliteModifyColumnTest extends TestCase
{
    private string $file = '';
    private SQLiteDriver $driver;
    private SchemaBuilder $schema;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/let_migrate_modify_' . uniqid('', true) . '.sqlite';

        $grammar      = new SQLiteGrammar();
        $this->driver = new SQLiteDriver($this->file);
        $this->schema = new SchemaBuilder(
            $this->driver,
            $grammar,
            new SQLiteSchemaInspector($this->driver, $grammar),
        );

        $this->schema->create('accounts', static function ($t) {
            $t->id();
            $t->string('email', 191);
            $t->string('status', 20)->default('active');
            $t->boolean('verified')->default(false);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['email']);
            $t->unique(['status']);
        });

        $this->driver->execute("INSERT INTO accounts (email, status) VALUES ('a@b.c', 'pending')");
        $this->driver->execute("INSERT INTO accounts (email, status) VALUES ('d@e.f', 'archived')");
    }

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function test_modifying_a_column_keeps_every_other_column_and_its_data(): void
    {
        $this->modifyStatus();

        $rows = $this->driver->fetchAll('SELECT * FROM accounts ORDER BY id');

        $this->assertCount(2, $rows);
        $this->assertSame('a@b.c', $rows[0]['email']);
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame('archived', $rows[1]['status']);
        $this->assertNotSame('', $rows[0]['created_at']);
        $this->assertArrayHasKey('verified', $rows[0]);
    }

    public function test_the_modification_is_actually_applied(): void
    {
        // status starts NOT NULL; the modification makes it nullable.
        $this->assertStringContainsString('"status" TEXT NOT NULL', $this->tableSql());

        $this->modifyStatus();

        $this->assertStringNotContainsString('"status" TEXT NOT NULL', $this->tableSql());
        $this->driver->execute("INSERT INTO accounts (email, status) VALUES ('g@h.i', NULL)");
        $this->assertCount(3, $this->driver->fetchAll('SELECT * FROM accounts'));
    }

    /** Dropping the table drops its indexes with it — they must be rebuilt. */
    public function test_existing_indexes_survive_the_rebuild(): void
    {
        $this->modifyStatus();

        $this->assertSame(['idx_email', 'uq_status'], $this->indexNames());
    }

    /**
     * PRAGMA reports a default as a raw SQL literal — `'active'` WITH quotes.
     * Carrying it through untouched would re-quote it on the way out, so the
     * literal grows a layer of quotes on every single rebuild.
     */
    public function test_defaults_are_not_re_quoted_on_rebuild(): void
    {
        $this->modifyStatus();
        $this->modifyStatus();   // twice — a re-quote compounds

        $sql = $this->tableSql();

        $this->assertStringContainsString("DEFAULT 'active'", $sql);
        $this->assertStringNotContainsString("'''", $sql);
        $this->assertStringNotContainsString("\\'", $sql);
    }

    /** A raw expression default must stay an expression, not become a string. */
    public function test_a_current_timestamp_default_survives_as_an_expression(): void
    {
        $this->modifyStatus();

        $this->assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $this->tableSql());
        $this->assertStringNotContainsString("'CURRENT_TIMESTAMP'", $this->tableSql());
    }

    public function test_a_column_added_in_the_same_alter_is_created(): void
    {
        $this->schema->table('accounts', static function ($t) {
            $t->modifyColumn('status', static fn () => (new ColumnDefinition('status', 'VARCHAR(60)'))->default('active')->nullable());
            $t->string('nickname', 40)->nullable();
        });

        $rows = $this->driver->fetchAll('SELECT * FROM accounts ORDER BY id');

        $this->assertArrayHasKey('nickname', $rows[0]);
        $this->assertNull($rows[0]['nickname']);
        $this->assertCount(2, $rows);
    }

    public function test_no_temporary_table_is_left_behind(): void
    {
        $this->modifyStatus();

        $this->assertNull(
            $this->driver->fetchOne("SELECT name FROM sqlite_master WHERE name = '__tmp_accounts'"),
        );
    }

    /**
     * Without an inspector the existing definition cannot be read, so the
     * rebuild has nothing to rebuild FROM. Say that, rather than compiling an
     * empty CREATE TABLE and letting the engine answer 'near ")": syntax error'.
     */
    public function test_it_refuses_clearly_when_no_inspector_is_available(): void
    {
        $schema = new SchemaBuilder($this->driver, new SQLiteGrammar());   // no inspector

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/requires rebuilding the table/');

        $schema->table('accounts', static function ($t) {
            $t->modifyColumn('status', static fn () => (new ColumnDefinition('status', 'VARCHAR(60)'))->nullable());
        });
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function modifyStatus(): void
    {
        $this->schema->table('accounts', static function ($t) {
            $t->modifyColumn(
                'status',
                static fn () => (new ColumnDefinition('status', 'VARCHAR(60)'))->default('active')->nullable(),
            );
        });
    }

    private function tableSql(): string
    {
        return (string) $this->driver->fetchOne(
            "SELECT sql FROM sqlite_master WHERE name = 'accounts'",
        )['sql'];
    }

    /** @return list<string> */
    private function indexNames(): array
    {
        $names = array_column(
            $this->driver->fetchAll(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'accounts'"
                . " AND name NOT LIKE 'sqlite_autoindex%' ORDER BY name",
            ),
            'name',
        );

        return array_values($names);
    }
}
