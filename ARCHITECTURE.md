# LetMigrate — Enterprise Architecture

This document describes the layered architecture of `LetMigrate` and the
service-boundary rule that governs how components interact.

---

## Layer Map

```
┌─────────────────────────────────────────────────────────────────┐
│  External Code (CLI commands, HTTP controllers, framework code)  │
│   depends ONLY on ↓                                              │
├─────────────────────────────────────────────────────────────────┤
│  AlfaCode\LetMigrate\LetMigrate  (facade, entry point)          │
│   delegates to ↓                                                 │
├─────────────────────────────────────────────────────────────────┤
│  Contract\MigrationServiceInterface                              │
│   single concrete implementation ↓                               │
├─────────────────────────────────────────────────────────────────┤
│  Service\MigrationService  ← SOLE repository owner              │
│   ↕  (private)                                                   │
│  Contract\MigrationRepositoryInterface                           │
│   (concrete: Repository\DatabaseMigrationRepository)             │
├─────────────────────────────────────────────────────────────────┤
│  Runner\MigrationRunner  (internal orchestrator)                 │
│   ↕                                                              │
│  Resolver\FilesystemMigrationResolver                            │
│  Schema\SchemaBuilder                                            │
│  Registry\DriverRegistry                                         │
├─────────────────────────────────────────────────────────────────┤
│  Driver\{MySQL,PostgreSQL,SQLite,SQLServer}\{Driver,Grammar}     │
└─────────────────────────────────────────────────────────────────┘
```

---

## Directory Structure

```
src/
├── LetMigrate.php                        ← Public facade (start here)
├── aliases.php                           ← BC alias registrations (loaded via autoload)
│
├── Config/
│   └── MigrationConfig.php              ← Typed, immutable config value object
│
├── Contract/
│   ├── DatabaseDriverInterface.php       ← Low-level DB contract
│   ├── MigrationInterface.php            ← Every migration file implements this
│   ├── MigrationRepositoryInterface.php  ← Persistence contract (sealed inside service)
│   ├── MigrationResolverInterface.php    ← File discovery contract
│   ├── MigrationRunnerInterface.php      ← Internal orchestrator contract
│   ├── MigrationServiceInterface.php     ← PUBLIC service contract (only this leaks out)
│   └── SchemaBuilderInterface.php        ← Exposed to migration up()/down() methods
│
├── Driver/
│   ├── AbstractPdoDriver.php
│   ├── MySQL/   {MySQLDriver, MySQLGrammar}
│   ├── PostgreSQL/ {PostgreSQLDriver, PostgreSQLGrammar}
│   ├── SQLite/  {SQLiteDriver, SQLiteGrammar}
│   └── SQLServer/ {SQLServerDriver, SQLServerGrammar}
│
├── Event/
│   ├── MigrationEvent.php               ← Abstract base
│   ├── MigrationStarted.php
│   ├── MigrationFinished.php
│   ├── MigrationFailed.php
│   ├── MigrationsCompleted.php
│   └── MigrationEventDispatcher.php
│
├── Exception/
│   ├── LetMigrateException.php
│   ├── ConnectionException.php
│   ├── MigrationException.php
│   └── QueryException.php
│
├── Migration/
│   ├── MigrationRecord.php              ← Immutable tracking-table row value object
│   └── MigrationResult.php             ← Immutable run/rollback result value object
│
├── Registry/
│   └── DriverRegistry.php              ← Driver + grammar factory / registry
│
├── Repository/
│   └── DatabaseMigrationRepository.php ← INTERNAL — access via MigrationService ONLY
│
├── Resolver/
│   └── FilesystemMigrationResolver.php ← Discovers .php migration files
│
├── Runner/
│   └── MigrationRunner.php             ← INTERNAL orchestrator — used by MigrationService
│
├── Schema/
│   ├── AbstractGrammar.php
│   ├── Blueprint.php
│   ├── ColumnDefinition.php
│   ├── ForeignKeyDefinition.php
│   ├── GrammarInterface.php
│   ├── IndexDefinition.php
│   └── SchemaBuilder.php
│
└── Service/
    ├── MigrationService.php            ← SOLE authorised repository holder
    └── MigrationServiceFactory.php     ← SOLE authorised place to instantiate repository

tests/
├── Unit/
│   ├── BlueprintTest.php
│   ├── DriverRegistryTest.php
│   ├── FilesystemMigrationResolverTest.php
│   ├── GrammarTest.php
│   ├── MigrationConfigAndResultTest.php
│   ├── MigrationConfigTest.php
│   ├── MigrationEventDispatcherTest.php
│   ├── MigrationRecordTest.php
│   ├── MigrationResultTest.php
│   ├── MigrationServiceFactoryTest.php
│   └── MigrationServiceTest.php
│
└── Integration/
    ├── MigrationRunnerTest.php
    └── SchemaBuilderSQLiteTest.php
```

