<?php

declare(strict_types=1);

namespace Ausus\Persistence\Sqlite\Dialect;

use PDO;

/**
 * SQLite dialect (PDO).
 *
 * Canonical entity table — engine-neutral, JSON payload:
 *
 *   ausus_entities(
 *     tenant_id   TEXT,   -- tenant isolation
 *     entity_fqn  TEXT,   -- content-addressed entity identity
 *     identity    TEXT,   -- per-entity handle (UUID by default)
 *     version     TEXT,   -- optimistic concurrency token ("1","2",…)
 *     fields_json TEXT,   -- the business payload (json)
 *     PRIMARY KEY (tenant_id, entity_fqn, identity)
 *   )
 *
 * `onConnect` enables foreign keys and a busy timeout, and requests WAL so that
 * readers never block on a writer and a separate transaction sees only committed
 * rows — the same isolation the Memory driver provides via its staging overlay.
 * (WAL is silently ignored for `:memory:`; file databases get true durability.)
 */
final class SqliteDialect implements Dialect
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function createEntitiesTableSql(string $table): string
    {
        $t = $this->quoteIdentifier($table);

        return "CREATE TABLE IF NOT EXISTS {$t} (
            tenant_id   TEXT NOT NULL,
            entity_fqn  TEXT NOT NULL,
            identity    TEXT NOT NULL,
            version     TEXT NOT NULL,
            fields_json TEXT NOT NULL,
            PRIMARY KEY (tenant_id, entity_fqn, identity)
        )";
    }

    public function createIndexesSql(string $table): array
    {
        $t = $this->quoteIdentifier($table);

        // Hot read path: enumerate one entity kind within one tenant, ordered.
        return [
            "CREATE INDEX IF NOT EXISTS idx_ausus_entities_kind ON {$t} (tenant_id, entity_fqn, identity)",
        ];
    }

    public function onConnect(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        // WAL: concurrent reader/writer isolation on file databases.
        $pdo->exec('PRAGMA journal_mode = WAL');
    }
}
