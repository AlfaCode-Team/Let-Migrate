# LetMigrate

> **Enterprise-grade, multi-database PHP migration engine.**  
> Standalone namespace `AlfaCode\LetMigrate` — works independently of any framework.

---

## Features

- **Four databases out of the box** — MySQL/MariaDB, PostgreSQL, SQLite, SQL Server
- **Per-driver migration folders** — keep dialect-specific DDL neatly separated
- **Fluent Blueprint API** — write migrations once, let the Grammar translate to correct DDL
- **Batched runs & rollbacks** — each `run()` is a batch; `rollback(steps: N)` reverses N batches
- **Full transaction support** — every migration runs inside its own transaction; failures auto-rollback
- **Lifecycle events** — hook into `MigrationStarted`, `MigrationFinished`, `MigrationFailed`, `MigrationsCompleted`
- **PSR-3 logger support** — pass any PSR-3 logger for structured output
- **Extensible** — register custom drivers and grammars via `DriverRegistry::extendDriver()`
- **Pretend mode** — log SQL without executing (safe for CI previews)
- **Zero framework dependencies** — only requires `psr/log ^3.0` and PHP 8.2+

---

## Directory Structure

```
src/
├── LetMigrate.php                  ← Main facade (start here)
├── Config/
│   └── MigrationConfig.php        ← Typed config value object
├── Contract/
│   ├── DatabaseDriverInterface.php ← Low-level DB contract
│   ├── MigrationInterface.php      ← Every migration implements this
│   ├── MigrationRepositoryInterface.php
│   ├── MigrationResolverInterface.php
│   └── SchemaBuilderInterface.php  ← Exposed to migrations
├── Driver/
│   ├── AbstractPdoDriver.php       ← Shared PDO logic
│   ├── MySQL/
│   │   ├── MySQLDriver.php
│   │   └── MySQLGrammar.php        ← MySQL/MariaDB DDL dialect
│   ├── PostgreSQL/
│   │   ├── PostgreSQLDriver.php
│   │   └── PostgreSQLGrammar.php   ← PostgreSQL DDL dialect
│   ├── SQLite/
│   │   ├── SQLiteDriver.php
│   │   └── SQLiteGrammar.php       ← SQLite DDL dialect
│   └── SQLServer/
│       ├── SQLServerDriver.php
│       └── SQLServerGrammar.php    ← T-SQL dialect
├── Event/
│   ├── MigrationEvent.php          ← Base event class
│   ├── Events.php                  ← All four concrete events
│   └── MigrationEventDispatcher.php
├── Exception/
│   ├── LetMigrateException.php     ← Root exception
│   ├── ConnectionException.php
│   ├── MigrationException.php
│   └── QueryException.php
├── Migration/
│   ├── DatabaseMigrationRepository.php
│   ├── FilesystemMigrationResolver.php
│   ├── MigrationRecord.php
│   ├── MigrationResult.php
│   └── MigrationRunner.php         ← Core orchestrator
├── Registry/
│   └── DriverRegistry.php          ← Driver + grammar factory
└── Schema/
    ├── AbstractGrammar.php          ← Shared DDL compilation
    ├── Blueprint.php                ← Fluent table definition
    ├── ColumnDefinition.php
    ├── ForeignKeyDefinition.php
    ├── GrammarInterface.php
    ├── IndexDefinition.php
    └── SchemaBuilder.php            ← Executes Blueprint → DDL

migrations/
├── mysql/        ← MySQL-specific migration files
├── postgresql/   ← PostgreSQL-specific migration files
├── sqlite/       ← SQLite-specific migration files
└── sqlserver/    ← SQL Server-specific migration files
```

---

## Quick Start

### Installation

```bash
composer require alfacode-team/let-migrate
```

### Bootstrap (any PHP project)

```php
use AlfaCode\LetMigrate\LetMigrate;

$engine = LetMigrate::configure([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'my_app',
    'username' => 'root',
    'password' => 'secret',
    'paths'    => [__DIR__ . '/migrations/mysql'],
]);

$result = $engine->run();
echo $result->summary();
// "3 migration(s) applied in batch 1."
```

