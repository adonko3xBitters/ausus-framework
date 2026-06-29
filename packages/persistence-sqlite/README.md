# ausus/persistence-sqlite

The first **public SQL `PersistenceDriver`** for AUSUS 2.0 — a PDO SQLite
implementation of the frozen kernel persistence SPI, **drop-in interchangeable**
with `ausus/persistence-memory`.

```php
use Ausus\Persistence\Sqlite\SqliteDriver;

// file-backed (durable, survives restarts)
$driver = new SqliteDriver(__DIR__ . '/var/app.db');

// the ONLY change from the Memory driver:
//   - new MemoryDriver()
//   + new SqliteDriver($path)
$engine->bind($schema, $driver)->invoke('create', $inputs, $context);
```

It realises exactly the same SPI (`PersistenceDriver`, `PersistenceContext`,
`Repository`, `TransactionHandle`), so the Entity Engine, Runtime, **L3
Projection Queries**, **L4 Aggregations**, API Runtime, View System and React
Renderer all work unchanged.

## Design

- **Engine-neutral storage:** one table `ausus_entities(tenant_id, entity_fqn,
  identity, version, fields_json)` with the business payload as JSON — portable
  to every SQL engine, no per-entity DDL, no migrations.
- **Isolation:** one real SQLite transaction per handle, on its own connection
  (WAL) — an uncommitted write on one handle is invisible to another, mirroring
  the Memory driver's committed/staging split.
- **Optimistic concurrency:** `version` token, checked on `update`.
- **Tenant isolation:** every statement is `tenant_id`-scoped.
- **Identity:** UUID v4.
- **Dialect seam:** the driver/repository speak only the `Dialect` interface, so
  a future Postgres/MySQL/MariaDB/SQL Server/CockroachDB/PlanetScale/Turso driver
  is one new `Dialect` — no kernel or runtime change.

Depends only on `ausus/kernel`. No business logic, no compiler, no authorization.
