<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Unit;

use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use AlfaCode\LetMigrate\Event\MigrationFailed;
use AlfaCode\LetMigrate\Event\MigrationFinished;
use AlfaCode\LetMigrate\Event\MigrationStarted;
use AlfaCode\LetMigrate\Event\MigrationsCompleted;
use AlfaCode\LetMigrate\MigrationResult;
use PHPUnit\Framework\TestCase;

final class MigrationEventDispatcherTest extends TestCase
{
    private MigrationEventDispatcher $bus;

    protected function setUp(): void
    {
        $this->bus = new MigrationEventDispatcher();
    }

    // ── on / dispatch ─────────────────────────────────────────────

    public function test_listener_is_called_on_dispatch(): void
    {
        $called = false;
        $this->bus->on(MigrationStarted::class, static function () use (&$called): void {
            $called = true;
        });

        $this->bus->dispatch(new MigrationStarted('2024_01_migration', 'up'));
        $this->assertTrue($called);
    }

    public function test_listener_receives_event_instance(): void
    {
        $received = null;
        $this->bus->on(MigrationStarted::class, static function (MigrationStarted $e) use (&$received): void {
            $received = $e;
        });

        $event = new MigrationStarted('some_migration', 'up');
        $this->bus->dispatch($event);

        $this->assertSame($event, $received);
    }

    public function test_multiple_listeners_all_fire(): void
    {
        $log = [];
        $this->bus->on(MigrationStarted::class, static function () use (&$log): void { $log[] = 'A'; });
        $this->bus->on(MigrationStarted::class, static function () use (&$log): void { $log[] = 'B'; });
        $this->bus->on(MigrationStarted::class, static function () use (&$log): void { $log[] = 'C'; });

        $this->bus->dispatch(new MigrationStarted('mig', 'up'));

        $this->assertSame(['A', 'B', 'C'], $log);
    }

    public function test_dispatch_on_unregistered_event_does_nothing(): void
    {
        // No listeners registered — should not throw
        $this->bus->dispatch(new MigrationStarted('mig', 'up'));
        $this->assertTrue(true);
    }

    // ── once ──────────────────────────────────────────────────────

    public function test_once_listener_fires_only_once(): void
    {
        $count = 0;
        $this->bus->once(MigrationStarted::class, static function () use (&$count): void { $count++; });

        $event = new MigrationStarted('mig', 'up');
        $this->bus->dispatch($event);
        $this->bus->dispatch($event);
        $this->bus->dispatch($event);

        $this->assertSame(1, $count);
    }

    // ── off ───────────────────────────────────────────────────────

    public function test_off_with_listener_removes_specific_listener(): void
    {
        $count = 0;
        $listener = static function () use (&$count): void { $count++; };

        $this->bus->on(MigrationStarted::class, $listener);
        $this->bus->off(MigrationStarted::class, $listener);
        $this->bus->dispatch(new MigrationStarted('mig', 'up'));

        $this->assertSame(0, $count);
    }

    public function test_off_without_listener_removes_all_listeners(): void
    {
        $count = 0;
        $this->bus->on(MigrationStarted::class, static function () use (&$count): void { $count++; });
        $this->bus->on(MigrationStarted::class, static function () use (&$count): void { $count++; });

        $this->bus->off(MigrationStarted::class);
        $this->bus->dispatch(new MigrationStarted('mig', 'up'));

        $this->assertSame(0, $count);
    }

    public function test_off_on_unknown_event_does_not_throw(): void
    {
        $this->bus->off(MigrationStarted::class);
        $this->assertTrue(true);
    }

    // ── hasListeners ──────────────────────────────────────────────

    public function test_has_listeners_returns_false_initially(): void
    {
        $this->assertFalse($this->bus->hasListeners(MigrationStarted::class));
    }

    public function test_has_listeners_returns_true_after_on(): void
    {
        $this->bus->on(MigrationStarted::class, static fn() => null);
        $this->assertTrue($this->bus->hasListeners(MigrationStarted::class));
    }

    public function test_has_listeners_returns_false_after_off(): void
    {
        $listener = static fn() => null;
        $this->bus->on(MigrationStarted::class, $listener);
        $this->bus->off(MigrationStarted::class, $listener);

        $this->assertFalse($this->bus->hasListeners(MigrationStarted::class));
    }

    // ── All event types ───────────────────────────────────────────

    public function test_migration_finished_event_carries_direction(): void
    {
        $received = null;
        $this->bus->on(MigrationFinished::class, static function (MigrationFinished $e) use (&$received): void {
            $received = $e->direction;
        });

        $this->bus->dispatch(new MigrationFinished('mig', 'down'));
        $this->assertSame('down', $received);
    }

    public function test_migration_failed_event_carries_exception(): void
    {
        $receivedException = null;
        $this->bus->on(MigrationFailed::class, static function (MigrationFailed $e) use (&$receivedException): void {
            $receivedException = $e->exception;
        });

        $ex = new \RuntimeException('DB exploded');
        $this->bus->dispatch(new MigrationFailed('mig', 'up', $ex));

        $this->assertSame($ex, $receivedException);
    }

    public function test_migrations_completed_event_carries_result(): void
    {
        $receivedResult = null;
        $this->bus->on(MigrationsCompleted::class, static function (MigrationsCompleted $e) use (&$receivedResult): void {
            $receivedResult = $e->result;
        });

        $result = new MigrationResult(applied: ['a', 'b'], rolledBack: [], batch: 1);
        $this->bus->dispatch(new MigrationsCompleted($result));

        $this->assertSame($result, $receivedResult);
        $this->assertSame(2, $receivedResult->appliedCount());
    }

    // ── event.occurredAt ─────────────────────────────────────────

    public function test_all_events_have_occurred_at_timestamp(): void
    {
        $events = [
            new MigrationStarted('mig', 'up'),
            new MigrationFinished('mig', 'up'),
            new MigrationFailed('mig', 'up', new \RuntimeException()),
            new MigrationsCompleted(MigrationResult::empty()),
        ];

        foreach ($events as $event) {
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
                $event->occurredAt,
                get_class($event) . '::occurredAt must be a valid datetime string',
            );
        }
    }
}
