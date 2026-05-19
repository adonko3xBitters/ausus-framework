# ausus/persistence-sql — V0 Implementation Design

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Active design (V0 implementation phase)                |
| Authors       | architect, persistence maintainer                      |
| Date          | 2026-05-19                                             |
| Implements    | RFC-002 (PersistenceDriver)                            |
| V0 target     | PostgreSQL primary, SQLite secondary (only if nearly free), MySQL deferred |
| Owning package | `ausus/persistence-sql` (namespace `Ausus\Persistence\Sql\`) |
| Depends on    | `ausus/kernel` (contracts + MetadataGraph), Laravel's `illuminate/database` |

This document is the **how** for the SQL driver. RFC-002 is the **what**. Where this document deviates, RFC-002 wins.

V0 builds the smallest driver capable of supporting RFC-000's `HelloInvoice` slice and the M1 integration test in `apps/playground`. Single deployment driver; row-level tenancy only.

---

## 1. Mission and scope

### 1.1 Mission

Implement `PersistenceDriver`, `PersistenceContext`, `Repository`, `TransactionHandle` from RFC-002 against PostgreSQL via Laravel's `Illuminate\Database\ConnectionInterface`. Derive SQL schema from the `MetadataGraph` at migration time. Map every native database error to the closed RFC-002 §12.1 taxonomy.

### 1.2 V0 in scope

| Repository method | V0 | Notes                                        |
|-------------------|-----|----------------------------------------------|
| `find`            | ✓  | "findById" — single-row by canonical reference |
| `findMany`        | ✓  | Minimal filter grammar (§8.2); cursor pagination |
| `create`          | ✓  | INSERT; ULID identity; returns Entity        |
| `update`          | ✓  | UPDATE with optimistic lock                  |
| `delete`          | ✓  | DELETE with optimistic lock                  |
| `exists`          | ✓  | SELECT 1 ... LIMIT 1                         |
| `count`           | ✓  | SELECT COUNT(*) ...                          |
| `iterate`         | ✗  | Deferred to M2 (streaming reads)             |
| `fetchRelated`    | ✗  | No Relations in HelloInvoice; deferred       |
| `updateMany`      | ✗  | MaintenanceAction territory; stub raises     |
| `deleteMany`      | ✗  | Same                                         |

| Driver method        | V0 | Notes                                              |
|----------------------|-----|----------------------------------------------------|
| `beginTransaction`   | ✓  |                                                    |
| `commit`             | ✓  |                                                    |
| `rollback`           | ✓  |                                                    |
| `context`            | ✓  |                                                    |
| `generateIdentity`   | ✓  | ULID (Crockford base32, 26 chars)                  |
| `capabilities`       | ✓  | Advertises row tenancy, 8 savepoints, ulid identity |

### 1.3 V0 out of scope

- **MySQL** — deferred. If the V0 implementation incidentally works on MySQL, fine; we do not test it, we do not ship support claims.
- **SQLite** — supported best-effort for fast local dev / CI. If supporting SQLite requires non-trivial extra code, defer. PostgreSQL is the contract.
- Advanced joins beyond declared Relations (no Relations in V0 anyway).
- Reporting optimization, query planner sophistication (ReportingDriver is a separate package; SQL Repository is just CRUD).
- Geo, vector, regex predicates (not in RFC-002 §10.1 grammar).
- Heterogeneous storage; multi-driver deployments.
- Disk-cached compiled query plans.
- Connection pooling beyond Laravel's defaults.
- Read replicas, write/read splitting (deferred to RFC-002 §17.3).
- Soft deletes (per RFC-002 §17.2: use Workflow `archived` state instead).
- Schema versioning / migration history table (V0 uses idempotent DDL only).

---

## 2. Driver architecture

### 2.1 Class map

```
src/
├── SqlPersistenceServiceProvider.php    # Laravel SP; binds the driver
├── Driver/
│   ├── SqlPersistenceDriver.php         # implements PersistenceDriver
│   ├── SqlPersistenceContext.php        # implements PersistenceContext
│   ├── SqlTransactionHandle.php         # implements TransactionHandle (sealed)
│   └── SqlRepository.php                # implements Repository
├── Schema/
│   ├── SqlSchemaDeriver.php             # MetadataGraph → TableDdl[]
│   ├── TableDdl.php                     # value object (CREATE/ALTER statements + indexes)
│   └── FieldTypeMapper.php              # FieldNode.type + typeOptions → SQL column type
├── Query/
│   ├── SqlQueryCompiler.php             # Filter tree → SqlClause (where + params)
│   ├── SqlClause.php                    # value object {sql: string, bindings: array}
│   └── CursorEncoder.php                # opaque cursor encode/decode (base64 of {last_id, sort_keys})
├── Identity/
│   └── UlidGenerator.php                # 26-char Crockford base32 ULID
├── Errors/
│   ├── SqlErrorMapper.php               # QueryException + SQLSTATE → PersistenceError
│   └── NotImplementedInV0.php           # raises for deferred methods (bulk, iterate, fetchRelated)
├── Capabilities/
│   └── SqlDriverCapabilities.php        # final value object
└── Console/
    ├── MigrateCommand.php               # `php artisan ausus:migrate`
    └── DoctorCheck.php                  # contributions to `ausus:doctor`
```

**15 classes total**. Smallest viable SQL driver.

### 2.2 Layer placement

L3 (driver-layer plugin). Depends only on `ausus/kernel` per RFC-001 §3.2 + the implementation plan in `docs/IMPLEMENTATION-PLAN.md` §3.

External dependencies:
- `illuminate/database` — `ConnectionInterface`, `QueryException`. Eloquent itself is unused; we use the query builder only where convenient, raw SQL elsewhere.
- `illuminate/contracts` — Laravel container, etc.
- `illuminate/support` — service provider base.

### 2.3 No Eloquent leakage

Per RFC-002 §13 and the implementation plan §3.3: zero Eloquent types cross the contract boundary. The driver's public methods return:

- `Entity` (kernel value object), not `Illuminate\Database\Eloquent\Model`.
- `EntityPage` (kernel value object), not `Illuminate\Database\Eloquent\Collection`.
- `Reference`, `Version`, `IdentityHandle` — all kernel value objects.

The PHPStan ruleset in CI (`phpstan/phpstan` level 8) detects accidental Eloquent escape. Conformance test asserts the public-method return types.

---

## 3. Identity strategy

### 3.1 ULID (Crockford base32, 26 characters)

ULID format (per [the standard](https://github.com/ulid/spec)):

- 48 bits — Unix time in milliseconds, big-endian.
- 80 bits — randomness.
- Encoded as 26 characters Crockford base32 (`0123456789ABCDEFGHJKMNPQRSTVWXYZ`, no `I`/`L`/`O`/`U`).

Why ULID:

- Sortable by generation time (good for B-tree indexes; INSERTs go to the index tail).
- 128-bit collision resistance (negligible probability in 10¹² records per tenant).
- String-safe (no escaping for SQL params; no special characters).
- Cross-database portable (varchar(26)).
- Fixed length (efficient storage in fixed-width columns).

### 3.2 Column type

`VARCHAR(26)` everywhere. Postgres / SQLite both store it efficiently.

Postgres alternative considered: native `UUID` type. Rejected for V0 because ULID is human-readable, sortable, and avoids forcing UUID v7 generation libraries.

### 3.3 Generation

```php
final class UlidGenerator
{
    public function generate(): string
    {
        $timestampMs = (int) (microtime(true) * 1000);
        $timeBytes   = pack('NnC', ...);  // 6 bytes, big-endian
        $randomBytes = random_bytes(10);
        return $this->encodeCrockfordBase32($timeBytes . $randomBytes);
    }

    private function encodeCrockfordBase32(string $bytes): string
    {
        // 16 bytes → 26 base32 chars
        // standard 5-bit grouping with Crockford alphabet
    }
}
```

~40 lines including the Crockford alphabet table. No external dependency.

### 3.4 Identity flow

- `SqlPersistenceDriver::generateIdentity(string $entityFqn): IdentityHandle` calls `UlidGenerator::generate()`, wraps in `IdentityHandle`.
- `SqlRepository::create($payload, ?$identity)`: if `$identity` is null, calls `generateIdentity`. Otherwise uses the supplied handle (per RFC-002 §6 application-supplied identity).
- ULID is opaque to plugins per RFC-002 §6.2.

### 3.5 Composite keys

Not supported in V0. Single-column `id` only. Per RFC-002 §6.3, composite keys are encoded as one opaque string by drivers that need them; we do not need any in V0.

---

## 4. Tenancy strategy

### 4.1 Row-level only

Per RFC-003 §4 + RFC-012 §3.2: row-level tenancy is the only strategy in V0. Every Entity table has a `tenant_id` column. Every SELECT, UPDATE, DELETE includes `WHERE tenant_id = ?`.

### 4.2 Column shape

`tenant_id VARCHAR(64) NOT NULL` on every Entity table. 64 chars chosen to accommodate ULIDs (26 chars) plus the reserved `__system__` literal plus future identifiers.

### 4.3 Enforcement points

| Operation         | Enforcement                                                                          |
|-------------------|--------------------------------------------------------------------------------------|
| `find(Reference)` | Verify `Reference::tenantId() === activeTenant`; SELECT predicates `WHERE tenant_id = ?` |
| `findMany(filter)`| SqlQueryCompiler injects `tenant_id = ?` as the outermost AND                         |
| `create(payload)` | INSERT includes `tenant_id = activeTenant`                                           |
| `update(ref, ...)`| Verify ref's tenant; UPDATE includes `tenant_id = ?` in WHERE                        |
| `delete(ref, ...)`| Same                                                                                 |

If `Reference::tenantId()` differs from the active Tenant, `SqlErrorMapper` raises `TenantBoundaryViolation` (RFC-002 §12.1) BEFORE the SQL is even constructed. Defence in depth: even if the check is missed, the SQL `WHERE tenant_id = ?` ensures cross-tenant rows are not returned.

### 4.4 System Tenant

`tenant_id = '__system__'` per RFC-003 §12.1. Kernel-internal Entities (audit log, tenant catalog, override store) live with `tenant_id = '__system__'`. The driver treats `system` identically to any other Tenant; the literal value is the only special handling.

### 4.5 Index strategy

- Primary key: `id` (single-column).
- Tenancy index: `(tenant_id)` for table-wide tenant filtering.
- Per-column indexes: `(tenant_id, <filterable_field>)` for every Field that appears in `Projection.filters`.
- Unique constraints: `UNIQUE (tenant_id, <field>)` for every Field declared `uniqueWithinTenant()`.

Per-Field index selection is conservative for V0: index every Field referenced in any Projection's `filters` array. Production deployments tune; V0 prioritizes correctness.

---

## 5. Table derivation

### 5.1 Naming

Entity FQN → table name: replace dots with underscores. `billing.invoice` → `billing_invoice`. Singular (no pluralization). Plugin author cannot override in V0.

### 5.2 Column derivation (FieldTypeMapper)

Per FieldNode → SQL column:

| FieldNode type | Postgres column                                                  | SQLite column        |
|----------------|------------------------------------------------------------------|----------------------|
| `string`       | `VARCHAR(typeOptions.maxLength)` (default 255) or `TEXT` if >65535 | `TEXT`               |
| `integer`      | `INTEGER` (or `BIGINT` if `typeOptions.bigint === true`)         | `INTEGER`            |
| `decimal`      | `NUMERIC(precision, scale)` (default 12, 2)                      | `NUMERIC(p, s)` (stored as TEXT under the hood) |
| `boolean`      | `BOOLEAN`                                                        | `INTEGER` (0/1)      |
| `date`         | `DATE`                                                           | `TEXT` (ISO 8601)    |
| `datetime`     | `TIMESTAMPTZ`                                                    | `TEXT` (ISO 8601)    |
| `time`         | `TIME`                                                           | `TEXT`               |
| `enum`         | `VARCHAR(64) CHECK (col IN ('v1','v2',...))`                     | `TEXT CHECK (...)`   |
| `money`        | Two columns: `<name> NUMERIC(14, 2)` + `<name>_currency VARCHAR(3)` | same             |
| `json`         | `JSONB`                                                          | `TEXT` + JSON check  |
| `reference`    | `VARCHAR(26)` + foreign-key constraint                           | same                 |
| `identity`     | `VARCHAR(26) PRIMARY KEY`                                        | same                 |
| `version`      | `VARCHAR(26)` (`_version`)                                       | same                 |

Nullability per FieldNode.`nullable`; default per FieldNode.`default`.

### 5.3 System fields injected by the compiler

Per the compiler design §6.4 + §10.7: the Compiler's Normalizer injects `id`, `tenant_id`, `_version`, `created_at`, `updated_at`. The SchemaDeriver emits them as the first columns in the CREATE TABLE.

### 5.4 Example: `billing.invoice`

For the HelloInvoice Entity (RFC-011 §2.1), the SchemaDeriver produces:

```sql
CREATE TABLE IF NOT EXISTS billing_invoice (
    id              VARCHAR(26)   NOT NULL,
    tenant_id       VARCHAR(64)   NOT NULL,
    number          VARCHAR(32)   NOT NULL,
    customer_name   VARCHAR(200)  NOT NULL,
    amount          NUMERIC(14,2) NOT NULL,
    amount_currency VARCHAR(3)    NOT NULL DEFAULT 'USD',
    status          VARCHAR(64)   NOT NULL DEFAULT 'DRAFT'
                    CHECK (status IN ('DRAFT','ISSUED','CANCELLED')),
    issued_at       TIMESTAMPTZ   NULL,
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    _version        VARCHAR(26)   NOT NULL,

    PRIMARY KEY (id),
    CONSTRAINT billing_invoice_number_uniq UNIQUE (tenant_id, number)
);

CREATE INDEX IF NOT EXISTS billing_invoice_tenant_idx        ON billing_invoice (tenant_id);
CREATE INDEX IF NOT EXISTS billing_invoice_tenant_status_idx ON billing_invoice (tenant_id, status);
```

`amount` becomes two columns (NUMERIC + currency). `status` becomes VARCHAR with CHECK. `uniqueWithinTenant()` becomes a UNIQUE constraint on `(tenant_id, field)`.

For SQLite, the same logic emits:

```sql
CREATE TABLE IF NOT EXISTS billing_invoice (
    id              TEXT NOT NULL,
    tenant_id       TEXT NOT NULL,
    number          TEXT NOT NULL,
    customer_name   TEXT NOT NULL,
    amount          NUMERIC NOT NULL,
    amount_currency TEXT NOT NULL DEFAULT 'USD',
    status          TEXT NOT NULL DEFAULT 'DRAFT'
                    CHECK (status IN ('DRAFT','ISSUED','CANCELLED')),
    issued_at       TEXT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now')),
    _version        TEXT NOT NULL,

    PRIMARY KEY (id),
    UNIQUE (tenant_id, number)
);
```

Same structure, SQLite-flavored types.

---

## 6. Migration flow

### 6.1 Strategy: idempotent derive-and-apply

Per RFC-012 §3.4: no per-Entity `.php` migration files. The schema is derived from the current MetadataGraph and applied directly.

### 6.2 `php artisan ausus:migrate` flow

```
1. Boot Laravel; KernelServiceProvider compiles the MetadataGraph.
2. SqlSchemaDeriver::derive($graph) → TableDdl[]
   - For every EntityNode, produce one TableDdl with CREATE TABLE IF NOT EXISTS + indexes + constraints.
3. For each TableDdl:
   a. Check if the table exists (query information_schema.tables for Postgres; sqlite_master for SQLite).
   b. If absent: execute the CREATE TABLE.
   c. If present: introspect existing columns (information_schema.columns); ALTER TABLE ADD COLUMN for missing columns.
   d. Apply CREATE INDEX IF NOT EXISTS for declared indexes (Postgres native; SQLite supports IF NOT EXISTS).
4. Report applied changes to stdout.
```

V0 limitations (documented):

- **Add-only.** ALTER ADD COLUMN works; ALTER DROP / RENAME does not (would risk data loss). Field removals require manual migration in V0.
- **Type widening only.** Changing a column's type (e.g., VARCHAR(32) → VARCHAR(64)) is not attempted in V0. Type changes require manual migration.
- **No history table.** V0 does not record which migrations ran. Re-running `ausus:migrate` is idempotent (CREATE/ALTER IF NOT EXISTS); no harm in repeated invocations.
- **No down migrations.** Forward-only.

### 6.3 Kernel-managed tables

The kernel's own Entities (`kernel.audit_log` via `ausus/audit-database`, `kernel.tenant` and `kernel.tenant_override` via `ausus/tenancy-row`) are derived the same way. Their compiler descriptors are registered by the kernel's own service provider. `ausus:migrate` emits their tables alongside plugin-owned tables.

### 6.4 Production deployment

Run `php artisan ausus:migrate` in the deploy pipeline before swapping to the new release. Idempotent; safe to re-run.

### 6.5 Future (deferred)

- Migration history table (M2/M3).
- Down migrations (post-V1).
- Type-widening detection (post-V1).
- Online ALTER for large tables (DB-specific; post-V1).
- Schema diff preview (`ausus:migrate --dry-run`).

---

## 7. Repository implementation plan

### 7.1 Construction

`SqlRepository` is constructed by `SqlPersistenceContext::repository($entityFqn)`. Each instance binds:

```php
final class SqlRepository implements Repository
{
    public function __construct(
        private SqlPersistenceContext $context,        // for tenant() and transaction()
        private EntityNode $entity,                     // from MetadataGraph
        private string $tableName,                      // derived from entity FQN
        private ConnectionInterface $connection,        // Laravel DB connection
        private SqlQueryCompiler $compiler,
        private SqlErrorMapper $errors,
        private UlidGenerator $identity,
    ) {}
}
```

Constructed per-Entity, per-request, per-(Tenant, Transaction). Cheap to construct; not cached.

### 7.2 `find(Reference $ref): ?Entity`

```
1. Verify $ref->tenantId() === $this->context->tenant()->id()->value(); else throw TenantBoundaryViolation.
2. SELECT * FROM <table> WHERE id = ? AND tenant_id = ?
3. If 0 rows: return null.
4. If 1 row: hydrate into Entity value object (mapping DB row → field array → Entity with Reference + Version).
5. If >1 row: impossible (PK enforcement); but if observed: throw DriverError.
```

Returns the kernel value object `Entity`, never an Eloquent model.

### 7.3 `findMany(Filter $filter, ?Sort $sort, ?int $limit, ?Cursor $after): EntityPage`

```
1. Compile $filter via SqlQueryCompiler → SqlClause.
2. Build full query: SELECT * FROM <table> WHERE tenant_id = ? AND (<filter-clause>) ORDER BY ... LIMIT ?+1.
3. If $after: append cursor-based WHERE predicate (id > <last_id>).
4. Execute with bindings.
5. Slice to $limit results (the +1 row indicates "more pages exist").
6. Encode nextCursor as base64 of {last_id} of the last returned row.
7. Hydrate rows; return EntityPage{items, nextCursor, totalEstimate: null, pageSize: $limit}.
```

`totalEstimate` is null in V0; computing exact counts via separate query is deferred.

### 7.4 `create(array $payload, ?IdentityHandle $identity = null): Entity`

```
1. If $identity is null: generate ULID via UlidGenerator.
2. Set system fields: id, tenant_id (from context), _version (new ULID), created_at, updated_at (NOW).
3. Build column list from $payload + system fields.
4. Validate every payload key matches a declared Field on the Entity; reject otherwise (throws UnknownField → DriverError; design choice for V0).
5. INSERT INTO <table> (<columns>) VALUES (?, ?, ?, ...)
6. On QueryException: catch and map via SqlErrorMapper.
7. Return Entity hydrated from the inserted values (no SELECT round-trip; the values are already known).
```

### 7.5 `update(Reference $ref, array $patch, Version $expected): Entity`

The critical method: optimistic locking lives here.

```
1. Verify ref tenant matches active.
2. Set _version = new ULID (for the new version).
3. Set updated_at = NOW().
4. Build UPDATE: UPDATE <table>
                  SET <patch columns>, _version = ?, updated_at = ?
                  WHERE id = ? AND tenant_id = ? AND _version = ?
5. Execute. Inspect rowsAffected.
6. If rowsAffected == 1: success. Hydrate Entity from patch + system updates; return.
7. If rowsAffected == 0:
   a. SELECT _version FROM <table> WHERE id = ? AND tenant_id = ?
   b. If row missing: throw NotFound(ref).
   c. If row present with different _version: throw ConcurrencyConflict(ref, expected, actual).
8. On QueryException: catch and map.
```

This is the canonical optimistic-locking pattern. RFC-002 §8 explicitly requires every update to verify version.

### 7.6 `delete(Reference $ref, Version $expected): void`

```
1. Verify ref tenant.
2. DELETE FROM <table> WHERE id = ? AND tenant_id = ? AND _version = ?
3. If rowsAffected == 1: success.
4. If rowsAffected == 0: same disambiguation as update (NotFound vs ConcurrencyConflict).
5. On QueryException with FK violation: ConstraintViolation('foreign_key', ...).
```

### 7.7 `exists` and `count`

```
exists(Reference): SELECT 1 FROM <table> WHERE id = ? AND tenant_id = ? LIMIT 1
count(Filter):    SELECT COUNT(*) FROM <table> WHERE tenant_id = ? AND <filter-clause>
```

Both straightforward.

### 7.8 Deferred methods (V0 stubs)

`iterate`, `fetchRelated`, `updateMany`, `deleteMany` are interface members on Repository; V0 implementations throw `NotImplementedInV0(method, "deferred to M2")`. This satisfies PHP's interface contract without implementing.

The kernel's `BulkOutsideMaintenanceAction` (RFC-002 §11.4 / §12.1) is raised by the Invoker before reaching the Repository's bulk methods, so the stubs are reachable only via direct (bypass-attempted) calls — flagged in `ausus:doctor`.

---

## 8. SQL generation flow

### 8.1 Single compiler entry point

```php
final class SqlQueryCompiler
{
    public function compile(EntityNode $entity, Filter $filter, Tenant $tenant): SqlClause
    {
        // returns { sql: "tenant_id = ? AND (...)", bindings: [tenant_id, ...] }
    }
}
```

`SqlClause` is the value-object output; later assembled into full SELECT / DELETE / UPDATE.

### 8.2 V0 Filter node support

From RFC-002 §10.1 grammar (10 closed types). V0 implements 6:

| Filter node          | V0 | SQL                                             |
|----------------------|-----|-------------------------------------------------|
| `And(children[])`    | ✓  | `(c1 AND c2 AND ...)`                            |
| `Or(children[])`     | ✓  | `(c1 OR c2 OR ...)`                              |
| `Not(child)`         | ✓  | `NOT (c)`                                        |
| `FieldEquals(f, v)`  | ✓  | `<f> = ?` with `v` bound                         |
| `FieldIn(f, values)` | ✓  | `<f> IN (?, ?, ...)` with values bound           |
| `FieldNull(f)`       | ✓  | `<f> IS NULL`                                    |
| `ReferenceEquals(r)` | ✓  | `id = ? AND tenant_id = ?` (already covered by active-tenant injection) |
| `FieldComparison`    | ◐  | `<f> <op> ?` — V0 implements but treats unknown op as error |
| `FieldRange`         | ✗  | Deferred (use AND of two FieldComparison)        |
| `FieldStringMatch`   | ✗  | Deferred to M2                                   |
| `RelationExists`     | ✗  | No Relations in V0                               |

### 8.3 Compilation algorithm

Recursive descent over the Filter tree:

```php
public function compile(EntityNode $entity, Filter $filter, Tenant $tenant): SqlClause
{
    $inner = $this->compileNode($entity, $filter);
    return new SqlClause(
        sql: "tenant_id = ? AND ({$inner->sql})",
        bindings: [$tenant->id()->value(), ...$inner->bindings],
    );
}

private function compileNode(EntityNode $entity, Filter $node): SqlClause
{
    return match (true) {
        $node instanceof FieldEquals => $this->compileEquals(...),
        $node instanceof And_        => $this->compileAnd(...),
        $node instanceof Or_         => $this->compileOr(...),
        // ...
        default => throw new DriverError("Unsupported filter node: " . $node::class),
    };
}
```

### 8.4 Field reference resolution

When a Filter references `field_name`, the compiler:

1. Looks up the FieldNode on the Entity.
2. Maps to SQL column name (1:1 for most types; `money` becomes two columns).
3. Wraps the column name in driver-appropriate quoting (`"<col>"` for Postgres, `` `<col>` `` for SQLite, none for ANSI fallback).

`money` field comparisons in V0 compare only the numeric component; currency-aware comparison is deferred. (HelloInvoice uses USD only.)

### 8.5 Parameter binding

Every value is bound via PDO parameter placeholders (`?`). The compiler NEVER concatenates values into SQL. Defence against injection is structural.

### 8.6 Sort and pagination

`Sort` is a simple list of `(field, direction)` pairs. Compiled to `ORDER BY <col1> <dir1>, <col2> <dir2>`.

Cursor pagination:

```php
final class CursorEncoder
{
    public function encode(array $sortKeys): string  // base64 JSON
    public function decode(string $cursor): array
}
```

V0 cursor shape: `base64({last_id, last_sort_values: [...]})`. For "next page" the WHERE clause adds `(sort_value > ? OR (sort_value = ? AND id > ?))` for the last sort key plus tiebreaker on id.

For V0 simplicity, the only sort supported is by `id ASC` (creation order). Multi-column sort cursors deferred to M2.

### 8.7 Query trace

In `APP_DEBUG=true`, the compiler logs:

```
[ausus.sql] entity=billing.invoice filter=FieldEquals(status, "ISSUED") tenant=acme
[ausus.sql] sql="SELECT * FROM billing_invoice WHERE tenant_id = ? AND (status = ?)" bindings=["acme","ISSUED"]
```

For dev only. Production logging is RFC-009 telemetry territory.

---

## 9. Optimistic locking

### 9.1 The `_version` column

Every Entity row carries a `_version` (VARCHAR(26), opaque ULID). On every write, the driver generates a new ULID and stores it.

### 9.2 Update flow (restated for clarity)

```sql
UPDATE billing_invoice
   SET <fields>,
       _version   = '<new_ulid>',
       updated_at = NOW()
 WHERE id         = ?
   AND tenant_id  = ?
   AND _version   = '<expected_ulid>';
```

`rowsAffected == 0` is the conflict signal.

### 9.3 Concurrency disambiguation

When `rowsAffected == 0`, the cause is ambiguous between "row doesn't exist" and "version mismatch." Disambiguation requires a follow-up SELECT (§7.5 step 7). This is a known performance cost (extra round-trip on every failed update) accepted in V0 because:

- Updates succeed in the common case (one round-trip).
- Failed updates are exception paths; the extra latency is acceptable for clearer errors.
- The alternative (single-trip with `RETURNING _version` in Postgres) would diverge SQLite/Postgres code paths.

### 9.4 No bypass

Per RFC-002 §8.4: optimistic locking is mandatory for `update` and `delete`. The `Version` parameter is required; the driver does not accept null. The compiler-side check that callers pass a version is the kernel's concern; the driver assumes a non-null `Version` argument.

### 9.5 Bulk operation exception

Per RFC-002 §11.4: bulk operations skip per-row version checks (last-write-wins). V0 defers `updateMany` / `deleteMany`; the exception is documented but not exercised.

---

## 10. Transactions

### 10.1 Driver methods

```php
public function beginTransaction(Tenant $tenant): TransactionHandle
{
    $this->connection->beginTransaction();
    return new SqlTransactionHandle(
        tenant: $tenant,
        depth: $this->connection->transactionLevel(),
    );
}

public function commit(TransactionHandle $handle): void
{
    $this->connection->commit();
}

public function rollback(TransactionHandle $handle): void
{
    $this->connection->rollBack();
}
```

### 10.2 Nested savepoints

Laravel's `ConnectionInterface::beginTransaction()` automatically uses `SAVEPOINT trans<N>` for nested calls. `commit()` releases the savepoint; `rollback()` rolls back to the savepoint.

V0 relies on this built-in behavior. RFC-002 §7.4 requires 8 levels of nesting; Laravel handles arbitrary depth (Postgres native, SQLite via emulation).

### 10.3 Transaction boundary plan

```
Invoker.invoke(actor, actionFqn, subject, inputs):
    txn = driver.beginTransaction(activeTenant)
    try:
        ctx = driver.context(activeTenant, txn)
        # Policy chain (RFC-005)
        # Workflow guard (RFC-006)
        outputs = effect.execute(ctx, subject, inputs)        # Repository operations within txn
        # Audit emission (RFC-007)
        if primary_audit_acked:
            driver.commit(txn)
            return outputs
        else:
            driver.rollback(txn)
            throw AuditEmissionFailed
    except Throwable as e:
        driver.rollback(txn)
        throw EffectFailed(actionFqn, e)
```

This matches RFC-001 §A-1.4 §8.2.1 exactly. The driver does nothing beyond the three methods; the Invoker owns the lifecycle.

### 10.4 Cross-Tenant prohibition

`beginTransaction(tenantA)` followed by `context(tenantB, handle)` raises `TenantBoundaryViolation` per RFC-002 §7.5. The driver verifies `tenantB === handle.tenant` before constructing the context.

### 10.5 No XA / 2PC

V0 single-driver only. Distributed transactions are not in scope (RFC-002 §14).

---

## 11. Error mapping

### 11.1 Mapping table

| Native exception / condition                                       | Mapped PersistenceError                                       |
|--------------------------------------------------------------------|---------------------------------------------------------------|
| `find()` returns 0 rows                                            | `null` (NotFound is for write paths only per RFC-002 §5.3.3)  |
| `update()` rowsAffected = 0; row exists                            | `ConcurrencyConflict(ref, expected, actual)`                  |
| `update()` / `delete()` rowsAffected = 0; row missing              | `NotFound(ref)`                                               |
| Postgres SQLSTATE `23505` (unique violation)                       | `ConstraintViolation('unique_violation', details)`            |
| Postgres SQLSTATE `23503` (foreign key violation)                  | `RelationConstraintViolation(relation, details)`              |
| Postgres SQLSTATE `23502` (not null)                               | `ConstraintViolation('not_null', column)`                     |
| Postgres SQLSTATE `23514` (check)                                  | `ConstraintViolation('check_violation', constraint_name)`     |
| Postgres SQLSTATE `42P01` (table missing) / SQLite `no such table` | `DriverError('schema-missing', cause)` + doctor flags          |
| `PDOException` connection refused / network                        | `DriverError('connection', cause)`                            |
| Active Tenant ≠ Reference tenant                                   | `TenantBoundaryViolation(attempted, expected)` (driver-side, before SQL) |
| Unknown filter operator                                            | `DriverError('unsupported_filter', op)`                       |
| Bulk size exceeded `maxBulkTransactionSize`                        | `TransactionTooLarge(estimate, limit)`                        |
| Attempt to call `iterate` / `fetchRelated` / `updateMany` / `deleteMany` in V0 | `NotImplementedInV0(method)` (custom V0-only error) |
| Any other `PDOException`                                           | `DriverError(message, cause)`                                  |

### 11.2 SQLSTATE-based detection

`QueryException::getCode()` returns the SQLSTATE (Postgres) or driver-specific code (SQLite — different shape; mapper has a small `if-instance` check). The mapper is a single class with ~50 lines of conditionals.

### 11.3 Native exception suppression

Per RFC-002 §12.2: native exceptions MUST NOT propagate to the caller. Every Repository method wraps its body in try/catch on `\Throwable`; the mapper converts; the converted error is rethrown.

```php
public function find(Reference $ref): ?Entity
{
    try {
        // ... SQL ...
    } catch (\Throwable $e) {
        throw $this->errors->map($e, context: ['op' => 'find', 'entity' => $this->entity->fqn]);
    }
}
```

### 11.4 No leak of inner exception in production

In `APP_ENV=production`, the `cause` field on the mapped error is preserved but NOT included in the error's `getMessage()` output. Postgres error messages can include row values (per Postgres conventions); we do not surface those to callers. Per RFC-002 §18.10: drivers MUST scrub error details to the active Tenant.

V0 scrubbing: strip everything after the first 200 chars of any native message. Crude but safe. M2 adds proper scrubbing.

---

## 12. Bulk operation handling

### 12.1 V0 behavior

`updateMany`, `deleteMany`, `iterate` — all raise `NotImplementedInV0("$method deferred to M2")` when called.

The Repository interface (kernel-defined) requires these methods to exist. PHP interface contract is satisfied by stub methods that throw.

### 12.2 Why deferred

- HelloInvoice does not require bulk operations.
- MaintenanceActions are out of M1 scope (per `docs/IMPLEMENTATION-PLAN.md` §13 item 5).
- Implementing bulk correctly requires: chunked DDL, BulkResult shape with sample_handles, proper interaction with the all-or-nothing transactional rule (RFC-002 §11.6).

### 12.3 What the kernel does

Per RFC-002 §11.4: bulk operations require a MaintenanceAction with `acknowledges_bulk_lwm: true`. The kernel's Invoker raises `BulkOutsideMaintenanceAction` before the Repository is ever called.

Combined: V0 has neither MaintenanceActions exercised nor a bulk implementation; both fronts are aligned.

### 12.4 M2 implementation sketch (deferred)

When implemented:

```php
public function updateMany(Filter $filter, array $patch): BulkResult
{
    // SELECT id FROM <table> WHERE tenant_id = ? AND <filter> LIMIT maxBulk + 1
    // If count > maxBulk: throw TransactionTooLarge
    // UPDATE <table> SET <patch> WHERE id IN (<collected_ids>)
    // Construct BulkResult{affectedCount, sampleHandles: first 100 ids, failedHandles: []}
}
```

Atomic within the active Invoker transaction per RFC-002 §11.6.

---

## 13. Doctor validations

The `ausus/persistence-sql` package contributes the following checks to `ausus:doctor`:

| # | Check                                                                                     | Severity |
|---|-------------------------------------------------------------------------------------------|----------|
| 1 | Connection reachable (SELECT 1)                                                            | error    |
| 2 | Every Entity in the MetadataGraph has a corresponding table                                | error    |
| 3 | Every Entity table has `id`, `tenant_id`, `_version`, `created_at`, `updated_at` columns  | error    |
| 4 | Every Entity table has a PRIMARY KEY on `id`                                              | error    |
| 5 | Every `enum` Field has a CHECK constraint matching its declared values                    | warning  |
| 6 | Every `uniqueWithinTenant` Field has a UNIQUE (tenant_id, field) constraint               | warning  |
| 7 | Every Field in a Projection's `filters` has an index `(tenant_id, field)`                 | notice   |
| 8 | `kernel_audit_log` table exists (audit-database package contributes)                       | error    |
| 9 | Database is Postgres (preferred) or SQLite (acceptable for dev); not MySQL (V0 unsupported)| warning  |
| 10 | ULID generator produces 26-char Crockford base32 (sample 10 generations)                 | error    |
| 11 | Transaction depth supports at least 8 nested savepoints (test: nest, commit, rollback)    | error    |
| 12 | Repository's bulk methods raise `NotImplementedInV0` (V0 marker; removed in M2)            | notice   |

Items marked `error` fail boot via `ausus:doctor --strict`. Items marked `warning` continue but log. Items marked `notice` are informational.

---

## 14. PostgreSQL vs SQLite differences

### 14.1 Where V0 diverges

| Aspect                  | Postgres                                  | SQLite                                    |
|-------------------------|-------------------------------------------|-------------------------------------------|
| Column types            | Native (`VARCHAR`, `NUMERIC`, `TIMESTAMPTZ`, `JSONB`) | All TEXT/NUMERIC (no JSONB; no TIMESTAMPTZ) |
| Identifier quoting      | `"col"`                                   | `"col"` (compatible)                      |
| `CREATE INDEX IF NOT EXISTS` | supported                           | supported (3.40+)                         |
| `ALTER TABLE ADD COLUMN`| straightforward                           | supported with limitations (no FK additions to existing tables) |
| `information_schema`    | available                                  | use `sqlite_master` instead              |
| SQLSTATE in errors      | yes (standard codes)                       | driver-specific codes                     |
| Concurrent transactions | full MVCC                                  | one-writer-at-a-time (database-level lock) |
| `SAVEPOINT`             | native                                     | native                                    |

### 14.2 Code structure

A single `Postgres` driver class handles Postgres. A thin `Sqlite` driver class subclasses or composes with type-mapping overrides. NOT a polymorphic class hierarchy — V0 implements Postgres in full and adds SQLite via a `if ($connection->getDriverName() === 'sqlite')` switch where types diverge. Minimal, ugly, V0-honest.

If SQLite support proves to add >100 LOC of conditional logic, drop it and require Postgres for V0 (per the user's "SQLite optional only if nearly free" constraint).

### 14.3 MySQL deferral

MySQL is NOT supported in V0. The driver does not emit MySQL-specific SQL. If a deployment configures MySQL, the `ausus:doctor` warning (§13 item 9) fires; the driver may still work for trivial cases (CREATE TABLE syntax overlaps) but is unsupported.

M2 may add MySQL after Postgres is stable.

---

## 15. Implementation order

### 15.1 Day-by-day (within M1, after kernel is M1-complete)

Aligned with `docs/IMPLEMENTATION-PLAN.md` §12 sprint plan. M1 days 5-7 cover this driver.

| Day | Focus                                                                                              |
|-----|----------------------------------------------------------------------------------------------------|
| 1   | `UlidGenerator`, `SqlDriverCapabilities`, `SqlTransactionHandle` value objects; tests             |
| 1   | `SqlPersistenceServiceProvider` skeleton; binds the driver in container                            |
| 2   | `SqlPersistenceDriver` — `beginTransaction` / `commit` / `rollback` / `generateIdentity` / `capabilities` |
| 2   | `SqlPersistenceContext` — `repository()` factory; `tenant()`, `transaction()` introspection         |
| 3   | `SqlRepository` skeleton + `find` + `create`; integration test against SQLite                      |
| 4   | `SqlQueryCompiler` — Filter → SqlClause for the 7 V0 filter node types                              |
| 4   | `SqlRepository::findMany` with cursor pagination                                                   |
| 5   | `SqlRepository::update` + `delete` with optimistic locking; ConcurrencyConflict disambiguation     |
| 5   | `SqlErrorMapper` — full SQLSTATE table for Postgres + SQLite                                       |
| 6   | `SqlSchemaDeriver` + `FieldTypeMapper` for the 11 Standard Stack Field Types                       |
| 6   | `MigrateCommand` (`php artisan ausus:migrate`) + idempotent CREATE/ALTER                           |
| 7   | `DoctorCheck` extension contributing 12 checks                                                     |
| 7   | Integration test against Postgres (CI) + SQLite (local)                                            |

Seven working days. Approximately 1,500 LOC including tests.

### 15.2 Parallelization

Once the kernel ships M1 compiler + graph types, this driver can be built in parallel with `ausus/tenancy-row`, `ausus/audit-database`, `ausus/auth-bridge`, `ausus/runtime-default`. All five depend on `ausus/kernel` and on no other AUSUS package.

---

## 16. Minimal executable milestone

### 16.1 Definition of done

A single PHPUnit test in `apps/playground/tests/PersistenceDriverTest.php` that:

```
1. Boot Laravel with KernelServiceProvider + SqlPersistenceServiceProvider.
2. Register a one-Entity test plugin ("test.invoice" with fields: id, tenant_id, _version, number, customer_name, amount, status).
3. Run `ausus:migrate` against an in-memory SQLite database.
4. Assert that the `test_invoice` table exists with all expected columns.
5. Bootstrap a Tenant: tenant_id = "test-tenant-1".
6. Acquire a PersistenceDriver from the container.
7. driver.beginTransaction(tenant) → handle
8. context = driver.context(tenant, handle)
9. repo = context.repository("test.invoice")
10. invoice = repo.create([
        "number" => "INV-001",
        "customer_name" => "Test Customer",
        "amount" => 1500.00,
        "status" => "DRAFT",
    ])
11. Assert invoice.field("status") === "DRAFT"
12. Assert invoice.version() is not null and is 26 chars
13. driver.commit(handle)
14. # In a new transaction:
15. handle2 = driver.beginTransaction(tenant)
16. context2 = driver.context(tenant, handle2)
17. repo2 = context2.repository("test.invoice")
18. loaded = repo2.find(invoice.reference())
19. Assert loaded is not null
20. Assert loaded.field("number") === "INV-001"
21. updated = repo2.update(loaded.reference(), ["status" => "ISSUED"], loaded.version())
22. Assert updated.field("status") === "ISSUED"
23. Assert updated.version() !== loaded.version()
24. # Stale write attempt:
25. try {
        repo2.update(loaded.reference(), ["status" => "CANCELLED"], loaded.version())
        // loaded.version() is now stale because we already updated to a new version
        fail("Expected ConcurrencyConflict")
    } catch (ConcurrencyConflict $e) { /* pass */ }
26. driver.commit(handle2)
27. # Verify cross-Tenant isolation:
28. otherTenant = Tenant("test-tenant-2")
29. handle3 = driver.beginTransaction(otherTenant)
30. context3 = driver.context(otherTenant, handle3)
31. repo3 = context3.repository("test.invoice")
32. result = repo3.find(invoice.reference())  // ref tenant != active tenant
33. // EITHER raises TenantBoundaryViolation OR returns null (Reference rejected before SQL)
34. assert that result reflects cross-tenant blocking
35. driver.rollback(handle3)
```

All 35 steps pass. That's the M1 acceptance for this driver.

### 16.2 Coverage of RFC-002 clauses

This test exercises:

- Driver lifecycle (§3.1)
- Context construction (§4.1)
- Repository find / create / update (§5.1)
- Identity generation (§6.1)
- Optimistic locking (§8)
- Transaction begin / commit / rollback (§7)
- Tenant scope enforcement (§13.1)
- ConcurrencyConflict error (§12.1)

Single test, ~35 assertions, covers ~70% of RFC-002's V0-relevant clauses.

### 16.3 Out of M1 milestone (deferred)

- `findMany` with cursor pagination (will be tested in M2 when ViewSchema needs lists)
- `delete` with optimistic locking (tested if added; not strictly required for HelloInvoice's M1 path)
- `count`, `exists` (added when needed by ViewSchema)
- ConstraintViolation paths (tested when validation matters)
- All bulk methods (M2+)
- Schema diff / type widening (post-V1)

---

## 17. Summary

- **15 classes**, ~1,500 LOC including tests.
- **Postgres first-class, SQLite secondary, MySQL ignored.**
- **Row-level tenancy only**; every operation injects `WHERE tenant_id = ?`.
- **ULID identity** in `VARCHAR(26)`.
- **Optimistic locking via `_version` column**; conflict surfaces after a single follow-up SELECT.
- **Schema derived from MetadataGraph** at `ausus:migrate` time; no per-Entity migration files; idempotent CREATE/ALTER.
- **V0 implements**: `find`, `findMany`, `create`, `update`, `delete`, `exists`, `count`, transactions, identity generation, capabilities.
- **V0 stubs**: `iterate`, `fetchRelated`, `updateMany`, `deleteMany` raise `NotImplementedInV0`.
- **Error mapping**: 12 native conditions → 9 RFC-002 §12.1 error types via single `SqlErrorMapper` class.
- **12 doctor checks** contributed to `ausus:doctor`.
- **Single integration test** with 35 assertions covers ~70% of RFC-002's V0-relevant surface.

Built in 7 working days within M1 days 5-7 of the sprint plan.