---

## The Service Boundary Rule

### What it enforces

> **Only `MigrationService` may hold a reference to `MigrationRepositoryInterface`.**
> All other code must depend on `MigrationServiceInterface`.

### Why it exists

Without this rule the repository leaks into commands, factories, and framework
adapters. Every caller then couples itself to persistence details (table names,
SQL dialects, transaction handling) that belong exclusively to the service layer.

### How it is implemented

1. `DatabaseMigrationRepository` lives in `Repository\` — it has no public
   API beyond `MigrationRepositoryInterface`.

2. `MigrationServiceFactory` is the **only** class that instantiates
   `DatabaseMigrationRepository`. It immediately passes the instance into
   `MigrationService` via the constructor.

3. `MigrationService` stores the repository in a **private readonly** property
   with no public accessor. PHPUnit's `MigrationServiceTest` asserts this.

4. `MigrationRunnerInterface` is an **internal** contract used only between
   `MigrationService` and `Runner\MigrationRunner`. External code must not
   depend on it.

5. `LetMigrate` (the facade) holds a `MigrationServiceInterface` reference —
   never the runner or repository directly.

### Dependency graph

```
External → LetMigrate → MigrationServiceInterface
                              ↓
                       MigrationService (private repo)
                         ├── MigrationRepositoryInterface (private)
                         │       └── DatabaseMigrationRepository
                         ├── MigrationRunner (internal)
                         │       ├── MigrationResolverInterface
                         │       └── SchemaBuilderInterface
                         └── MigrationEventDispatcher
```

### Violation examples

```php
// ❌ Never do this — bypasses the service boundary
$repo   = new DatabaseMigrationRepository($driver, $grammar);
$runner = new MigrationRunner($repo, $resolver, $schema);
$runner->run();

// ✅ Correct — always go through the factory / facade
$service = MigrationServiceFactory::create($registry, $config);
$service->run();

// ✅ Or use the LetMigrate facade
$engine = LetMigrate::configure($config);
$engine->run();
```

---

## Backward Compatibility

All classes that existed in the root `AlfaCode\LetMigrate\` namespace before
the enterprise refactor are registered as `class_alias` entries in
`src/aliases.php`. This file is loaded automatically by Composer's `files`
autoloader key so no import changes are required in existing code.

The aliases map:

| Old (deprecated)                              | New (canonical)                                      |
|-----------------------------------------------|------------------------------------------------------|
| `AlfaCode\LetMigrate\MigrationConfig`         | `AlfaCode\LetMigrate\Config\MigrationConfig`         |
| `AlfaCode\LetMigrate\DriverRegistry`          | `AlfaCode\LetMigrate\Registry\DriverRegistry`        |
| `AlfaCode\LetMigrate\MigrationResult`         | `AlfaCode\LetMigrate\Migration\MigrationResult`      |
| `AlfaCode\LetMigrate\MigrationRecord`         | `AlfaCode\LetMigrate\Migration\MigrationRecord`      |
| `AlfaCode\LetMigrate\DatabaseMigrationRepository` | `AlfaCode\LetMigrate\Repository\DatabaseMigrationRepository` |
| `AlfaCode\LetMigrate\FilesystemMigrationResolver` | `AlfaCode\LetMigrate\Resolver\FilesystemMigrationResolver`   |
| `AlfaCode\LetMigrate\MigrationRunner`         | `AlfaCode\LetMigrate\Runner\MigrationRunner`         |

All root-namespace aliases are marked `@deprecated` and will be removed in v2.0.

---

## Adding a New Database Driver

1. Create `src/Driver/MyDB/MyDBDriver.php` extending `AbstractPdoDriver`.
2. Create `src/Driver/MyDB/MyDBGrammar.php` extending `AbstractGrammar`.
3. Register via `DriverRegistry::extendDriver('mydb', fn($cfg) => new MyDBDriver($cfg))`.
4. Register grammar via `DriverRegistry::extendGrammar('mydb', fn($cfg) => new MyDBGrammar())`.
5. Use `LetMigrate::configure(['driver' => 'mydb', ...])` as normal.

No changes to any service, factory, or runner are needed.
