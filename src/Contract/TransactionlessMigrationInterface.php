<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

/**
 * OPTIONAL marker: a migration that MUST run outside of any transaction.
 *
 * The canonical case is PostgreSQL `CREATE INDEX CONCURRENTLY`, which the
 * server rejects inside a transaction block. Other examples: certain
 * `ALTER TYPE … ADD VALUE` enum changes, `VACUUM`, and some online DDL.
 *
 * Interface segregation by design — opting in is additive and there is NO
 * change to MigrationInterface and NO breaking change. A migration
 * implementing this marker is executed by the runner with NO per-migration
 * transaction; and if `all_or_nothing` is active the batch transaction is
 * committed immediately before it (atomicity is necessarily split around
 * such a migration — this is intrinsic, the same constraint Doctrine and
 * Laravel document), then a fresh batch transaction is opened for the
 * remaining migrations.
 *
 *   final class AddEmailIndexConcurrently
 *       implements MigrationInterface, TransactionlessMigrationInterface
 *   {
 *       public function up(SchemaBuilderInterface $s): void
 *       {
 *           $s->raw('CREATE INDEX CONCURRENTLY idx_users_email ON users (email)');
 *       }
 *       public function down(SchemaBuilderInterface $s): void
 *       {
 *           $s->raw('DROP INDEX CONCURRENTLY IF EXISTS idx_users_email');
 *       }
 *   }
 *
 * Marker only — no methods. Implement it alongside MigrationInterface.
 */
interface TransactionlessMigrationInterface
{
}