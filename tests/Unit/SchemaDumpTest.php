<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Exception\LetMigrateException;
use AlfaCode\LetMigrate\SchemaDump;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 — schema-dump / rollup workflow (last Doctrine §8.2 gap-closer).
 */
final class SchemaDumpTest extends TestCase
{
    private string $dir;
    private string $sql;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lm_dump_' . uniqid('', true);
        mkdir($this->dir);
        $this->sql = $this->dir . '/schema.sql';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_exists_false_before_write(): void
    {
        $this->assertFalse((new SchemaDump($this->sql))->exists());
    }

    public function test_write_creates_sql_and_manifest(): void
    {
        $dump = new SchemaDump($this->sql);
        $dump->write(
            ['CREATE TABLE users (id INT)', 'CREATE TABLE posts (id INT)'],
            ['2024_01_01_create_users', '2024_01_02_create_posts'],
        );

        $this->assertTrue($dump->exists());
        $this->assertFileExists($this->sql);
        $this->assertFileExists($this->dir . '/schema.manifest.json');

        $sqlContent = file_get_contents($this->sql);
        $this->assertStringContainsString('CREATE TABLE users (id INT);', $sqlContent);
        $this->assertStringContainsString('let-migrate schema dump', $sqlContent);
    }

    public function test_covered_migrations_round_trips(): void
    {
        $dump = new SchemaDump($this->sql);
        $covered = ['2024_01_01_a', '2024_01_02_b', '2024_01_03_c'];
        $dump->write(['CREATE TABLE t (id INT)'], $covered);

        $this->assertSame($covered, $dump->coveredMigrations());
    }

    public function test_covered_migrations_empty_when_no_manifest(): void
    {
        $this->assertSame([], (new SchemaDump($this->sql))->coveredMigrations());
    }

    public function test_load_executes_each_statement(): void
    {
        $dump = new SchemaDump($this->sql);
        $dump->write(
            ['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)', 'CREATE INDEX i ON a (id)'],
            ['m1'],
        );

        $executed = [];
        $driver = new class($executed) implements DatabaseDriverInterface {
            public function __construct(public array &$ex) {}
            public function getName(): string { return 'fake'; }
            public function execute(string $s, array $b = []): int { $this->ex[] = $s; return 0; }
            public function fetchOne(string $s, array $b = []): array|null { return null; }
            public function fetchAll(string $s, array $b = []): array { return []; }
            public function insert(string $t, array $d): int { return 1; }
            public function update(string $t, array $d, array $w = []): int { return 1; }
            public function delete(string $t, array $w): int { return 1; }
            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollback(): void {}
            public function inTransaction(): bool { return false; }
            public function tableExists(string $t): bool { return false; }
            public function columnExists(string $t, string $c): bool { return false; }
            public function listColumns(string $t): array { return []; }
            public function listTables(): array { return []; }
            public function quoteIdentifier(string $i): string { return $i; }
        
    /**
     * @inheritDoc
     */
    public function getPlatformName(): string {
        return 'fake';
    }
};

        $count = $dump->load($driver);

        $this->assertSame(3, $count);
        $this->assertCount(3, $executed);
        $this->assertStringContainsString('CREATE TABLE a', $executed[0]);
        $this->assertStringContainsString('CREATE INDEX i', $executed[2]);
        // header comment lines must not be executed as statements
        foreach ($executed as $stmt) {
            $this->assertStringNotContainsString('let-migrate schema dump', $stmt);
        }
    }

    public function test_load_throws_when_missing(): void
    {
        $this->expectException(LetMigrateException::class);
        (new SchemaDump($this->dir . '/nope.sql'))->load(
            $this->createStub(DatabaseDriverInterface::class),
        );
    }
}