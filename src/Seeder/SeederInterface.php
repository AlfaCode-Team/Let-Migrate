<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Seeder;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;

/**
 * Contract every database seeder must implement.
 *
 * Seeders are discovered and executed by SeederRunner.
 * Dependencies between seeders are declared via getDependencies() and the
 * runner resolves execution order through topological sort.
 *
 * Example seeder:
 *
 *   return new class implements SeederInterface {
 *
 *       public function run(DatabaseDriverInterface $db): void
 *       {
 *           $db->insert('roles', ['name' => 'admin', 'created_at' => date('Y-m-d H:i:s')]);
 *           $db->insert('roles', ['name' => 'user',  'created_at' => date('Y-m-d H:i:s')]);
 *       }
 *
 *       public function getDependencies(): array
 *       {
 *           return []; // run first — no deps
 *       }
 *   };
 */
interface SeederInterface
{
    /**
     * Populate the database with data.
     *
     * The $db parameter gives direct access to execute(), insert(), fetchAll()
     * etc. No query builder — raw PDO under the hood for maximum speed.
     */
    public function run(DatabaseDriverInterface $db): void;

    /**
     * Return the class names (or file basenames) of seeders that must run
     * before this one.
     *
     * Return an empty array when this seeder has no dependencies.
     *
     * @return string[]
     */
    public function getDependencies(): array;
}
