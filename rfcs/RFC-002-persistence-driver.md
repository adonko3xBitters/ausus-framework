# RFC-002 — Persistence Driver contract for AUSUS V1

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Draft                                                  |
| Authors       | architect, kernel, challenger                          |
| Date          | 2026-05-18                                             |
| Depends on    | RFC-001 Draft-03 (includes Amendment-01)               |
| Supersedes    | —                                                      |
| Stability     | Foundational. Changes after acceptance require a follow-up RFC. |

---

## 0. Problem statement

RFC-001 names `PersistenceDriver` and `Repository` as kernel contracts (§5.2) and asserts that drivers are interchangeable (§3.2.5). RFC-001 deliberately specifies neither contract: it asserts only that plugins MUST NOT import Eloquent (§3.2.5), that the canonical reference tuple is `(tenant_id, entity_fqn, identity_handle)` (§2.1.1.4), and that Amendment-01 §A-1.4 / §A-1.6 require a transaction contract the Invoker can roll back when the primary audit sink fails.

This RFC defines those contracts and the surrounding ones (identity generation, optimistic locking, relation persistence, query surface, bulk operations, error model, tenancy enforcement) in enough detail that:

- a plugin author can write Action effects against a stable, ORM-agnostic surface;
- a driver author can implement on top of Eloquent, Doctrine, or a non-relational store without leaking storage semantics;
- the Invoker can guarantee rollback under audit failure;
- the row / schema / database tenancy strategies of RFC-003 can all bind to the same driver contract;
- MaintenanceActions (RFC-001 §2.4.1) can mutate at bulk without breaking the §A-1.6 audit guarantee.

The ten-year horizon and SemVer discipline of RFC-001 (§6.4) apply: every method named in this RFC is part of the AUSUS V1 public surface and cannot be removed or have its semantics changed within the V1 major.

---

## 1. Scope and inherited constraints

### 1.1 Inherited from RFC-001

The following are not negotiable in this RFC; they are restated to make the constraint set local.

1. Drivers live at **L3**. They depend on the Kernel (L0) and on no higher layer (§3.2).
2. Plugins (L7) MUST NOT import Eloquent or any other driver-internal type (§3.2.5).
3. The **Invoker** (Amendment-01 §A-1.4) owns transaction boundaries. Drivers expose primitives; the Invoker decides when to begin, commit, and roll back.
4. The canonical cross-Entity reference is `(tenant_id, entity_fqn, identity_handle)` (§2.1.1.4). Drivers MUST accept it as input and emit it as part of returned Entities.
5. Identity handles are produced by the driver or by the application; they are opaque to the Kernel, serializable, and immutable (§2.1.1).
6. Tenant binding is immutable per-instance (§2.1.2.1). Moving an instance between Tenants is delete-and-recreate.
7. Cross-Tenant Relations are forbidden unless both endpoints are `system` (§2.1.2.3).
8. The audit primary sink failure path (Amendment-01 §A-1.6) requires that the driver be able to roll back the entire Action effect after the effect has run.
9. Field-level visibility (`FieldDescriptor.visibility`, Amendment-01 §A-1.2) is enforced by the **ReportingDriver** (RFC-010), not by this PersistenceDriver. Per-Field read-time policy filtering is **out of scope** for RFC-002.

### 1.2 Local scope

This RFC defines:

- The `PersistenceDriver` L3 contract.
- The `PersistenceContext` per-(Tenant, Transaction) view of a driver.
- The `Repository` per-Entity surface, including identity, transactions, optimistic locking, relations, queries, and bulk operations.
- The closed error taxonomy that drivers expose.
- The single-driver-per-deployment constraint for V1 (heterogeneous storage is deferred).

This RFC does NOT define:

- Specific identity types (UUID, ULID, snowflake) — drivers choose within §6's contract.
- Migration tooling — owned by a follow-up RFC.
- The ReportingDriver contract — owned by RFC-010.
- Tenancy isolation **storage** mechanics (row / schema / db) — owned by RFC-003.
- Concrete Field Types (`string`, `money`, `date`) — owned by the Field Types plugin.

---

## 2. Contracts overview

The L3 persistence surface consists of four interlocking contracts. All live in `Ausus\Kernel\Contracts\Persistence\`.

```
PersistenceDriver       configured once per deployment; produces PersistenceContext
PersistenceContext      bound to (Tenant, TransactionHandle); produces Repository
Repository              bound to one Entity FQN; performs all reads and writes
TransactionHandle       opaque token; lifecycle owned by the Invoker
```

The Invoker uses them as follows on every Action invocation:

```
1. tx = driver.beginTransaction(activeTenant)
2. ctx = driver.context(activeTenant, tx)
3. effect(ctx, subject, inputs)           // Action's registered effect
4. audit.emit(...)                         // primary sink synchronous
5a. on audit ack:    driver.commit(tx)
5b. on audit fail:   driver.rollback(tx); return error
```

Plugins author Action effects against `PersistenceContext`. They never see `PersistenceDriver` or `TransactionHandle`. The Invoker is the only authorized caller of `PersistenceDriver`'s transaction methods.

---

## 3. `PersistenceDriver`

### 3.1 Contract

```
interface PersistenceDriver
{
  // Lifecycle — called by the Invoker only.
  function beginTransaction(Tenant $tenant): TransactionHandle;
  function commit(TransactionHandle $handle): void;
  function rollback(TransactionHandle $handle): void;

  // Context construction — called by the Invoker after beginTransaction.
  function context(Tenant $tenant, TransactionHandle $handle): PersistenceContext;

  // Identity generation — see §6.
  function generateIdentity(string $entityFqn): IdentityHandle;