### Per-driver paths

```php
$engine = LetMigrate::configure([
    'driver' => 'pgsql',
    'host'   => '127.0.0.1',
    // ...
    'paths'  => [
        __DIR__ . '/migrations/postgresql',   // PostgreSQL DDL
        __DIR__ . '/migrations/shared',       // driver-agnostic seeds
    ],
]);
```

---

## Writing Migrations

Every migration file must return an anonymous class (or a named class) implementing `MigrationInterface`:

```php
<?php
// migrations/mysql/2024_01_15_000001_create_users_table.php

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

return new class implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('users', static function (Blueprint $t): void {
            $t->id();
            $t->string('email', 191)->unique()->notNull();
            $t->string('password');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();

            $t->index(['email'], 'idx_email');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('users');
    }
};
```

### Filename Convention

```
YYYY_MM_DD_NNNNNN_description.php
2024_01_15_000001_create_users_table.php
2024_01_15_000002_create_posts_table.php
2024_03_22_000001_add_avatar_to_users.php
```

Files are sorted lexicographically, so the timestamp prefix guarantees correct order.

---

## Blueprint API

### Numeric Columns

| Method | SQL Type |
|---|---|
| `$t->id()` | `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` |
| `$t->tinyInteger('x')` | `TINYINT` |
| `$t->smallInteger('x')` | `SMALLINT` |
| `$t->integer('x')` | `INT` |
| `$t->bigInteger('x')` | `BIGINT` |
| `$t->decimal('price', 8, 2)` | `DECIMAL(8,2)` |
| `$t->float('rate')` | `FLOAT` |
| `$t->double('score')` | `DOUBLE` |

### String Columns

| Method | SQL Type |
|---|---|
| `$t->char('code', 36)` | `CHAR(36)` |
| `$t->string('name', 255)` | `VARCHAR(255)` |
| `$t->text('body')` | `TEXT` |
| `$t->longText('content')` | `LONGTEXT` |

### Date / Time Columns

| Method | SQL Type |
|---|---|
| `$t->date('born_on')` | `DATE` |
| `$t->dateTime('happened_at')` | `DATETIME` |
| `$t->timestamp('deleted_at')` | `TIMESTAMP` |
| `$t->timestamps()` | `created_at` + `updated_at` |
| `$t->softDeletes()` | `deleted_at DATETIME NULL` |

### Special Columns

| Method | SQL Type |
|---|---|
| `$t->boolean('flag')` | `TINYINT(1)` |
| `$t->json('metadata')` | `JSON` |
| `$t->enum('status', ['a','b'])` | `ENUM('a','b')` |
| `$t->uuid('id')` | `CHAR(36)` |
| `$t->binary('data')` | `BLOB` |

### Column Modifiers

```php
$t->string('name')
    ->nullable()         // allow NULL
    ->notNull()          // NOT NULL (default)
    ->unsigned()         // UNSIGNED (numeric)
    ->default('hello')   // DEFAULT value
    ->comment('hint')    // COMMENT
    ->after('email')     // column position (MySQL)
    ->first()            // place first (MySQL)
    ->unique()           // inline UNIQUE constraint
    ->primary()          // inline PRIMARY KEY
    ->autoIncrement();   // AUTO_INCREMENT
```

### Indexes

```php
$t->index(['col_a', 'col_b'], 'idx_name');
$t->unique(['email'], 'uq_email');
$t->primary(['user_id', 'role_id']);   // composite PK
```

### Foreign Keys

```php
$t->foreign('edition_id')
    ->references('id')
    ->on('vote_editions')
    ->onDelete('CASCADE')      // or cascadeOnDelete()
    ->onUpdate('RESTRICT')     // or restrictOnDelete()
    ->name('fk_edition');      // optional constraint name
```

---

## Runner Operations

```php
// Apply all pending migrations
$result = $engine->run();

// Roll back the last batch
$result = $engine->rollback();

// Roll back the last 3 batches
$result = $engine->rollback(steps: 3);

// Roll back ALL migrations (dev only)
$result = $engine->reset();

// Reset then re-run everything (dev only)
$result = $engine->refresh();

// Show status of all discovered migrations
$status = $engine->status();
// ['2024_01_01_create_users' => ['status' => 'applied', 'batch' => 1], ...]

// List only pending migrations
$pending = $engine->pending();
```

