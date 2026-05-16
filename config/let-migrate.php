<?php

/**
 * Let-Migrate configuration for Laravel.
 *
 * Publish this file with:
 *   php artisan vendor:publish --provider="AlfaCode\LetMigrate\Bridge\Laravel\LetMigrateServiceProvider"
 */

return [

    /*
    |----------------------------------------------------------------------
    | Database Driver
    |----------------------------------------------------------------------
    | Supported: "mysql", "pgsql", "sqlite", "sqlsrv"
    |
    */
    'driver' => env('LET_MIGRATE_DRIVER', env('DB_CONNECTION', 'mysql')),

    /*
    |----------------------------------------------------------------------
    | Connection
    |----------------------------------------------------------------------
    */
    'host'     => env('LET_MIGRATE_HOST',     env('DB_HOST',     '127.0.0.1')),
    'port'     => env('LET_MIGRATE_PORT',     env('DB_PORT',     3306)),
    'database' => env('LET_MIGRATE_DATABASE', env('DB_DATABASE', '')),
    'username' => env('LET_MIGRATE_USERNAME', env('DB_USERNAME', '')),
    'password' => env('LET_MIGRATE_PASSWORD', env('DB_PASSWORD', '')),

    /*
    |----------------------------------------------------------------------
    | Migration Paths
    |----------------------------------------------------------------------
    | Directories where Let-Migrate looks for migration files.
    |
    */
    'paths' => [
        database_path('let-migrations'),
    ],

    /*
    |----------------------------------------------------------------------
    | Tracking Table
    |----------------------------------------------------------------------
    */
    'tracking_table' => env('LET_MIGRATE_TABLE', 'let_migrations'),

    /*
    |----------------------------------------------------------------------
    | Pretend Mode
    |----------------------------------------------------------------------
    | When true, SQL is logged but never executed. Safe for CI previews.
    |
    */
    'pretend' => (bool) env('LET_MIGRATE_PRETEND', false),

    /*
    |----------------------------------------------------------------------
    | Transactional
    |----------------------------------------------------------------------
    | Wrap each migration in its own BEGIN / COMMIT block.
    |
    */
    'transactional' => (bool) env('LET_MIGRATE_TRANSACTIONAL', true),

    /*
    |----------------------------------------------------------------------
    | Seeders
    |----------------------------------------------------------------------
    */
    'seeders_path'  => database_path('let-seeders'),
    'seeders_table' => env('LET_MIGRATE_SEEDERS_TABLE', 'let_seeders'),

];
