<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\MigrationResult;
use PHPUnit\Framework\TestCase;

final class MigrationResultTest extends TestCase
{
    // ── empty() factory ───────────────────────────────────────────

    public function test_empty_result_is_empty(): void
    {
        $result = MigrationResult::empty();

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->appliedCount());
        $this->assertSame(0, $result->rolledBackCount());
        $this->assertSame(0, $result->batch);
        $this->assertEmpty($result->applied);
        $this->assertEmpty($result->rolledBack);
    }

    public function test_empty_result_summary(): void
    {
        $this->assertSame('Nothing to migrate.', MigrationResult::empty()->summary());
    }

    // ── applied ───────────────────────────────────────────────────

    public function test_applied_count_matches_array_length(): void
    {
        $result = new MigrationResult(
            applied: ['mig_a', 'mig_b', 'mig_c'],
            rolledBack: [],
            batch: 1,
        );

        $this->assertSame(3, $result->appliedCount());
        $this->assertFalse($result->isEmpty());
    }

    public function test_applied_summary_includes_count_and_batch(): void
    {
        $result = new MigrationResult(
            applied: ['a', 'b'],
            rolledBack: [],
            batch: 3,
        );

        $summary = $result->summary();
        $this->assertStringContainsString('2 migration(s) applied', $summary);
        $this->assertStringContainsString('batch 3', $summary);
    }

    // ── rolledBack ────────────────────────────────────────────────

    public function test_rolled_back_count_matches_array_length(): void
    {
        $result = new MigrationResult(
            applied: [],
            rolledBack: ['mig_x', 'mig_y'],
            batch: 0,
        );

        $this->assertSame(2, $result->rolledBackCount());
        $this->assertFalse($result->isEmpty());
    }

    public function test_rollback_summary_includes_count(): void
    {
        $result = new MigrationResult(
            applied: [],
            rolledBack: ['c'],
            batch: 0,
        );

        $this->assertStringContainsString('1 migration(s) rolled back', $result->summary());
    }

    // ── mixed ─────────────────────────────────────────────────────

    public function test_summary_includes_both_applied_and_rolled_back(): void
    {
        $result = new MigrationResult(
            applied: ['a'],
            rolledBack: ['b'],
            batch: 2,
        );

        $summary = $result->summary();
        $this->assertStringContainsString('applied', $summary);
        $this->assertStringContainsString('rolled back', $summary);
    }

    // ── is_not_empty when either side has entries ──────────────────

    public function test_not_empty_when_applied(): void
    {
        $result = new MigrationResult(applied: ['a'], rolledBack: [], batch: 1);
        $this->assertFalse($result->isEmpty());
    }

    public function test_not_empty_when_rolled_back(): void
    {
        $result = new MigrationResult(applied: [], rolledBack: ['a'], batch: 0);
        $this->assertFalse($result->isEmpty());
    }

    // ── Immutability ──────────────────────────────────────────────

    public function test_result_is_readonly(): void
    {
        $result = MigrationResult::empty();
        $ref = new \ReflectionClass($result);

        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} must be readonly");
        }
    }
}
