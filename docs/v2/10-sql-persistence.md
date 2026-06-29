# 10. SQL Persistence (the SQLite driver)

`ausus/persistence-sqlite` is the first **public SQL `PersistenceDriver`** of
AUSUS 2.0 — a PDO SQLite implementation of the frozen kernel persistence SPI. It
is **drop-in interchangeable** with `ausus/persistence-memory`: the Entity
Engine, Runtime, L3 Projection Queries, L4 Aggregations, API Runtime, View
System and React Renderer all work unchanged. The only application change is the
bound driver.

```php
// before — in-memory, ephemeral
$driver = new Ausus\Persistence\Memory\MemoryDriver();
// after — SQLite, durable
$driver = new Ausus\Persistence\Sqlite\SqliteDriver(__DIR__ . '/var/app.db');
```

> **Why this is additive.** The persistence SPI was already complete:
> CRUD/actions/transitions/guards and L3/L4 all run **in the runtime over
> `Repository::findAll()`**, so a driver only implements `find` / `create` /
> `update` / `findAll` plus transactions, versioning and tenant scoping. No
> kernel, runtime, or public contract changed — a new package was added, exactly
> as `persistence-memory` is.

---

## 1. Where the driver sits

```
RuntimeEntity (read/invoke)  →  PersistenceDriver  →  PersistenceContext
                                      │                      │
                                      │                      └→ Repository (find/create/update/findAll)
                                      └→ TransactionHandle (begin/commit/rollback)
```

The runtime owns L3/L4, visibility and expand; the **driver owns only storage**.
That split is why one SPI serves both Memory and SQL — and why a future SQLite →
Postgres swap needs no contract change.

## 2. The SPI it implements (unchanged)

| Contract | Operations |
|----------|------------|
| `PersistenceDriver` | `beginTransaction`, `commit`, `rollback`, `context`, `generateIdentity` |
| `PersistenceContext` | `repository(fqn)`, `tenant()` |
| `Repository` | `find`, `create`, `update`, `findAll` |
| `TransactionHandle` | `tenant()` |

`PagedRepository::findPaged` (pushdown of filter/sort/pagination to SQL) is an
**optional** future optimisation — like Memory, the SQLite driver does not
implement it, because L3/L4 already produce correct results over `findAll`.

## 3. Internal architecture

```
SqliteDriver        — PersistenceDriver; owns connections, transactions, identity
 ├─ SqliteConnection — PDO factory (DSN, :memory: → shared-cache rewrite)
 ├─ SchemaManager    — idempotent CREATE TABLE IF NOT EXISTS + indexes
 ├─ Dialect (SPI)    — engine seam; SqliteDialect = quoting, DDL, PRAGMAs
 └─ SqliteRepository — Repository; tenant-scoped, parameterised SQL, JSON payload
```

| Component | Visibility | Stability |
|-----------|------------|-----------|
| `SqliteDriver`, `SqliteRepository` | public | stable |
| `Dialect` interface | public SPI | stable (the multi-engine seam) |
| `SqliteDialect`, `SchemaManager`, `SqliteConnection` | public, internal-by-convention | stable |
| `MigrationPlanner`, `findPaged` pushdown | not yet present | experimental / future |

### Storage model — engine-neutral

One table holds every entity; the business payload is JSON, so there is **no
per-entity DDL and no migrations**:

```sql
CREATE TABLE ausus_entities (
  tenant_id   TEXT NOT NULL,
  entity_fqn  TEXT NOT NULL,
  identity    TEXT NOT NULL,
  version     TEXT NOT NULL,
  fields_json TEXT NOT NULL,
  PRIMARY KEY (tenant_id, entity_fqn, identity)
);
```

The same shape ports to PostgreSQL, MySQL, MariaDB, SQL Server, CockroachDB,
PlanetScale and Turso — each is a new `Dialect`, not a new driver.

## 4. Lifecycle & transactions

- `beginTransaction(tenant)` opens a **fresh connection** and `BEGIN`s a real
  SQLite transaction. Each handle is independent.
- Writes via the handle's repository are visible to that handle (read-your-writes)
  but **invisible to other handles until commit** — WAL snapshot isolation, the
  same guarantee the Memory driver gives through its committed/staging overlay.
- `commit()` / `rollback()` finalise and release the connection.
- The runtime reads inside a transaction it then rolls back (read-only), and
  wraps each mutation in `begin … commit`, rolling back on any error.

**Optimistic concurrency.** `create` writes `version = "1"`; `update` bumps it
and refuses a stale `expected` version (`… not found` / `… version conflict`,
matching Memory so HTTP status mapping is identical). **Tenant isolation:** every
statement is `tenant_id`-scoped. **Identity:** UUID v4.

## 5. Design choices (and why)

- **JSON payload, single table** — universality over micro-optimisation; the
  contract must outlive any one engine.
- **Connection-per-transaction** — the only faithful way to reproduce Memory's
  cross-transaction invisibility on SQLite; a connection pool is a future,
  additive optimisation.
- **WAL + busy timeout** — concurrent readers never block the writer; durable.
- **`Dialect` seam** — the driver/repository never name SQLite directly, so new
  engines plug in without touching the engine-agnostic logic.
- **Behavioural parity with Memory** — identical version bumps, conflict/ not-found
  semantics and `findAll` ordering, so applications cannot tell the drivers apart.

## 6. Migration from the Memory driver

1. `composer require ausus/persistence-sqlite`.
2. Replace `new MemoryDriver()` with `new SqliteDriver('/path/to/app.db')`.
3. Nothing else changes — same entities, actions, projections, queries,
   aggregations, API and renderer.

```php
// Hello Invoice / Teranga PMS — the entity, compiler, engine and API are identical
$engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$driver = new SqliteDriver(__DIR__ . '/var/hello-invoice.db');   // ← only line that differs
$engine->bind($repo->resolve('invoice'), $driver)
       ->invoke('create', ['number' => 'INV-001', /* … */ 'total' => 1500], $user);
```

The reference test suite proves it: the **real Hello Invoice entity** run through
`MemoryDriver` and `SqliteDriver` yields byte-identical projection rows and L4
aggregates, guards deny identically, and SQLite data survives dropping the driver
and reopening on the same file (process restart) — something the Memory driver
cannot do.

## 7. Limitations

- **No pushdown** yet: L3/L4 run in the runtime over `findAll`; large datasets
  will want `findPaged` SQL pushdown (future, additive — the `PagedRepository`
  SPI already exists).
- **No schema migrations**: the single JSON table is fixed; a `MigrationPlanner`
  is future work.
- **SQLite write concurrency**: one writer at a time (WAL); fine for embedded /
  single-node, a server engine (Postgres) is the next dialect.
- **JSON field typing**: values round-trip through JSON (scalars preserved);
  there is no column-level typing or indexing of individual fields yet.

See also the **Capabilities** and **Known limits** references (sidebar →
*Concepts* / *Reference*).