---

## Events

```php
use AlfaCode\LetMigrate\Event\MigrationFailed;
use AlfaCode\LetMigrate\Event\MigrationFinished;
use AlfaCode\LetMigrate\Event\MigrationStarted;
use AlfaCode\LetMigrate\Event\MigrationsCompleted;

$engine->events()->on(MigrationStarted::class, function (MigrationStarted $e): void {
    echo "⏳ Starting: {$e->migration} ({$e->direction})\n";
});

$engine->events()->on(MigrationFinished::class, function (MigrationFinished $e): void {
    echo "✔ Done: {$e->migration}\n";
});

$engine->events()->on(MigrationFailed::class, function (MigrationFailed $e): void {
    // Report to error tracking
    Sentry::captureException($e->exception);
});

$engine->events()->on(MigrationsCompleted::class, function (MigrationsCompleted $e): void {
    echo $e->result->summary();
});

$engine->run();
```

---

## Custom Drivers

```php
use AlfaCode\LetMigrate\Registry\DriverRegistry;

// Register a custom PDO-backed driver
DriverRegistry::extendDriver('cockroach', fn($cfg) => new CockroachDBDriver($cfg));

// Register a matching grammar
DriverRegistry::extendGrammar('cockroach', fn($cfg) => new CockroachDBGrammar());

// Now use it normally
$engine = LetMigrate::configure([
    'driver'   => 'cockroach',
    'host'     => '127.0.0.1',
    'database' => 'mydb',
    'paths'    => [__DIR__ . '/migrations/cockroach'],
]);
```

---

## Pretend Mode

Preview the SQL that would be executed without touching the database:

```php
$engine = LetMigrate::configure([
    // ...
    'pretend' => true,
], $logger); // Pass a PSR-3 logger to capture the output

$engine->run(); // Logs SQL, executes nothing
```

---

## PSR-3 Logging

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('migrations');
$logger->pushHandler(new StreamHandler('php://stdout'));

$engine = LetMigrate::configure($config, $logger);
$engine->run();
// [LetMigrate] Migrating:  2024_01_01_000001_create_users_table
// [LetMigrate] Migrated:   2024_01_01_000001_create_users_table
// [LetMigrate] 1 migration(s) applied in batch 1.
```

---

## Database-Specific Notes

### MySQL / MariaDB
- InnoDB engine, utf8mb4 charset by default
- Backtick identifier quoting
- `AUTO_INCREMENT`, `FOREIGN KEY … ON DELETE CASCADE`

### PostgreSQL
- Double-quote identifier quoting
- `BIGSERIAL` / `SERIAL` for auto-increment
- `TIMESTAMP` instead of `DATETIME`, `JSONB` instead of `JSON`
- FK checks via `SET session_replication_role`

### SQLite
- All types mapped to SQLite affinity groups (INTEGER, TEXT, REAL, BLOB)
- `INTEGER PRIMARY KEY AUTOINCREMENT` for auto-increment
- FK checks via `PRAGMA foreign_keys`
- DDL is transactional (rolled-back migrations are fully undone)

### SQL Server
- Square-bracket `[identifier]` quoting
- `IDENTITY(1,1)` for auto-increment
- `NVARCHAR` for Unicode strings, `DATETIME2` for datetimes
- FK checks via `sp_MSforeachtable`

---

## Testing

```bash
composer install
vendor/bin/phpunit                  # all tests (Unit + Integration)
vendor/bin/phpunit --testsuite Unit # unit only (no DB needed)
vendor/bin/phpunit --testsuite Integration  # requires SQLite ext
vendor/bin/phpstan analyse          # static analysis level 8
```

Integration tests use an **in-memory SQLite database** — no MySQL or PostgreSQL installation required.

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | `^8.2` |
| `psr/log` | `^3.0` |
| PDO extension | any supported driver |
| `pdo_sqlite` | for integration tests |

---

## License

MIT © 2026 AlfaCode Team
