<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Schema\ForeignKeyDefinition;
use AlfaCode\LetMigrate\Schema\IndexDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 — PostgreSQL-advanced option carriers.
 *
 * These options are honoured by PostgreSQLGrammar (see
 * PATCHES-phase3-postgres-advanced.md) and ignored by the other grammars.
 * The fluent carriers themselves are drop-ins and unit-tested here.
 */
final class PostgresAdvancedTest extends TestCase
{
    public function test_index_defaults_are_inert(): void
    {
        $idx = IndexDefinition::index(['email'], 'idx_email');

        $this->assertSame('', $idx->getUsing());
        $this->assertSame('', $idx->getWhere());
        $this->assertSame('', $idx->getExpression());
        $this->assertFalse($idx->isConcurrent());
        // unchanged core behaviour (NF-05 + fulltext intact)
        $this->assertSame(IndexDefinition::TYPE_INDEX, $idx->getType());
        $this->assertSame(['email'], $idx->getColumns());
    }

    public function test_index_fluent_options(): void
    {
        $idx = IndexDefinition::index(['data'], 'idx_data')
            ->using('gin')
            ->where('deleted_at IS NULL')
            ->concurrently();

        $this->assertSame('GIN', $idx->getUsing());
        $this->assertSame('deleted_at IS NULL', $idx->getWhere());
        $this->assertTrue($idx->isConcurrent());
    }

    public function test_index_expression(): void
    {
        $idx = IndexDefinition::index([], 'idx_lower_email')
            ->expression('lower(email)');

        $this->assertSame('lower(email)', $idx->getExpression());
    }

    public function test_concurrently_toggle(): void
    {
        $idx = IndexDefinition::index(['x'])->concurrently()->concurrently(false);
        $this->assertFalse($idx->isConcurrent());
    }

    public function test_fk_deferrable_defaults_off(): void
    {
        $fk = (new ForeignKeyDefinition('user_id'))->references('id')->on('users');

        $this->assertFalse($fk->isDeferrable());
        $this->assertFalse($fk->isInitiallyDeferred());
    }

    public function test_fk_deferrable(): void
    {
        $fk = (new ForeignKeyDefinition('user_id'))
            ->references('id')->on('users')
            ->deferrable();

        $this->assertTrue($fk->isDeferrable());
        $this->assertFalse($fk->isInitiallyDeferred());
    }

    public function test_fk_initially_deferred_implies_deferrable(): void
    {
        $fk = (new ForeignKeyDefinition('user_id'))
            ->references('id')->on('users')
            ->initiallyDeferred();

        $this->assertTrue($fk->isDeferrable(), 'INITIALLY DEFERRED implies DEFERRABLE');
        $this->assertTrue($fk->isInitiallyDeferred());
    }

    public function test_fk_advanced_options_do_not_break_existing_accessors(): void
    {
        $fk = (new ForeignKeyDefinition('post_id'))
            ->references('id')->on('posts')
            ->cascadeOnDelete()
            ->deferrable();

        // NF-04 aliases + core accessors still consistent
        $this->assertSame('posts', $fk->getReferencedTable());
        $this->assertSame('posts', $fk->getOn());
        $this->assertSame('CASCADE', $fk->getOnDelete());
        $this->assertTrue($fk->isDeferrable());
    }
}