<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Driver\MySQL\MySQLGrammar;
use AlfaCode\LetMigrate\Driver\PostgreSQL\PostgreSQLGrammar;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\Driver\SQLServer\SQLServerGrammar;
use AlfaCode\LetMigrate\Schema\Blueprint;
use AlfaCode\LetMigrate\Schema\IndexDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 — new column types, polymorphic helpers, and fullText index.
 *
 * Requires PATCHES-phase1-cols.md (P1C-1..P1C-4) + updated
 * src/Schema/IndexDefinition.php (TYPE_FULLTEXT).
 */
final class ColumnTypesAndMorphsTest extends TestCase
{
    // ── New scalar types ──────────────────────────────────────────

    public function test_new_scalar_column_types(): void
    {
        $bp = new Blueprint('t');
        $bp->ulid();
        $bp->ipAddress();
        $bp->macAddress();
        $bp->rememberToken();
        $bp->mediumInteger('hits');
        $bp->set('flags', ['a', 'b', 'c']);

        $byName = [];
        foreach ($bp->getColumns() as $c) {
            $byName[$c->getName()] = $c;
        }

        $this->assertSame('CHAR(26)',     $byName['id']->getType());
        $this->assertSame('VARCHAR(45)',  $byName['ip_address']->getType());
        $this->assertSame('VARCHAR(17)',  $byName['mac_address']->getType());
        $this->assertSame('VARCHAR(100)', $byName['remember_token']->getType());
        $this->assertTrue($byName['remember_token']->isNullable());
        $this->assertSame('MEDIUMINT',    $byName['hits']->getType());
        $this->assertSame("SET('a','b','c')", $byName['flags']->getType());
    }

    public function test_ulid_accepts_custom_name(): void
    {
        $bp = new Blueprint('t');
        $bp->ulid('public_id');

        $this->assertSame('public_id', $bp->getColumns()[0]->getName());
        $this->assertSame('CHAR(26)', $bp->getColumns()[0]->getType());
    }

    // ── SET portability across grammars ───────────────────────────

    public function test_set_is_portable_across_grammars(): void
    {
        $bp = new Blueprint('t');
        $bp->set('flags', ['x', 'y']);

        // MySQL keeps SET natively
        $this->assertStringContainsString(
            "SET('x','y')",
            (new MySQLGrammar())->compileCreate($bp),
        );

        // Non-MySQL must NOT emit the invalid SET(...) keyword
        foreach ([new PostgreSQLGrammar(), new SQLServerGrammar(), new SQLiteGrammar()] as $g) {
            $sql = $g->compileCreate($bp);
            $this->assertStringNotContainsString(
                "SET('x','y')",
                $sql,
                get_class($g) . ' must map SET(...) to a string type',
            );
        }
    }

    // ── Polymorphic helpers ───────────────────────────────────────

    public function test_morphs_creates_id_type_and_index(): void
    {
        $bp = new Blueprint('comments');
        $bp->morphs('commentable');

        $names = array_map(static fn($c) => $c->getName(), $bp->getColumns());
        $this->assertSame(['commentable_id', 'commentable_type'], $names);

        $idCol = $bp->getColumns()[0];
        $this->assertSame('BIGINT', $idCol->getType());
        $this->assertTrue($idCol->isUnsigned());

        $idx = $bp->getIndexes();
        $this->assertCount(1, $idx);
        $this->assertSame(['commentable_type', 'commentable_id'], $idx[0]->getColumns());
    }

    public function test_nullable_morphs_are_nullable(): void
    {
        $bp = new Blueprint('comments');
        $bp->nullableMorphs('commentable');

        foreach ($bp->getColumns() as $c) {
            $this->assertTrue($c->isNullable(), "{$c->getName()} must be nullable");
        }
    }

    public function test_uuid_morphs_use_char36(): void
    {
        $bp = new Blueprint('comments');
        $bp->uuidMorphs('commentable');

        $this->assertSame('CHAR(36)', $bp->getColumns()[0]->getType());
    }

    // ── fullText index ────────────────────────────────────────────

    public function test_fulltext_registers_fulltext_index(): void
    {
        $bp = new Blueprint('articles');
        $bp->text('body');
        $bp->fullText(['body']);

        $idx = $bp->getIndexes();
        $this->assertCount(1, $idx);
        $this->assertSame(IndexDefinition::TYPE_FULLTEXT, $idx[0]->getType());
        $this->assertSame(['body'], $idx[0]->getColumns());
    }

    public function test_fulltext_compiles_per_dialect(): void
    {
        $bp = new Blueprint('articles');
        $bp->text('body');
        $bp->fullText(['body'], 'ft_body');

        // MySQL → FULLTEXT KEY
        $this->assertStringContainsString(
            'FULLTEXT KEY',
            (new MySQLGrammar())->compileCreate($bp),
        );

        // PostgreSQL → GIN / to_tsvector standalone index statement
        $pg = new PostgreSQLGrammar();
        $stmts = $pg->compileIndexStatements($bp);
        $joined = implode("\n", $stmts);
        $this->assertStringContainsString('USING GIN', $joined);
        $this->assertStringContainsString('to_tsvector', $joined);

        // SQLite / SQL Server → must not crash; degrade to a plain index
        foreach ([new SQLiteGrammar(), new SQLServerGrammar()] as $g) {
            $sql = $g->compileCreate($bp);
            $this->assertIsString($sql);
            $this->assertStringNotContainsString(
                'FULLTEXT',
                $sql,
                get_class($g) . ' should degrade fulltext to a plain index',
            );
        }
    }
}