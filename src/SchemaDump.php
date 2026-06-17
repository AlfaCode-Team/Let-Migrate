<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate;

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Exception\LetMigrateException;

/**
 * Schema-dump / rollup workflow — the last Doctrine §8.2 gap-closer
 * (≈ Doctrine `dump-schema` + `rollup`, Laravel `schema:dump --prune`).
 *
 * `migrate:squash` captures the cumulative SQL via the existing
 * CaptureDriver; this class persists that SQL to a `.sql` file plus a
 * `.json` sidecar manifest listing which migrations the dump COVERS. On a
 * fresh/empty database, `migrate:run` loads the dump first, marks the
 * covered migrations as already applied (baseline), then runs only the
 * migrations created AFTER the dump — so a brand-new environment skips
 * replaying years of historical migrations.
 *
 * Filesystem-only and driver-thin → unit-testable without a database.
 */
final class SchemaDump
{
    public function __construct(
        private readonly string $sqlPath,
    ) {}

    private function manifestPath(): string
    {
        return preg_replace('/\.sql$/', '', $this->sqlPath) . '.manifest.json';
    }

    public function exists(): bool
    {
        return is_file($this->sqlPath) && is_file($this->manifestPath());
    }

    /**
     * Persist the captured SQL + the list of migrations it covers.
     *
     * @param string[] $sqlStatements
     * @param string[] $coveredMigrations filename-keys covered by the dump
     */
    public function write(array $sqlStatements, array $coveredMigrations): void
    {
        $dir = dirname($this->sqlPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new LetMigrateException("Cannot create dump directory: {$dir}");
        }

        $header = "-- let-migrate schema dump\n"
                . '-- generated: ' . date('c') . "\n"
                . '-- covers ' . count($coveredMigrations) . " migration(s)\n\n";

        $body = '';
        foreach ($sqlStatements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }
            $body .= rtrim($stmt, ';') . ";\n";
        }

        if (file_put_contents($this->sqlPath, $header . $body) === false) {
            throw new LetMigrateException("Failed to write dump: {$this->sqlPath}");
        }

        $manifest = json_encode(
            [
                'generated_at' => date('c'),
                'covers'       => array_values($coveredMigrations),
                'statements'   => count($sqlStatements),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        if ($manifest === false
            || file_put_contents($this->manifestPath(), $manifest) === false
        ) {
            throw new LetMigrateException(
                "Failed to write dump manifest: {$this->manifestPath()}",
            );
        }
    }

    /**
     * Migrations the dump covers (already represented by the dumped DDL).
     *
     * @return string[]
     */
    public function coveredMigrations(): array
    {
        if (!is_file($this->manifestPath())) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->manifestPath()), true);

        return is_array($data) && isset($data['covers']) && is_array($data['covers'])
            ? array_map('strval', $data['covers'])
            : [];
    }

    /**
     * Execute the dump against a (fresh) database. Statements are split on
     * ";\n" — the same convention CaptureDriver/SchemaBuilder use.
     */
    public function load(DatabaseDriverInterface $driver): int
    {
        if (!is_file($this->sqlPath)) {
            throw new LetMigrateException("Schema dump not found: {$this->sqlPath}");
        }

        $sql   = (string) file_get_contents($this->sqlPath);
        $count = 0;

        foreach (explode(";\n", $sql) as $statement) {
            // strip comment-only / blank fragments
            $clean = trim(preg_replace('/^--.*$/m', '', $statement) ?? $statement);
            if ($clean === '') {
                continue;
            }
            $driver->execute($clean);
            $count++;
        }

        return $count;
    }
}