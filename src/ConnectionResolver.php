<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Exception\LetMigrateException;

/**
 * Resolves a single flat connection config out of a (possibly
 * multi-connection) configuration array.
 *
 * Two supported config shapes:
 *
 *  A) LEGACY / SINGLE (unchanged — full BC):
 *     [ 'driver' => 'mysql', 'host' => '…', 'paths' => [...], … ]
 *     → returned as-is; `--connection` is irrelevant.
 *
 *  B) MULTI-CONNECTION (new):
 *     [
 *       'default'     => 'primary',
 *       'connections' => [
 *           'primary'   => ['driver'=>'mysql', 'host'=>'…', …],
 *           'reporting' => ['driver'=>'pgsql', 'host'=>'…', …],
 *       ],
 *       // shared keys live at the top level and are merged in as
 *       // defaults for every connection (connection-specific wins):
 *       'paths'          => [...],
 *       'tracking_table' => 'let_migrations',
 *       'transactional'  => true,
 *     ]
 *
 * All multi-connection logic lives HERE so DriverRegistry and
 * MigrationConfig stay unchanged and BC.
 */
final class ConnectionResolver
{
    /**
     * Return the flat, single-connection config that
     * DriverRegistry::fromConfig() / MigrationConfig::fromArray()
     * already understand.
     *
     * @param  array<string, mixed> $config
     * @return array<string, mixed>
     *
     * @throws LetMigrateException for an unknown connection name
     */
    public static function resolve(array $config, string|null $connection = null): array
    {
        if (!self::isMultiConnection($config)) {
            // Legacy flat config — behaviour is exactly as before.
            return $config;
        }

        /** @var array<string, array<string, mixed>> $connections */
        $connections = $config['connections'];
        $name        = self::resolveName($config, $connection);

        if (!isset($connections[$name]) || !is_array($connections[$name])) {
            throw new LetMigrateException(sprintf(
                "Unknown connection '%s'. Available: %s",
                $name,
                implode(', ', array_keys($connections)) ?: '(none)',
            ));
        }

        // Shared top-level keys act as defaults; connection-specific
        // keys override them.
        $shared = $config;
        unset($shared['connections'], $shared['default']);

        $resolved = array_merge($shared, $connections[$name]);

        // Expose the resolved name (ignored by DriverRegistry /
        // MigrationConfig — they only read known keys — but useful for
        // status display and diagnostics).
        $resolved['connection_name'] = $name;

        return $resolved;
    }

    /**
     * The connection name that resolve() would pick.
     *
     * @param array<string, mixed> $config
     */
    public static function resolveName(array $config, string|null $connection = null): string
    {
        if (!self::isMultiConnection($config)) {
            return 'default';
        }

        if ($connection !== null && $connection !== '') {
            return $connection;
        }

        $default = $config['default'] ?? null;
        if (is_string($default) && $default !== '') {
            return $default;
        }

        $first = array_key_first($config['connections']);

        return is_string($first) ? $first : 'default';
    }

    /**
     * List configured connection names ('default' for legacy configs).
     *
     * @param  array<string, mixed> $config
     * @return string[]
     */
    public static function available(array $config): array
    {
        if (!self::isMultiConnection($config)) {
            return ['default'];
        }

        return array_map('strval', array_keys($config['connections']));
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function isMultiConnection(array $config): bool
    {
        return isset($config['connections'])
            && is_array($config['connections'])
            && $config['connections'] !== [];
    }
}