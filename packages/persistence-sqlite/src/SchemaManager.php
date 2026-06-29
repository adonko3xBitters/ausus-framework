<?php

declare(strict_types=1);

namespace Ausus\Persistence\Sqlite;

use Ausus\Persistence\Sqlite\Dialect\Dialect;
use PDO;

/**
 * Idempotent schema bootstrap for the canonical entity table.
 *
 * v1 is a single, fixed table (no per-entity DDL, no migrations) — so `ensure()`
 * is a `CREATE TABLE IF NOT EXISTS` plus its indexes, safe to call on every
 * driver construction. A future, additive `MigrationPlanner` can version this
 * schema; the table shape is intentionally stable so it never needs to.
 */
final class SchemaManager
{
    public function __construct(
        private readonly Dialect $dialect,
        private readonly string $table,
    ) {
    }

    public function ensure(PDO $pdo): void
    {
        $pdo->exec($this->dialect->createEntitiesTableSql($this->table));
        foreach ($this->dialect->createIndexesSql($this->table) as $sql) {
            $pdo->exec($sql);
        }
    }
}
