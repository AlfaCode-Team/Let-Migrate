<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Contract;

/**
 * Every migration must implement this contract.
 *
 * The `up()` method applies the migration (CREATE, ALTER, INSERT seed data …).
 * The `down()` method fully reverses it so rollbacks are always possible.
 *
 * Example implementation:
 *
 *   final class CreateUsersTable implements MigrationInterface
 *   {
 *       public function up(SchemaBuilderInterface $schema): void
 *       {
 *           $schema->create('users', static function (Blueprint $t): void {
 *               $t->id();
 *               $t->string('email')->unique();
 *               $t->timestamps();
 *           });
 *       }
 *
 *       public function down(SchemaBuilderInterface $schema): void
 *       {
 *           $schema->dropIfExists('users');
 *       }
 *   }
 */
interface MigrationInterface
{
    /**
     * Apply the migration.
     */
    public function up(SchemaBuilderInterface $schema): void;

    /**
     * Reverse the migration.
     */
    public function down(SchemaBuilderInterface $schema): void;
}
