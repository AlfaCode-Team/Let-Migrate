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
            $t->bigInteger('EditionID')->notNull();
            $t->string('FullName');
            $t->string('StageName')->nullable();
            $t->string('PhotoURL')->nullable();
            $t->bigInteger('Votes')->default(0);
            $t->string('Status')->default('active'); // SQLite: no ENUM
            $t->timestamps();

            $t->foreign('EditionID')
                ->references('ID')->on('vote_editions')
                ->cascadeOnDelete()->name('fk_contestant_edition');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('vote_contestants');
    }
};
