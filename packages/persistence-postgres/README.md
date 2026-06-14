# ausus/persistence-postgres

L3 — PostgreSQL `PersistenceDriver` implementation of the AUSUS persistence contract.

A behaviour-compatible PostgreSQL backend that mirrors the reference
`ausus/persistence-sql` (SQLite) driver across the full kernel persistence
contract. Parity is asserted by a shared cross-driver compatibility harness
(`tests/compat.php`).

## Public surface

All classes live in `Ausus\Persistence\Postgres` and implement kernel
interfaces only — consumers depend on the kernel contracts, never on the
concrete classes.

| Class | Kernel interface |
|-------|------------------|
| `PostgresPersistenceDriver` | `Ausus\PersistenceDriver` |
| `PostgresContext`           | `Ausus\PersistenceContext` |
| `PostgresRepository`        | `Ausus\Repository`, `Ausus\PagedRepository` |
| `PostgresTransactionHandle` | `Ausus\TransactionHandle` |
| `PostgresAuditSink`         | `Ausus\AuditSink` |
| `PostgresSchemaDeriver`     | schema-derivation helper (DDL emitter) |

## Guarantees (parity with persistence-sql)

- Per-tenant transactions; row-level `WHERE tenant_id = ?` isolation.
- Optimistic locking via version column → `ConcurrencyConflict`.
- Filter grammar: `eq`, `contains` (LIKE-metacharacter-escaped), `in`.
- Sorting: caller order + deterministic `id ASC` tie-breaker; duplicate
  sort columns rejected; undeclared columns rejected (`resolveColumn`).
- Referential integrity on reference fields (RFC-015) →
  `ReferentialIntegrityViolation`, message-identical to SQLite.
- In-transaction audit sink (17-column `kernel_audit_log`) — no orphan rows.
- Error taxonomy identical to persistence-sql.

## Dependencies

- PHP ≥ 8.3
- `ext-pdo`, `ext-pdo_pgsql`
- `ausus/kernel`

`ausus/persistence-sql` is a **dev-only** dependency: it is the reference
driver the compat harness validates parity against. It is **never** referenced
by `src/` at runtime — the runtime depends on `ausus/kernel` alone.

## Usage

```php
$pdo    = new \PDO($dsn, $user, $pass);   // pgsql DSN
$driver = new \Ausus\Persistence\Postgres\PostgresPersistenceDriver($pdo, $graph);

// Derive + create schema.
foreach (\Ausus\Persistence\Postgres\PostgresSchemaDeriver::deriveAll($graph) as $ddl) {
    $pdo->exec($ddl);
}

$tx   = $driver->beginTransaction($tenant);
$repo = $driver->context($tenant, $tx)->repository('your.entity');
// $repo->create(...) / find(...) / update(...) / findAll() / findPaged(...)
$driver->commit($tx);
```

## Testing

The cross-driver harness runs SQLite always and PostgreSQL when configured:

```
AUSUS_PG_DSN=pgsql:host=127.0.0.1;port=5433;dbname=ausus_compat \
AUSUS_PG_USER=ausus AUSUS_PG_PASS=ausus \
php packages/persistence-postgres/tests/compat.php
```

Set `AUSUS_PG_REQUIRED=1` to make the suite **fail** if the PostgreSQL branch
did not actually run (CI uses this to prevent SQLite-only false positives — a
missing extension, unreachable service, or unset DSN turns a green-on-SQLite
run into a hard failure).

## License

MIT — see `LICENSE`.
