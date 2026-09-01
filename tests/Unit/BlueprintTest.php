<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Schema\Blueprint;
use AlfaCode\LetMigrate\Schema\IndexDefinition;
use PHPUnit\Framework\TestCase;

final class BlueprintTest extends TestCase
{
    // ── Column types ──────────────────────────────────────────────

    public function test_id_adds_biginteger_auto_increment_primary_column(): void
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

    public function test_id_with_custom_name(): void
    {
        $bp = new Blueprint('votes');
        $bp->id('VoteID');

        $this->assertSame('VoteID', $bp->getColumns()[0]->getName());
    }

    public function test_string_creates_varchar_column(): void
    {
        $bp = new Blueprint('users');
        $bp->string('email', 191);

        $col = $bp->getColumns()[0];
        $this->assertSame('email', $col->getName());
        $this->assertSame('VARCHAR(191)', $col->getType());
    }

    public function test_string_defaults_to_255(): void
    {
        $bp = new Blueprint('users');
        $bp->string('name');

        $this->assertSame('VARCHAR(255)', $bp->getColumns()[0]->getType());
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

    public function test_integer_creates_int_column(): void
    {
        $bp = new Blueprint('items');
        $bp->integer('quantity');

        $this->assertSame('INT', $bp->getColumns()[0]->getType());
    }

    public function test_biginteger_creates_bigint_column(): void
    {
        $bp = new Blueprint('payments');
        $bp->bigInteger('amount');

        $this->assertSame('BIGINT', $bp->getColumns()[0]->getType());
    }

    public function test_smallinteger_creates_smallint_column(): void
    {
        $bp = new Blueprint('votes');
        $bp->smallInteger('VoteCount');

        $this->assertSame('SMALLINT', $bp->getColumns()[0]->getType());
    }

    public function test_decimal_creates_decimal_column_with_precision(): void
    {
        $bp = new Blueprint('orders');
        $bp->decimal('price', 10, 2);

        $this->assertSame('DECIMAL(10,2)', $bp->getColumns()[0]->getType());
    }

    public function test_text_creates_text_column(): void
    {
        $bp = new Blueprint('posts');
        $bp->text('body');

        $this->assertSame('TEXT', $bp->getColumns()[0]->getType());
    }

    public function test_datetime_creates_datetime_column(): void
    {
        $bp = new Blueprint('events');
        $bp->dateTime('happened_at');

        $this->assertSame('DATETIME', $bp->getColumns()[0]->getType());
    }

    public function test_json_creates_json_column(): void
    {
        $bp = new Blueprint('logs');
        $bp->json('payload');

        $this->assertSame('JSON', $bp->getColumns()[0]->getType());
    }

    public function test_timestamps_adds_created_at_and_updated_at(): void
    {
        $bp = new Blueprint('users');
        $bp->timestamps();

        $names = array_map(static fn($c) => $c->getName(), $bp->getColumns());
        $this->assertContains('created_at', $names);
        $this->assertContains('updated_at', $names);
    }

    public function test_timestamps_are_nullable_with_defaults(): void
    {
        $bp = new Blueprint('users');
        $bp->timestamps();

        foreach ($bp->getColumns() as $col) {
            $this->assertTrue($col->isNullable(), "{$col->getName()} must be nullable");
            $this->assertTrue($col->hasDefault(), "{$col->getName()} must have a default");
        }
    }

    public function test_soft_deletes_adds_deleted_at_nullable(): void
    {
        $bp = new Blueprint('users');
        $bp->softDeletes();

        $col = $bp->getColumns()[0];
        $this->assertSame('deleted_at', $col->getName());
        $this->assertTrue($col->isNullable());
    }

    public function test_enum_creates_correct_type_string(): void
    {
        $bp = new Blueprint('editions');
        $bp->enum('status', ['draft', 'active', 'closed']);

        $this->assertStringContainsString(
            "ENUM('draft','active','closed')",
            $bp->getColumns()[0]->getType(),
        );
    }

    public function test_uuid_creates_char_36_primary(): void
    {
        $bp = new Blueprint('tokens');
        $bp->uuid('id');

        $col = $bp->getColumns()[0];
        $this->assertSame('CHAR(36)', $col->getType());
        $this->assertTrue($col->isPrimary());
    }

    // ── Column modifiers ──────────────────────────────────────────

    public function test_nullable_sets_nullable_flag(): void
    {
        $bp = new Blueprint('users');
        $col = $bp->string('bio')->nullable();

        $this->assertTrue($col->isNullable());
    }

    public function test_not_null_clears_nullable_flag(): void
    {
        $bp = new Blueprint('users');
        $col = $bp->string('email')->nullable()->notNull();

        $this->assertFalse($col->isNullable());
    }

    public function test_unsigned_sets_unsigned_flag(): void
    {
        $bp = new Blueprint('users');
        $col = $bp->bigInteger('points')->unsigned();

        $this->assertTrue($col->isUnsigned());
    }

    public function test_default_sets_default_value(): void
    {
        $bp = new Blueprint('settings');
        $col = $bp->string('theme')->default('light');

        $this->assertTrue($col->hasDefault());
        $this->assertSame('light', $col->getDefault());
    }

    public function test_comment_sets_comment(): void
    {
        $bp = new Blueprint('votes');
        $col = $bp->string('ip')->comment('IPv4 or IPv6');

        $this->assertSame('IPv4 or IPv6', $col->getComment());
    }

    public function test_after_sets_position(): void
    {
        $bp = new Blueprint('users');
        $col = $bp->string('middle_name')->nullable()->after('first_name');

        $this->assertSame('first_name', $col->getAfter());
    }

    public function test_unique_sets_unique_flag_on_column(): void
    {
        $bp = new Blueprint('users');
        $col = $bp->string('email')->unique();

        $this->assertTrue($col->isUnique());
    }

    // ── Indexes ───────────────────────────────────────────────────

    public function test_index_adds_index_definition(): void
    {
        $bp = new Blueprint('users');
        $bp->index(['email'], 'idx_email');

        $idxs = $bp->getIndexes();
        $this->assertCount(1, $idxs);
        $this->assertSame(IndexDefinition::TYPE_INDEX, $idxs[0]->getType());
        $this->assertSame(['email'], $idxs[0]->getColumns());
        $this->assertSame('idx_email', $idxs[0]->getName());
    }

    public function test_unique_adds_unique_index(): void
    {
        $bp = new Blueprint('users');
        $bp->unique(['email']);

        $this->assertSame(IndexDefinition::TYPE_UNIQUE, $bp->getIndexes()[0]->getType());
    }

    public function test_primary_adds_primary_key(): void
    {
        $bp = new Blueprint('order_items');
        $bp->primary(['order_id', 'product_id']);

        $idx = $bp->getIndexes()[0];
        $this->assertSame(IndexDefinition::TYPE_PRIMARY, $idx->getType());
        $this->assertSame(['order_id', 'product_id'], $idx->getColumns());
    }

    public function test_multiple_indexes_are_accumulated(): void
    {
        $bp = new Blueprint('votes');
        $bp->index(['user_id', 'contestant_id', 'edition_id'], 'idx_free_vote_check');
        $bp->index(['user_id', 'edition_id'], 'idx_user_edition');

        $this->assertCount(2, $bp->getIndexes());
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

    public function test_foreign_with_custom_name(): void
    {
        $bp = new Blueprint('votes');
        $bp->foreign('edition_id')
            ->references('id')
            ->on('vote_editions')
            ->name('fk_vote_edition');

        $this->assertSame('fk_vote_edition', $bp->getForeignKeys()[0]->getConstraintName());
    }

    public function test_foreign_null_on_delete(): void
    {
        $bp = new Blueprint('votes');
        $fk = $bp->foreign('user_id')
            ->references('id')
            ->on('users')
            ->nullOnDelete();

        $this->assertSame('SET NULL', $fk->getOnDelete());
    }

    public function test_foreign_restrict_on_delete_is_default(): void
    {
        $bp = new Blueprint('votes');
        $fk = $bp->foreign('user_id')
            ->references('id')
            ->on('users');

        $this->assertSame('RESTRICT', $fk->getOnDelete());
        $this->assertSame('RESTRICT', $fk->getOnUpdate());
    }

    // ── Drop helpers ──────────────────────────────────────────────

    public function test_drop_column_records_dropped_columns(): void
    {
        $bp = new Blueprint('users');
        $bp->dropColumn('legacy_field', 'old_flag');

        $this->assertSame(['legacy_field', 'old_flag'], $bp->getDroppedColumns());
    }

    public function test_drop_index_records_dropped_index(): void
    {
        $bp = new Blueprint('users');
        $bp->dropIndex('idx_email');

        $this->assertContains('idx_email', $bp->getDroppedIndexes());
    }

    public function test_drop_foreign_records_dropped_fk(): void
    {
        $bp = new Blueprint('orders');
        $bp->dropForeign('fk_user');

        $this->assertContains('fk_user', $bp->getDroppedForeignKeys());
    }

    // ── Table options ─────────────────────────────────────────────

    public function test_engine_sets_engine(): void
    {
        $bp = new Blueprint('logs');
        $bp->engine('MyISAM');

        $this->assertSame('MyISAM', $bp->getEngine());
    }

    public function test_charset_sets_charset(): void
    {
        $bp = new Blueprint('logs');
        $bp->charset('latin1');

        $this->assertSame('latin1', $bp->getCharset());
    }

    public function test_collation_sets_collation(): void
    {
        $bp = new Blueprint('logs');
        $bp->collation('latin1_swedish_ci');

        $this->assertSame('latin1_swedish_ci', $bp->getCollation());
    }

    public function test_default_engine_is_innodb(): void
    {
        $bp = new Blueprint('users');

        $this->assertSame('InnoDB', $bp->getEngine());
    }

    public function test_default_charset_is_utf8mb4(): void
    {
        $bp = new Blueprint('users');

        $this->assertSame('utf8mb4', $bp->getCharset());
    }

    // ── Fluent chaining ───────────────────────────────────────────

    public function test_blueprint_methods_return_self_for_chaining(): void
    {
        $bp = new Blueprint('t');

        $this->assertSame($bp, $bp->engine('InnoDB'));
        $this->assertSame($bp, $bp->charset('utf8mb4'));
        $this->assertSame($bp, $bp->collation('utf8mb4_unicode_ci'));
        $this->assertSame($bp, $bp->index(['col'], 'idx'));
        $this->assertSame($bp, $bp->unique(['col']));
        $this->assertSame($bp, $bp->primary(['col']));
        $this->assertSame($bp, $bp->dropColumn('col'));
        $this->assertSame($bp, $bp->dropIndex('idx'));
        $this->assertSame($bp, $bp->dropForeign('fk'));
    }

    // ── Table name accessor ───────────────────────────────────────

    public function test_get_table_returns_table_name(): void
    {
        $bp = new Blueprint('vote_editions');

        $this->assertSame('vote_editions', $bp->getTable());
    }
    public function test_nf05_index_methods_do_not_fatal_and_set_type(): void
    {
        $bp = new Blueprint('t');
        $bp->primary(['a']);
        $bp->unique(['b'], 'uq_b');
        $bp->index(['c']);

        $idx = $bp->getIndexes();
        $this->assertCount(3, $idx);
        $this->assertSame(IndexDefinition::TYPE_PRIMARY, $idx[0]->getType());
        $this->assertSame(IndexDefinition::TYPE_UNIQUE, $idx[1]->getType());
        $this->assertSame(IndexDefinition::TYPE_INDEX, $idx[2]->getType());
        $this->assertSame('uq_b', $idx[1]->getName());
    }

    public function test_nf05_unique_index_compiles_to_unique_keyword(): void
    {
        $bp = new Blueprint('t');
        $bp->string('email');
        $bp->unique(['email']);

        $sql = (new \AlfaCode\LetMigrate\Driver\MySQL\MySQLGrammar())->compileCreate($bp);
        $this->assertStringContainsString('UNIQUE KEY', $sql);  // not a plain KEY
    }
}
