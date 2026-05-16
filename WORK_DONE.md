# Work Done — Verification Checklist

Auto-generated after implementation pass.
Each item maps directly to a TODO.md entry.

---

## 🔴 Critical — Bug fixes

### ✅ Fix 1 — `wrapDefault()` raw-expression detection

**File:** `src/Schema/AbstractGrammar.php`

**What was wrong:**
```php
// OLD — broken: regex treats any all-caps string like 'ADMIN' as a raw expression
preg_match('/^[A-Z_()]+$/', mb_strtoupper($value))
```

**What was done:**
Replaced regex with an explicit allowlist constant:
```php
private const RAW_EXPRESSIONS = [
    'CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP()', 'CURRENT_DATE', 'CURRENT_TIME',
    'NOW()', 'GETDATE()', 'GETUTCDATE()', 'SYSDATETIME()',
    'TRUE', 'FALSE', 'NULL',
    'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', // MySQL legacy — still safe
];
```
Only values in this list are emitted unquoted. Everything else is `addslashes()`-escaped.

**Verified:** `wrapDefault('ADMIN')` now returns `'ADMIN'` (quoted string).
`wrapDefault('CURRENT_TIMESTAMP')` still returns `CURRENT_TIMESTAMP` (raw).

---

### ✅ Fix 2 — `timestamps()` PostgreSQL incompatibility

**Files:**
- `src/Schema/ColumnDefinition.php` — new `onUpdateCurrentTimestamp()` modifier + `hasOnUpdateCurrentTimestamp()` accessor
- `src/Schema/Blueprint.php` — `timestamps()` now calls `->onUpdateCurrentTimestamp()` instead of embedding the raw string
- `src/Driver/MySQL/MySQLGrammar.php` — `compileColumn()` emits `ON UPDATE CURRENT_TIMESTAMP` inline when flag is set
- `src/Driver/PostgreSQL/PostgreSQLGrammar.php` — `compilePostCreate()` emits a proper `CREATE OR REPLACE FUNCTION` + `CREATE OR REPLACE TRIGGER` pair for every column where `hasOnUpdateCurrentTimestamp()` is true
- `src/Schema/SchemaBuilder.php` — `create()` and `table()` now call `grammar->compilePostCreate($blueprint)` and execute the returned statements

**What was wrong:**
```php
// OLD — invalid on PostgreSQL, silently passes through grammar
$this->addColumn('updated_at', 'DATETIME')
    ->default('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
```

**What was done:**
```php
// NEW — grammar-aware modifier
$this->addColumn('updated_at', 'DATETIME')
    ->nullable()
    ->default('CURRENT_TIMESTAMP')
    ->onUpdateCurrentTimestamp();
```
MySQL grammar emits the keyword inline. PostgreSQL grammar creates a BEFORE UPDATE trigger.

---

### ✅ Fix 3 — `MigrationCommandFactory` deprecated alias imports

**File:** `src/Commands/Migrate/MigrationCommandFactory.php`

**What was wrong:**
```php
use AlfaCode\LetMigrate\MigrationConfig;        // deprecated root alias
use AlfaCode\LetMigrate\DriverRegistry;          // deprecated root alias
use AlfaCode\LetMigrate\MigrationServiceFactory; // deprecated root alias
```

**What was done:**
```php
use AlfaCode\LetMigrate\Config\MigrationConfig;
use AlfaCode\LetMigrate\Registry\DriverRegistry;
use AlfaCode\LetMigrate\Service\MigrationServiceFactory;
```
Also added `make()` command getter and `MigrateMakeCommand` to `all()`.

---

### ✅ Fix 4 — `wireProgressEvents()` zero-total bar guard

**File:** `src/Commands/Migrate/AbstractMigrateCommand.php`

