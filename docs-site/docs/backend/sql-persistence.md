---
id: sql-persistence
title: SQL Persistence
sidebar_label: SQL Persistence
description: The SQLite-backed persistence driver and schema derivation.
---

# SQL Persistence

`ausus/persistence-sql` (layer L3) is the SQLite persistence driver. It is
SQLite-backed, derives its schema from the [metadata graph](../concepts/metadata-graph.md),
and enforces tenant isolation and optimistic concurrency. It is one of two
implementations of the kernel `PersistenceDriver` contract — see
[the shared contract & PostgreSQL](#shared-contract-postgresql) below.

## Deriving the schema {#deriving-the-schema}

`SchemaDeriver` turns a compiled graph into `CREATE TABLE` statements — one
table per entity, plus the audit log table:

```php
use Ausus\Persistence\Sql\SchemaDeriver;

foreach (SchemaDeriver::deriveAll($graph) as $stmt) {
    $pdo->exec($stmt);
}
```

- The table name is the entity FQN with dots replaced by underscores —
  `billing.invoice` → `billing_invoice`.
- Column types map from field types: `string`/`datetime` → `TEXT`,
  `integer` → `INTEGER`, `money` → `NUMERIC`, `identity`/`version` →
  `TEXT NOT NULL`.
- `id` is the primary key. Non-nullable fields get `NOT NULL`; field defaults
  become column `DEFAULT`s.

## The driver {#the-driver}

`SqlitePersistenceDriver` implements the kernel `PersistenceDriver` contract:

```php
use Ausus\Persistence\Sql\SqlitePersistenceDriver;

$driver = new SqlitePersistenceDriver($pdo, $graph);

$tx   = $driver->beginTransaction($tenant);
$ctx  = $driver->context($tenant, $tx);
$repo = $ctx->repository('billing.invoice');
// ... use the repository ...
$driver->commit($tx);
```

A `PersistenceContext` is always bound to a `Tenant`; asking for a context with
a tenant that does not match the transaction handle throws
`TenantBoundaryViolation`.

## The repository {#the-repository}

`SqliteRepository` is the per-entity data API. Its operations:

| Method | Behaviour |
|---|---|
| `find(Reference $ref): ?Entity` | reads one row by id, scoped to the tenant |
| `create(array $payload, ?string $identity = null): Entity` | inserts a row, generating a ULID id and `_version` |
| `update(Reference $ref, array $patch, Version $expected): Entity` | updates a row if `$expected` matches the current `_version` |
| `findAll(): list<Entity>` | reads every row for the active tenant, ordered by id |
| `findPaged(int $limit, int $offset, array $filters, array $sort): array` | a deterministic page plus total count, with optional filters (`eq` / `contains` / `in`) and sorting |

```php
$entity = $repo->find($ref);
$entity = $repo->create(['number' => 'INV-1', /* ... */]);
$entity = $repo->update($ref, ['customer_name' => 'New'], $entity->version);
```

## Tenant isolation {#tenant-isolation}

Every table has a `tenant_id` column. Every query is filtered by it, and a
`Reference` whose `tenantId` does not match the active tenant is rejected with
`TenantBoundaryViolation` **before** any SQL runs. Tenant scoping is enforced
in the driver, not left to the caller.

## Optimistic concurrency {#optimistic-concurrency}

Every row carries a `_version` column — a ULID regenerated on every write.
`update()` includes `_version = :expected` in its `WHERE` clause:

- If the row is updated, the version matched.
- If zero rows are affected, the driver checks whether the row exists at all:
  missing → `NotFound`; present but a different version → `ConcurrencyConflict`.

This is how a stale write is detected — there is no row locking.

## The audit log {#the-audit-log}

`SchemaDeriver` also emits a `kernel_audit_log` table. `DatabaseAuditSink`
implements the kernel `AuditSink` contract and writes one row per successful
action — actor, tenant, action FQN, subject, inputs, outputs, timestamp,
correlation id, and sequence number. The write happens **inside the action's
transaction**, so the audit entry and the data change commit or roll back
together. See [The Runtime](runtime.md).

## The shared `PersistenceDriver` contract & PostgreSQL {#shared-contract-postgresql}

`PersistenceDriver` is a **contract**, not a single implementation. AUSUS ships
two drivers behind it:

- **`ausus/persistence-sql`** — the SQLite-backed **reference** driver described
  above (zero-config; ideal for development and tests).
- **`ausus/persistence-postgres`** — the **production** PostgreSQL driver
  (`PostgresPersistenceDriver`, `PostgresRepository`, `PostgresSchemaDeriver`,
  `PostgresAuditSink`).

Both implement the same operations — schema derivation, tenant isolation,
optimistic concurrency, `find` / `create` / `update` / `findAll` / `findPaged`,
referential integrity, and the in-transaction audit sink. They are
**behaviourally compatible**: the same operations produce the same results and
raise message-identical exceptions (`TenantBoundaryViolation`,
`ConcurrencyConflict`, `ReferentialIntegrityViolation`, …). A continuous
cross-driver compatibility gate runs both drivers against the same suite on
every change.

Because the contract is shared, an application moves from SQLite (development) to
PostgreSQL (production) by configuring a different driver — with no domain
change:

```bash
composer require ausus/persistence-postgres:^1.1
```

## Current limitations {#current-v010-limitations}

- **SQLite and PostgreSQL** are both implemented behind the shared contract
  (above). **MySQL** is a design goal but is not implemented.
- The repository has **no `delete`**. Listing, filtering, sorting, and
  pagination are available through `findAll` / `findPaged` (see
  [Projections](../concepts/projections.md)).
- There are no migrations — `SchemaDeriver` uses `CREATE TABLE IF NOT EXISTS`.
  Changing an entity's fields does not alter an existing table.
- `_version` is regenerated as a ULID; it is a change token, not a counter.

## Related {#related}

- [The Runtime](runtime.md) — writes through this driver.
- [The Metadata Graph](../concepts/metadata-graph.md) — the schema source.
- [Error Reference](../reference/errors.md) — `NotFound`, `ConcurrencyConflict`.
