<?php

declare(strict_types=1);

namespace Ausus\Persistence\Sqlite;

use Ausus\PersistenceContext;
use Ausus\PersistenceDriver;
use Ausus\Persistence\Sqlite\Dialect\Dialect;
use Ausus\Persistence\Sqlite\Dialect\SqliteDialect;
use Ausus\Tenant;
use Ausus\TransactionHandle;
use PDO;
use Throwable;

/**
 * The first public AUSUS SQL {@see PersistenceDriver}: PDO SQLite.
 *
 * It is a drop-in replacement for {@see \Ausus\Persistence\Memory\MemoryDriver}
 * — it realises the same frozen kernel SPI, so the Entity Engine, Runtime, L3
 * Projection Queries, L4 Aggregations, API Runtime, View System and React
 * Renderer all work unchanged. Swapping `new MemoryDriver()` for
 * `new SqliteDriver($path)` is the only application change required.
 *
 * Transaction model — one real SQLite transaction per handle, on its own
 * connection:
 *   - beginTransaction() opens a fresh connection and `BEGIN`s;
 *   - create()/update() write within that connection (read-your-writes);
 *   - a different handle, on a different connection, sees only committed rows
 *     (WAL snapshot isolation) — exactly the Memory committed/staging split;
 *   - commit()/rollback() finalise and release the connection.
 *
 * It owns NO business logic, NO compiler, NO authorisation — strictly the
 * persistence SPI. It depends ONLY on `ausus/kernel`.
 */
final class SqliteDriver implements PersistenceDriver
{
    public const TABLE = 'ausus_entities';

    private readonly Dialect $dialect;
    private readonly SqliteConnection $connection;
    /** Keep-alive connection: holds the schema and (for `:memory:`) the shared store alive. */
    private readonly PDO $keepAlive;

    /** @var array<int,PDO> open transaction connections, keyed by handle object id */
    private array $open = [];
    /** @var array<int,TransactionHandle> keep handles alive (prevents spl_object_id reuse) */
    private array $handles = [];

    public function __construct(
        string $location = ':memory:',
        ?Dialect $dialect = null,
        private readonly string $table = self::TABLE,
    ) {
        $this->dialect = $dialect ?? new SqliteDialect();
        $this->connection = new SqliteConnection($location, $this->dialect);
        $this->keepAlive = $this->connection->open();
        (new SchemaManager($this->dialect, $this->table))->ensure($this->keepAlive);
    }

    public function beginTransaction(Tenant $tenant): TransactionHandle
    {
        $handle = new class($tenant) implements TransactionHandle {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function tenant(): Tenant
            {
                return $this->tenant;
            }
        };

        $pdo = $this->connection->open();
        $pdo->beginTransaction();

        $id = spl_object_id($handle);
        $this->open[$id] = $pdo;
        $this->handles[$id] = $handle;

        return $handle;
    }

    public function commit(TransactionHandle $h): void
    {
        $id = spl_object_id($h);
        $pdo = $this->open[$id] ?? null;
        if ($pdo === null) {
            return; // already finalised / unknown handle — no-op
        }
        try {
            $pdo->commit();
        } catch (Throwable $e) {
            throw SqlPersistenceError::driver('commit failed', $e);
        } finally {
            unset($this->open[$id], $this->handles[$id]);
        }
    }

    public function rollback(TransactionHandle $h): void
    {
        $id = spl_object_id($h);
        $pdo = $this->open[$id] ?? null;
        if ($pdo === null) {
            return;
        }
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } finally {
            unset($this->open[$id], $this->handles[$id]);
        }
    }

    public function context(Tenant $tenant, TransactionHandle $h): PersistenceContext
    {
        $pdo = $this->open[spl_object_id($h)]
            ?? throw SqlPersistenceError::driver('context for an unknown or finalised transaction', new \RuntimeException());

        return new class($pdo, $this->dialect, $this->table, $tenant, $this) implements PersistenceContext {
            public function __construct(
                private readonly PDO $pdo,
                private readonly Dialect $dialect,
                private readonly string $table,
                private readonly Tenant $tenant,
                private readonly SqliteDriver $driver,
            ) {
            }

            public function repository(string $entityFqn): \Ausus\Repository
            {
                return new SqliteRepository(
                    $this->pdo, $this->dialect, $this->table, $this->tenant, $entityFqn, $this->driver,
                );
            }

            public function tenant(): Tenant
            {
                return $this->tenant;
            }
        };
    }

    public function generateIdentity(string $entityFqn): string
    {
        return self::uuid4();
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // version 4
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // variant
        $hex = bin2hex($b);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
