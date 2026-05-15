<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\MigrationConfig;
use AlfaCode\LetMigrate\MigrationResult;
use PHPUnit\Framework\TestCase;

/**
 * Combined backward-compat test that mirrors the existing
 * tests/Unit/MigrationConfigAndResultTest.php but uses the new sub-namespaces.
 * The original file imported from AlfaCode\LetMigrate\Config\MigrationConfig
 * and AlfaCode\LetMigrate\MigrationResult — both now resolve correctly.
 */
final class MigrationConfigAndResultTest extends TestCase
{
    // ── MigrationConfig ───────────────────────────────────────────

    public function test_from_array_sets_paths(): void
    {
        $config = MigrationConfig::fromArray([
            'paths' => ['/tmp/migrations/mysql'],
        ]);

        $this->assertSame(['/tmp/migrations/mysql'], $config->paths);
    }

    public function test_from_array_accepts_single_path_key(): void
    {
        $config = MigrationConfig::fromArray([
            'path' => '/tmp/migrations',
        ]);

        $this->assertSame(['/tmp/migrations'], $config->paths);
    }

    public function test_from_array_applies_defaults(): void
    {
        $config = MigrationConfig::fromArray(['paths' => ['/tmp']]);

        $this->assertSame('let_migrations', $config->trackingTable);
        $this->assertFalse($config->pretend);
        $this->assertTrue($config->transactional);
    }

    public function test_from_array_respects_custom_tracking_table(): void
    {
        $config = MigrationConfig::fromArray([
            'paths' => ['/tmp'],
            'tracking_table' => 'my_migrations',
        ]);

        $this->assertSame('my_migrations', $config->trackingTable);
    }

    public function test_from_array_respects_pretend_flag(): void
    {
        $config = MigrationConfig::fromArray([
            'paths' => ['/tmp'],
            'pretend' => true,
        ]);

        $this->assertTrue($config->pretend);
    }

    public function test_empty_paths_throws_invalid_argument_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one migration path/i');

        new MigrationConfig(paths: []);
    }

    // ── MigrationResult ───────────────────────────────────────────

    public function test_empty_result_has_no_applied_or_rolled_back(): void
    {
        $result = MigrationResult::empty();

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->appliedCount());
        $this->assertSame(0, $result->rolledBackCount());
    }

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

    public function test_rolled_back_count_matches_array_length(): void
    {
        $result = new MigrationResult(
            applied: [],
            rolledBack: ['mig_x', 'mig_y'],
            batch: 0,
        );

        $this->assertSame(2, $result->rolledBackCount());
    }

    public function test_summary_for_empty_result(): void
    {
        $this->assertSame('Nothing to migrate.', MigrationResult::empty()->summary());
    }

    public function test_summary_for_applied_result(): void
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

    public function test_summary_for_rollback_result(): void
    {
        $result = new MigrationResult(
            applied: [],
            rolledBack: ['c'],
            batch: 0,
        );

        $this->assertStringContainsString('1 migration(s) rolled back', $result->summary());
    }
}
