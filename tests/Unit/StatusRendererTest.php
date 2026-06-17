<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Commands\Support\StatusRenderer;
use AlfaCode\LetMigrate\Contract\MigrationServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 — richer migrate:status (StatusRenderer + enriched shape).
 */
final class StatusRendererTest extends TestCase
{
    private function service(array $status): MigrationServiceInterface
    {
        $s = $this->createStub(MigrationServiceInterface::class);
        $s->method('status')->willReturn($status);
        return $s;
    }

    public function test_to_data_counts_and_normalises(): void
    {
        $svc = $this->service([
            '2024_01_01_000001_create_users' => [
                'status' => 'applied', 'batch' => 1, 'applied_at' => '2024-01-01 12:00:00',
            ],
            '2024_02_01_000002_create_posts' => [
                'status' => 'pending', 'batch' => null, 'applied_at' => null,
            ],
        ]);

        $data = (new StatusRenderer($svc, 'default'))->toData();

        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['applied']);
        $this->assertSame(1, $data['pending']);

        $this->assertSame('applied', $data['rows'][0]['status']);
        $this->assertSame('1', $data['rows'][0]['batch']);
        $this->assertSame('2024-01-01 12:00:00', $data['rows'][0]['applied_at']);
        $this->assertSame('default', $data['rows'][0]['connection']);

        // pending row → em-dashes
        $this->assertSame('—', $data['rows'][1]['batch']);
        $this->assertSame('—', $data['rows'][1]['applied_at']);
    }

    public function test_table_lines_contain_headers_and_summary(): void
    {
        $svc = $this->service([
            '2024_01_01_000001_create_users' => [
                'status' => 'applied', 'batch' => 1, 'applied_at' => '2024-01-01 12:00:00',
            ],
        ]);

        $lines = (new StatusRenderer($svc, 'mysql'))->toTableLines();
        $text  = implode("\n", $lines);

        $this->assertStringContainsString('Migration', $text);
        $this->assertStringContainsString('Applied At', $text);
        $this->assertStringContainsString('Connection', $text);
        $this->assertStringContainsString('2024_01_01_000001_create_users', $text);
        $this->assertStringContainsString('1 total · 1 applied · 0 pending', $text);
        $this->assertStringContainsString('connection: mysql', $text);
    }

    public function test_empty_status(): void
    {
        $lines = (new StatusRenderer($this->service([]), 'default'))->toTableLines();

        $this->assertSame(['No migrations discovered.'], $lines);
    }

    public function test_json_output_is_valid_and_complete(): void
    {
        $svc = $this->service([
            '2024_01_01_000001_create_users' => [
                'status' => 'applied', 'batch' => 2, 'applied_at' => '2024-03-03 09:00:00',
            ],
        ]);

        $json = (new StatusRenderer($svc, 'pgsql'))->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['total']);
        $this->assertSame(1, $decoded['applied']);
        $this->assertSame('2024_01_01_000001_create_users', $decoded['rows'][0]['migration']);
        $this->assertSame('pgsql', $decoded['rows'][0]['connection']);
        $this->assertSame('2024-03-03 09:00:00', $decoded['rows'][0]['applied_at']);
    }

    public function test_missing_applied_at_key_falls_back_gracefully(): void
    {
        // Older shape (no applied_at key at all) must not error.
        $svc = $this->service([
            'm1' => ['status' => 'applied', 'batch' => 1],
        ]);

        $data = (new StatusRenderer($svc))->toData();
        $this->assertSame('—', $data['rows'][0]['applied_at']);
    }
}