  // Capability advertisement — see §13, §15.
  function capabilities(): DriverCapabilities;
}
```

### 3.2 Invariants

1. Exactly one `PersistenceDriver` is bound per deployment (§15). A deployment with zero drivers is a boot-time error.
2. `beginTransaction` MUST require a Tenant. Cross-Tenant transactions are forbidden; the driver MUST reject any attempt to issue a Repository operation against a Tenant other than the one the transaction was opened for.
3. `commit` and `rollback` are terminal for the handle; subsequent calls on the same handle MUST raise `TransactionAborted`.
4. `context(...)` is cheap. Repeated calls with the same `(tenant, handle)` MUST return equivalent contexts (the contract does not require object identity, only behavioural equivalence).
5. `generateIdentity` is a pure function over `entity_fqn` for handle *shape*; the value MUST be unique within `(tenant_id, entity_fqn)` per §2.1.1.
6. `capabilities()` is invariant for the lifetime of the process. The driver MUST NOT advertise different capabilities per Tenant or per call.

### 3.3 What lives outside this contract

- Connection pooling, credential management, retry policies — driver-internal.
- Schema migrations, DDL — owned by a follow-up RFC; the driver may expose a separate maintenance surface, but it is not part of L0 contracts.
- Query optimization, prepared-statement caching — driver-internal.

---

## 4. `PersistenceContext`

### 4.1 Contract

```
interface PersistenceContext
{
  function repository(string $entityFqn): Repository;
  function tenant(): Tenant;
  function transaction(): TransactionHandle;   // introspection only
}
```

### 4.2 Invariants

1. Every Repository returned by a single `PersistenceContext` shares the same `(Tenant, TransactionHandle)`. A plugin cannot accidentally route writes for `billing.invoice` and `billing.invoice_line` into different transactions through a single context.
2. `repository(entityFqn)` for an FQN not present in the Metadata Graph MUST raise `UnknownEntity(entityFqn)`. The driver MUST NOT permit ad-hoc access to storage tables that do not correspond to a registered Entity.
3. `transaction()` exposes the handle for introspection (e.g., a plugin that wants to nest savepoints — see §7.4). Plugins MUST NOT pass the handle back to `PersistenceDriver::commit` or `::rollback`; doing so is undefined behaviour and SHOULD raise `UnauthorizedTransactionControl` if detected.

### 4.3 Lifetime

A `PersistenceContext` is valid only for the lifetime of its `TransactionHandle`. After `commit` or `rollback`, every Repository derived from the context becomes invalid; further calls MUST raise `TransactionAborted`.

---

## 5. `Repository`

### 5.1 Contract

```
interface Repository
{
  // Reads.
  function find(Reference $ref): ?Entity;
  function findMany(Filter $filter, ?Sort $sort = null, ?int $limit = null, ?Cursor $after = null): EntityPage;
  function exists(Reference $ref): bool;
  function count(Filter $filter): int;
  function iterate(Filter $filter, int $chunkSize = 1000): iterable;   // generator of Entity

  // Writes.
  function create(array $payload, ?IdentityHandle $identity = null): Entity;
  function update(Reference $ref, array $patch, Version $expected): Entity;
  function delete(Reference $ref, Version $expected): void;

  // Relations.
  function fetchRelated(Reference $ref, string $relationName, ?Filter $filter = null): EntityPage;

  // Bulk — see §11.
  function updateMany(Filter $filter, array $patch): BulkResult;
  function deleteMany(Filter $filter): BulkResult;
}
```

### 5.2 Argument and return types

- `Reference` — a value object wrapping `(tenant_id, entity_fqn, identity_handle)`. Constructed only by the Kernel; the driver does not subclass.
- `Filter` — see §10. A structured predicate; driver translates to its native query language.
- `Sort` — `[(field_fqn, asc|desc), ...]`.
- `Cursor` — opaque value emitted by `EntityPage`; opaque to plugins, parsed only by the driver that produced it.
- `IdentityHandle` — see §6.
- `Version` — see §8.
- `Entity` — a kernel-defined value object carrying `(reference, fields, version)`. Drivers MUST construct Entities through the Kernel-provided factory; subclassing is forbidden.
- `EntityPage` — `(items: Entity[], nextCursor: Cursor | null, totalCountEstimate: int | null)`.
- `BulkResult` — see §11.

### 5.3 Invariants

1. Every Entity returned by a Repository MUST carry a `Reference` whose `tenant_id` matches the active Tenant. The driver MUST NOT return Entities from a different Tenant under any circumstances, including misconfigured queries.
2. Every Entity returned MUST carry a `Version` (§8).
3. `find()` MUST return `null` for a non-existent reference. `update()` and `delete()` on a non-existent reference MUST raise `NotFound`.
4. `create()` MUST raise `ConstraintViolation` for any violation surfaced by the driver's underlying storage (unique, foreign-key, check). It MUST NOT silently coerce.
5. `update()` and `delete()` MUST raise `ConcurrencyConflict` on version mismatch (§8).
6. `fetchRelated()` for a `relationName` not declared on the Entity MUST raise `UnknownRelation`. The driver MUST NOT permit free-form joins.
7. `iterate()` returns a generator that the caller MUST exhaust or close; the driver MAY hold a cursor open in the underlying store until then.

### 5.4 What is NOT on the Repository

- Raw SQL or any native query string.
- Aggregations (`sum`, `avg`, `group_by`). Those are owned by the ReportingDriver (RFC-010).
- Cross-Entity joins. Same. The Repository can fetch related Entities via declared Relations (§9); it cannot synthesize joins not declared in the Metadata Graph.
- Schema introspection (`columns()`, `indexes()`). The Metadata Graph is the source of truth for schema; the driver maps the graph to storage.
- Connection escape hatches. There is no `Repository::connection()` and no `Repository::raw()`. Amendment-01 forbids these by extension of the "no privileged bypass API" principle.

---

## 6. Identity

### 6.1 Generation

```
interface IdentityHandle
{
  function value(): string;      // canonical serializable form
  function entityFqn(): string;
}

