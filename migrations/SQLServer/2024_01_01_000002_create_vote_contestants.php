<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

/**
 * SQL Server: vote_contestants
 * Grammar maps: BIGINT AUTO_INCREMENT → BIGINT IDENTITY(1,1)
 *               VARCHAR               → NVARCHAR
 *               DATETIME              → DATETIME2
 *               ENUM                  → NVARCHAR(100)
 */
return new class implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('vote_contestants', static function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('EditionID')->notNull();
            $t->string('FullName');
            $t->string('StageName')->nullable();
            $t->string('PhotoURL', 500)->nullable();
            $t->bigInteger('Votes')->default(0);
            $t->string('Status', 20)->default('active');
            $t->timestamps();

            $t->index(['EditionID'],           'idx_edition');
            $t->index(['EditionID', 'Status'], 'idx_edition_status');

            $t->foreign('EditionID')
                ->references('ID')->on('vote_editions')
                ->cascadeOnDelete()->name('fk_contestant_edition');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->disableForeignKeyChecks();
        $schema->dropIfExists('vote_contestants');
        $schema->enableForeignKeyChecks();
    }
};
