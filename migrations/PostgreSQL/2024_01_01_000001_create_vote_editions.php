<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

/**
 * PostgreSQL migration: create vote_editions.
 *
 * Blueprint types are automatically mapped to PostgreSQL equivalents
 * by PostgreSQLGrammar (e.g. DATETIME → TIMESTAMP, BIGINT AUTO_INCREMENT → BIGSERIAL).
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
            // PostgreSQL: ENUM is stored as VARCHAR(100) by the grammar
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
