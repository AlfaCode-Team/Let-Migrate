<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Schema\ForeignKeyDefinition;
use AlfaCode\LetMigrate\Schema\TablePrefixer;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 — table-prefix support.
 */
final class TablePrefixerTest extends TestCase
{
    // ── TablePrefixer ─────────────────────────────────────────────

    public function test_empty_prefix_is_passthrough(): void
    {
        $p = new TablePrefixer('');
        $this->assertSame('users', $p->prefix('users'));
        $this->assertFalse($p->isEnabled());
    }

    public function test_applies_prefix(): void
    {
        $p = new TablePrefixer('app_');
        $this->assertSame('app_users', $p->prefix('users'));
        $this->assertTrue($p->isEnabled());
        $this->assertSame('app_', $p->getPrefix());
    }

    public function test_is_idempotent(): void
    {
        $p = new TablePrefixer('app_');
        $this->assertSame('app_users', $p->prefix($p->prefix('users')));
        $this->assertSame('app_users', $p->prefix('app_users'));
    }

    public function test_empty_table_unchanged(): void
    {
        $this->assertSame('', (new TablePrefixer('app_'))->prefix(''));
    }

    // ── ForeignKeyDefinition::applyTablePrefix ────────────────────

    public function test_fk_referenced_table_is_prefixed_once(): void
    {
        $fk = (new ForeignKeyDefinition('team_id'))
            ->references('id')
            ->on('teams');

        $fk->applyTablePrefix('app_');
        $this->assertSame('app_teams', $fk->getReferencedTable());

        // idempotent — second call must not double-prefix
        $fk->applyTablePrefix('app_');
        $this->assertSame('app_teams', $fk->getReferencedTable());
    }

    public function test_fk_empty_prefix_is_noop(): void
    {
        $fk = (new ForeignKeyDefinition('team_id'))->references('id')->on('teams');
        $fk->applyTablePrefix('');
        $this->assertSame('teams', $fk->getReferencedTable());
    }

    public function test_fk_unset_referenced_table_is_safe(): void
    {
        $fk = new ForeignKeyDefinition('team_id'); // no on() called
        $fk->applyTablePrefix('app_');
        $this->assertSame('', $fk->getReferencedTable());
    }

    // ── ForeignKeyDefinition aliases still consistent (NF-04) ─────

    public function test_fk_aliases_reflect_prefixed_table(): void
    {
        $fk = (new ForeignKeyDefinition('team_id'))->references('id')->on('teams');
        $fk->applyTablePrefix('app_');

        $this->assertSame('app_teams', $fk->getReferencedTable());
        $this->assertSame('app_teams', $fk->getOn()); // NF-04 alias
    }
}