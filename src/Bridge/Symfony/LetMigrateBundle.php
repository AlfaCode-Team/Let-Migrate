<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Bridge\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Let-Migrate Symfony bundle.
 *
 * Package: alfacode-team/let-migrate-symfony
 *
 * Installation:
 *   composer require alfacode-team/let-migrate-symfony
 *
 * Registration in config/bundles.php:
 *   return [
 *       ...
 *       AlfaCode\LetMigrate\Bridge\Symfony\LetMigrateBundle::class => ['all' => true],
 *   ];
 *
 * Configuration in config/packages/let_migrate.yaml:
 *   let_migrate:
 *       driver:          mysql
 *       host:            '%env(DB_HOST)%'
 *       port:            '%env(int:DB_PORT)%'
 *       database:        '%env(DB_NAME)%'
 *       username:        '%env(DB_USER)%'
 *       password:        '%env(DB_PASS)%'
 *       paths:
 *           - '%kernel.project_dir%/migrations'
 *       tracking_table:  let_migrations
 *       seeders_path:    '%kernel.project_dir%/seeders'
 *       seeders_table:   let_seeders
 *       pretend:         false
 *       transactional:   true
 */
final class LetMigrateBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
    }

    public function getContainerExtension(): LetMigrateBundleExtension
    {
        return new LetMigrateBundleExtension();
    }
}

// ─────────────────────────────────────────────────────────────────────────────

final class LetMigrateBundleExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->mergeConfigs($configs);

        // Store the raw config as a parameter so the factory can read it
        $container->setParameter('let_migrate.config', $config);

        // Register LetMigrate engine as a service
        $container->register(\AlfaCode\LetMigrate\LetMigrate::class)
            ->setFactory([\AlfaCode\LetMigrate\LetMigrate::class, 'configure'])
            ->setArguments(['%let_migrate.config%'])
            ->setPublic(true);

        // Register MigrationServiceInterface — delegates to engine
        $container->register(
            \AlfaCode\LetMigrate\Contract\MigrationServiceInterface::class,
        )->setFactory([new Reference(\AlfaCode\LetMigrate\LetMigrate::class), 'service'])
         ->setPublic(true);

        // Register SchemaInspectorInterface — delegates to engine
        $container->register(
            \AlfaCode\LetMigrate\Contract\SchemaInspectorInterface::class,
        )->setFactory([new Reference(\AlfaCode\LetMigrate\LetMigrate::class), 'inspect'])
         ->setPublic(false);

        // Register SeederRunner
        $container->register(\AlfaCode\LetMigrate\Seeder\SeederRunner::class)
            ->setFactory([\AlfaCode\LetMigrate\Bridge\Symfony\SeederRunnerFactory::class, 'create'])
            ->setArguments(['%let_migrate.config%'])
            ->setPublic(true);

        // Tag all migration + seed commands for auto-wiring
        $this->registerCommands($container, $config);
    }

    public function getAlias(): string
    {
        return 'let_migrate';
    }

    private function mergeConfigs(array $configs): array
    {
        $merged = [
            'driver'          => 'mysql',
            'host'            => '127.0.0.1',
            'port'            => 3306,
            'database'        => '',
            'username'        => '',
            'password'        => '',
            'paths'           => [],
            'tracking_table'  => 'let_migrations',
            'pretend'         => false,
            'transactional'   => true,
            'seeders_path'    => null,
            'seeders_table'   => 'let_seeders',
        ];

        foreach ($configs as $c) {
            $merged = array_merge($merged, array_filter($c, static fn($v) => $v !== null));
        }

        return $merged;
    }

    private function registerCommands(ContainerBuilder $container, array $config): void
    {
        $commandClasses = [
            \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\MigrateRunCommand::class,
            \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\MigrateRollbackCommand::class,
            \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\MigrateResetCommand::class,
            \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\MigrateRefreshCommand::class,
            \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\MigrateStatusCommand::class,
            \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\MigratePendingCommand::class,
            \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\MigrateMakeCommand::class,
            \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\MigrateListCommand::class,
            \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\MigrateBaselineCommand::class,
            \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\MigrateSquashCommand::class,
            \AlfacodeTeam\PhpServicePlatform\Commands\Seed\SeedRunCommand::class,
            \AlfacodeTeam\PhpServicePlatform\Commands\Seed\SeedFreshCommand::class,
            \AlfacodeTeam\PhpServicePlatform\Commands\Seed\SeedStatusCommand::class,
        ];

        foreach ($commandClasses as $class) {
            if (class_exists($class)) {
                $container->register($class)
                    ->addTag('console.command')
                    ->setAutowired(true)
                    ->setPublic(false);
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Factory helper used by the DI extension to build SeederRunner from config.
 */
final class SeederRunnerFactory
{
    public static function create(array $config): \AlfaCode\LetMigrate\Seeder\SeederRunner
    {
        $registry = \AlfaCode\LetMigrate\Registry\DriverRegistry::fromConfig($config);
        $cfg      = \AlfaCode\LetMigrate\Config\MigrationConfig::fromArray($config);
        $driver   = $registry->driver();
        $grammar  = $registry->grammar();

        $repository = new \AlfaCode\LetMigrate\Seeder\SeederRepository($driver, $grammar, $cfg->seedersTable);

        return new \AlfaCode\LetMigrate\Seeder\SeederRunner(
            driver:     $driver,
            repository: $repository,
            paths:      $cfg->hasSeederPath() ? [$cfg->seedersPath] : [],
        );
    }
}