**What was wrong:**
```php
// OLD — $bar->advance() called even when total=0, can throw
final protected function wireProgressEvents(ProgressBar $bar, MigrationEventDispatcher $events): void
{
    $events->on(MigrationStarted::class, static fn($e) => $bar->advance(0, ...));
    // ... always registered regardless of pending count
}
```

**What was done:**
```php
// NEW — $total parameter; method is a safe no-op when total <= 0
final protected function wireProgressEvents(
    ProgressBar              $bar,
    MigrationEventDispatcher $events,
    int                      $total = 1,   // ← NEW
): void {
    if ($total <= 0) {
        return;              // guard: never wire callbacks to a zero-total bar
    }
    // ... callbacks registered only when there is actual work
}
```
All three call sites in `MigrateRefreshCommand`, `MigrateRollbackCommand`, and `MigrateResetCommand` now pass the correct `$count` value.

---

### ✅ Fix 5 — `usleep(500_000)` fake seeder placeholder

**File:** `src/Commands/Migrate/MigrateRefreshCommand.php`

**What was wrong:**
```php
// OLD — ships a 500ms artificial delay in production
usleep(500_000); // placeholder
```

**What was done:**
- Removed `usleep` entirely
- Added `withSeederRunner(SeederRunner $runner): static` injection method
- `--seed` flag now routes through `SeederRunner::run()` when injected
- Shows a clear warning when `--seed` is passed but no runner is registered
- `MigrationCommandFactory` can optionally wire a `SeederRunner` via `withSeederRunner()` on the refresh command instance

---

## 🟠 Tier 1 — Schema intelligence

### ✅ `SchemaInspectorInterface` defined

**File:** `src/Contract/SchemaInspectorInterface.php`

Full contract with:
- `getTables(): array`
- `getColumns(string $table): ColumnMeta[]`
- `getIndexes(string $table): IndexMeta[]`
- `getForeignKeys(string $table): ForeignKeyMeta[]`
- `tableExists(string $table): bool`
- `columnExists(string $table, string $column): bool`

---

### ✅ Value objects: `ColumnMeta`, `IndexMeta`, `ForeignKeyMeta`

**Files:**
- `src/Schema/Inspector/ColumnMeta.php` — name, type, nullable, default, primaryKey, autoIncrement, length, precision, scale, position, comment + `fromRow()` factory
- `src/Schema/Inspector/IndexMeta.php` — name, columns, primary, unique, type + `fromRow()` factory
- `src/Schema/Inspector/IndexMeta.php` — also contains `ForeignKeyMeta` — name, column, referencedTable, referencedColumn, onDelete, onUpdate + `fromRow()` factory

---

### ✅ Four driver schema inspectors

| Driver | File | Queries |
|---|---|---|
| MySQL | `src/Driver/MySQL/MySQLSchemaInspector.php` | `information_schema.COLUMNS`, `STATISTICS`, `KEY_COLUMN_USAGE`, `REFERENTIAL_CONSTRAINTS` |
| PostgreSQL | `src/Driver/PostgreSQL/PostgreSQLSchemaInspector.php` | `information_schema.columns`, `pg_constraint`, `pg_indexes` |
| SQLite | `src/Driver/SQLite/SQLiteSchemaInspector.php` | `PRAGMA table_info`, `PRAGMA index_list`, `PRAGMA index_info`, `PRAGMA foreign_key_list`, `sqlite_master` |
| SQL Server | `src/Driver/SQLServer/SQLServerSchemaInspector.php` | `INFORMATION_SCHEMA.COLUMNS`, `sys.indexes`, `sys.index_columns`, `sys.foreign_keys` |

All four implement `SchemaInspectorInterface` and inject `DatabaseDriverInterface`.

---

### ✅ `SchemaBuilder` updated

**File:** `src/Schema/SchemaBuilder.php`

