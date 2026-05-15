<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Exception\MigrationException;
use AlfaCode\LetMigrate\FilesystemMigrationResolver;
use PHPUnit\Framework\TestCase;

final class FilesystemMigrationResolverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/let_migrate_resolver_' . uniqid('', true);
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.php') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    // ── Resolve ───────────────────────────────────────────────────

    public function test_resolve_returns_empty_when_no_files(): void
    {
        $resolver = new FilesystemMigrationResolver([$this->dir]);

        $this->assertEmpty($resolver->resolve());
    }

    public function test_resolve_finds_timestamped_migration_files(): void
    {
        $this->write('2024_01_01_000001_create_alpha');
        $this->write('2024_01_01_000002_create_beta');

        $resolver = new FilesystemMigrationResolver([$this->dir]);
        $resolved = $resolver->resolve();

        $this->assertArrayHasKey('2024_01_01_000001_create_alpha', $resolved);
        $this->assertArrayHasKey('2024_01_01_000002_create_beta', $resolved);
    }

    public function test_resolve_returns_sorted_by_filename(): void
    {
        $this->write('2024_01_01_000003_create_gamma');
        $this->write('2024_01_01_000001_create_alpha');
        $this->write('2024_01_01_000002_create_beta');

        $resolver = new FilesystemMigrationResolver([$this->dir]);
        $keys = array_keys($resolver->resolve());

        $this->assertSame([
            '2024_01_01_000001_create_alpha',
            '2024_01_01_000002_create_beta',
            '2024_01_01_000003_create_gamma',
        ], $keys);
    }

    public function test_resolve_returns_migration_interface_instances(): void
    {
        $this->write('2024_01_01_000001_create_alpha');

        $resolver = new FilesystemMigrationResolver([$this->dir]);
        $resolved = $resolver->resolve();
        $migration = $resolved['2024_01_01_000001_create_alpha'];

        $this->assertInstanceOf(
            \AlfaCode\LetMigrate\Contract\MigrationInterface::class,
            $migration,
        );
    }

    // ── Path management ───────────────────────────────────────────

    public function test_add_path_accepts_valid_directory(): void
    {
        $resolver = new FilesystemMigrationResolver();
        $resolver->addPath($this->dir);

        $this->assertContains($this->dir, $resolver->paths());
    }

    public function test_add_path_throws_for_nonexistent_directory(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/does not exist/i');

        $resolver = new FilesystemMigrationResolver();
        $resolver->addPath('/nonexistent/path/that/does/not/exist');
    }

    public function test_same_path_added_twice_is_deduplicated(): void
    {
        $resolver = new FilesystemMigrationResolver();
        $resolver->addPath($this->dir);
        $resolver->addPath($this->dir);

        $this->assertCount(1, $resolver->paths());
    }

    // ── File filtering ────────────────────────────────────────────

    public function test_skips_non_php_files(): void
    {
        file_put_contents("{$this->dir}/2024_01_01_000001_readme.txt", 'ignore me');
        $this->write('2024_01_01_000001_create_alpha');

        $resolver = new FilesystemMigrationResolver([$this->dir]);
        $keys = array_keys($resolver->resolve());

        $this->assertNotContains('2024_01_01_000001_readme', $keys);
        $this->assertContains('2024_01_01_000001_create_alpha', $keys);
    }

    public function test_skips_files_not_matching_naming_pattern(): void
    {
        file_put_contents("{$this->dir}/helpers.php", '<?php function foo() {}');
        $this->write('2024_01_01_000001_create_alpha');

        $resolver = new FilesystemMigrationResolver([$this->dir]);
        $keys = array_keys($resolver->resolve());

        $this->assertNotContains('helpers', $keys);
    }

    // ── Multiple paths ────────────────────────────────────────────

    public function test_multiple_paths_are_merged(): void
    {
        $dir2 = sys_get_temp_dir() . '/let_migrate_resolver2_' . uniqid('', true);
        mkdir($dir2, 0o777, true);

        try {
            $this->write('2024_01_01_000001_create_alpha');
            file_put_contents("{$dir2}/2024_01_01_000002_create_beta.php", <<<'PHP'
                <?php
                use AlfaCode\LetMigrate\Contract\MigrationInterface;
                use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
                use AlfaCode\LetMigrate\Schema\Blueprint;
                return new class implements MigrationInterface {
                    public function up(SchemaBuilderInterface $schema): void {
                        $schema->create('beta', static function (Blueprint $t): void { $t->id(); });
                    }
                    public function down(SchemaBuilderInterface $schema): void { $schema->dropIfExists('beta'); }
                };
                PHP);

            $resolver = new FilesystemMigrationResolver([$this->dir, $dir2]);
            $resolved = $resolver->resolve();

            $this->assertArrayHasKey('2024_01_01_000001_create_alpha', $resolved);
            $this->assertArrayHasKey('2024_01_01_000002_create_beta', $resolved);
        } finally {
            foreach (glob($dir2 . '/*.php') ?: [] as $f) {
                unlink($f);
            }
            rmdir($dir2);
        }
    }

    public function test_duplicate_filename_across_paths_throws(): void
    {
        $dir2 = sys_get_temp_dir() . '/let_migrate_dup_' . uniqid('', true);
        mkdir($dir2, 0o777, true);

        try {
            $this->write('2024_01_01_000001_create_alpha');
            file_put_contents("{$dir2}/2024_01_01_000001_create_alpha.php", <<<'PHP'
                <?php
                use AlfaCode\LetMigrate\Contract\MigrationInterface;
                use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
                return new class implements MigrationInterface {
                    public function up(SchemaBuilderInterface $s): void {}
                    public function down(SchemaBuilderInterface $s): void {}
                };
                PHP);

            $this->expectException(MigrationException::class);
            $this->expectExceptionMessageMatches('/[Dd]uplicate/');

            $resolver = new FilesystemMigrationResolver([$this->dir, $dir2]);
            $resolver->resolve();
        } finally {
            foreach (glob($dir2 . '/*.php') ?: [] as $f) {
                unlink($f);
            }
            rmdir($dir2);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function write(string $filename, string $table = 'test_table'): void
    {
        $php = <<<PHP
            <?php
            use AlfaCode\LetMigrate\Contract\MigrationInterface;
            use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
            use AlfaCode\LetMigrate\Schema\Blueprint;
            return new class implements MigrationInterface {
                public function up(SchemaBuilderInterface \$schema): void {
                    \$schema->create('{$table}', static function (Blueprint \$t): void { \$t->id(); });
                }
                public function down(SchemaBuilderInterface \$schema): void {
                    \$schema->dropIfExists('{$table}');
                }
            };
            PHP;
        file_put_contents("{$this->dir}/{$filename}.php", $php);
    }
}
