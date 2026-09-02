# Changelog

All notable changes to `let-migrate` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project follows [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

### Fixed

- **`ALTER TABLE` compiled MySQL syntax for every driver.** Additions were
  batched into one comma-separated statement and indexes added with `ADD KEY` —
  neither of which SQLite, PostgreSQL or SQL Server accept — and drops ran
  columns BEFORE the indexes over them, which only MySQL tolerates. A rollback
  written in the correct order was reordered by the compiler into one that could
  not run anywhere but MySQL. Grammars now opt in to batching
  (`supportsMultiClauseAlter()`) and inline indexes
  (`supportsInlineIndexInAlter()`); the portable default is one statement per
  change, which every engine accepts.
- **PostgreSQL: a boolean default compiled to `1` / `0`.** `BOOLEAN DEFAULT 1`
  is rejected — *column is of type boolean but default expression is of type
  integer*. Emits `TRUE` / `FALSE`. SQL Server keeps `1` / `0` deliberately: its
  `BIT` type rejects the keywords.
- **PostgreSQL: `modifyColumn()` compiled three `;`-joined statements** into one
  clause, which the extended query protocol refuses — *cannot insert multiple
  commands into a prepared statement* — so the migration died before applying
  any DDL. Now one statement with comma-separated subcommands, which is also the
  form that lets PostgreSQL order the subcommands by pass.
- **PostgreSQL: every schema lookup silently matched nothing.** The driver and
  inspector used libpq's `$1` placeholders, which PDO does not understand and
  does not reject, so `tableExists()` answered `false` for a table with seven
  columns and the inspector reported no columns, indexes or foreign keys. All
  queries now use `?`. `getColumns()` binds schema/table twice because `?` is
  positional and cannot be reused the way `$1` can.
- **SQL Server: `ALTER TABLE … ADD COLUMN` is a syntax error.** T-SQL has no
  `COLUMN` keyword there. Added `addColumnKeyword()`, overridden to `ADD`.
- **SQL Server: `ON DELETE RESTRICT` is not implemented.** Its referential
  actions are `NO ACTION`, `CASCADE`, `SET NULL`, `SET DEFAULT`. Added
  `mapReferentialAction()`, identity by default, mapping `RESTRICT` →
  `NO ACTION` on SQL Server only.
- **SQLite: `modifyColumn()` could not work at all.** The rebuild SQLite
  requires was driven from the ALTER blueprint, which holds only the delta, so
  it compiled `CREATE TABLE "__tmp_users" ()`. `SchemaBuilder` now reconstructs
  the complete table from the `SchemaInspector` plus the delta, carries existing
  indexes across (dropping a table drops its indexes with it), and unwraps
  defaults so a literal is not re-quoted on every rebuild. The grammar's
  single-column `compileModifyColumn()` — which would have rebuilt the table
  with only the modified column — now refuses with the route to take.
- **SQLite: adding a foreign key to an existing table** produced
  `near "FOREIGN": syntax error`. There is no `ALTER TABLE … ADD CONSTRAINT` in
  SQLite; it now fails early naming the column and the fix.
- **Seeding a second database in one run died with "Cannot redeclare class".**
  `SeederRunner` `require`d every seeder unconditionally, and `require` executes
  the file, so a seeder declaring a named class could be loaded only once per
  process. It now skips the require when the class is already in memory;
  `return new class {...}` files declare no named class and are still required
  every time, as they must be.
- **`MigrationConfig::fromArray()`: singular `path` overrode plural `paths`.**
  The singular is a fallback and now applies only when no `paths` were given.
  Passing both silently ran the one path and ignored the array — failing by
  doing less work rather than by erroring.
- **`Blueprint::dropColumn()` accepted only one column**, so
  `dropColumn('a', 'b')` silently dropped only `a`. Now variadic;
  `dropColumn()`, `dropIndex()` and `dropForeign()` return `$this`.

### Added

- **`ColumnDefinition::useCurrent()` / `useCurrentOnUpdate()`** — Laravel-parity
  aliases of `default('CURRENT_TIMESTAMP')` and `onUpdateCurrentTimestamp()`.
- **`Blueprint::bigIncrements()`** — Laravel-parity alias of `id()`.
- **`Commands\Support\StatusRenderer`** — presenter for `migrate:status`:
  normalised data, aligned table lines and JSON from one source, so the human
  and `--json` views cannot disagree.
- **`Blueprint::addColumnDefinition()` / `addIndexDefinition()`** — append
  definitions built elsewhere, for rebuilding a table from inspector metadata.
- **`tests/Live` — the compiler executed against every reachable engine.**
  Configured with `LETMIGRATE_DB_MYSQL` / `_PGSQL` / `_SQLSRV` (`GROUND_DB_*`
  honoured as a fallback); each run uses its own scratch database and drops it.
  A driver that is unconfigured or not answering SKIPS with the reason — never
  silently dropped, never counted as a pass.

### Changed

- Test suite repaired: recorders that were value-snapshots taken before the code
  under test ran, driver stubs whose `inTransaction()` answered the type-default
  and so discarded every commit, and fixtures that asserted per-migration
  rollback while providing single-batch data. `rollback(steps: N)` reverses N
  BATCHES, as the README documents.


### Added — Enterprise Architecture Refactor

**Service boundary**

- `Contract\MigrationServiceInterface` — the sole public contract for all
  migration operations. External code (CLI commands, framework adapters) must
  depend only on this interface.
- `Contract\MigrationRunnerInterface` — internal seam between the service and
  the runner; not intended for application code.
- `Service\MigrationService` — concrete service implementation; the **only**
  class in the codebase authorised to hold a `MigrationRepositoryInterface`
  reference. Repository property is `private readonly` with no public accessor.
- `Service\MigrationServiceFactory` — the **sole** authorised instantiation
  point for `DatabaseMigrationRepository`. Wires repository into service so no
  external code can bypass the boundary.

**Sub-namespace reorganisation**

| New canonical location | Previous location |
|---|---|
| `Config\MigrationConfig` | `MigrationConfig` (root) |
| `Registry\DriverRegistry` | `DriverRegistry` (root) |
| `Migration\MigrationResult` | `MigrationResult` (root) |
| `Migration\MigrationRecord` | `MigrationRecord` (root) |
| `Repository\DatabaseMigrationRepository` | `DatabaseMigrationRepository` (root) |
| `Resolver\FilesystemMigrationResolver` | `FilesystemMigrationResolver` (root) |
| `Runner\MigrationRunner` | `MigrationRunner` (root) |

All old root-namespace classes remain available via `class_alias` entries in
`src/aliases.php` (loaded automatically by Composer) — no existing imports
break. All aliases are marked `@deprecated` for removal in v2.0.

**Bug fixes**

- `src/Contract/MigrationRunner.php` contained a duplicate copy of
  `MigrationInterface` — replaced with the correct `MigrationRunnerInterface`.
- `LetMigrate::fromRegistry()` and `LetMigrate::configure()` now route through
  `MigrationServiceFactory` so the repository is never exposed to the facade.
- `MigrationRunner` now accepts `MigrationEventDispatcher` via constructor
  promotion with a default value, removing the nullable/default inconsistency.

**New tests**

- `tests/Unit/MigrationConfigTest.php` — comprehensive `Config\MigrationConfig` coverage
- `tests/Unit/MigrationResultTest.php` — `Migration\MigrationResult` coverage
- `tests/Unit/MigrationRecordTest.php` — `Migration\MigrationRecord` hydration + readonly
- `tests/Unit/DriverRegistryTest.php` — `Registry\DriverRegistry` updated imports
- `tests/Unit/MigrationServiceTest.php` — service boundary assertions (private repo, no getter)
- `tests/Unit/MigrationServiceFactoryTest.php` — factory sole-instantiation-point assertions
- `tests/Unit/FilesystemMigrationResolverTest.php` — updated to `Resolver\` namespace
- `tests/Unit/MigrationEventDispatcherTest.php` — updated `MigrationResult` import
- `tests/Unit/MigrationConfigAndResultTest.php` — updated to new sub-namespaces
- `tests/Integration/MigrationRunnerTest.php` — updated all imports + added boundary assertions
- `tests/Integration/SchemaBuilderSQLiteTest.php` — unchanged functionality, clean imports

**Configuration**

- `phpstan.neon` — updated paths; added `aliases.php` exclude; added alias-resolution ignores
- `phpunit.xml` — added `aliases.php` to source exclude list
- `.php-cs-fixer.php` — full PER-CS + PHP 8.2 ruleset with `aliases.php` excluded
- `composer.json` — added `src/aliases.php` to `autoload.files`; added `php-cs-fixer` dev dep
- `ARCHITECTURE.md` — full layer map, boundary rule, violation examples, BC alias table

---

## [1.0.0] — 2026-05-03

### Added
*(see original CHANGELOG for full v1.0.0 release notes)*