PersistenceDriver::generateIdentity(string $entityFqn): IdentityHandle
```

### 6.2 Rules

1. Identity handles are **opaque** to the Kernel and to plugins. Plugin code MUST treat `IdentityHandle::value()` as a black-box string.
2. The driver MAY use UUID v7, ULID, snowflake, autoincrement, content-addressed hash, or any other shape; the choice MUST satisfy §2.1.1 (opaque, immutable, serializable, externally-generatable).
3. Uniqueness is required within `(tenant_id, entity_fqn)` (§2.1.2.2). Drivers MAY guarantee global uniqueness; they MUST NOT require it.
4. Plugin or application code MAY supply an identity at `create()` time by passing it as the second argument. If absent, the Repository calls `driver.generateIdentity(entityFqn)`.
5. Application-supplied identity MUST be validated by the driver against the format it accepts. A handle the driver cannot parse MUST be rejected with `InvalidIdentity`.

### 6.3 Composite keys

Composite primary keys (e.g., `(customer_id, sequence_no)`) are expressible only through driver-internal mapping: the driver emits a single canonical handle (e.g., a concatenation, a hash, or a JSON-encoded tuple) and accepts the same form back. The Kernel does not see composite keys as composite; they appear as one `IdentityHandle::value()` string.

This keeps `Reference` uniform and avoids leaking storage shape into the canonical reference tuple of §2.1.1.4.

---

## 7. Transactions

### 7.1 Contract recap

```
PersistenceDriver::beginTransaction(Tenant): TransactionHandle
PersistenceDriver::commit(TransactionHandle): void
PersistenceDriver::rollback(TransactionHandle): void
```

### 7.2 Invoker ownership

Per Amendment-01 §A-1.4, the Invoker is the sole authorized caller of these three methods. Plugins MUST NOT call them; the API Surface MUST NOT call them. The Invoker invokes them in the order specified by §2 of this RFC.

### 7.3 Audit rollback contract

The transaction MUST be capable of full rollback **after** the Action's effect has run and **before** `commit` is called. Specifically:

1. Between `beginTransaction` and `commit`, every mutation performed via the Repository MUST be reversible by a subsequent `rollback`.
2. After `rollback`, no mutation issued during the transaction MUST be observable to any subsequent reader, in any Tenant.
3. After `commit`, mutations MUST be durable subject to the driver's underlying durability guarantees.

This is the explicit contract that Amendment-01 §A-1.6 ("the Invoker MUST abort the Action: the Action's effect is rolled back through the Persistence Driver's transaction contract") requires.

### 7.4 Nested invocation

Action effects MAY invoke other Actions through the Invoker (RFC-001 §8.2.1). The inner Invoker call sees the outer transaction as already open and MUST reuse it via savepoint:

1. `beginTransaction` on an already-open handle for the same Tenant MUST return a savepoint-bearing handle that is distinct from but linked to the outer handle.
2. `commit` on the inner handle finalizes its savepoint; mutations remain pending until the outer `commit`.
3. `rollback` on the inner handle reverts only the inner savepoint; the outer transaction remains open.

The driver MUST support at least 8 levels of nesting. Drivers MAY support more; plugins MUST NOT assume more.

### 7.5 Cross-Tenant prohibition

`beginTransaction(tenant)` binds the handle to one Tenant. Operations issued through the resulting `PersistenceContext` against a different Tenant MUST raise `TenantBoundaryViolation`. Cross-Tenant operations are accomplished by separate Invoker calls under separate transactions, coordinated via `Ausus::elevate(...)` (RFC-001 §8.1).

The driver MUST NOT support a "multi-Tenant transaction" handle even if its underlying storage permits one.

---

## 8. Optimistic locking

### 8.1 Contract

```
final class Version
{
  function value(): string;       // opaque to plugins
  function isZero(): bool;        // true for newly-created Entities not yet read back
}
```

Every Entity returned by a Repository carries a `Version`. `update()` and `delete()` require the caller to pass back the `Version` they read; mismatch raises `ConcurrencyConflict(reference, expected_version, actual_version)`.

### 8.2 Implementation freedom

The driver chooses how it tracks versions: dedicated column, row checksum, ETag, document `_rev`, MVCC snapshot id, etc. Plugins never see the choice; they only see opaque `Version` values.

### 8.3 Version on create

`create()` returns the new Entity with `Version` populated by the driver. The returned `Version` is the version observable immediately after the create commits.

### 8.4 Optimistic locking is mandatory

The contract requires versions on all single-Entity writes. There is no opt-out flag on `update` or `delete`. A driver that cannot implement versioning is not a conforming driver.

The MaintenanceAction bulk path (§11) MAY skip per-Subject version checking; this is the only sanctioned bypass.

---

## 9. Relation persistence

### 9.1 Storage responsibility

Relations are declared in the Metadata Graph (§2.3). The driver translates them to storage. Plugin code never writes a foreign-key column directly; plugin code calls Repository operations and the driver maintains referential integrity.

### 9.2 Required relation semantics

The driver MUST honour the four cascade modes declared on the Relation descriptor (§2.3):

- `restrict` — `delete()` of a referenced Entity raises `RelationConstraintViolation` if dependents exist.
- `cascade` — `delete()` of a referenced Entity also deletes dependents, in a single transaction.
- `set-null` — dependents' reference Field is set to null; raises if the Field is non-nullable.
- `detach` — dependents' reference Field is removed without deleting the dependent (many-to-many only).

### 9.3 Navigation

`Repository::fetchRelated(ref, relationName, filter)` returns dependent Entities. The driver MAY satisfy this through join, lookup, or batch fetch. The caller does not specify how.

Eager loading is requested via `findMany` and `find` taking an optional `with: [relationName, ...]` argument; this is a hint, not a guarantee. The driver MAY satisfy lazily under load.

### 9.4 Cross-Tenant relations

A Relation whose endpoints would span different Tenants is rejected at write time (`TenantBoundaryViolation`). The Compiler validates statically when both endpoints are non-`system` (§2.1.2.3); the driver enforces dynamically as a defense-in-depth check.

### 9.5 Cross-driver relations

Out of scope for V1. With one driver per deployment (§15), all Entities reside in the same driver; the question does not arise. Heterogeneous-storage relations are deferred.

---

## 10. Query surface

### 10.1 Filter grammar

A `Filter` is a structured tree with a fixed set of node types. The driver MUST support all nodes; it MAY optimize.

```
Filter :=
  | And(Filter[])
  | Or(Filter[])
  | Not(Filter)
  | FieldEquals(field_fqn, value)
  | FieldIn(field_fqn, value[])
  | FieldComparison(field_fqn, op, value)        // op ∈ { <, <=, >, >= }
  | FieldRange(field_fqn, lower, upper, inclusive)
  | FieldNull(field_fqn)
  | FieldStringMatch(field_fqn, pattern, mode)   // mode ∈ { prefix, suffix, contains, exact }
  | RelationExists(relation_name, ?Filter)       // sub-filter applies to related Entity
  | ReferenceEquals(Reference)
