<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\Seeder\SeederRepository;
use AlfaCode\LetMigrate\Seeder\SeederRunner;
use PHPUnit\Framework\TestCase;

/**
 * Discovering the same seeder directory TWICE in one process.
 *
 * `require` EXECUTES the file, so a seeder declaring a named class can only be
 * loaded once per process — a second load is a hard, uncatchable
 * "Cannot redeclare class" fatal that takes the whole run with it.
 *
 * Nothing hit that while SQLite was the only target: one database, one load.
 * It became reachable the moment one run began seeding once per DRIVER, which
 * is exactly what `hkm ground migrate` does across its four-engine matrix.
 */
final class SeederResolveTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/let_migrate_seeders_' . uniqid('', true);
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function test_a_named_class_seeder_survives_a_second_resolve(): void
    {
        $class = 'ResolveTwiceSeeder';

        file_put_contents($this->dir . "/{$class}.php", <<<PHP
        <?php
        use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
        use AlfaCode\LetMigrate\Seeder\SeederInterface;

        final class {$class} implements SeederInterface
        {
            public function run(DatabaseDriverInterface \$db): void {}
            public function getDependencies(): array { return []; }
        }
        PHP);

        $runner = $this->makeRunner();

        $first = $runner->resolve();
        $this->assertArrayHasKey($class, $first);

        // The load that used to be a fatal.
        $second = $runner->resolve();
        $this->assertArrayHasKey($class, $second);
        $this->assertInstanceOf($class, $second[$class]);
    }

    /**
     * `return new class {...}` declares no named class, so class_exists() is
     * false for it and it MUST still be required on every pass — otherwise the
     * skip that fixes the named case silently drops the anonymous one.
     */
    public function test_an_anonymous_class_seeder_is_still_loaded_every_time(): void
    {
        file_put_contents($this->dir . '/AnonymousSeeder.php', <<<'PHP'
        <?php
        use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
        use AlfaCode\LetMigrate\Seeder\SeederInterface;

        return new class implements SeederInterface {
            public function run(DatabaseDriverInterface $db): void {}
            public function getDependencies(): array { return []; }
        };
        PHP);

        $runner = $this->makeRunner();

        $this->assertArrayHasKey('AnonymousSeeder', $runner->resolve());
        $this->assertArrayHasKey('AnonymousSeeder', $runner->resolve());
    }

    private function makeRunner(): SeederRunner
    {
        $driver = $this->createStub(DatabaseDriverInterface::class);

        return new SeederRunner(
            driver:     $driver,
            repository: new SeederRepository($driver, new SQLiteGrammar()),
            paths:      [$this->dir],
        );
    }
}
