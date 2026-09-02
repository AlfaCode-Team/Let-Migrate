<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Tests\Live;

use AlfaCode\LetMigrate\Schema\ColumnDefinition;
use AlfaCode\LetMigrate\Schema\SchemaBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The compiler, executed against every real engine this machine can reach.
 *
 * Read LiveTarget for how connections are configured and why an unreachable
 * driver SKIPS rather than passes. Everything asserted here is something that
 * compiled cleanly, read correctly, and was still refused by an engine:
 *
 *   MySQL      batched `ADD COLUMN a, ADD COLUMN b`, `ADD KEY` inline
 *   PostgreSQL `BOOLEAN DEFAULT 1`; a ';'-joined multi-statement clause
 *   SQLite     `ADD CONSTRAINT`; dropping a column before its index
 *   SQLServer  the COLUMN keyword in ALTER … ADD; `ON DELETE RESTRICT`
 *
 * Not one of those is visible to `php -l`, to a unit test that inspects the
 * compiled string, or to a fake that records SQL without executing it.
 */
final class DriverMatrixTest extends TestCase
{
    private ?LiveTarget $target = null;

    protected function tearDown(): void
    {
        $this->target?->destroy();
        $this->target = null;
    }

    /** @return iterable<string, array{string}> */
    public static function drivers(): iterable
    {
        foreach (LiveTarget::DRIVERS as $driver) {
            yield $driver => [$driver];
        }
    }

    #[DataProvider('drivers')]
    public function test_the_full_ddl_lifecycle_executes(string $driver): void
    {
        $t      = $this->target($driver);
        $schema = new SchemaBuilder($t->driver, $t->grammar, $t->inspector);

        // ── CREATE ────────────────────────────────────────────────
        $schema->create('accounts', static function ($table) {
            $table->bigIncrements('id');
            $table->string('email', 191);
            $table->string('status', 20)->default('active');
            $table->boolean('verified')->default(false);
            $table->boolean('is_admin')->default(true);         // pgsql: must be TRUE, not 1
            $table->timestamp('created_at')->useCurrent();
            $table->index(['email']);
        });

        $t->driver->execute("INSERT INTO accounts (email) VALUES ('a@b.c')");
        $row = $t->driver->fetchOne('SELECT * FROM accounts');

        $this->assertNotEmpty($row['created_at'] ?? null, 'CURRENT_TIMESTAMP default did not apply');
        $this->assertTrue(
            in_array($row['is_admin'], [1, '1', true, 't'], true),
            'boolean default true came back as ' . var_export($row['is_admin'], true),
        );

        // ── ALTER: several adds + an index in ONE blueprint ────────
        $schema->table('accounts', static function ($table) {
            $table->string('nickname', 40)->nullable();
            $table->string('locale', 8)->nullable();
            $table->index(['nickname']);
        });

        $this->assertCount(1, $t->driver->fetchAll('SELECT * FROM accounts'), 'row lost during ALTER');
        $this->assertTrue($t->driver->columnExists('accounts', 'nickname'));
        $this->assertTrue($t->driver->columnExists('accounts', 'locale'));

        // ── MODIFY ────────────────────────────────────────────────
        $schema->table('accounts', static function ($table) {
            $table->modifyColumn(
                'status',
                static fn () => (new ColumnDefinition('status', 'VARCHAR(60)'))->default('active')->nullable(),
            );
        });

        $this->assertCount(1, $t->driver->fetchAll('SELECT * FROM accounts'), 'row lost during MODIFY');

        // ── Foreign key, declared on CREATE so SQLite can take it ──
        $schema->create('orders', static function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('account_id')->unsigned();
            $table->foreign('account_id')->references('id')->on('accounts');
        });

        $this->assertTrue($t->driver->tableExists('orders'));

        // ── ROLLBACK shape: the index, then the columns under it ───
        $schema->table('accounts', static function ($table) {
            $table->dropIndex('idx_nickname');
            $table->dropColumn('nickname', 'locale');
        });

        $columns = $t->driver->listColumns('accounts');

        foreach (['id', 'email', 'status', 'verified', 'is_admin', 'created_at'] as $kept) {
            $this->assertContains($kept, $columns, "column {$kept} should have survived");
        }

        foreach (['nickname', 'locale'] as $dropped) {
            $this->assertNotContains($dropped, $columns, "column {$dropped} should have been dropped");
        }
    }

    /**
     * The schema inspector must report what is actually there.
     *
     * PostgreSQL's queries used libpq's `$1` placeholders, which PDO does not
     * understand and — this is the dangerous part — does not reject: every
     * lookup silently matched nothing, so tableExists() answered false for a
     * table with seven columns. Only a live database can catch that.
     */
    #[DataProvider('drivers')]
    public function test_the_inspector_reports_the_real_schema(string $driver): void
    {
        $t      = $this->target($driver);
        $schema = new SchemaBuilder($t->driver, $t->grammar, $t->inspector);

        $schema->create('widgets', static function ($table) {
            $table->bigIncrements('id');
            $table->string('name', 80);
            $table->index(['name']);
        });

        $this->assertTrue($t->driver->tableExists('widgets'), 'tableExists() denied a table it just created');
        $this->assertTrue($t->driver->columnExists('widgets', 'name'));
        $this->assertContains('widgets', $t->inspector->getTables());

        $columns = array_map(static fn ($c) => $c->name, $t->inspector->getColumns('widgets'));

        $this->assertSame(['id', 'name'], $columns);

        $id = null;
        foreach ($t->inspector->getColumns('widgets') as $column) {
            if ($column->name === 'id') {
                $id = $column;
            }
        }

        $this->assertNotNull($id);
        $this->assertTrue($id->primaryKey, 'id not reported as the primary key');
        $this->assertTrue($id->autoIncrement, 'id not reported as auto-incrementing');
    }

    private function target(string $driver): LiveTarget
    {
        $reason = LiveTarget::unavailableReason($driver);

        if ($reason !== null) {
            $this->markTestSkipped($reason);
        }

        try {
            return $this->target = LiveTarget::create($driver);
        } catch (\RuntimeException $e) {
            // Configured but not answering is a SKIP with the reason, never a
            // pass — a missing server must never look like a green tick.
            $this->markTestSkipped($e->getMessage());
        }
    }
}
