<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Driver\MySQL\MySQLGrammar;
use AlfaCode\LetMigrate\Driver\PostgreSQL\PostgreSQLGrammar;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\Driver\SQLServer\SQLServerGrammar;
use AlfaCode\LetMigrate\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrammarTest extends TestCase
{
    // ── MySQL ─────────────────────────────────────────────────────

    public function test_mysql_grammar_compiles_create_table(): void
    {
        $grammar = new MySQLGrammar();
        $bp = $this->makeSimpleBlueprint('users');
        $sql = $grammar->compileCreate($bp);

        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('`users`', $sql);
        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
        $this->assertStringContainsString('utf8mb4', $sql);
    }

    public function test_mysql_grammar_uses_backtick_quoting(): void
    {
        $this->assertSame('`my_table`', (new MySQLGrammar())->quoteIdentifier('my_table'));
    }

    public function test_mysql_grammar_compiles_drop_if_exists(): void
    {
        $this->assertSame(
            'DROP TABLE IF EXISTS `users`',
            (new MySQLGrammar())->compileDropIfExists('users'),
        );
    }

    public function test_mysql_grammar_creates_migration_tracking_table(): void
    {
        $sql = (new MySQLGrammar())->compileCreateMigrationTable('let_migrations');

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', $sql);
        $this->assertStringContainsString('`let_migrations`', $sql);
        $this->assertStringContainsString('migration', $sql);
        $this->assertStringContainsString('batch', $sql);
    }

    public function test_mysql_fk_checks_use_set_foreign_key_checks(): void
    {
        $grammar = new MySQLGrammar();

        $this->assertStringContainsString('FOREIGN_KEY_CHECKS', $grammar->compileForeignKeyChecksOff());
        $this->assertStringContainsString('FOREIGN_KEY_CHECKS', $grammar->compileForeignKeyChecksOn());
    }

    // ── PostgreSQL ────────────────────────────────────────────────

    public function test_postgresql_grammar_uses_double_quote_quoting(): void
    {
        $this->assertSame('"my_table"', (new PostgreSQLGrammar())->quoteIdentifier('my_table'));
    }

    public function test_postgresql_grammar_compiles_create_without_engine(): void
    {
        $sql = (new PostgreSQLGrammar())->compileCreate($this->makeSimpleBlueprint('users'));

        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringNotContainsString('ENGINE=', $sql);
        $this->assertStringNotContainsString('CHARSET', $sql);
    }

    public function test_postgresql_grammar_maps_bigint_autoincrement_to_bigserial(): void
    {
        $bp = new Blueprint('users');
        $bp->id();

        $sql = (new PostgreSQLGrammar())->compileCreate($bp);

        $this->assertStringContainsString('BIGSERIAL', $sql);
        $this->assertStringNotContainsString('AUTO_INCREMENT', $sql);
    }

    public function test_postgresql_grammar_maps_datetime_to_timestamp(): void
    {
        $bp = new Blueprint('events');
        $bp->dateTime('happened_at');

        $sql = (new PostgreSQLGrammar())->compileCreate($bp);

        $this->assertStringContainsString('TIMESTAMP', $sql);
    }

    public function test_postgresql_fk_checks_use_session_replication_role(): void
    {
        $grammar = new PostgreSQLGrammar();

        $this->assertStringContainsString('replica', $grammar->compileForeignKeyChecksOff());
        $this->assertStringContainsString('DEFAULT', $grammar->compileForeignKeyChecksOn());
    }

    public function test_postgresql_creates_migration_table_without_engine(): void
    {
        $sql = (new PostgreSQLGrammar())->compileCreateMigrationTable('let_migrations');

        $this->assertStringContainsString('"let_migrations"', $sql);
        $this->assertStringNotContainsString('ENGINE=', $sql);
        $this->assertStringContainsString('SERIAL', $sql);
    }

    // ── SQLite ────────────────────────────────────────────────────

    public function test_sqlite_grammar_maps_bigint_to_integer(): void
    {
        $bp = new Blueprint('items');
        $bp->bigInteger('quantity');

        $sql = (new SQLiteGrammar())->compileCreate($bp);

        $this->assertStringContainsString('INTEGER', $sql);
    }

    public function test_sqlite_autoincrement_uses_integer_pk_autoincrement(): void
    {
        $bp = new Blueprint('things');
        $bp->id();

        $sql = (new SQLiteGrammar())->compileCreate($bp);

        $this->assertStringContainsString('INTEGER', $sql);
        $this->assertStringContainsString('AUTOINCREMENT', $sql);
    }

    public function test_sqlite_fk_checks_use_pragma(): void
    {
        $grammar = new SQLiteGrammar();

        $this->assertSame('PRAGMA foreign_keys = OFF', $grammar->compileForeignKeyChecksOff());
        $this->assertSame('PRAGMA foreign_keys = ON', $grammar->compileForeignKeyChecksOn());
    }

    public function test_sqlite_grammar_has_no_engine_clause(): void
    {
        $sql = (new SQLiteGrammar())->compileCreate($this->makeSimpleBlueprint('things'));

        $this->assertStringNotContainsString('ENGINE', $sql);
    }

    public function test_sqlite_creates_migration_tracking_table_with_autoincrement(): void
    {
        $sql = (new SQLiteGrammar())->compileCreateMigrationTable('let_migrations');

        $this->assertStringContainsString('"let_migrations"', $sql);
        $this->assertStringContainsString('AUTOINCREMENT', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', $sql);
    }

    // ── SQL Server ────────────────────────────────────────────────

    public function test_sqlserver_grammar_uses_bracket_quoting(): void
    {
        $this->assertSame('[my_table]', (new SQLServerGrammar())->quoteIdentifier('my_table'));
    }

    public function test_sqlserver_grammar_escapes_brackets_in_identifier(): void
    {
        $this->assertSame('[weird]]name]', (new SQLServerGrammar())->quoteIdentifier('weird]name'));
    }

    public function test_sqlserver_grammar_maps_autoincrement_to_identity(): void
    {
        $bp = new Blueprint('records');
        $bp->id();

        $sql = (new SQLServerGrammar())->compileCreate($bp);

        $this->assertStringContainsString('IDENTITY(1,1)', $sql);
        $this->assertStringNotContainsString('AUTO_INCREMENT', $sql);
    }

    public function test_sqlserver_grammar_maps_text_to_nvarchar_max(): void
    {
        $bp = new Blueprint('posts');
        $bp->text('body');

        $sql = (new SQLServerGrammar())->compileCreate($bp);

        $this->assertStringContainsString('NVARCHAR(MAX)', $sql);
    }

    public function test_sqlserver_rename_uses_sp_rename(): void
    {
        $this->assertStringContainsString(
            'sp_rename',
            (new SQLServerGrammar())->compileRename('old_table', 'new_table'),
        );
    }

    public function test_sqlserver_creates_migration_table_with_identity(): void
    {
        $sql = (new SQLServerGrammar())->compileCreateMigrationTable('let_migrations');

        $this->assertStringContainsString('[let_migrations]', $sql);
        $this->assertStringContainsString('IDENTITY(1,1)', $sql);
    }

    // ── Cross-grammar ─────────────────────────────────────────────

    #[DataProvider('grammarProvider')]
    public function test_all_grammars_compile_drop_table(string $grammarClass): void
    {
        /** @var \AlfaCode\LetMigrate\Schema\GrammarInterface $grammar */
        $grammar = new $grammarClass();
        $sql = $grammar->compileDrop('some_table');

        $this->assertStringContainsString('DROP TABLE', $sql);
        $this->assertStringContainsString('some_table', $sql);
    }

    #[DataProvider('grammarProvider')]
    public function test_all_grammars_compile_migration_tracking_table(string $grammarClass): void
    {
        /** @var \AlfaCode\LetMigrate\Schema\GrammarInterface $grammar */
        $grammar = new $grammarClass();
        $sql = $grammar->compileCreateMigrationTable('let_migrations');

        $this->assertStringContainsString('let_migrations', $sql);
        $this->assertStringContainsString('migration', $sql);
        $this->assertStringContainsString('batch', $sql);
    }

    #[DataProvider('grammarProvider')]
    public function test_all_grammars_have_consistent_fk_check_methods(string $grammarClass): void
    {
        /** @var \AlfaCode\LetMigrate\Schema\GrammarInterface $grammar */
        $grammar = new $grammarClass();

        $this->assertNotEmpty($grammar->compileForeignKeyChecksOff());
        $this->assertNotEmpty($grammar->compileForeignKeyChecksOn());
    }

    #[DataProvider('grammarProvider')]
    public function test_all_grammars_quote_identifier(string $grammarClass): void
    {
        /** @var \AlfaCode\LetMigrate\Schema\GrammarInterface $grammar */
        $grammar = new $grammarClass();
        $quoted = $grammar->quoteIdentifier('my_column');

        $this->assertStringContainsString('my_column', $quoted);
        $this->assertNotSame('my_column', $quoted); // must be wrapped
    }

    /** @return array<string, array{string}> */
    public static function grammarProvider(): array
    {
        return [
            'mysql' => [MySQLGrammar::class],
            'postgresql' => [PostgreSQLGrammar::class],
            'sqlite' => [SQLiteGrammar::class],
            'sqlserver' => [SQLServerGrammar::class],
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function makeSimpleBlueprint(string $table): Blueprint
    {
        $bp = new Blueprint($table);
        $bp->id();
        $bp->string('name');
        $bp->timestamps();

        return $bp;
    }
}
