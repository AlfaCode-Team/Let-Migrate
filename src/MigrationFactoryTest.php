<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Exception\MigrationException;
use AlfaCode\LetMigrate\ContainerMigrationFactory;
use AlfaCode\LetMigrate\DefaultMigrationFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Phase 3 — service/DI injection into migrations.
 *
 * Skipped automatically if psr/container is not installed in the test
 * environment (the container factory is opt-in).
 */
final class MigrationFactoryTest extends TestCase
{
    public function test_default_factory_constructs_zero_arg_migration(): void
    {
        $class = ZeroArgMigration::class;

        $m = (new DefaultMigrationFactory())->make($class);

        $this->assertInstanceOf(MigrationInterface::class, $m);
    }

    public function test_default_factory_rejects_missing_class(): void
    {
        $this->expectException(MigrationException::class);
        (new DefaultMigrationFactory())->make('No\\Such\\Migration\\Class');
    }

    public function test_default_factory_rejects_non_migration(): void
    {
        $this->expectException(MigrationException::class);
        (new DefaultMigrationFactory())->make(NotAMigration::class);
    }

    public function test_container_factory_resolves_with_dependencies(): void
    {
        if (!interface_exists(ContainerInterface::class)) {
            $this->markTestSkipped('psr/container not installed');
        }

        $service = new FakeSlugService();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            fn($id) => $id === ServiceBackedMigration::class,
        );
        $container->method('get')->willReturnCallback(
            fn($id) => $id === ServiceBackedMigration::class
                ? new ServiceBackedMigration($service)
                : null,
        );

        $m = (new ContainerMigrationFactory($container))->make(ServiceBackedMigration::class);

        $this->assertInstanceOf(ServiceBackedMigration::class, $m);
        $this->assertSame($service, $m->slugs);
    }

    public function test_container_factory_falls_back_when_not_registered(): void
    {
        if (!interface_exists(ContainerInterface::class)) {
            $this->markTestSkipped('psr/container not installed');
        }

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        // not in container → falls back to new $class()
        $m = (new ContainerMigrationFactory($container))->make(ZeroArgMigration::class);

        $this->assertInstanceOf(ZeroArgMigration::class, $m);
    }

    public function test_container_factory_rejects_non_migration(): void
    {
        if (!interface_exists(ContainerInterface::class)) {
            $this->markTestSkipped('psr/container not installed');
        }

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn(new NotAMigration());

        $this->expectException(MigrationException::class);
        (new ContainerMigrationFactory($container))->make(NotAMigration::class);
    }
}

// ── fixtures ──────────────────────────────────────────────────────

final class ZeroArgMigration implements MigrationInterface
{
    public function up($schema): void {}
    public function down($schema): void {}
}

final class FakeSlugService
{
    public function slugify(string $s): string { return strtolower($s); }
}

final class ServiceBackedMigration implements MigrationInterface
{
    public function __construct(public readonly FakeSlugService $slugs) {}
    public function up($schema): void {}
    public function down($schema): void {}
}

final class NotAMigration
{
}