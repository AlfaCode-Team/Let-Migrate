<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

/**
 * SQL Server migration: create vote_editions.
 *
 * The SQLServerGrammar maps:
 *   BIGINT AUTO_INCREMENT → BIGINT IDENTITY(1,1)
 *   VARCHAR               → NVARCHAR (Unicode)
 *   DATETIME              → DATETIME2
 *   TEXT                  → NVARCHAR(MAX)
 */
return new class implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('vote_editions', static function (Blueprint $t): void {
            $t->id();
            $t->string('Title');
            $t->text('Description')->nullable();
            $t->dateTime('StartDate');
            $t->dateTime('EndDate');
            $t->string('Status', 20)->default('draft');
            $t->timestamps();

            $t->index(['Status'],              'idx_status');
            $t->index(['StartDate', 'EndDate'], 'idx_dates');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('vote_editions');
    }
};
