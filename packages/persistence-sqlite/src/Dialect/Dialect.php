<?php

declare(strict_types=1);

namespace Ausus\Persistence\Sqlite\Dialect;

use PDO;

/**
 * SPI seam for SQL engines.
 *
 * The driver, repository and schema manager speak ONLY this interface — never a
 * concrete engine. That is what lets a future `ausus/persistence-postgres`,
 * `-mysql`, `-mariadb`, `-sqlserver`, `-cockroach`, `-planetscale` or `-turso`
 * reuse the entire driver/repository by providing one new `Dialect`, without
 * touching the kernel SPI or the runtime.
 *
 * The storage model is engine-neutral by design: a single entity table whose
 * business payload lives in a JSON/TEXT column. No per-entity DDL, no per-field
 * columns, no migrations — every engine can host it. Field-level pushdown
 * (filter/sort over JSON) is a future, dialect-specific optimisation and is NOT
 * part of this v1 surface.
 */
interface Dialect
{
    /** Short engine name, e.g. "sqlite". */
    public function name(): string;

    /** Quote a table/column identifier. */
    public function quoteIdentifier(string $name): string;

    /** `CREATE TABLE IF NOT EXISTS` DDL for the canonical entity table. */
    public function createEntitiesTableSql(string $table): string;

    /**
     * Additional index DDL (the primary key already covers the hot path).
     *
     * @return list<string>
     */
    public function createIndexesSql(string $table): array;

    /**
     * Per-connection setup (PRAGMAs / session settings) applied to every
     * connection the driver opens. Must be idempotent.
     */
    public function onConnect(PDO $pdo): void;
}
