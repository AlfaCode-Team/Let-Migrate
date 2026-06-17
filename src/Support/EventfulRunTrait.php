<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Support;

use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use AlfaCode\LetMigrate\Event\MigrationFailed;
use AlfaCode\LetMigrate\Event\MigrationFinished;
use AlfaCode\LetMigrate\Event\MigrationStarted;
use AlfacodeTeam\PhpIoCli\Components\ProgressBar;

/**
 * Reusable event-listener wiring for any LetMigrateCommand that executes
 * migrations (run / rollback / reset / refresh / fresh / redo / migrate:to).
 *
 * Usage in a command:
 *
 *   final class FooCommand extends LetMigrateCommand
 *   {
 *       use EventfulRunTrait;
 *
 *       protected function configureEvents(MigrationEventDispatcher $e): void
 *       {
 *           $this->attachProgressListeners($e, 'Migrating');
 *       }
 *
 *       protected function handle(): int
 *       {
 *           $this->startProgress(count($this->service()->pending()));
 *           // … run …
 *           $this->finishProgress('Done');
 *       }
 *   }
 *
 * The trait assumes the consuming class exposes these methods from
 * AbstractCommand: progressBar(), error(), wantsJson() (from
 * LetMigrateCommand).
 *
 * @phpstan-require-extends \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\LetMigrateCommand
 */
trait EventfulRunTrait
{
    private ?ProgressBar $eventBar = null;

    /** @var string[] */
    private array $eventSucceeded = [];

    /**
     * Attach the standard listener triplet:
     *   MigrationStarted  → label refresh
     *   MigrationFinished → advance(1) + tick label
     *   MigrationFailed   → error line; bar finalised in tearDownProgress()
     *
     * Silent under --json so the JSON payload stays clean.
     */
    protected function attachProgressListeners(
        MigrationEventDispatcher $events,
        string $verb = 'Running',
    ): void {
        if ($this->wantsJson()) {
            return;
        }

        $events->on(MigrationStarted::class,
            function (MigrationStarted $e) use ($verb): void {
                $this->eventBar?->advance(0, "{$verb}: {$e->direction} {$e->migration}");
            });

        $events->on(MigrationFinished::class,
            function (MigrationFinished $e): void {
                $this->eventSucceeded[] = $e->migration;
                $this->eventBar?->advance(1, "✓ {$e->migration}");
            });

        $events->on(MigrationFailed::class,
            function (MigrationFailed $e): void {
                $this->eventBar?->finish('Migration failed');
                $this->eventBar = null;
                $this->error("✘ {$e->migration}: " . $e->exception->getMessage());
            });
    }

    /**
     * Begin a determinate progress bar of $total steps.
     * No-op under --json (no listeners attached either way).
     */
    protected function startProgress(int $total, string $label = 'Migrations'): void
    {
        if ($this->wantsJson()) {
            return;
        }
        $this->eventBar = $this->progressBar($label, $total);
        $this->eventBar->start();
    }

    /**
     * Finalise the progress bar; idempotent and safe after errors.
     */
    protected function finishProgress(string $message = 'Done'): void
    {
        if ($this->eventBar !== null) {
            $this->eventBar->finish($message);
            $this->eventBar = null;
        }
    }

    /** Names of migrations that succeeded during the run (for renderers). */
    protected function successfulMigrations(): array
    {
        return $this->eventSucceeded;
    }
}