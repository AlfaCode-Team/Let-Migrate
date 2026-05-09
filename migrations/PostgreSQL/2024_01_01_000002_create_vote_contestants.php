<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

return new class implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('vote_contestants', static function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('EditionID')->unsigned()->notNull();
            $t->string('FullName');
            $t->string('StageName')->nullable();
            $t->string('PhotoURL', 500)->nullable();
            $t->bigInteger('Votes')->unsigned()->default(0);
            $t->enum('Status', ['active', 'disqualified', 'withdrawn'])->default('active');
            $t->timestamps();

            $t->index(['EditionID'],           'idx_edition');
            $t->index(['EditionID', 'Status'], 'idx_edition_status');

            $t->foreign('EditionID')
                ->references('ID')
                ->on('vote_editions')
                ->cascadeOnDelete()
                ->name('fk_contestant_edition');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->disableForeignKeyChecks();
        $schema->dropIfExists('vote_contestants');
        $schema->enableForeignKeyChecks();
    }
};
