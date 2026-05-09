<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

/**
 * MySQL migration: create the vote_editions table.
 *
 * Pulse-Engine — Migration 001 (MySQL)
 * Converted from raw SQL to the LetMigrate Blueprint API.
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
            $t->enum('Status', ['draft', 'active', 'closed'])->default('draft');
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
