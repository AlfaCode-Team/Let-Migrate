<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

/**
 * SQLite migration: create vote_editions.
 *
 * SQLite uses loose typing — the grammar maps all types to
 * TEXT / INTEGER / REAL / BLOB / NUMERIC affinity groups.
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
            $t->string('Status')->default('draft');   // SQLite has no ENUM
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
