<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Bridge\Laravel;

use AlfaCode\LetMigrate\Contract\MigrationServiceInterface;
use AlfaCode\LetMigrate\Contract\SchemaInspectorInterface;
use AlfaCode\LetMigrate\LetMigrate;
use AlfaCode\LetMigrate\Registry\DriverRegistry;
use AlfaCode\LetMigrate\Seeder\SeederRunner;
use AlfaCode\LetMigrate\Seeder\SeederRepository;
use AlfaCode\LetMigrate\Commands\Seed\SeedCommandFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel service provider for Let-Migrate.
 *
 * Package: alfacode-team/let-migrate-laravel
 *
 * Installation:
 *   composer require alfacode-team/let-migrate-laravel
 *
 * Auto-discovery via composer.json extra.laravel.providers — no manual registration needed.
 *
 * Configuration:
 *   php artisan vendor:publish --provider="AlfaCode\LetMigrate\Bridge\Laravel\LetMigrateServiceProvider"
 *   # publishes config/let-migrate.php
 *
 * The provider binds:
 *   LetMigrate                  → singleton (the facade engine)
 *   MigrationServiceInterface   → bound to the service inside LetMigrate
 *   SchemaInspectorInterface    → bound to the inspector for the active driver
 *   SeederRunner                → singleton (when seeders_path is configured)
 */
final class LetMigrateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config defaults
        $this->mergeConfigFrom(__DIR__ . '/config/let-migrate.php', 'let-migrate');

        // Bind LetMigrate engine singleton
        $this->app->singleton(LetMigrate::class, function ($app) {
            $config = $app['config']['let-migrate'] ?? [];
            $logger = $app->bound(\Psr\Log\LoggerInterface::class)
                ? $app->make(\Psr\Log\LoggerInterface::class)
                : new \Psr\Log\NullLogger();

            return LetMigrate::configure($config, $logger);
        });

        // Bind MigrationServiceInterface — delegates to the engine
        $this->app->bind(MigrationServiceInterface::class, function ($app) {
            return $app->make(LetMigrate::class)->service();
        });

        // Bind SchemaInspectorInterface — delegates to the engine
        $this->app->bind(SchemaInspectorInterface::class, function ($app) {
            return $app->make(LetMigrate::class)->inspect();
        });

        // Bind SeederRunner singleton — only when seeders_path is configured
        $this->app->singleton(SeederRunner::class, function ($app) {
            $engine  = $app->make(LetMigrate::class);
            $config  = $app['config']['let-migrate'] ?? [];
            $logger  = $app->bound(\Psr\Log\LoggerInterface::class)
                ? $app->make(\Psr\Log\LoggerInterface::class)
                : new \Psr\Log\NullLogger();

            $registry = DriverRegistry::fromConfig($config);
            $cfg      = \AlfaCode\LetMigrate\Config\MigrationConfig::fromArray($config);
            $driver   = $registry->driver();
            $grammar  = $registry->grammar();

            $repository = new SeederRepository($driver, $grammar, $cfg->seedersTable);

            return new SeederRunner(
                driver:     $driver,
                repository: $repository,
                paths:      $cfg->hasSeederPath() ? [$cfg->seedersPath] : [],
                logger:     $logger,
            );
        });
    }

    public function boot(): void
    {
        // Publish config
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config/let-migrate.php' => config_path('let-migrate.php'),
            ], 'let-migrate-config');

            // Register Artisan commands
            $this->registerCommands();
        }
    }

    private function registerCommands(): void
    {
        $engine      = $this->app->make(LetMigrate::class);
        $seederRunner = $this->app->make(SeederRunner::class);

        $migrateFactory = \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\MigrationCommandFactory::fromConfig(
            $this->app['config']['let-migrate'] ?? [],
            $this->app->bound(\Psr\Log\LoggerInterface::class)
                ? $this->app->make(\Psr\Log\LoggerInterface::class)
                : new \Psr\Log\NullLogger(),
        );

        $seedFactory = SeedCommandFactory::fromRunner($seederRunner);

        $this->commands([
            ...$migrateFactory->all(),
            ...$seedFactory->all(),
        ]);
    }
}
