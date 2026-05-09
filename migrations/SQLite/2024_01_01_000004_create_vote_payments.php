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
            $t->bigInteger('UserID')->notNull();
            $t->bigInteger('ContestantID')->notNull();
            $t->bigInteger('EditionID')->notNull();
            $t->integer('VoteCount')->notNull();
            $t->bigInteger('AmountKobo')->notNull();
            $t->string('Reference', 64)->notNull()->unique();
            $t->string('Status')->default('pending');
            $t->text('GatewayResponse')->nullable();
            $t->timestamps();

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
        $schema->dropIfExists('vote_payments');
    }
};
