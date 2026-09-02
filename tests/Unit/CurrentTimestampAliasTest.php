<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Driver\MySQL\MySQLGrammar;
use AlfaCode\LetMigrate\Driver\PostgreSQL\PostgreSQLGrammar;
use AlfaCode\LetMigrate\Driver\SQLServer\SQLServerGrammar;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * useCurrent() / useCurrentOnUpdate() — Laravel-parity aliases.
 *
 * These exist so a migration ported from Laravel compiles unchanged. Without
 * them the failure is a fatal "Call to undefined method" raised the moment the
 * migration runs — during a deploy, mid schema change.
 *
 * They must stay EXACT synonyms of the idiomatic spellings, which is what the
 * equivalence tests below pin down: if someone changes how a raw
 * CURRENT_TIMESTAMP default is carried, both spellings move together or the
 * suite fails.
 */
final class CurrentTimestampAliasTest extends TestCase
{
    public function test_use_current_sets_a_raw_current_timestamp_default(): void
    {
        $bp = new Blueprint('t');
        $col = $bp->timestamp('created_at')->useCurrent();

        $this->assertTrue($col->hasDefault());
        $this->assertSame('CURRENT_TIMESTAMP', $col->getDefault());
    }

    public function test_use_current_is_identical_to_the_idiomatic_spelling(): void
    {
        $alias = new Blueprint('t');
        $alias->timestamp('created_at')->useCurrent();

        $idiomatic = new Blueprint('t');
        $idiomatic->timestamp('created_at')->default('CURRENT_TIMESTAMP');

        foreach ($this->grammars() as $grammar) {
            $this->assertSame(
                $grammar->compileCreate($idiomatic),
                $grammar->compileCreate($alias),
                $grammar::class,
            );
        }
    }

    public function test_use_current_on_update_is_identical_to_the_idiomatic_spelling(): void
    {
        $alias = new Blueprint('t');
        $alias->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

        $idiomatic = new Blueprint('t');
        $idiomatic->dateTime('updated_at')->default('CURRENT_TIMESTAMP')->onUpdateCurrentTimestamp();

        foreach ($this->grammars() as $grammar) {
            $this->assertSame(
                $grammar->compileCreate($idiomatic),
                $grammar->compileCreate($alias),
                $grammar::class,
            );
        }
    }

    /**
     * Like Laravel's, useCurrentOnUpdate() sets ONLY the on-update behaviour.
     * If it also set a default, `->useCurrent()->useCurrentOnUpdate()` would be
     * doing the same work twice and a column wanting on-update WITHOUT a
     * default could not be expressed at all.
     */
    public function test_use_current_on_update_does_not_also_set_a_default(): void
    {
        $bp  = new Blueprint('t');
        $col = $bp->dateTime('updated_at')->useCurrentOnUpdate();

        $this->assertTrue($col->hasOnUpdateCurrentTimestamp());
        $this->assertFalse($col->hasDefault());
    }

    /** The default must reach the DDL unquoted on every driver. */
    public function test_the_default_is_emitted_as_an_expression_not_a_string(): void
    {
        foreach ($this->grammars() as $grammar) {
            $bp = new Blueprint('t');
            $bp->timestamp('created_at')->useCurrent();

            $sql = $grammar->compileCreate($bp);

            $this->assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $sql, $grammar::class);
            $this->assertStringNotContainsString("'CURRENT_TIMESTAMP'", $sql, $grammar::class);
        }
    }

    /** MySQL is the one that spells on-update inline. */
    public function test_mysql_emits_the_inline_on_update_keyword(): void
    {
        $bp = new Blueprint('t');
        $bp->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

        $this->assertStringContainsString(
            'ON UPDATE CURRENT_TIMESTAMP',
            (new MySQLGrammar())->compileCreate($bp),
        );
    }

    /** @return list<\AlfaCode\LetMigrate\Schema\GrammarInterface> */
    private function grammars(): array
    {
        return [
            new MySQLGrammar(),
            new PostgreSQLGrammar(),
            new SQLiteGrammar(),
            new SQLServerGrammar(),
        ];
    }
}
