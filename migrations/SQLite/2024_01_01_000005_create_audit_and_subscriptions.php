<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

return new class implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('vote_audit_log', static function (Blueprint $t): void {
            $t->id('LogID');
            $t->string('Event', 100)->notNull();
            $t->bigInteger('UserID')->nullable();
            $t->string('IPAddress', 45)->notNull();
            $t->text('Payload')->nullable(); // SQLite: JSON stored as TEXT
            $t->dateTime('CreatedAt')->default('CURRENT_TIMESTAMP');
        });

        $schema->create('vote_user_subscriptions', static function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('UserID')->notNull();
            $t->bigInteger('EditionID')->notNull();
            $t->string('PlanName', 100)->default('basic');
            $t->integer('DailyLimit')->default(1);
            $t->dateTime('ValidFrom')->notNull();
            $t->dateTime('ValidTo')->notNull();
            $t->timestamps();

            $t->foreign('EditionID')
                ->references('ID')->on('vote_editions')
                ->cascadeOnDelete()->name('fk_sub_edition');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('vote_user_subscriptions');
        $schema->dropIfExists('vote_audit_log');
    }
};
