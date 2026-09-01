<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Driver\MySQL\MySQLGrammar;
use AlfaCode\LetMigrate\Driver\PostgreSQL\PostgreSQLGrammar;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\Driver\SQLServer\SQLServerGrammar;
use AlfaCode\LetMigrate\Schema\Blueprint;
use AlfaCode\LetMigrate\Schema\ColumnDefinition;
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

    #[DataProvider('grammarProvider')]
    public function test_all_grammars_emit_primary_key_for_autoincrement_id(string $grammarClass): void
    {
        $bp = new Blueprint('records');
        $bp->id('record_id');

        /** @var \AlfaCode\LetMigrate\Schema\GrammarInterface $grammar */
        $grammar = new $grammarClass();
        $sql = $grammar->compileCreate($bp);

        // Every dialect must declare the auto-increment column as a PRIMARY KEY,
        // whether inline (SQLite) or as a standalone clause (MySQL/PG/SQL Server).
        $this->assertStringContainsString('PRIMARY KEY', $sql);
        $this->assertStringContainsString('record_id', $sql);

        // Exactly one PRIMARY KEY — never doubled.
        $this->assertSame(1, substr_count($sql, 'PRIMARY KEY'));
    }

    #[DataProvider('grammarProvider')]
    public function test_all_grammars_emit_check_constraints_inline(string $grammarClass): void
    {
        $bp = new Blueprint('projects');
        $bp->id();
        $bp->tinyInteger('status')->unsigned()->default(1);
        $bp->check('status between 1 and 3', 'chk_status');

        /** @var \AlfaCode\LetMigrate\Schema\GrammarInterface $grammar */
        $grammar = new $grammarClass();
        $sql = $grammar->compileCreate($bp);

        $this->assertStringContainsString('CHECK (status between 1 and 3)', $sql);
        $this->assertStringContainsString('chk_status', $sql);
    }

    public function test_mysql_emits_row_format_and_table_comment(): void
    {
        $bp = new Blueprint('projects');
        $bp->id();
        $bp->rowFormat('dynamic');                       // lower-case → normalised
        $bp->comment('Core multi-tenant project registry');

        $sql = (new MySQLGrammar())->compileCreate($bp);

        $this->assertStringContainsString('ROW_FORMAT=DYNAMIC', $sql);
        $this->assertStringContainsString("COMMENT='Core multi-tenant project registry'", $sql);
    }

    public function test_non_mysql_grammars_ignore_table_options(): void
    {
        $bp = new Blueprint('projects');
        $bp->id();
        $bp->rowFormat('DYNAMIC');
        $bp->comment('reg');

        foreach ([PostgreSQLGrammar::class, SQLiteGrammar::class, SQLServerGrammar::class] as $cls) {
            $sql = (new $cls())->compileCreate($bp);
            $this->assertStringNotContainsString('ROW_FORMAT', $sql);
            $this->assertStringNotContainsString('COMMENT=', $sql);
        }
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

    // ── Boolean defaults, per driver ──────────────────────────────

    /**
     * PostgreSQL rejects `BOOLEAN … DEFAULT 1` outright:
     *
     *     ERROR: column "flag" is of type boolean but default expression
     *            is of type integer
     *
     * MySQL and SQLite accept it, so a bool default compiled to 1/0 for every
     * driver looked correct everywhere it was tested and broke on the one
     * driver nobody ran locally.
     */
    public function test_postgres_emits_boolean_keywords_for_a_bool_default(): void
    {
        $grammar = new PostgreSQLGrammar();

        $this->assertSame('TRUE', $grammar->wrapDefault(true));
        $this->assertSame('FALSE', $grammar->wrapDefault(false));
    }

    public function test_postgres_boolean_column_default_reaches_the_ddl(): void
    {
        $bp = new Blueprint('settings');
        $bp->id();
        $bp->boolean('show_phone')->default(true);
        $bp->boolean('is_admin')->default(false);

        $sql = (new PostgreSQLGrammar())->compileCreate($bp);

        $this->assertStringContainsString('DEFAULT TRUE', $sql);
        $this->assertStringContainsString('DEFAULT FALSE', $sql);
        $this->assertStringNotContainsString('DEFAULT 1', $sql);
        $this->assertStringNotContainsString('DEFAULT 0', $sql);
    }

    /** SQL Server's BIT takes 1/0 and rejects TRUE/FALSE — it must NOT follow Postgres. */
    public function test_other_drivers_keep_integer_boolean_defaults(): void
    {
        foreach ([new MySQLGrammar(), new SQLiteGrammar(), new SQLServerGrammar()] as $grammar) {
            $this->assertSame('1', $grammar->wrapDefault(true), $grammar::class);
            $this->assertSame('0', $grammar->wrapDefault(false), $grammar::class);
        }
    }

    /** The override must not swallow every other default type on Postgres. */
    public function test_postgres_still_delegates_non_bool_defaults(): void
    {
        $grammar = new PostgreSQLGrammar();

        $this->assertSame('NULL', $grammar->wrapDefault(null));
        $this->assertSame('42', $grammar->wrapDefault(42));
        $this->assertSame("'public'", $grammar->wrapDefault('public'));
    }


    // ── Modify column: one clause must be ONE statement ───────────

    /**
     * compileAlter() treats every clause it collects as a single statement and
     * hands it straight to the driver, which prepares it. PostgreSQL's extended
     * query protocol refuses more than one command in a prepared statement:
     *
     *     SQLSTATE[42601]: cannot insert multiple commands into a
     *     prepared statement
     *
     * so a `;`-joined clause could never execute — the migration died before
     * applying any DDL at all.
     */
    public function test_postgres_modify_column_compiles_to_a_single_statement(): void
    {
        $bp = new Blueprint('users');
        $bp->modifyColumn('password_hash', static fn () => (new ColumnDefinition('password_hash', 'VARCHAR(255)'))->notNull());

        $statements = (new PostgreSQLGrammar())->compileAlter($bp);

        $this->assertCount(1, $statements);

        $sql = $statements[0];
        $this->assertStringNotContainsString(';', $sql);
        $this->assertSame(1, substr_count($sql, 'ALTER TABLE'), $sql);
        $this->assertStringContainsString('ALTER COLUMN "password_hash" TYPE VARCHAR(255)', $sql);
        $this->assertStringContainsString('ALTER COLUMN "password_hash" SET NOT NULL', $sql);
        $this->assertStringContainsString('ALTER COLUMN "password_hash" DROP DEFAULT', $sql);
    }

    public function test_postgres_modify_column_keeps_a_default_and_nullability(): void
    {
        $bp = new Blueprint('users');
        $bp->modifyColumn('status', static fn () => (new ColumnDefinition('status', 'VARCHAR(20)'))->default('active')->nullable());

        $sql = (new PostgreSQLGrammar())->compileAlter($bp)[0];

        $this->assertStringNotContainsString(';', $sql);
        $this->assertStringContainsString("SET DEFAULT 'active'", $sql);
        $this->assertStringContainsString('DROP NOT NULL', $sql);
    }


    private function makeSimpleBlueprint(string $table): Blueprint
    {
        $bp = new Blueprint($table);
        $bp->id();
        $bp->string('name');
        $bp->timestamps();

        return $bp;
    }
}
