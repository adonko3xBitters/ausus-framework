<?php

declare(strict_types=1);

namespace Ausus\Persistence\Sqlite;

use RuntimeException;

/**
 * Base error for the SQL persistence layer.
 *
 * Concurrency / not-found conditions are surfaced with message substrings that
 * match the reference {@see \Ausus\Persistence\Memory\MemoryDriver} ("not found",
 * "version conflict"), so the runtime and the HTTP status mapping behave
 * identically whichever driver is bound.
 */
final class SqlPersistenceError extends RuntimeException
{
    public static function notFound(string $identity): self
    {
        return new self("SqliteRepository: entity '{$identity}' not found");
    }

    public static function versionConflict(string $identity): self
    {
        return new self("SqliteRepository: version conflict on '{$identity}'");
    }

    public static function driver(string $message, \Throwable $previous): self
    {
        return new self("SqliteDriver: {$message}", 0, $previous);
    }
}
