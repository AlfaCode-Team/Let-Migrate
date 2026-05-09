<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Schema\Blueprint;
use AlfaCode\LetMigrate\Schema\IndexDefinition;
use PHPUnit\Framework\TestCase;

final class BlueprintTest extends TestCase
{
    // ── Column types ──────────────────────────────────────────────

    public function test_id_adds_biginteger_auto_increment_column(): void
    {
        $bp = new Blueprint('users');
        $bp->id();

        $cols = $bp->getColumns();
        $this->assertCount(1, $cols);
        $this->assertSame('id', $cols[0]->getName());
        $this->assertStringContainsString('BIGINT', $cols[0]->getType());
        $this->assertTrue($cols[0]->isAutoIncrement());
        $this->assertTrue($cols[0]->isPrimary());
    }

    public function test_string_creates_varchar_column(): void
    {
        $bp = new Blueprint('users');
        $bp->string('email', 191);

        $col = $bp->getColumns()[0];
        $this->assertSame('email', $col->getName());
        $this->assertSame('VARCHAR(191)', $col->getType());
    }

    public function test_boolean_creates_tinyint_column(): void
    {
        $bp = new Blueprint('users');
        $bp->boolean('is_active')->default(true);

        $col = $bp->getColumns()[0];
        $this->assertSame('TINYINT(1)', $col->getType());
        $this->assertTrue($col->hasDefault());
        $this->assertTrue($col->getDefault());
    }

    public function test_timestamps_adds_two_columns(): void
    {
        $bp = new Blueprint('users');
        $bp->timestamps();

        $names = array_map(fn($c) => $c->getName(), $bp->getColumns());
        $this->assertContains('created_at', $names);
        $this->assertContains('updated_at', $names);
    }

    public function test_enum_creates_correct_type_string(): void
    {
        $bp = new Blueprint('editions');
        $bp->enum('status', ['draft', 'active', 'closed']);

        $col = $bp->getColumns()[0];
        $this->assertStringContainsString("ENUM('draft','active','closed')", $col->getType());
    }

    // ── Indexes ───────────────────────────────────────────────────

    public function test_index_method_adds_index_definition(): void
    {
        $bp = new Blueprint('users');
        $bp->index(['email'], 'idx_email');

        $idxs = $bp->getIndexes();
        $this->assertCount(1, $idxs);
        $this->assertSame(IndexDefinition::TYPE_INDEX, $idxs[0]->getType());
        $this->assertSame(['email'], $idxs[0]->getColumns());
        $this->assertSame('idx_email', $idxs[0]->getName());
    }

    public function test_unique_method_adds_unique_index(): void
    {
        $bp = new Blueprint('users');
        $bp->unique(['email']);

        $idxs = $bp->getIndexes();
        $this->assertSame(IndexDefinition::TYPE_UNIQUE, $idxs[0]->getType());
    }

    public function test_primary_method_adds_primary_key(): void
    {
        $bp = new Blueprint('order_items');
        $bp->primary(['order_id', 'product_id']);

        $idxs = $bp->getIndexes();
        $this->assertSame(IndexDefinition::TYPE_PRIMARY, $idxs[0]->getType());
        $this->assertSame(['order_id', 'product_id'], $idxs[0]->getColumns());
    }

    // ── Foreign keys ──────────────────────────────────────────────

    public function test_foreign_adds_foreign_key_definition(): void
    {
        $bp = new Blueprint('votes');
        $bp->foreign('edition_id')
            ->references('id')
            ->on('vote_editions')
            ->cascadeOnDelete();

        $fks = $bp->getForeignKeys();
        $this->assertCount(1, $fks);
        $this->assertSame('edition_id', $fks[0]->getColumn());
        $this->assertSame('id', $fks[0]->getReferencedColumn());
        $this->assertSame('vote_editions', $fks[0]->getReferencedTable());
        $this->assertSame('CASCADE', $fks[0]->getOnDelete());
    }

    // ── Drop helpers ──────────────────────────────────────────────

    public function test_drop_column_records_dropped_columns(): void
    {
        $bp = new Blueprint('users');
        $bp->dropColumn('legacy_field', 'old_flag');

        $this->assertSame(['legacy_field', 'old_flag'], $bp->getDroppedColumns());
    }

    // ── Table options ─────────────────────────────────────────────

    public function test_engine_and_charset_are_configurable(): void
    {
        $bp = new Blueprint('logs');
        $bp->engine('MyISAM')->charset('latin1')->collation('latin1_swedish_ci');

        $this->assertSame('MyISAM', $bp->getEngine());
        $this->assertSame('latin1', $bp->getCharset());
        $this->assertSame('latin1_swedish_ci', $bp->getCollation());
    }

    // ── Column fluent modifiers ───────────────────────────────────

    public function test_nullable_modifier_sets_nullable_flag(): void
    {
        $bp  = new Blueprint('users');
        $col = $bp->string('bio')->nullable();

        $this->assertTrue($col->isNullable());
    }

    public function test_unsigned_modifier_sets_unsigned_flag(): void
    {
        $bp  = new Blueprint('users');
        $col = $bp->bigInteger('points')->unsigned();

        $this->assertTrue($col->isUnsigned());
    }

    public function test_after_modifier_sets_position(): void
    {
        $bp  = new Blueprint('users');
        $col = $bp->string('middle_name')->nullable()->after('first_name');

        $this->assertSame('first_name', $col->getAfter());
    }
}
