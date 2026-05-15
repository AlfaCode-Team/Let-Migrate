# Changelog

All notable changes to `let-migrate` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project follows [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

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
