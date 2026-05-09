<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

return new class implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('vote_voting', static function (Blueprint $t): void {
            $t->id('VoteID');
            $t->bigInteger('UserID')->notNull();
            $t->bigInteger('ContestantID')->notNull();
            $t->bigInteger('EditionID')->notNull();
            $t->integer('VoteCount')->default(1);
            $t->boolean('IsPaid')->default(false);
            $t->string('Status')->default('approved');
            $t->string('IPAddress', 45)->notNull();
            $t->string('UserAgent')->default('');
            $t->dateTime('CreatedAt')->default('CURRENT_TIMESTAMP');

            $t->foreign('ContestantID')
                ->references('ID')->on('vote_contestants')
                ->cascadeOnDelete()->name('fk_vote_contestant');

            $t->foreign('EditionID')
                ->references('ID')->on('vote_editions')
                ->cascadeOnDelete()->name('fk_vote_edition');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('vote_voting');
    }
};
