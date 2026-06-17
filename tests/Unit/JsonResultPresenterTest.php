<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Support\JsonResultPresenter;
use AlfaCode\LetMigrate\MigrationResult;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 — --json for run / rollback / pending (stable CI schema).
 */
final class JsonResultPresenterTest extends TestCase
{
    private JsonResultPresenter $p;

    protected function setUp(): void
    {
        $this->p = new JsonResultPresenter();
    }

    public function test_applied_result_data(): void
    {
        $r = new MigrationResult(applied: ['m1', 'm2'], rolledBack: [], batch: 3);

        $d = $this->p->resultData($r);

        $this->assertSame(['m1', 'm2'], $d['applied']);
        $this->assertSame([], $d['rolled_back']);
        $this->assertSame(3, $d['batch']);
        $this->assertSame(2, $d['applied_count']);
        $this->assertSame(0, $d['rolled_back_count']);
        $this->assertFalse($d['empty']);
        $this->assertStringContainsString('applied', $d['summary']);
    }

    public function test_empty_result(): void
    {
        $d = $this->p->resultData(MigrationResult::empty());

        $this->assertTrue($d['empty']);
        $this->assertSame(0, $d['applied_count']);
        $this->assertSame('Nothing to migrate.', $d['summary']);
    }

    public function test_rolled_back_result(): void
    {
        $r = new MigrationResult(applied: [], rolledBack: ['x', 'y', 'z'], batch: 0);
        $d = $this->p->resultData($r);

        $this->assertSame(['x', 'y', 'z'], $d['rolled_back']);
        $this->assertSame(3, $d['rolled_back_count']);
        $this->assertFalse($d['empty']);
    }

    public function test_result_json_is_valid_and_complete(): void
    {
        $r = new MigrationResult(applied: ['a'], rolledBack: [], batch: 1);

        $json    = $this->p->resultJson($r);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame(['a'], $decoded['applied']);
        $this->assertSame(1, $decoded['batch']);
        $this->assertArrayHasKey('summary', $decoded);
        // stable schema: exactly these keys
        $this->assertSame(
            ['applied', 'rolled_back', 'batch', 'applied_count',
             'rolled_back_count', 'empty', 'summary'],
            array_keys($decoded),
        );
    }

    public function test_pending_data_and_json(): void
    {
        $pending = [
            '2024_01_01_a' => new \stdClass(),
            '2024_01_02_b' => new \stdClass(),
        ];

        $d = $this->p->pendingData($pending);
        $this->assertSame(['2024_01_01_a', '2024_01_02_b'], $d['pending']);
        $this->assertSame(2, $d['count']);

        $decoded = json_decode($this->p->pendingJson($pending), true);
        $this->assertSame(2, $decoded['count']);
        $this->assertSame(['pending', 'count'], array_keys($decoded));
    }

    public function test_empty_pending(): void
    {
        $d = $this->p->pendingData([]);
        $this->assertSame([], $d['pending']);
        $this->assertSame(0, $d['count']);
    }
}