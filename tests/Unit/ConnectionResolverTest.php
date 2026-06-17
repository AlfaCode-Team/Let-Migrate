<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\ConnectionResolver;
use AlfaCode\LetMigrate\Exception\LetMigrateException;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 — multi-connection routing (ConnectionResolver).
 *
 * Critical: legacy flat configs must pass through UNCHANGED (full BC).
 */
final class ConnectionResolverTest extends TestCase
{
    // ── Backward compatibility ────────────────────────────────────

    public function test_legacy_flat_config_is_returned_unchanged(): void
    {
        $cfg = [
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'database' => 'app',
            'paths'    => ['/m'],
        ];

        $this->assertSame($cfg, ConnectionResolver::resolve($cfg));
        $this->assertSame($cfg, ConnectionResolver::resolve($cfg, 'anything'));
        $this->assertSame('default', ConnectionResolver::resolveName($cfg));
        $this->assertSame(['default'], ConnectionResolver::available($cfg));
    }

    public function test_empty_connections_treated_as_legacy(): void
    {
        $cfg = ['driver' => 'sqlite', 'connections' => []];
        $this->assertSame($cfg, ConnectionResolver::resolve($cfg));
    }

    // ── Multi-connection resolution ───────────────────────────────

    private function multi(): array
    {
        return [
            'default'     => 'primary',
            'connections' => [
                'primary'   => ['driver' => 'mysql', 'database' => 'app'],
                'reporting' => ['driver' => 'pgsql', 'database' => 'analytics'],
            ],
            'paths'          => ['/migrations'],
            'tracking_table' => 'let_migrations',
            'transactional'  => true,
        ];
    }

    public function test_explicit_connection_wins(): void
    {
        $r = ConnectionResolver::resolve($this->multi(), 'reporting');

        $this->assertSame('pgsql', $r['driver']);
        $this->assertSame('analytics', $r['database']);
        $this->assertSame('reporting', $r['connection_name']);
    }

    public function test_falls_back_to_default_key(): void
    {
        $r = ConnectionResolver::resolve($this->multi());

        $this->assertSame('mysql', $r['driver']);
        $this->assertSame('primary', $r['connection_name']);
    }

    public function test_falls_back_to_first_when_no_default(): void
    {
        $cfg = $this->multi();
        unset($cfg['default']);

        $r = ConnectionResolver::resolve($cfg);
        $this->assertSame('mysql', $r['driver']); // 'primary' is first
        $this->assertSame('primary', ConnectionResolver::resolveName($cfg));
    }

    public function test_shared_top_level_keys_merge_as_defaults(): void
    {
        $r = ConnectionResolver::resolve($this->multi(), 'reporting');

        // shared keys present on the resolved connection
        $this->assertSame(['/migrations'], $r['paths']);
        $this->assertSame('let_migrations', $r['tracking_table']);
        $this->assertTrue($r['transactional']);
        // 'connections'/'default' stripped from the resolved flat config
        $this->assertArrayNotHasKey('connections', $r);
        $this->assertArrayNotHasKey('default', $r);
    }

    public function test_connection_specific_overrides_shared(): void
    {
        $cfg = $this->multi();
        $cfg['tracking_table'] = 'shared_table';
        $cfg['connections']['reporting']['tracking_table'] = 'reporting_table';

        $r = ConnectionResolver::resolve($cfg, 'reporting');
        $this->assertSame('reporting_table', $r['tracking_table']);

        $r2 = ConnectionResolver::resolve($cfg, 'primary');
        $this->assertSame('shared_table', $r2['tracking_table']);
    }

    public function test_unknown_connection_throws_with_available_list(): void
    {
        $this->expectException(LetMigrateException::class);
        $this->expectExceptionMessageMatches('/Unknown connection .nope.*primary, reporting/s');

        ConnectionResolver::resolve($this->multi(), 'nope');
    }

    public function test_available_lists_connection_names(): void
    {
        $this->assertSame(
            ['primary', 'reporting'],
            ConnectionResolver::available($this->multi()),
        );
    }

    public function test_resolveName_honours_precedence(): void
    {
        $cfg = $this->multi();
        $this->assertSame('reporting', ConnectionResolver::resolveName($cfg, 'reporting'));
        $this->assertSame('primary',   ConnectionResolver::resolveName($cfg));        // default key
        unset($cfg['default']);
        $this->assertSame('primary',   ConnectionResolver::resolveName($cfg));        // first
    }
}