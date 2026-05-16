<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

/**
 * Resolves the list of tenants and the per-tenant driver configuration.
 *
 * Implement this interface to wire Let-Migrate into your multi-tenant setup.
 * The runner calls getTenantIds() to discover tenants, then getConfigForTenant()
 * to build the correct DriverRegistry for each one.
 *
 * Two common strategies:
 *
 *   Strategy A — separate databases per tenant:
 *     getConfigForTenant('acme') returns ['driver' => 'mysql', 'database' => 'tenant_acme', ...]
 *
 *   Strategy B — separate schemas within one PostgreSQL database:
 *     getConfigForTenant('acme') returns ['driver' => 'pgsql', 'schema' => 'acme', ...]
 *     (DriverRegistry passes 'schema' to PostgreSQLSchemaInspector and search_path)
 */
interface TenantResolverInterface
{
    /**
     * Return the list of all registered tenant identifiers.
     *
     * @return string[]
     */
    public function getTenantIds(): array;

    /**
     * Return the database configuration array for a given tenant.
     *
     * @param  string               $tenantId
     * @return array<string, mixed>
     *
     * @throws \AlfaCode\LetMigrate\Exception\LetMigrateException when tenant is unknown
     */
    public function getConfigForTenant(string $tenantId): array;
}