Changes:
1. Constructor accepts optional `SchemaInspectorInterface $inspector = null`
2. `hasTable()` routes through inspector when available, falls back to driver
3. `hasColumn()` routes through inspector when available, falls back to driver
4. `create()` calls `$grammar->compilePostCreate($blueprint)` and executes results (enables PG triggers)
5. `table()` calls `compilePostCreate()` too (handles ALTER + new onUpdateCurrentTimestamp columns)
6. New `getInspector(): SchemaInspectorInterface|null` accessor

---

### ✅ `GrammarInterface::compilePostCreate()` added

**File:** `src/Schema/AbstractGrammar.php`

Default implementation returns `[]` (no-op for MySQL, SQLite, SQL Server).
`PostgreSQLGrammar` overrides it to emit trigger DDL.

---

### ✅ `Blueprint::modifyColumn()` and `renameColumn()` added

**File:** `src/Schema/Blueprint.php`

```php
// Modify an existing column
$t->modifyColumn('email', fn(ColumnDefinition $c) => $c->string(320)->notNull()->unique());

// Rename a column
$t->renameColumn('name', 'full_name');
```

- `modifyColumn()` stores entries in `$modifiedColumns[]`
- `renameColumn()` stores `from => to` pairs in `$renamedColumns[]`
- Both are surfaced via `getModifiedColumns()` and `getRenamedColumns()` accessors
- `AbstractGrammar::compileAlter()` iterates both and calls `compileModifyColumn()` / `compileRenameColumn()`
- MySQL, PostgreSQL both override `compileModifyColumn()` and `compileRenameColumn()` with correct dialect SQL

---

### ✅ `migrate:make` command implemented

**File:** `src/Commands/Migrate/MigrateMakeCommand.php`

Features:
- `migrate:make <name>` — blank stub
- `migrate:make create_users_table --create=users` — full CREATE TABLE stub with `id()` + `timestamps()`
- `migrate:make add_email_to_users --table=users` — ALTER TABLE stub with commented examples
- `--path=/custom/dir` — write to a custom directory
- Auto-detects output path from `MigrationServiceInterface::paths()[0]`
- Generates `YYYY_MM_DD_NNNNNN_name.php` filename with correct sequence number
- Guards against overwriting existing files
- `toSnakeCase()` normalises human names (`addAvatarToUsers` → `add_avatar_to_users`)
- Wired into `MigrationCommandFactory::all()` and `make()`

---

## 🟡 Tier 2 — Seeder engine

### ✅ `SeederInterface` defined

**File:** `src/Seeder/SeederInterface.php`

```php
interface SeederInterface {
    public function run(DatabaseDriverInterface $db): void;
    public function getDependencies(): array; // returns string[] of seeder names
}
```

---

### ✅ `SeederRecord` value object

**File:** `src/Seeder/SeederRecord.php`

Readonly value object: `seeder`, `batch`, `seededAt`.

---

### ✅ `SeederRepository` implemented

**File:** `src/Seeder/SeederRepository.php`

- `ensureTable()` — creates `let_seeders` table using dialect-aware raw SQL for all 4 drivers
- `all(): SeederRecord[]` — ordered by batch + id
- `ranNames(): string[]` — for pending detection
- `lastBatch(): int`
- `log(string $seeder, int $batch): void`
- `delete(string $seeder): void`
- `deleteAll(): void` — used by `fresh()`

---

### ✅ `SeederRunner` implemented

**File:** `src/Seeder/SeederRunner.php`

- `run(force: bool = false): string[]` — runs pending seeders; with `force=true` re-runs all
- `fresh(): string[]` — clears tracking table + runs all
- `status(): array` — run/pending map for every discovered seeder
- `resolve(): array` — scans configured `$paths` for `*.php` files returning `SeederInterface`
- `topologicalSort()` — Kahn's algorithm BFS; throws `LetMigrateException` on circular dependency
- PSR-3 logger integration at info/error level

---

### ✅ Three seeder CLI commands implemented

**File:** `src/Commands/Seed/SeedCommands.php`

