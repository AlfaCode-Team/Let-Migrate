<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\MigrationRepositoryInterface;
use AlfaCode\LetMigrate\Contract\MigrationResolverInterface;
use AlfaCode\LetMigrate\Contract\MigrationServiceInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enterprise migration service — the single authorised boundary between
 * application code and the persistence layer.
 *
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  ENTERPRISE ARCHITECTURE BOUNDARY                           ║
 * ║                                                             ║
 * ║  MigrationService is the ONLY class in the codebase that    ║
 * ║  holds a reference to MigrationRepositoryInterface.         ║
 * ║                                                             ║
 * ║  All external code (CLI commands, HTTP controllers,         ║
 * ║  framework adapters) MUST depend on                         ║
 * ║  MigrationServiceInterface — never on the repository or     ║
 * ║  runner directly.                                           ║
 * ║                                                             ║
 * ║  Instantiation MUST go through MigrationServiceFactory.     ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * Dependency graph:
 *
 *   External Code
 *       │  depends only on ↓
 *   MigrationServiceInterface
 *       │  implemented by ↓
 *   MigrationService          ← you are here
 *       │  exclusively owns ↓
 *   MigrationRepositoryInterface  (private — inaccessible outside this class)
 *       │
 *   DatabaseMigrationRepository  (created only inside MigrationServiceFactory)
 */
final class MigrationService implements MigrationServiceInterface
{
    private readonly MigrationRunner $runner;

    /**
     * Constructor is intentionally package-internal.
     * Use MigrationServiceFactory::create() to obtain an instance.
     *
     * @internal Called only by MigrationServiceFactory.
     */
    public function __construct(
        private readonly MigrationRepositoryInterface $repository,
        MigrationResolverInterface                    $resolver,
        SchemaBuilderInterface                        $schema,
        private readonly MigrationEventDispatcher     $events = new MigrationEventDispatcher(),
        LoggerInterface                               $logger = new NullLogger(),
        bool                                          $pretend = false,
    ) {
        // MigrationRunner is an internal orchestrator; it receives the
        // repository reference here — the only place outside this constructor
        // where repository passes through.
        $this->runner = new MigrationRunner(
            repository: $this->repository,
            resolver: $resolver,
            schema: $schema,
            events: $this->events,
            logger: $logger,
            pretend: $pretend,
        );
    }

    // ── MigrationServiceInterface ─────────────────────────────────

    public function run(): MigrationResult
    {
        return $this->runner->run();
    }

    public function rollback(int $steps = 1): MigrationResult
    {
        return $this->runner->rollback($steps);
    }

    public function reset(): MigrationResult
    {
        return $this->runner->reset();
    }

    public function refresh(): MigrationResult
    {
        return $this->runner->refresh();
    }

    public function status(): array
    {
        return $this->runner->status();
    }

    public function pending(): array
    {
        return $this->runner->pending();
    }

    public function events(): MigrationEventDispatcher
    {
        return $this->events;
    }

    // ── Internal accessor for LetMigrate facade ────────────────────

    /**
     * Expose the underlying runner for advanced use-cases (testing, decoration).
     * Application code should prefer the MigrationServiceInterface methods above.
     *
     * @internal
     */
    public function runner(): MigrationRunner
    {
        return $this->runner;
    }
}
