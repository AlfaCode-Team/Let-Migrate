<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Schema;

use AlfaCode\LetMigrate\Exception\LetMigrateException;

/**
 * Registry of desired table schemas — the "what you want" side of migrate:diff.
 *
 * Design decision: Option B was chosen.
 * Users define a `schema.php` file in their migrations path that returns an
 * array of closures, each receiving a Blueprint. The registry discovers and
 * loads these files, keyed by table name.
 *
 * Example schema.php:
 *
 *   <?php
 *   return [
 *       'users' => static function (\AlfaCode\LetMigrate\Schema\Blueprint $t): void {
 *           $t->id();
 *           $t->string('email', 191)->unique()->notNull();
 *           $t->string('password');
 *           $t->boolean('is_active')->default(true);
 *           $t->timestamps();
 *       },
 *       'roles' => static function (\AlfaCode\LetMigrate\Schema\Blueprint $t): void {
 *           $t->id();
 *           $t->string('name', 64)->unique()->notNull();
 *       },
 *   ];
 *
 * Resolution:
 *   $registry = BlueprintRegistry::fromPath('/path/to/migrations');
 *   $blueprints = $registry->all(); // ['users' => Blueprint, 'roles' => Blueprint]
 */
final class BlueprintRegistry
{
    /** @var array<string, Blueprint> table name → compiled Blueprint */
    private array $blueprints = [];

    private function __construct() {}

    // ── Static constructors ───────────────────────────────────────

    /**
     * Load from a `schema.php` file in the given directory.
     *
     * @param  string $path  directory containing schema.php
     * @throws LetMigrateException when schema.php is missing or malformed
     */
    public static function fromPath(string $path): self
    {
        $schemaFile = rtrim($path, '/') . '/schema.php';

        if (!file_exists($schemaFile)) {
            throw new LetMigrateException(
                "Blueprint registry file not found: {$schemaFile}\n"
                . "Create a schema.php file in your migrations directory that returns\n"
                . "an array of ['table_name' => fn(Blueprint \$t): void {...}].",
            );
        }

        $definitions = require $schemaFile;

        if (!is_array($definitions)) {
            throw new LetMigrateException(
                "schema.php must return an array of ['table' => callable] pairs.",
            );
        }

        $registry = new self();

        foreach ($definitions as $table => $callback) {
            if (!is_string($table) || $table === '') {
                throw new LetMigrateException("Blueprint table name must be a non-empty string.");
            }

            if (!is_callable($callback)) {
                throw new LetMigrateException(
                    "Blueprint definition for '{$table}' must be a callable(Blueprint \$t): void.",
                );
            }

            $blueprint = new Blueprint($table);
            $callback($blueprint);
            $registry->blueprints[$table] = $blueprint;
        }

        return $registry;
    }

    /**
     * Build from a manually provided map (useful in tests).
     *
     * @param array<string, callable(Blueprint): void> $definitions
     */
    public static function fromArray(array $definitions): self
    {
        $registry = new self();

        foreach ($definitions as $table => $callback) {
            $blueprint = new Blueprint($table);
            $callback($blueprint);
            $registry->blueprints[$table] = $blueprint;
        }

        return $registry;
    }

    // ── Accessors ─────────────────────────────────────────────────

    /**
     * @return array<string, Blueprint>
     */
    public function all(): array
    {
        return $this->blueprints;
    }

    public function get(string $table): Blueprint
    {
        if (!isset($this->blueprints[$table])) {
            throw new LetMigrateException("No blueprint registered for table '{$table}'.");
        }

        return $this->blueprints[$table];
    }

    public function has(string $table): bool
    {
        return isset($this->blueprints[$table]);
    }

    /** @return string[] */
    public function tables(): array
    {
        return array_keys($this->blueprints);
    }
}