```

The grammar is exhaustive for V1. New node types require a new RFC.

### 10.2 What the Filter grammar deliberately omits

- Free-text search beyond exact / prefix / suffix / contains.
- Geo predicates.
- Vector / similarity predicates.
- Aggregations.
- Sub-queries beyond `RelationExists`.

Each omission is a driver-portability decision: the grammar's node set is what every conforming driver MUST support. Anything richer would force the contract to fragment per driver.

### 10.3 Sort, limit, cursor

- `Sort` is a list of `(field_fqn, direction)` pairs. The driver MUST honour the list in declared order.
- `limit` is the maximum number of Entities returned; the driver MUST NOT exceed it.
- `Cursor` is opaque; only the driver that produced one understands it. Cursors MUST be stable for the lifetime of the data shape they describe; a cursor remains valid across `commit` boundaries.

### 10.4 Counting

`count(filter)` returns an exact count. Drivers that cannot provide exact counts efficiently MAY expose `countEstimate(filter)` separately, but `count` itself MUST be exact.

### 10.5 What Actions see vs what Reports see

| Capability | Action (Repository) | Report (ReportingDriver, RFC-010) |
|---|---|---|
| Single-Entity lookup | yes | yes (read-only) |
| Filter grammar of §10.1 | yes | yes |
| Cross-Entity joins | no | yes |
| Aggregations | no | yes |
| Field-level visibility filtering | no | **yes** (per Amendment-01 §A-1.2) |
| Mutation | yes | no |

This split is the load-bearing distinction between RFC-002 and RFC-010. The Repository contract is intentionally narrow; analytical surfaces live on the ReportingDriver.

---

## 11. Bulk operations for MaintenanceAction

### 11.1 Contract recap

```
Repository::updateMany(Filter, array $patch): BulkResult
Repository::deleteMany(Filter): BulkResult
Repository::iterate(Filter, int $chunkSize = 1000): iterable
```

### 11.2 `BulkResult`

```
final class BulkResult
{
  function affectedCount(): int;
  function sampleHandles(): IdentityHandle[];   // bounded; default 100, sink-configurable
  function failedHandles(): IdentityHandle[];   // empty on all-or-nothing success
  function isPartial(): bool;                   // true if some Subjects mutated, some failed
}
```

`sampleHandles` populates Amendment-01 §A-1.8's `BulkSubject.sample_handles` directly.

### 11.3 Transaction binding

Bulk operations execute within the active Invoker transaction. The driver MUST roll the entire bulk back on transaction rollback (e.g., primary-audit failure). The driver MUST NOT chunk across separate transactions for bulk operations issued from within an Invoker transaction.

If the bulk operation exceeds the driver's transaction capacity, the driver MUST raise `TransactionTooLarge(affected_estimate, driver_limit)`. The MaintenanceAction is then the caller's problem to redesign (e.g., partition the filter and run multiple Invocations).

### 11.4 Optimistic locking opt-out

Bulk operations do not accept a `Version` argument; they are last-write-wins by design. This is the only sanctioned bypass of §8.4. MaintenanceAction declarations MUST acknowledge this with a manifest flag (`acknowledges_bulk_lwm: true`); the Kernel rejects a MaintenanceAction declaration that omits the flag.

### 11.5 Streaming reads

`iterate(filter, chunkSize)` returns a generator. The driver MUST hold a stable cursor for the duration of the iteration; reads see a consistent snapshot taken at the start of the iteration (where the storage supports MVCC) or document the divergence (where it does not). This is a capability advertised by `DriverCapabilities` (§13.2).

### 11.6 Atomicity of bulk

`updateMany` and `deleteMany` within an Invoker transaction are all-or-nothing. There is no partial-success path under transaction control. `BulkResult::failedHandles` is non-empty only when bulk is executed outside any transaction — which the contract forbids in V1.

---

## 12. Error model

### 12.1 Closed error taxonomy

All driver-raised exceptions extend `Ausus\Kernel\Contracts\Persistence\PersistenceError`. The full V1 taxonomy is:

```
PersistenceError                          (abstract base)
├── NotFound(Reference)
├── UnknownEntity(string $entityFqn)
├── UnknownRelation(string $relationName)
├── InvalidIdentity(IdentityHandle)
├── ConcurrencyConflict(Reference, expected: Version, actual: Version)
├── ConstraintViolation(name: string, details: array)
├── RelationConstraintViolation(relation_name, details: array)
├── TenantBoundaryViolation(attempted: Tenant, expected: Tenant)
├── TransactionAborted(reason: string)
├── TransactionTooLarge(affected_estimate: int, driver_limit: int)
├── UnauthorizedTransactionControl()
└── DriverError(message: string, cause: ?Throwable)   // catch-all wrap
```

The taxonomy is closed for V1. New error types require a new RFC and a major kernel bump if the new type changes the set of exceptions a plugin must handle for a previously valid operation.

### 12.2 Driver-native exception suppression

Drivers MUST wrap every storage-level exception (PDOException, MongoException, gRPC status, etc.) into one of the types above. Direct propagation of a driver-native exception is a conformance failure.

### 12.3 Errors are not Audit Entries

A raised `PersistenceError` does not, on its own, emit an Audit Entry. Audit is the Invoker's responsibility, after the Action effect either succeeds or fails. The driver does not write audit.

---

## 13. Tenancy

### 13.1 Strategy independence

The PersistenceDriver contract is identical regardless of whether the deployment uses row-level, schema-per-tenant, or db-per-tenant isolation. The strategy is the responsibility of the bound `TenantIsolationStrategy` plugin (RFC-003); the driver consults it on every operation.

The contract requires:

1. Every Repository operation MUST scope to the active Tenant. Row-level drivers filter; schema/db drivers route. Plugin code never sees the difference.
2. A driver MUST declare which strategies it supports via `DriverCapabilities::supportedTenancyStrategies()`. A deployment binding an incompatible strategy is a boot-time error.

### 13.2 `DriverCapabilities`

```
final class DriverCapabilities
{
  function supportedTenancyStrategies(): string[];        // any of 'row', 'schema', 'database'
  function supportsSnapshotReads(): bool;                 // affects §11.5
  function maxNestedSavepoints(): int;                    // ≥ 8 required (§7.4)
  function maxBulkTransactionSize(): int;                 // §11.3
  function identityShape(): string;                       // 'uuid' | 'ulid' | 'snowflake' | 'composite' | 'opaque'
}
```

Capabilities are advertised once at boot and surfaced by `ausus:doctor`.

### 13.3 `system` Tenant

The `system` Tenant (RFC-001 §2.1.2.3) is a distinguished Tenant value the driver MUST accept. Operations under `system` see all `system`-bound Entities regardless of isolation strategy. The driver MUST forbid `system` operations on non-`system` Entities (and vice versa).

### 13.4 Elevation

`Ausus::elevate(...)` (RFC-001 §8.1) opens a new Invoker context bound to a different Tenant. From the driver's perspective, this is indistinguishable from a normal Invoker call against that Tenant. The driver does not see "elevation" as a concept; it sees a transaction opened for Tenant X by an Actor whose home Tenant is Y. Audit captures the elevation; the driver does not.

---

## 14. Single-driver constraint for V1

### 14.1 Decision

Exactly one `PersistenceDriver` is bound per deployment. All Entities, across all installed plugins, use the same driver instance.

### 14.2 Rationale

- Transactions span only one driver. A single-driver world makes "the active transaction" unambiguous and makes Amendment-01 §A-1.6 rollback trivially implementable.
- Relations cross plugins (`billing.invoice` → `crm.account`) but not drivers. With one driver, cross-plugin relations work without distributed transactions.
- Identity uniqueness within `(tenant_id, entity_fqn)` is delegated to one driver; cross-driver identity reconciliation does not arise.
- The error taxonomy and capability vocabulary are uniform.

### 14.3 What is deferred

Heterogeneous storage (e.g., transactional Entities in Postgres + event-stream Entities in Kafka + document Entities in Mongo) is a real use case, deferred to a post-V1 RFC. That RFC will introduce a routing layer and a distributed-transaction or saga model. None of it lives in V1.

---

## 15. Alternatives considered

### 15.1 Unit of Work

**Rejected.** A Unit of Work would queue mutations and flush at commit. It would conflict with the Invoker's explicit transaction ownership, hide the moment writes become visible, and re-create the ORM ergonomics RFC-001 §3.2.5 was designed to prevent. Every Repository call performs its operation immediately within the active transaction; drivers MAY batch internally for performance but MUST NOT defer observability.

### 15.2 ActiveRecord-style Entities

**Rejected.** Entities returned by the Repository are passive value objects. They carry `(reference, fields, version)` and nothing else. There is no `Entity::save()`, no `Entity::refresh()`. All mutation goes through `Repository::update(...)`. This isolates the contract from per-Entity behaviour leaking into the persistence surface and from the lifecycle-bug class that ActiveRecord historically produces.

### 15.3 Generic query builder

**Rejected.** A fluent query builder (`Repository::query()->where(...)->whereIn(...)`) would expose a surface that drivers cannot uniformly support without leaking storage semantics. The structured `Filter` grammar in §10.1 is harder to write by hand but produces a contract every driver can implement identically.

### 15.4 Per-Repository transactions

**Rejected.** Some ORM designs scope transactions to a single Repository. AUSUS Action effects routinely span multiple Entities (`Invoice` + `InvoiceLine` + `Payment`); per-Repository transactions would require an out-of-band coordinator. The chosen design (transaction per Invoker call, shared across all Repositories accessed through the same PersistenceContext) is simpler and matches the natural granularity of an Action.

### 15.5 Plugin-declared identity types

**Rejected.** Letting plugins declare per-Entity identity types (`UuidIdentity`, `SnowflakeIdentity`) would force every driver to support every type. The chosen design — drivers declare their `identityShape()` and produce opaque handles — keeps the contract uniform. Applications that need cross-system identity (e.g., import data with externally-known IDs) use `create()`'s optional `identity` parameter.

### 15.6 An `Ausus::raw()` escape from Repository

**Rejected** (already rejected globally by RFC-001 Amendment-01 §A-1.2 / §1.2). Restated here because the pressure recurs at the persistence layer: there will be requests for "just give me a Connection." The answer is no. Cross-Entity reads go to ReportingDriver (RFC-010); bulk writes go to MaintenanceAction (§11); ad-hoc maintenance goes to driver-specific tooling outside the request path.

### 15.7 Multi-driver V1

**Rejected.** §14.

### 15.8 Optimistic locking opt-out per Action

**Rejected** for standard Actions. The cost of always tracking versions is negligible compared to the cost of debugging a missing optimistic lock in production. The single exception is bulk operations (§11.4), which require an explicit manifest acknowledgement.

---

## 16. Trade-offs

1. **Narrow query surface** (§10) makes some plugin authors reach for ReportingDriver more often than they would for an unconstrained ORM. Accepted; the alternative is driver fragmentation.
2. **Closed error taxonomy** (§12) requires drivers to wrap every storage exception. Accepted; the alternative is plugins catching `PDOException` and binding to one driver.
3. **Single driver V1** (§14) excludes mixed-storage architectures. Accepted; deferred.
4. **Mandatory optimistic locking** (§8.4) adds a column / metadata field to every Entity. Accepted; the cost is negligible.
5. **No Unit of Work** (§15.1) forces plugins to write more explicit Repository calls. Accepted; it matches the explicit-over-magical ethos of the project.
6. **Nested savepoints required to 8 levels** (§7.4) constrains drivers without savepoint support. Accepted; any conforming driver targeting Action-invoking-Action use cases must offer them.

---

## 17. Open questions

1. **RFC-002a — Migrations.** Schema evolution, online migrations under multi-tenancy, the relationship between the Metadata Graph diff and storage-level DDL. Deferred.
2. **RFC-002b — Soft delete.** Filament-style global soft-delete is incompatible with §9.2 cascade semantics; the right primitive in AUSUS is a Workflow state (`archived`), not a hidden column. Confirm in a follow-up.
3. **RFC-002c — Read replicas.** Read/write split, replication lag handling, cursor stability across replicas. Likely a `DriverCapabilities` extension; deferred.
4. **RFC-003 — TenantIsolationStrategy contract.** Defines the strategy plugins this driver consults (§13).
5. **RFC-010 — ReportingDriver.** Owns aggregations, cross-Entity joins, and Field-level visibility enforcement (§10.5).
6. **Post-V1 — Heterogeneous storage.** Multi-driver, saga coordination, distributed transaction policy.
7. **Audit-write coordination with primary sink in a separate system.** When the primary audit sink shares the driver's connection, A-1.6 rollback is trivially achieved within one transaction. When the sink is external (Kafka, SIEM), the Invoker must write audit first, then commit data. If data commit fails after audit ack, an orphan Audit Entry exists. RFC-007 owns the orphan-cleanup contract; RFC-002 only guarantees that data rollback is possible.

---

## 18. Challenger review — attack matrix

Every contract in this RFC is attacked against the five categories the brief requires: **layer violations**, **tenancy bypass**, **audit bypass**, **ORM leakage**, **SemVer traps**.

### 18.1 `PersistenceDriver`

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | A plugin acquires the `PersistenceDriver` from the container and calls `beginTransaction` directly. | §3.2.2 of RFC-001 forbids plugin → Runtime imports. The container binding for `PersistenceDriver` is private; plugins are exposed `PersistenceContext` only. Static analysis on the kernel contracts package rejects plugin imports of `PersistenceDriver`. |
| Tenancy bypass | A caller opens a transaction for Tenant A, then calls `context(TenantB, handle)`. | §7.5: the context call MUST be rejected if the handle's Tenant does not match the requested Tenant. Driver-side check; raises `TenantBoundaryViolation`. |
| Audit bypass | A caller commits a transaction without going through the Invoker (skipping audit). | Same as layer violation: only the Invoker is permitted to call `commit`. Plugins discovered to do so via `UnauthorizedTransactionControl` (§4.2). |
| ORM leakage | `DriverCapabilities::identityShape()` returns driver-specific strings; plugins switch on the value. | Acknowledged. Plugins switching on `identityShape` are coupling themselves to drivers. Mitigation: documentation marks `identityShape` as informational for tooling, not for runtime branching. SemVer permits new values without bump. |
| SemVer trap | Adding a new transaction method (e.g. `savepoint(label)`) post-V1. | New methods on `PersistenceDriver` require a major bump under §6.4 SemVer discipline. Mitigation: §7.4 declares the 8-level savepoint requirement up front; ad-hoc savepoint naming is not part of V1. |

### 18.2 `PersistenceContext`

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Plugin calls `context.transaction()` and passes the handle to `driver.commit()`. | §4.2.3: the driver MUST detect and raise `UnauthorizedTransactionControl`. The introspection method exists for nested-savepoint use cases (§7.4) which are scoped to the Invoker only. |
| Tenancy bypass | Plugin obtains a Repository for `billing.invoice` and another for `system.audit_log` from the same context, mixing scope. | The Tenant on the context is fixed at construction (§4.2.1). `system` Entities are addressable only when the active Tenant is `system`; a non-`system` context refusing to return a Repository for a `system` Entity is enforced at §13.3. |
| Audit bypass | n/a — context is a read/write surface, not an audit surface. | — |
| ORM leakage | Repository factory leaks driver-internal types. | The factory returns Repository (an L0 contract). Driver-internal types stay behind the interface. |
| SemVer trap | Adding `repositoryForRelation(...)` later for ergonomics. | Major bump. Mitigation: §9.3's `fetchRelated` covers the common case at V1. |

### 18.3 `Repository`

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Plugin downcasts to a driver-specific Repository subclass. | The Kernel-published Repository is final; driver-specific extensions are not in the published type. Subclassing the Kernel's value types (Entity, Reference) is forbidden (§5.2). |
| Tenancy bypass | A plugin constructs a `Reference` manually with a different `tenant_id`. | The Repository MUST reject any `Reference` whose `tenant_id` does not match the active Tenant (§5.3.1). |
| Audit bypass | Mutations issued through Repository but no Audit Entry is emitted. | The Repository is reachable only from inside an Invoker-managed transaction (§7.2). The Invoker emits audit. Mutations outside the Invoker are architecturally impossible because no plugin can construct a `PersistenceContext` itself. |
| ORM leakage | `Repository::fetchRelated` returns an Eloquent Collection instead of `EntityPage`. | Conformance test: every Repository return value MUST be a Kernel-defined value type. Drivers failing the test are non-conforming. |
| SemVer trap | The §10.1 Filter grammar is published; a driver supports a richer grammar internally and exposes it through a custom Filter subclass. | Filter is final; new node types require a new RFC and a major bump. Custom subclasses of Filter are rejected by the Repository at the type level. |
| SemVer trap | `findMany`'s return type (`EntityPage`) is published; adding `groupAggregates` to it later breaks compatibility. | EntityPage is final and additive-only at minor versions. Aggregates belong to ReportingDriver (RFC-010) and never appear on EntityPage. |

### 18.4 Identity (§6)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Plugin parses `IdentityHandle::value()` and constructs a derived handle to read a sibling row. | §6.2.1: handles are opaque to plugins. Driver-side validation rejects constructed handles that the driver did not produce (or that fail driver-specific parsing). |
| Tenancy bypass | A `create()` call supplies an identity that collides with another Tenant's identity. | Uniqueness scope is `(tenant_id, entity_fqn)` (§6.2.3); cross-Tenant collision is allowed by the contract and irrelevant because Tenant scope is enforced on every read. |
| Audit bypass | n/a. | — |
| ORM leakage | Composite keys leak as JSON-encoded strings; plugins parse them. | §6.3: composite keys appear as one opaque string. Plugins parsing them violate §6.2.1 and SHOULD be caught by static analysis on the kernel contracts package. |
| SemVer trap | A driver upgrade changes identity shape (e.g., UUID v4 → UUID v7). | `identityShape()` is informational; runtime values change. Stored references are stable. Drivers that change shape mid-deployment are responsible for migration; the contract neither helps nor hinders. |

### 18.5 Transactions (§7)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Plugin opens its own transaction via the Laravel `DB` facade. | This bypasses the entire chain. Mitigation is governance: the kernel contracts package forbids `Illuminate\Database\*` imports in plugin code under static analysis. Detected post-hoc by `ausus:doctor`. Acknowledged risk. |
| Tenancy bypass | Plugin tries to interleave operations under multiple Tenants in one transaction. | §7.5: rejected at the driver. |
| Audit bypass | Transaction committed without Invoker's audit step. | Only the Invoker holds the handle (§7.2). Plugin code that obtained a handle (impossible without static analysis violation) and called commit is detected via `UnauthorizedTransactionControl`. |
| ORM leakage | `TransactionHandle` is a driver-specific type carrying connection state. | Handle is opaque (§7.1). Implementations carry whatever internal state they need; the Kernel sees only the contract. |
| SemVer trap | Adding distributed-transaction semantics post-V1 (saga, 2PC). | Reserved for a post-V1 RFC. V1 commits to single-driver, single-transaction semantics (§14). |

### 18.6 Optimistic locking (§8)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Plugin reads a `Version` from one Entity and passes it to `update()` of a different Entity. | The Repository MUST raise `ConcurrencyConflict` (versions are scoped per-Reference; foreign versions are guaranteed mismatched). |
| Tenancy bypass | n/a. | — |
| Audit bypass | n/a. | — |
| ORM leakage | `Version::value()` returns a recognizable storage primitive (e.g., a Postgres `xmin` int). | Opaque (§8.2). Plugins SHOULD treat the value as a black-box string. Inspection is permitted but not actionable. |
| SemVer trap | Adding a "force update" mode that ignores `Version`. | Explicitly excluded (§8.4). The only sanctioned bypass is the bulk path, gated by manifest flag (§11.4). |

### 18.7 Relations (§9)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | `fetchRelated` called with a `relationName` not declared on the Entity. | `UnknownRelation` raised (§5.3.6). |
| Tenancy bypass | A `cascade` delete crosses a Tenant boundary. | Cross-Tenant Relations are forbidden at the Compiler (§2.1.2.3) and at runtime by the driver (§9.4). Cascade therefore cannot cross Tenants. |
| Audit bypass | A cascade delete affects 50 child Entities; only the root is audited. | The Audit Entry for the root mutation is `BulkSubject` if the count exceeds 1, per Amendment-01 §A-1.8. Drivers MUST populate `affected_count` and `sample_handles` from the cascade closure. |
| ORM leakage | `EntityPage` returned by `fetchRelated` carries driver-internal cursor type. | `Cursor` is opaque (§5.2). |
| SemVer trap | New cascade modes (`archive`, `tombstone`) added post-V1. | §9.2's four modes are the V1 set. New modes require a new RFC and a major bump for any Relation that uses them. |

### 18.8 Query surface (§10)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Plugin builds a Filter that approximates a join via deeply nested `RelationExists`. | `RelationExists` is a permitted node (§10.1) and bounded by declared Relations. The driver translates it; depth is bounded by §13.2 capability advertisement. |
| Tenancy bypass | A Filter references a Field on an Entity not registered for the active Tenant (e.g., a Field added by another Tenant's override). | The Repository MUST validate field FQNs against the Tenant-merged graph (RFC-003). |
| Audit bypass | n/a — queries are reads. | — |
| ORM leakage | Plugin builds a `FieldStringMatch` with a driver-specific regex flavour. | `mode ∈ {prefix, suffix, contains, exact}` (§10.1). Regex is intentionally absent; drivers cannot expose a regex flavour through this contract. |
| SemVer trap | The Filter grammar is added to in V1.x. | §10.1 is closed for V1. New nodes require a new RFC. |

### 18.9 Bulk operations (§11)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Plugin calls `updateMany` from a non-MaintenanceAction context. | The Repository MUST verify the active Invoker call is a MaintenanceAction before permitting `updateMany` or `deleteMany`. Standard Actions calling bulk operations raise `BulkOutsideMaintenanceAction`. (This adds one error type to §12.1; included in the V1 closed taxonomy.) |
| Tenancy bypass | A bulk `Filter` matches across Tenants. | Same as standard queries: filter evaluation is Tenant-scoped (§13.1). |
| Audit bypass | A bulk operation succeeds but emits only a single Audit Entry. | One Audit Entry per Invoker call is correct; the entry uses `BulkSubject` (Amendment-01 §A-1.8) with `affected_count` and `sample_handles` populated from `BulkResult`. |
| ORM leakage | `BulkResult` exposes a driver-specific query plan. | Final class with fixed methods (§11.2). |
| SemVer trap | Drivers under-report `maxBulkTransactionSize`, forcing plugins to pre-chunk. | Capability is advertised; plugins query it and split filters accordingly. Not a trap, but a design discipline. |

### 18.10 Error model (§12)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Driver lets a `PDOException` propagate. | Conformance test rejects. §12.2. |
| Tenancy bypass | An error message includes data from another Tenant in `details`. | Drivers MUST scrub details to the active Tenant. Audit and SIEM rules enforce. Acknowledged residual risk; flagged in §17 for a sanitization sub-RFC. |
| Audit bypass | n/a — errors do not emit audit (§12.3). | — |
| ORM leakage | `DriverError` carries an inner `Throwable` that is a `PDOException`. | Permitted by the catch-all wrapping clause (§12.1). Plugin code SHOULD NOT inspect the inner `Throwable`; doing so couples the plugin to the driver. Documentation flags this. |
| SemVer trap | Adding a new error type post-V1 changes the exception surface plugins must handle. | New error types require a new RFC and a major bump if they replace previously-raised exceptions. New types for genuinely new operations (e.g., a future migration surface) may be added at minor versions. |

### 18.11 Tenancy (§13)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Driver consults the TenantIsolationStrategy at random points. | The driver MUST consult the strategy at exactly one place: when constructing the per-Tenant query / connection at `context(...)` call time. Strategy is not invoked per-Repository-call. |
| Tenancy bypass | A bug in row-level isolation drops the `tenant_id = ?` predicate. | §5.3.1's "every Entity returned MUST carry a `Reference` whose `tenant_id` matches the active Tenant" is verifiable per-row. Drivers SHOULD assert; assertion failures raise `TenantBoundaryViolation`. |
| Audit bypass | n/a. | — |
| ORM leakage | `DriverCapabilities` returns driver-specific strings (`'postgres-row'`). | The four returned values for `supportedTenancyStrategies()` are `'row' | 'schema' | 'database'` — fixed set, §13.2. |
| SemVer trap | Adding `'hybrid'` as a tenancy strategy later. | Requires a new RFC and (likely) a major bump because plugin configuration would gain a value. |

### 18.12 Single-driver constraint (§14)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Plugin author registers a second `PersistenceDriver` for one Entity. | The Kernel rejects multiple driver bindings at boot. `ausus:doctor` flags. |
| Tenancy bypass | n/a — single-driver doesn't relax Tenant rules. | — |
| Audit bypass | n/a. | — |
| ORM leakage | n/a. | — |
| SemVer trap | V2 introduces multi-driver and changes the binding semantics. | Multi-driver is a major-version concern by construction. V1's single-driver assumption is documented; transition path is part of the post-V1 RFC. |

---

## 19. Acceptance criteria

This RFC is accepted when:

1. The three role signatories (architect, kernel, challenger) sign off on §3, §5, §7, §10, §11, §12.
2. RFC-003 (TenantIsolationStrategy) commits to consuming `DriverCapabilities::supportedTenancyStrategies()` (§13).
3. RFC-007 (Audit sink contract) accepts that primary-sink failure rollback uses `PersistenceDriver::rollback` and that orphan Audit Entries (when the primary sink is external) are RFC-007's problem (§17.7).
4. RFC-010 (ReportingDriver) commits to the §10.5 split — aggregations and cross-Entity joins on ReportingDriver, never on Repository.
5. A conformance test suite for `PersistenceDriver` is scoped (not built) before V1: at minimum, one test per contract clause that names a "MUST" or "MUST NOT".
6. The Appendix-D-style walkthrough is rerun against this RFC, treating §D.10 (API invocation), §D.13 (Audit emission), §D.15 (MaintenanceAction execution) as the integration tests; no new contradictions surface.

Once accepted, this RFC is the source of truth for the L3 persistence surface. Any contradiction in a future RFC requires either an amendment to this document or an explicit "supersedes" declaration, per the RFC-001 §12 process.

---

## Appendix A — V1 public surface enumeration

The following types and members are part of the V1 SemVer-stable public surface. Removing any of them, or changing their semantics in a way visible to a conforming plugin, requires a major kernel version bump.

```
Ausus\Kernel\Contracts\Persistence\
  PersistenceDriver          (interface)
    beginTransaction(Tenant): TransactionHandle
    commit(TransactionHandle): void
    rollback(TransactionHandle): void
    context(Tenant, TransactionHandle): PersistenceContext
    generateIdentity(string): IdentityHandle
    capabilities(): DriverCapabilities
  PersistenceContext         (interface)
    repository(string): Repository
    tenant(): Tenant
    transaction(): TransactionHandle
  Repository                 (interface)
    find(Reference): ?Entity
    findMany(Filter, ?Sort, ?int, ?Cursor): EntityPage
    exists(Reference): bool
    count(Filter): int
    iterate(Filter, int): iterable
    create(array, ?IdentityHandle): Entity
    update(Reference, array, Version): Entity
    delete(Reference, Version): void
    fetchRelated(Reference, string, ?Filter): EntityPage
    updateMany(Filter, array): BulkResult
    deleteMany(Filter): BulkResult
  IdentityHandle             (final value object)
  Version                    (final value object)
  Reference                  (final value object; Kernel-constructed)
  Entity                     (final value object; Kernel-constructed)
  EntityPage                 (final value object)
  Cursor                     (final value object; driver-constructed, opaque)
  Filter                     (sealed sum type; closed set of nodes per §10.1)
  Sort                       (final value object)
  BulkResult                 (final value object)
  DriverCapabilities         (final value object)
  TransactionHandle          (sealed marker interface)

