<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\MigrationConfig;
use PHPUnit\Framework\TestCase;

final class MigrationConfigTest extends TestCase
{
    // ── fromArray — paths ─────────────────────────────────────────

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

    public function test_from_array_paths_takes_precedence_over_path(): void
    {
        $config = MigrationConfig::fromArray([
            'paths' => ['/tmp/a', '/tmp/b'],
            'path' => '/tmp/ignored',
        ]);

        $this->assertSame(['/tmp/a', '/tmp/b'], $config->paths);
    }

    // ── fromArray — defaults ──────────────────────────────────────

    public function test_from_array_applies_default_tracking_table(): void
    {
        $config = MigrationConfig::fromArray(['paths' => ['/tmp']]);

        $this->assertSame('let_migrations', $config->trackingTable);
    }

    public function test_from_array_applies_default_pretend_false(): void
    {
        $config = MigrationConfig::fromArray(['paths' => ['/tmp']]);

        $this->assertFalse($config->pretend);
    }

    public function test_from_array_applies_default_transactional_true(): void
    {
        $config = MigrationConfig::fromArray(['paths' => ['/tmp']]);

        $this->assertTrue($config->transactional);
    }

    // ── fromArray — overrides ────────────────────────────────────

    public function test_from_array_respects_custom_tracking_table(): void
    {
        $config = MigrationConfig::fromArray([
            'paths' => ['/tmp'],
            'tracking_table' => 'my_migrations',
        ]);

        $this->assertSame('my_migrations', $config->trackingTable);
    }

    public function test_from_array_respects_pretend_true(): void
    {
        $config = MigrationConfig::fromArray([
            'paths' => ['/tmp'],
            'pretend' => true,
        ]);

        $this->assertTrue($config->pretend);
    }

    public function test_from_array_respects_transactional_false(): void
    {
        $config = MigrationConfig::fromArray([
            'paths' => ['/tmp'],
            'transactional' => false,
        ]);

        $this->assertFalse($config->transactional);
    }

    // ── Constructor validation ────────────────────────────────────

    public function test_empty_paths_throws_invalid_argument_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one migration path/i');

        new MigrationConfig(paths: []);
    }

    public function test_from_array_with_no_paths_throws_invalid_argument_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MigrationConfig::fromArray([]);
    }

    // ── Immutability ──────────────────────────────────────────────

    public function test_config_is_readonly(): void
    {
        $config = MigrationConfig::fromArray(['paths' => ['/tmp']]);

        $ref = new \ReflectionClass($config);
        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} must be readonly");
        }
    }
}