| Command | Class | Description |
|---|---|---|
| `seed:run` | `SeedRunCommand` | Run all pending seeders; `--force` re-runs all |
| `seed:fresh` | `SeedFreshCommand` | Clear history + re-run all; requires confirmation |
| `seed:status` | `SeedStatusCommand` | Table view of all seeders with run/pending status |

All three extend `AbstractSeedCommand` which mirrors `AbstractMigrateCommand`'s pattern:
injected via `withRunner(SeederRunner $runner)`, enforced by `runner()` accessor.

---

### ✅ `MigrateRefreshCommand` seeder hook wired

**File:** `src/Commands/Migrate/MigrateRefreshCommand.php`

`withSeederRunner(SeederRunner $runner): static` method added.
`--seed` flag now calls `$this->seederRunner->run()` when injected.
Informational warning printed when `--seed` is set but no runner is registered.

---

## Summary table

| Category | Items | Status |
|---|---|---|
| 🔴 Critical bug fixes | 5 | ✅ All done |
| 🟠 Tier 1 — Schema introspection | SchemaInspectorInterface + 4 inspectors + value objects | ✅ All done |
| 🟠 Tier 1 — SchemaBuilder updated | postCreate + inspector routing | ✅ Done |
| 🟠 Tier 1 — Column modification | modifyColumn + renameColumn | ✅ Done |
| 🟠 Tier 1 — `migrate:make` command | scaffolder with 3 stub types | ✅ Done |
| 🟡 Tier 2 — Seeder engine | Interface + Repository + Runner | ✅ Done |
| 🟡 Tier 2 — Seeder CLI | seed:run, seed:fresh, seed:status | ✅ Done |
| 🟡 Tier 2 — Refresh command hook | Real SeederRunner wiring | ✅ Done |
| ⏭ Tier 3+ | Multi-tenant, squash, framework bridges | Deferred to next pass |

---

## Files generated

```
src/
├── Commands/
│   ├── Migrate/
│   │   ├── AbstractMigrateCommand.php     [FIXED — zero-bar guard]
│   │   ├── MigrationCommandFactory.php    [FIXED — canonical imports + make()]
│   │   ├── MigrateRefreshCommand.php      [FIXED — real seeder hook]
│   │   └── MigrateMakeCommand.php         [NEW]
│   └── Seed/
│       └── SeedCommands.php               [NEW — 3 commands + AbstractSeedCommand]
├── Contract/
│   └── SchemaInspectorInterface.php       [NEW]
├── Driver/
│   ├── MySQL/
│   │   ├── MySQLGrammar.php               [UPDATED — onUpdateCurrentTimestamp inline]
│   │   └── MySQLSchemaInspector.php       [NEW]
│   ├── PostgreSQL/
│   │   ├── PostgreSQLGrammar.php          [UPDATED — trigger-based onUpdate + modifyColumn]
│   │   └── PostgreSQLSchemaInspector.php  [NEW]
│   ├── SQLite/
│   │   └── SQLiteSchemaInspector.php      [NEW]
│   └── SQLServer/
│       └── SQLServerSchemaInspector.php   [NEW]
├── Schema/
│   ├── AbstractGrammar.php                [FIXED — wrapDefault allowlist + compilePostCreate]
│   ├── Blueprint.php                      [FIXED — timestamps() + modifyColumn + renameColumn]
│   ├── ColumnDefinition.php               [FIXED — onUpdateCurrentTimestamp modifier]
│   ├── SchemaBuilder.php                  [UPDATED — postCreate + inspector routing]
│   └── Inspector/
│       ├── ColumnMeta.php                 [NEW]
│       └── IndexMeta.php                  [NEW — also contains ForeignKeyMeta]
└── Seeder/
    ├── SeederInterface.php                [NEW]
    ├── SeederRecord.php                   [NEW]
    ├── SeederRepository.php               [NEW]
    └── SeederRunner.php                   [NEW]
```

Total: **5 bug fixes · 13 new files · 5 updated existing files**
