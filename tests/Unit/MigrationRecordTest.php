<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\MigrationRecord;
use PHPUnit\Framework\TestCase;

final class MigrationRecordTest extends TestCase
{
    // ── fromRow — lowercase keys (MySQL / PostgreSQL / SQLite) ────

    public function test_from_row_hydrates_lowercase_keys(): void
    {
        $record = MigrationRecord::fromRow([
            'id' => '7',
            'migration' => '2024_01_01_create_users',
            'batch' => '2',
            'applied_at' => '2024-01-01 12:00:00',
        ]);

        $this->assertSame(7, $record->id);
        $this->assertSame('2024_01_01_create_users', $record->migration);
        $this->assertSame(2, $record->batch);
        $this->assertSame('2024-01-01 12:00:00', $record->appliedAt);
    }

    // ── fromRow — uppercase keys (SQL Server) ─────────────────────

    public function test_from_row_hydrates_uppercase_keys(): void
    {
        $record = MigrationRecord::fromRow([
            'ID' => '3',
            'Migration' => '2024_01_01_create_posts',
            'Batch' => '1',
            'AppliedAt' => '2024-02-15 09:30:00',
        ]);

        $this->assertSame(3, $record->id);
        $this->assertSame('2024_01_01_create_posts', $record->migration);
        $this->assertSame(1, $record->batch);
        $this->assertSame('2024-02-15 09:30:00', $record->appliedAt);
    }

    // ── fromRow — missing optional fields fall back to zero/empty ──

    public function test_from_row_defaults_missing_id_to_zero(): void
    {
        $record = MigrationRecord::fromRow([
            'migration' => 'some_migration',
            'batch' => '1',
            'applied_at' => '2024-01-01 00:00:00',
        ]);

        $this->assertSame(0, $record->id);
    }

    public function test_from_row_defaults_missing_batch_to_one(): void
    {
        $record = MigrationRecord::fromRow([
            'migration' => 'some_migration',
            'applied_at' => '2024-01-01 00:00:00',
        ]);

        $this->assertSame(1, $record->batch);
    }

    // ── Constructor ───────────────────────────────────────────────

    public function test_constructor_sets_all_properties(): void
    {
        $record = new MigrationRecord(
            id: 42,
            migration: '2024_migration',
            batch: 3,
            appliedAt: '2024-06-01 10:00:00',
        );

        $this->assertSame(42, $record->id);
        $this->assertSame('2024_migration', $record->migration);
        $this->assertSame(3, $record->batch);
        $this->assertSame('2024-06-01 10:00:00', $record->appliedAt);
    }

    // ── Immutability ──────────────────────────────────────────────

    public function test_record_is_readonly(): void
    {
        $record = new MigrationRecord(1, 'mig', 1, '2024-01-01 00:00:00');
        $ref = new \ReflectionClass($record);

        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} must be readonly");
        }
    }
}
