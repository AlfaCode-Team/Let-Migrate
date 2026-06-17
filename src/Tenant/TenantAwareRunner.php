<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tenant;

use AlfaCode\LetMigrate\Contract\TenantResolverInterface;
use AlfaCode\LetMigrate\Exception\LetMigrateException;
use AlfaCode\LetMigrate\MigrationResult;
use AlfaCode\LetMigrate\DriverRegistry;
use AlfaCode\LetMigrate\MigrationServiceFactory;
use AlfaCode\LetMigrate\MigrationConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Wraps MigrationServiceFactory to run migration operations scoped to one
 * or all registered tenants.
 *
 * Each tenant gets its own DriverRegistry (and therefore its own PDO connection)
 * resolved from TenantResolverInterface::getConfigForTenant(). This supports
 * both separate-database (Strategy A) and separate-schema (Strategy B) models.
 *
 * Usage:
 *
 *   $runner = new TenantAwareRunner(
 *       resolver: new MyTenantResolver(),
 *       baseConfig: ['paths' => [__DIR__ . '/migrations'], 'tracking_table' => 'let_migrations'],
 *   );
 *
 *   // Run for a single tenant
 *   $result = $runner->runForTenant('acme');
 *
 *   // Run for all tenants
 *   $results = $runner->runForAllTenants();
 */
final class TenantAwareRunner
{
    public function __construct(
        private readonly TenantResolverInterface $resolver,
        /** @var array<string, mixed> base config merged with per-tenant config */
        private readonly array                   $baseConfig,
        private readonly LoggerInterface         $logger = new NullLogger(),
    ) {}

    // ── Single tenant ─────────────────────────────────────────────

    public function runForTenant(string $tenantId): MigrationResult
    {
        return $this->serviceForTenant($tenantId)->run();
    }

    public function rollbackForTenant(string $tenantId, int $steps = 1): MigrationResult
    {
        return $this->serviceForTenant($tenantId)->rollback($steps);
    }

    public function resetForTenant(string $tenantId): MigrationResult
    {
        return $this->serviceForTenant($tenantId)->reset();
    }

    public function refreshForTenant(string $tenantId): MigrationResult
    {
        return $this->serviceForTenant($tenantId)->refresh();
    }

    /**
     * @return array<string, array{status: string, batch: int|null}>
     */
    public function statusForTenant(string $tenantId): array
    {
        return $this->serviceForTenant($tenantId)->status();
    }

    // ── All tenants ───────────────────────────────────────────────

    /**
     * Run migrations for every tenant in sequence.
     *
     * @return array<string, \AlfaCode\LetMigrate\MigrationResult> tenantId => result
     */
    public function runForAllTenants(): array
    {
        return $this->forEachTenant(fn($tenantId) => $this->runForTenant($tenantId));
    }

    /**
     * @return array<string, array<string, array{status: string, batch: int|null}>>
     */
    public function statusForAllTenants(): array
    {
        return $this->forEachTenant(fn($tenantId) => $this->statusForTenant($tenantId));
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * @template T
     * @param callable(string): T $callback
     * @return array<string, T>
     */
    private function forEachTenant(callable $callback): array
    {
        $results = [];

        foreach ($this->resolver->getTenantIds() as $tenantId) {
            $this->logger->info("[LetMigrate:Tenant] Processing tenant: {$tenantId}");

            try {
                $results[$tenantId] = $callback($tenantId);
                $this->logger->info("[LetMigrate:Tenant] Done: {$tenantId}");
            } catch (\Throwable $e) {
                $this->logger->error("[LetMigrate:Tenant] Failed: {$tenantId} — {$e->getMessage()}");
                throw new LetMigrateException(
                    "Tenant migration failed for '{$tenantId}': {$e->getMessage()}",
                    (int) $e->getCode(),
                    $e,
                );
            }
        }

        return $results;
    }

    private function serviceForTenant(string $tenantId): \AlfaCode\LetMigrate\Contract\MigrationServiceInterface
    {
        $tenantConfig = $this->resolver->getConfigForTenant($tenantId);
        $merged       = array_merge($this->baseConfig, $tenantConfig);
        $registry     = DriverRegistry::fromConfig($merged);
        $config       = MigrationConfig::fromArray($merged);

        return MigrationServiceFactory::create($registry, $config, $this->logger);
    }
}
