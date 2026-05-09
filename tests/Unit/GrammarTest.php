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
        $bp      = $this->makeSimpleBlueprint('users');
        $sql     = $grammar->compileCreate($bp);

        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('`users`', $sql);
        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
        $this->assertStringContainsString('utf8mb4', $sql);
    }

    public function test_mysql_grammar_uses_backtick_quoting(): void
    {
        $grammar = new MySQLGrammar();
        $this->assertSame('`my_table`', $grammar->quoteIdentifier('my_table'));
    }

    public function test_mysql_grammar_compiles_drop_if_exists(): void
    {
        $grammar = new MySQLGrammar();
        $sql     = $grammar->compileDropIfExists('users');
        $this->assertSame('DROP TABLE IF EXISTS `users`', $sql);
    }

    public function test_mysql_grammar_creates_migration_tracking_table(): void
    {
        $grammar = new MySQLGrammar();
        $sql     = $grammar->compileCreateMigrationTable('let_migrations');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', $sql);
        $this->assertStringContainsString('`let_migrations`', $sql);
        $this->assertStringContainsString('migration', $sql);
        $this->assertStringContainsString('batch', $sql);
    }

    // ── PostgreSQL ────────────────────────────────────────────────

    public function test_postgresql_grammar_uses_double_quote_quoting(): void
    {
        $grammar = new PostgreSQLGrammar();
        $this->assertSame('"my_table"', $grammar->quoteIdentifier('my_table'));
    }

    public function test_postgresql_grammar_compiles_create_table_without_engine(): void
    {
        $grammar = new PostgreSQLGrammar();
        $bp      = $this->makeSimpleBlueprint('users');
        $sql     = $grammar->compileCreate($bp);

        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringNotContainsString('ENGINE=', $sql);
        $this->assertStringNotContainsString('CHARSET', $sql);
    }

    public function test_postgresql_grammar_maps_bigint_autoincrement_to_bigserial(): void
    {
        $grammar = new PostgreSQLGrammar();
        $bp      = new Blueprint('users');
        $bp->id();

        $sql = $grammar->compileCreate($bp);
        $this->assertStringContainsString('BIGSERIAL', $sql);
        $this->assertStringNotContainsString('AUTO_INCREMENT', $sql);
    }

    public function test_postgresql_grammar_maps_datetime_to_timestamp(): void
    {
        $grammar = new PostgreSQLGrammar();
        $bp      = new Blueprint('events');
        $bp->dateTime('happened_at');

        $sql = $grammar->compileCreate($bp);
        $this->assertStringContainsString('TIMESTAMP', $sql);
    }

    public function test_postgresql_fk_checks_use_session_replication_role(): void
    {
        $grammar = new PostgreSQLGrammar();
        $this->assertStringContainsString('replica', $grammar->compileForeignKeyChecksOff());
        $this->assertStringContainsString('DEFAULT', $grammar->compileForeignKeyChecksOn());
    }

    // ── SQLite ────────────────────────────────────────────────────

    public function test_sqlite_grammar_maps_bigint_to_integer(): void
    {
        $grammar = new SQLiteGrammar();
        $bp      = new Blueprint('items');
        $bp->bigInteger('quantity');

        $sql = $grammar->compileCreate($bp);
        $this->assertStringContainsString('INTEGER', $sql);
    }

    public function test_sqlite_grammar_autoincrement_uses_integer_pk_autoincrement(): void
    {
        $grammar = new SQLiteGrammar();
        $bp      = new Blueprint('things');
        $bp->id();

        $sql = $grammar->compileCreate($bp);
        $this->assertStringContainsString('INTEGER', $sql);
        $this->assertStringContainsString('AUTOINCREMENT', $sql);
    }

    public function test_sqlite_fk_checks_use_pragma(): void
    {
        $grammar = new SQLiteGrammar();
        $this->assertStringContainsString('PRAGMA foreign_keys = OFF', $grammar->compileForeignKeyChecksOff());
        $this->assertStringContainsString('PRAGMA foreign_keys = ON',  $grammar->compileForeignKeyChecksOn());
    }

    public function test_sqlite_grammar_has_no_engine_clause(): void
    {
        $grammar = new SQLiteGrammar();
        $bp      = $this->makeSimpleBlueprint('things');
        $sql     = $grammar->compileCreate($bp);

        $this->assertStringNotContainsString('ENGINE', $sql);
    }

    // ── SQL Server ────────────────────────────────────────────────

    public function test_sqlserver_grammar_uses_bracket_quoting(): void
    {
        $grammar = new SQLServerGrammar();
        $this->assertSame('[my_table]', $grammar->quoteIdentifier('my_table'));
    }

    public function test_sqlserver_grammar_escapes_brackets_in_identifier(): void
    {
        $grammar = new SQLServerGrammar();
        $this->assertSame('[weird]]name]', $grammar->quoteIdentifier('weird]name'));
    }

    public function test_sqlserver_grammar_maps_autoincrement_to_identity(): void
    {
        $grammar = new SQLServerGrammar();
        $bp      = new Blueprint('records');
        $bp->id();

        $sql = $grammar->compileCreate($bp);
        $this->assertStringContainsString('IDENTITY(1,1)', $sql);
        $this->assertStringNotContainsString('AUTO_INCREMENT', $sql);
    }

    public function test_sqlserver_grammar_maps_text_to_nvarchar_max(): void
    {
        $grammar = new SQLServerGrammar();
        $bp      = new Blueprint('posts');
        $bp->text('body');

        $sql = $grammar->compileCreate($bp);
        $this->assertStringContainsString('NVARCHAR(MAX)', $sql);
    }

    public function test_sqlserver_rename_uses_sp_rename(): void
    {
        $grammar = new SQLServerGrammar();
        $sql     = $grammar->compileRename('old_table', 'new_table');
        $this->assertStringContainsString('sp_rename', $sql);
    }

    // ── Cross-grammar: compile drop ────────────────────────────────

    #[DataProvider('grammarProvider')]
    public function test_all_grammars_compile_drop_table(string $grammarClass): void
    {
        /** @var \AlfaCode\LetMigrate\Schema\GrammarInterface $grammar */
        $grammar = new $grammarClass();
        $sql     = $grammar->compileDrop('some_table');

        $this->assertStringContainsString('DROP TABLE', $sql);
        $this->assertStringContainsString('some_table', $sql);
    }

    #[DataProvider('grammarProvider')]
    public function test_all_grammars_compile_migration_tracking_table(string $grammarClass): void
    {
        /** @var \AlfaCode\LetMigrate\Schema\GrammarInterface $grammar */
        $grammar = new $grammarClass();
        $sql     = $grammar->compileCreateMigrationTable('let_migrations');

        $this->assertStringContainsString('let_migrations', $sql);
        $this->assertStringContainsString('migration', $sql);
        $this->assertStringContainsString('batch', $sql);
    }

    public static function grammarProvider(): array
    {
        return [
            'mysql'      => [MySQLGrammar::class],
            'postgresql' => [PostgreSQLGrammar::class],
            'sqlite'     => [SQLiteGrammar::class],
            'sqlserver'  => [SQLServerGrammar::class],
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
