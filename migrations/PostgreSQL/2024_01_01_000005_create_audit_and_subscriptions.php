<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

return new class implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        // Audit log
        $schema->create('vote_audit_log', static function (Blueprint $t): void {
            $t->id('LogID');
            $t->string('Event', 100)->notNull();
            $t->bigInteger('UserID')->unsigned()->nullable();
            $t->string('IPAddress', 45)->notNull();
            $t->json('Payload')->nullable();   // PostgreSQL grammar maps to JSONB
            $t->dateTime('CreatedAt')->default('CURRENT_TIMESTAMP');

            $t->index(['Event'],     'idx_event');
            $t->index(['UserID'],    'idx_user');
            $t->index(['IPAddress'], 'idx_ip');
        });

        // User subscriptions
        $schema->create('vote_user_subscriptions', static function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('UserID')->unsigned()->notNull();
            $t->bigInteger('EditionID')->unsigned()->notNull();
            $t->string('PlanName', 100)->default('basic');
            $t->smallInteger('DailyLimit')->default(1);
            $t->dateTime('ValidFrom')->notNull();
            $t->dateTime('ValidTo')->notNull();
            $t->timestamps();

            $t->unique(['UserID', 'EditionID'], 'uq_user_edition');
            $t->index(['UserID'],    'idx_user');
            $t->index(['EditionID'], 'idx_edition');
            $t->index(['ValidTo'],   'idx_valid_to');

            $t->foreign('EditionID')
                ->references('ID')->on('vote_editions')
                ->cascadeOnDelete()->name('fk_sub_edition');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->disableForeignKeyChecks();
        $schema->dropIfExists('vote_user_subscriptions');
        $schema->dropIfExists('vote_audit_log');
        $schema->enableForeignKeyChecks();
    }
};
