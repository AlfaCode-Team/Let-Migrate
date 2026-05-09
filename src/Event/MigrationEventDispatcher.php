<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Event;

/**
 * Lightweight event dispatcher for migration lifecycle events.
 *
 * Register listeners by event class name. Multiple listeners per event are
 * supported and called in registration order.
 *
 * Usage:
 *
 *   $bus = new MigrationEventDispatcher();
 *
 *   $bus->on(MigrationStarted::class, function (MigrationStarted $event): void {
 *       echo "Starting: {$event->migration}\n";
 *   });
 *
 *   $bus->on(MigrationFailed::class, function (MigrationFailed $event): void {
 *       Sentry::captureException($event->exception);
 *   });
 */
final class MigrationEventDispatcher
{
    /** @var array<class-string, list<callable>> */
    private array $listeners = [];

    /**
     * Register a listener for a specific event class.
     *
     * @param class-string<MigrationEvent> $eventClass
     * @param callable(MigrationEvent): void $listener
     */
    public function on(string $eventClass, callable $listener): self
    {
        $this->listeners[$eventClass][] = $listener;

        return $this;
    }

    /**
     * Register a one-shot listener that fires only on the first matching event.
     *
     * @param class-string<MigrationEvent> $eventClass
     * @param callable(MigrationEvent): void $listener
     */
    public function once(string $eventClass, callable $listener): self
    {
        $wrapper = null;
        $wrapper = function (MigrationEvent $event) use ($eventClass, $listener, &$wrapper): void {
            $this->off($eventClass, $wrapper);
            $listener($event);
        };

        return $this->on($eventClass, $wrapper);
    }

    /**
     * Remove a specific listener, or all listeners for an event class.
     *
     * @param class-string<MigrationEvent> $eventClass
     */
    public function off(string $eventClass, ?callable $listener = null): self
    {
        if ($listener === null) {
            unset($this->listeners[$eventClass]);

            return $this;
        }

        $this->listeners[$eventClass] = array_values(
            array_filter(
                $this->listeners[$eventClass] ?? [],
                static fn($l) => $l !== $listener,
            ),
        );

        return $this;
    }

    /**
     * Dispatch an event to all registered listeners.
     * Listener exceptions are NOT caught — let them propagate to the caller.
     */
    public function dispatch(MigrationEvent $event): void
    {
        $class = get_class($event);

        foreach ($this->listeners[$class] ?? [] as $listener) {
            $listener($event);
        }

        // Also fire listeners registered against parent classes / interfaces
        foreach ($this->listeners as $registeredClass => $listeners) {
            if ($registeredClass !== $class && $event instanceof $registeredClass) {
                foreach ($listeners as $listener) {
                    $listener($event);
                }
            }
        }
    }

    /**
     * Return true when at least one listener is registered for the class.
     *
     * @param class-string<MigrationEvent> $eventClass
     */
    public function hasListeners(string $eventClass): bool
    {
        return !empty($this->listeners[$eventClass]);
    }
}
