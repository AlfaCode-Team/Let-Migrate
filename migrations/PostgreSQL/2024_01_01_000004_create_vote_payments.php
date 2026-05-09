<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

return new class implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('vote_payments', static function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('UserID')->unsigned()->notNull();
            $t->bigInteger('ContestantID')->unsigned()->notNull();
            $t->bigInteger('EditionID')->unsigned()->notNull();
            $t->integer('VoteCount')->notNull();
            $t->bigInteger('AmountKobo')->unsigned()->notNull();
            $t->string('Reference', 64)->notNull();
            $t->enum('Status', ['pending', 'completed', 'failed', 'refunded'])->default('pending');
            $t->text('GatewayResponse')->nullable();
            $t->timestamps();

            $t->unique(['Reference'], 'uq_reference');
            $t->index(['UserID'],                    'idx_user');
            $t->index(['ContestantID', 'EditionID'], 'idx_contestant_edition');
            $t->index(['Status'],                    'idx_status');

            $t->foreign('ContestantID')
                ->references('ID')->on('vote_contestants')
                ->cascadeOnDelete()->name('fk_payment_contestant');

            $t->foreign('EditionID')
                ->references('ID')->on('vote_editions')
                ->cascadeOnDelete()->name('fk_payment_edition');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->disableForeignKeyChecks();
        $schema->dropIfExists('vote_payments');
        $schema->enableForeignKeyChecks();
    }
};
