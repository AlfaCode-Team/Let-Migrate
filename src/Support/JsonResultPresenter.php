<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Support;

use AlfaCode\LetMigrate\MigrationResult;

/**
 * Machine-readable (`--json`) presenter for run / rollback / reset /
 * refresh results and the pending list.
 *
 * Mirrors the StatusRenderer pattern: a structured toArray()-style core
 * plus a JSON wrapper, so CI/CD pipelines get a stable, documented schema.
 * No rival (Phinx/Doctrine) emits structured JSON for these — Phase 4
 * leadership.
 *
 * Schemas (stable contract — document in the README):
 *
 *   result:
 *     { "applied": string[], "rolled_back": string[], "batch": int,
 *       "applied_count": int, "rolled_back_count": int,
 *       "empty": bool, "summary": string }
 *
 *   pending:
 *     { "pending": string[], "count": int }
 */
final class JsonResultPresenter
{
    /**
     * @return array{
     *   applied: string[], rolled_back: string[], batch: int,
     *   applied_count: int, rolled_back_count: int,
     *   empty: bool, summary: string
     * }
     */
    public function resultData(MigrationResult $result): array
    {
        return [
            'applied'           => array_values($result->applied),
            'rolled_back'       => array_values($result->rolledBack),
            'batch'             => $result->batch,
            'applied_count'     => $result->appliedCount(),
            'rolled_back_count' => $result->rolledBackCount(),
            'empty'             => $result->isEmpty(),
            'summary'           => $result->summary(),
        ];
    }

    public function resultJson(MigrationResult $result): string
    {
        return $this->encode($this->resultData($result));
    }

    /**
     * @param  array<string, mixed> $pending  filename => migration (the
     *                                         shape pending() returns)
     * @return array{pending: string[], count: int}
     */
    public function pendingData(array $pending): array
    {
        $names = array_values(array_map('strval', array_keys($pending)));

        return [
            'pending' => $names,
            'count'   => count($names),
        ];
    }

    /**
     * @param array<string, mixed> $pending
     */
    public function pendingJson(array $pending): string
    {
        return $this->encode($this->pendingData($pending));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '{}';
    }
}