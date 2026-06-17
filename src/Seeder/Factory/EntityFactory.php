<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Seeder\Factory;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Seeder\SeederInterface;

/**
 * Fluent, Laravel-style data factory — ORM-free and integrates with the
 * existing SeederInterface / SeederRunner.
 *
 *   $seeder = EntityFactory::for('users')
 *       ->definition(fn(FakeData $f, int $i) => [
 *           'name'  => $f->name(),
 *           'email' => $f->uniqueEmail($i),
 *           'role'  => 'user',
 *           'plan'  => 'free',
 *       ])
 *       ->count(50)
 *       ->state(['role' => 'admin'])                    // attribute override
 *       ->sequence('plan', new Sequence('free','pro'))  // cycle per row
 *       ->locale('es')                                  // locale fake data
 *       ->asSeeder();                                   // → SeederInterface
 *
 * Relationship helper (ORM-free, explicit & honest about IDs):
 *   ->withParent('user_id', $userFactory)
 *     — runs the parent factory first; child row i gets the parent id at
 *       index (i mod parentCount). Parent rows must define their own 'id'
 *       (or rely on positional 1..N) since lastInsertId is driver-specific.
 *
 * All builder methods are immutable-friendly (return $this); the heavy
 * work happens lazily inside the returned SeederInterface::run().
 */
final class EntityFactory
{
    /** @var (callable(FakeData,int):array<string,mixed>)|null */
    private $definition = null;

    private int $count = 1;

    /** @var array<int, array<string,mixed>|callable> */
    private array $states = [];

    /** @var array<string, Sequence> */
    private array $sequences = [];

    private string $locale = 'en';

    /** @var array<int, array{fk:string, factory:EntityFactory}> */
    private array $parents = [];

    /** @var string[] */
    private array $dependencies = [];

    private function __construct(private readonly string $table) {}

    public static function for(string $table): self
    {
        return new self($table);
    }

    /**
     * @param callable(FakeData,int):array<string,mixed> $definition
     */
    public function definition(callable $definition): self
    {
        $this->definition = $definition;
        return $this;
    }

    public function count(int $count): self
    {
        $this->count = max(0, $count);
        return $this;
    }

    /**
     * Override attributes for every row (Laravel "state"). Array or a
     * callable `fn(array $attrs, int $i): array`.
     *
     * @param array<string,mixed>|callable $state
     */
    public function state(array|callable $state): self
    {
        $this->states[] = $state;
        return $this;
    }

    public function sequence(string $column, Sequence $sequence): self
    {
        $this->sequences[$column] = $sequence;
        return $this;
    }

    public function locale(string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    public function dependsOn(string ...$seederNames): self
    {
        $this->dependencies = array_merge($this->dependencies, $seederNames);
        return $this;
    }

    /**
     * Create the parent rows first and link them by $foreignKey.
     */
    public function withParent(string $foreignKey, EntityFactory $parent): self
    {
        $this->parents[] = ['fk' => $foreignKey, 'factory' => $parent];
        return $this;
    }

    /**
     * Build the row set in memory (no DB). Useful for assertions/tests.
     *
     * @return array<int, array<string,mixed>>
     */
    public function raw(): array
    {
        $fake = new FakeData($this->locale);
        $rows = [];

        // Resolve parent rows once so children can reference their ids.
        $parentRows = [];
        foreach ($this->parents as $p) {
            $parentRows[$p['fk']] = $p['factory']->raw();
        }

        for ($i = 0; $i < $this->count; $i++) {
            $row = $this->definition !== null
                ? ($this->definition)($fake, $i)
                : [];

            foreach ($this->states as $state) {
                $override = is_callable($state) ? $state($row, $i) : $state;
                $row = array_merge($row, $override);
            }

            foreach ($this->sequences as $col => $seq) {
                $row[$col] = $seq->valueFor($i);
            }

            foreach ($parentRows as $fk => $pRows) {
                if ($pRows !== []) {
                    $parent      = $pRows[$i % count($pRows)];
                    $row[$fk]    = $parent['id'] ?? (($i % count($pRows)) + 1);
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Wrap the factory as a SeederInterface (use with SeederRunner or
     * call ->run($db) directly).
     */
    public function asSeeder(): SeederInterface
    {
        return new class ($this->table, $this, $this->dependencies, $this->parents)
            implements SeederInterface
        {
            /** @param array<int,array{fk:string,factory:EntityFactory}> $parents */
            public function __construct(
                private readonly string        $table,
                private readonly EntityFactory $factory,
                private readonly array         $deps,
                private readonly array         $parents,
            ) {}

            public function run(DatabaseDriverInterface $db): void
            {
                // Insert parent rows first so FK targets exist.
                foreach ($this->parents as $p) {
                    $p['factory']->asSeeder()->run($db);
                }

                foreach ($this->factory->raw() as $row) {
                    $db->insert($this->table, $row);
                }
            }

            public function getDependencies(): array
            {
                return $this->deps;
            }
        };
    }

    /**
     * Convenience: insert immediately.
     */
    public function create(DatabaseDriverInterface $db): void
    {
        $this->asSeeder()->run($db);
    }
}