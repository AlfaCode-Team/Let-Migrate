<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

/**
 * OPTIONAL companion contract for declaring inter-migration dependencies
 * (Doctrine 3.0 `@dependsOn` parity, ORM-free).
 *
 * Interface segregation by design: a migration opts in by additionally
 * implementing this interface. Existing migrations that implement only
 * MigrationInterface are completely unaffected — there is NO breaking
 * change and NO change to MigrationInterface.
 *
 *   final class AddTeamToUsers
 *       implements MigrationInterface, DependentMigrationInterface
 *   {
 *       public function dependsOn(): array
 *       {
 *           // run AFTER these, regardless of timestamp order
 *           return ['2024_01_01_000001_create_teams_table'];
 *       }
 *       public function up(SchemaBuilderInterface $s): void { … }
 *       public function down(SchemaBuilderInterface $s): void { … }
 *   }
 *
 * The runner topologically sorts pending migrations so every dependency
 * runs first; timestamp order is preserved as the stable tie-breaker.
 * Dependencies that are already applied (not in the pending set) are
 * simply ignored. A dependency cycle raises LetMigrateException.
 */
interface DependentMigrationInterface
{
    /**
     * Filename-keys (without .php) of migrations that MUST run before this
     * one, e.g. "2024_01_01_000001_create_teams_table".
     *
     * @return string[]
     */
    public function dependsOn(): array;
}