<?php

declare(strict_types=1);

namespace Ausus\Persistence\Sqlite;

use Ausus\Persistence\Sqlite\Dialect\Dialect;
use PDO;

/**
 * Internal connection factory.
 *
 * Produces fully-configured PDO connections to one SQLite database. The driver
 * opens an independent connection per transaction handle, which is what gives
 * cross-transaction isolation (an uncommitted write on one handle is invisible
 * to another) — mirroring the Memory driver's committed/staging split.
 *
 * `:memory:` is rewritten to a shared-cache, named in-memory database so the
 * driver's keep-alive connection and the per-handle connections refer to the
 * SAME in-memory store. A file path is used verbatim (the production path: real
 * durability, survives process restarts).
 */
final class SqliteConnection
{
    private readonly string $dsn;

    public function __construct(
        string $location,
        private readonly Dialect $dialect,
    ) {
        $this->dsn = self::toDsn($location);
    }

    public function open(): PDO
    {
        $pdo = new PDO($this->dsn);
        $this->dialect->onConnect($pdo);

        return $pdo;
    }

    private static function toDsn(string $location): string
    {
        if ($location === ':memory:' || $location === '') {
            // Shared, named in-memory DB so multiple connections see one store.
            return 'sqlite:file:ausus_mem_' . substr(md5($location . '|shared'), 0, 12)
                . '?mode=memory&cache=shared';
        }
        if (str_starts_with($location, 'sqlite:')) {
            return $location; // already a DSN
        }

        return 'sqlite:' . $location;
    }
}
