<?php

declare(strict_types=1);

namespace AlfaCode\LetMigrate\Exception;

/**
 * Thrown when a SQL statement fails execution.
 *
 * Carries the original SQL and bindings for debugging.
 */
final class QueryException extends LetMigrateException
{
    /** @param array<mixed> $bindings */
    public function __construct(
        public readonly string     $sql,
        public readonly array      $bindings,
        \Throwable                 $previous,
    ) {
        $preview = mb_strlen($sql) > 200 ? mb_substr($sql, 0, 200) . '…' : $sql;
        parent::__construct(
            "Database query failed: {$previous->getMessage()} | SQL: {$preview}",
            (int) $previous->getCode(),
            $previous,
        );
    }
}