Ausus\Kernel\Contracts\Persistence\Errors\
  PersistenceError           (abstract)
  NotFound, UnknownEntity, UnknownRelation, InvalidIdentity,
  ConcurrencyConflict, ConstraintViolation, RelationConstraintViolation,
  TenantBoundaryViolation, TransactionAborted, TransactionTooLarge,
  UnauthorizedTransactionControl, BulkOutsideMaintenanceAction,
  DriverError
```

Anything not enumerated above is not part of the V1 public surface and may change without notice.

---

## Appendix B — Invariants checklist

For driver authors, the following invariants are mandatory. A driver passing the conformance test (§19.5) MUST demonstrate each.

1. **Tenant scope.** No Repository operation returns or mutates data outside the active Tenant. (§5.3.1, §13.1.)
2. **Transaction rollback.** Every mutation issued between `beginTransaction` and `commit` is reversed by `rollback`. (§7.3.)
3. **Nested savepoints.** Eight levels of nesting supported. (§7.4.)
4. **Cross-Tenant prohibition.** `beginTransaction` binds to one Tenant; operations against another raise `TenantBoundaryViolation`. (§7.5.)
5. **Optimistic locking.** Every `update` and `delete` requires and verifies `Version`. (§8.)
6. **Cascade modes.** Four modes implemented per §9.2.
7. **Filter grammar.** All §10.1 nodes supported; no others permitted.
8. **Exact count.** `count(filter)` is exact. (§10.4.)
9. **Bulk transaction binding.** `updateMany` / `deleteMany` execute within the active transaction. (§11.3.)
10. **Bulk Audit shape.** `BulkResult` populates `sample_handles` such that `BulkSubject` (Amendment-01 §A-1.8) can be constructed. (§11.2.)
11. **Closed error taxonomy.** Only the §12.1 types are raised; storage-native exceptions wrapped. (§12.2.)
12. **Single driver per deployment.** Boot fails on multiple bindings. (§14, §18.12.)
13. **Capability advertisement.** `DriverCapabilities` truthful for the lifetime of the process. (§3.2.6, §13.2.)
14. **No Eloquent leakage.** No public return type, parameter type, or thrown exception names a driver-internal class. (§12.2, §13, §18.)
