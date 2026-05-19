# RFC-010 — ReportingDriver and MaintenanceAction execution contracts

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Draft                                                  |
| Authors       | architect, kernel, challenger                          |
| Date          | 2026-05-18                                             |
| Depends on    | RFC-001 Draft-03 (incl. Amendment-01), RFC-002 Draft, RFC-003 Draft, RFC-007 Draft |
| Supersedes    | —                                                      |
| Stability     | Foundational. Changes after acceptance require a follow-up RFC. |

---

## 0. Problem statement

RFC-001 Amendment-01 §A-1.2 introduces the `ReportingDriver` contract as the read-only, cross-Entity, Field-visibility-aware query interface for AUSUS. Amendment-01 §A-1.4 introduces the `MaintenanceAction` sub-category of Action for bulk and cross-instance operations. Both are referenced throughout RFC-001, RFC-002, RFC-003, and RFC-007 as load-bearing primitives. Neither is specified.

The downstream consequences of leaving these unspecified are concrete:

- RFC-002 §10.5 commits the Repository to a narrow query surface and pushes aggregations and cross-Entity joins to ReportingDriver. Until RFC-010 lands, Plugins have no path for legitimate analytical reads.
- RFC-002 §11.4 conditions `updateMany`/`deleteMany` on a manifest flag (`acknowledges_bulk_lwm: true`) declared on a MaintenanceAction. Until RFC-010 lands, no MaintenanceAction may be declared.
- RFC-001 §A-1.2 says ReportingDrivers must enforce `FieldDescriptor.visibility` via the `kernel.field.read` sentinel. The enforcement mechanism — query grammar, projection semantics, error surface — is unspecified.
- RFC-007 §13 defines the wire shape of `BulkSubject` audit but defers the "when a MaintenanceAction emits bulk audit" lifecycle to this RFC.
- RFC-001 §11.9 promises V1 ships with both contracts and §10.5 promises the strict layering mitigation (no privileged bypass) depends on RFC-010 landing.

This RFC closes those promises. It defines:

- The `ReportingDriver` L3 contract: query grammar, projection, joins, filter, aggregation, pagination, ordering, parameter binding.
- The query-side Policy evaluation chain (Entity-level, Field-level visibility, Tenant scope).
- The read-only enforcement: no method on `ReportingDriver` writes; no escape to the underlying store; no escape via aggregation side-effects.
- The audit-emission rules for reporting queries against `audited_reads` Entities.
- The `MaintenanceAction` declaration grammar: manifest flags, capability acknowledgements, execution constraints.
- The MaintenanceAction execution lifecycle through the Invoker, including bulk audit (`BulkSubject`) and the no-partial-success guarantee.

The ten-year horizon and SemVer discipline (RFC-001 §6.4) apply. Every contract is part of the V1 public surface.

---

## 1. Scope and inherited constraints

### 1.1 Inherited

1. ReportingDriver is read-only. It MUST NOT provide a path to mutation (RFC-001 §3.2.6, §1.1.9).
2. ReportingDriver enforces `FieldDescriptor.visibility` Policies via the kernel sentinel Action FQN `kernel.field.read` (Amendment-01 §A-1.2, §A-1.5, §A-1.9).
3. ReportingDriver enforces Tenant scope (RFC-001 §8.1, §1.1.9).
4. Drivers live at L3. Plugins (L7) consume L0 contracts (RFC-001 §3.2).
5. MaintenanceActions are Actions: they go through the Invoker, the Policy chain, the Audit Spine (RFC-001 §2.4.1, Amendment-01 §A-1.4, §A-1.6). They MAY bypass Workflow guards; they MUST NOT bypass Tenant, Policy, or Audit.
6. The audit emission for MaintenanceActions uses `BulkSubject` (Amendment-01 §A-1.8) with `affected_count` and `sample_handles` (RFC-007 §13).
7. Bulk operations on the Repository (`updateMany`, `deleteMany`) execute within the Invoker transaction and are all-or-nothing (RFC-002 §11.3, §11.6).
8. Bulk operations skip per-Subject optimistic locking; this opt-out is gated by a MaintenanceAction manifest acknowledgement (RFC-002 §11.4).
9. Single driver per deployment for V1: one PersistenceDriver, one ReportingDriver (RFC-002 §14; this RFC extends the same constraint to ReportingDriver in §2.7).
10. The Filter grammar of RFC-002 §10.1 is reused by reporting queries; nothing else from RFC-002 §10 carries over (no `Repository::findMany` semantics for reports).

### 1.2 Out of scope

- Concrete reporting-store choice (Postgres replica, Snowflake, ClickHouse, OLAP cube). The contract is store-agnostic; implementations choose.
- ETL/CDC pipelines that materialize data into a reporting store. Out-of-band of the Kernel.
- BI tool integrations (Tableau, Looker, Metabase). Downstream consumers of the contract.
- Saved queries, scheduled report generation, alerting on query results. Useful, but post-V1.
- Row-level access control beyond Tenant + Entity + Field visibility. V1 does not introduce row-level Policies; if needed, plugins compose via Filter predicates.
- Online (zero-downtime) MaintenanceAction execution. V1 ships synchronous, transaction-bound MaintenanceActions only.

---

## 2. `ReportingDriver` contract

### 2.1 Contract

```
interface ReportingDriver
{
  function execute(ReportingQuery $query, Tenant $tenant, Actor $actor): ReportingResult;
  function explain(ReportingQuery $query, Tenant $tenant, Actor $actor): ReportingPlan;
  function capabilities(): ReportingCapabilities;
}
```

### 2.2 `execute`

Runs the query against the reporting store, applies Policy and Field-visibility enforcement, returns a `ReportingResult`.

- Tenant scope is bound from the `Tenant` argument; the driver MUST NOT consult ambient state.
- The Actor is used for Policy and Field-visibility evaluation.
- The driver does NOT participate in the active Invoker transaction. Reports read committed state. Reads issued from within an Action's effect see pre-effect state, not the effect's uncommitted writes.

### 2.3 `explain`

Returns a `ReportingPlan`: an opaque, sink-defined description of how the query would be executed. Plugins may use it for diagnostics and quota estimation. Plan content is informational; this RFC does not normalize its shape beyond requiring it to be JSON-serializable.

### 2.4 `capabilities`

```
final class ReportingCapabilities
{
  function maxJoinDepth(): int;                     // V1 minimum: 4
  function maxGroupByFields(): int;                 // V1 minimum: 8
  function supportsCountDistinct(): bool;
  function supportsCrossPluginJoins(): bool;        // most implementations: true
  function pageSizeBounds(): array;                 // { min: int, max: int }
  function queryTimeoutSeconds(): int;              // hard upper bound per query
  function snapshotConsistency(): bool;             // true if the driver returns a consistent snapshot
}
```

Drivers MUST advertise truthfully. Misadvertisement is a §18 conformance failure.

### 2.5 Read-only by construction

`ReportingDriver` has no `update`, no `delete`, no `insert`. The interface has three methods total. There is no way to acquire a mutation path from a `ReportingDriver` instance, including:

- No `connection()` accessor returning a write-capable handle.
- No `raw()` escape.
- No transaction methods (`begin`, `commit`, `rollback`). Transactions are PersistenceDriver-owned (RFC-002 §7).
- No "passthrough" SQL execution. The query grammar is the only entry point.

This is enforced at the contract level. A driver advertising additional write-capable methods is not a conforming `ReportingDriver`.

### 2.6 No `Repository`-like surface

ReportingDriver does NOT return `Entity` instances (the value object from RFC-002 §5.2). It returns `ReportingResult` rows, which are flat-projected records. Plugins wanting Entity-shaped reads use the Repository (RFC-002 §5.1.`find` / `findMany`); plugins wanting analytical projections use ReportingDriver.

This is the load-bearing distinction. ReportingDriver results are NOT Entities; they are projections.

### 2.7 Single driver per deployment

Exactly one `ReportingDriver` is bound per deployment. Multiple bindings are a boot-time error. Heterogeneous reporting stores are deferred (post-V1) under the same logic as RFC-002 §14.

A deployment without a `ReportingDriver` is permitted; plugins that issue reporting queries against an unbound driver fail with `ReportingDriverUnbound` at first call.

---

## 3. `ReportingQuery` grammar

### 3.1 Top-level shape

```
ReportingQuery := {
  from:          EntitySource,
  joins:         Join[],
  filter:        Filter | null,                  // RFC-002 §10.1 grammar
  project:       ProjectionItem[],               // at least one item required
  groupBy:       FieldRef[],
  having:        AggregateFilter | null,
  orderBy:       SortKey[],
  pagination:    Pagination,
  parameters:    ParameterBinding[]
}
```

The grammar is closed. Anything not expressible in this tree is not expressible in V1.

### 3.2 `EntitySource`

```
EntitySource := {
  entity:        string,                          // FQN, e.g. "billing.invoice"
  alias:         string                           // local; used in projections, joins, filters
}
```

The aliased `from` Entity is the query's root. Every join is rooted relative to `from` (transitively).

### 3.3 `Join`

```
Join := {
  via:           string,                          // declared Relation name on the source side
  source:        string,                          // alias of the side declaring the Relation
  alias:         string,                          // local alias for the joined Entity
  kind:          "inner" | "left",                // V1 supports inner and left; right and full deferred
  filter:        Filter | null                    // applied to the joined side
}
```

Joins traverse declared Relations only. The driver MUST raise `UnknownRelation(via, source)` for an undeclared edge.

`maxJoinDepth` (§2.4) bounds the join chain length. Default minimum V1: 4. Drivers MAY support more.

### 3.4 `Filter`

The Filter grammar is exactly RFC-002 §10.1's closed set. Reporting reuses it; no extension. `RelationExists` permits correlated existence checks but not arbitrary subqueries.

### 3.5 `ProjectionItem`

```
ProjectionItem := FieldProjection | AggregateProjection

FieldProjection := {
  kind:          "field",
  source:        "alias.field_name",
  alias:         string                           // output column name
}

AggregateProjection := {
  kind:          "aggregate",
  function:      "count" | "sum" | "avg" | "min" | "max" | "count_distinct",
  source:        "alias.field_name" | null,       // null only for count(*)
  alias:         string
}
```

The aggregate function set is closed for V1. Median, percentile, stddev, var, custom aggregates are NOT in V1.

A query MUST declare at least one `ProjectionItem`. A query that projects nothing is rejected with `EmptyProjection`.

### 3.6 `groupBy`

```
FieldRef := "alias.field_name"
```

`groupBy` is a list of `FieldRef`. Empty `groupBy` with any `AggregateProjection` produces a single-row aggregate (`count(*) → 42`). Non-empty `groupBy` with any `FieldProjection` requires every projected non-aggregate to appear in `groupBy` (standard SQL semantics; driver enforces with `UngroupedFieldProjection`).

### 3.7 `having`

```
AggregateFilter := { ... }    // structurally identical to Filter (§3.4) but references aggregate aliases
```

`having` filters apply post-grouping. References to non-aggregate aliases in `having` are rejected with `HavingReferencesNonAggregate`.

### 3.8 `orderBy`

```
SortKey := { source: "alias.field_name" | "<projection alias>", direction: "asc" | "desc" }
```

Sort keys reference either source fields or projected aliases. Drivers MAY require sort keys to appear in `project`; this RFC does not require it.

### 3.9 `pagination`

```
Pagination := {
  kind:          "cursor",                        // V1: cursor only
  pageSize:      int,                             // within capabilities.pageSizeBounds
  cursor:        string | null                    // opaque; from previous result
}
```

Cursor is opaque, owned by the driver. Stable across the query's snapshot (when `snapshotConsistency: true`); on drivers without snapshot consistency, cursors are best-effort.

Offset pagination is NOT in V1.

### 3.10 `parameters`

```
ParameterBinding := { name: string, value: <typed value per RFC-004 §4> }
```

Parameter binding allows queries to be constructed once and re-executed with different values. Parameters substitute into `Filter` / `having` value positions. Untyped string concatenation is forbidden by construction: there is no SQL string, only a tree.

---

## 4. `ReportingResult`

### 4.1 Shape

```
ReportingResult := {
  rows:              Row[],
  nextCursor:        string | null,
  previousCursor:    string | null,
  totalEstimate:     int | null,
  pageSize:          int,
  schemaVersion:     string,                      // "1.0.0"
  metadata:          ResultMetadata
}

Row := { <projection_alias>: <typed value | null> }

ResultMetadata := {
  generatedAt:       string,                      // RFC 3339 UTC
  driverProfile:     string,                      // driver-defined identifier
  queryFingerprint:  string                       // opaque; for log correlation
}
```

### 4.2 Type fidelity

Each row's value types match the projection's value types per RFC-004 §4. Aggregate result types:

| Function | Input type | Output type |
|----------|------------|-------------|
| `count(*)` / `count(field)` | any | `integer` |
| `count_distinct(field)` | any | `integer` |
| `sum(field)` | `integer` / `decimal` / `money` | same as input |
| `avg(field)` | `integer` / `decimal` | `decimal` |
| `avg(field)` | `money` | `money` |
| `min(field)` / `max(field)` | comparable type | same as input |

`avg` of integers returns `decimal` (precision and scale advertised by the driver).

### 4.3 Currency uniformity

`sum(money_field)` and `avg(money_field)` require the underlying values to share a single currency. Mixed currencies raise `IncompatibleAggregateCurrency(field, currencies)`. Splitting the query (one per currency) is the operator's responsibility.

### 4.4 Null handling

Aggregate functions IGNORE null inputs (standard SQL `NULL`-skipping semantics) except `count(*)`, which counts all rows.

---

## 5. Field visibility enforcement

### 5.1 The strict stance

Field-level visibility (Amendment-01 §A-1.2) is enforced at query-validation time, BEFORE execution. Any Field referenced anywhere in the query — `project`, `filter`, `groupBy`, `having`, `orderBy`, `join.filter`, parameter substitution — whose `visibility` Policy does not return `Permit` for the requesting Actor causes the query to be rejected with:

```
FieldVisibilityDenied(field_fqn, actor)
```

The driver MUST NOT execute the query, MUST NOT return partial results, MUST NOT silently omit the field. Loud failure is the design intent: silent omission in an analytical context permits inference attacks (counting omitted-field group counts to deduce the field's value distribution).

### 5.2 Why ReportingDriver fails loudly while ViewSchema omits silently

ViewSchema (RFC-004 §5.6) silently omits because the renderer's UI must continue. Reports are explicitly analytical: the user is constructing the query. Silent omission would corrupt aggregations (counts, sums, group cardinalities) without the user knowing. A failing query at least signals "you cannot ask this question."

### 5.3 Evaluation contract

Per Amendment-01 §A-1.2:

```
evaluate(visibility_policy, Actor, Action: "kernel.field.read", Subject: null, Context)
```

The Subject is `null` because at query-validation time, the driver does not yet have specific instance Subjects (those are the rows it would return). The visibility Policy MUST accept `null` Subjects and return based on the Actor and Context only. Policies that require a Subject for evaluation reject reporting queries by returning `Deny` on `null` Subjects; this is the documented design pattern for "row-level only" visibility (which is not directly supported but expressible via this convention).

### 5.4 Entity-level Policy

Per RFC-001 §2.5 / §8.2, every Action invocation passes through the Entity-scoped Policy chain. For reporting queries, the analogous evaluation is:

```
evaluate(entity_read_policy_chain, Actor, Action: "kernel.entity.read",
         Subject: null, Context: { tenant, query_fingerprint })
```

If any Entity referenced in `from` or `joins` denies the chain, the query is rejected with `EntityReadDenied(entity_fqn, actor)`.

### 5.5 Tenant scope

The query's Tenant is bound from the `Tenant` argument to `execute`. The driver:

1. Adds an implicit Tenant predicate to every Entity in `from` and `joins` (the driver chooses the mechanism: `WHERE tenant_id = ?`, schema routing, or connection routing, mirroring the PersistenceDriver's strategy per RFC-002 §13).
2. Rejects any query whose tree (filter, having, parameter) references a Tenant other than the bound one.
3. Refuses to bypass even under elevation; elevated reports work because the Invoker rebinds `Tenant` to the target before invoking the driver.

Cross-Tenant joins are forbidden unless both Entities are `system` (RFC-001 §2.1.2.3).

### 5.6 No row-level Policy in V1

V1 does not introduce row-level access Policies. Row-restricted reporting is expressible via the query's `filter` (e.g., "rows where `owner_id = current_actor_id`"). This is plugin-author responsibility, not driver enforcement.

A future RFC may add row-level Policies; until then, the contract is: Tenant + Entity + Field visibility.

---

## 6. Policy evaluation order

For each `execute(query, tenant, actor)`:

1. **Tenant binding** (§5.5). Implicit Tenant predicate added. Cross-Tenant references rejected.
2. **Entity-level Policy** (§5.4). For each Entity in `from` and `joins`, evaluate `kernel.entity.read` chain. Deny → reject.
3. **Field-level visibility** (§5.1). For each Field reference in the query tree (projection, filter, group, having, order, join filter), evaluate `kernel.field.read`. Deny → reject.
4. **Capability check** (§2.4). Join depth, group-by width, query timeout. Exceed → reject.
5. **Schema validation**. Aggregation/group-by coherence (§3.6, §3.7). Reject on `EmptyProjection`, `UngroupedFieldProjection`, `HavingReferencesNonAggregate`.
6. **Execute**.

Each rejection short-circuits. The driver does not partially execute then attach an error.

---

## 7. Audit emission for reporting queries

### 7.1 Default: no audit

Per RFC-001 §8.3 and RFC-007 §15: reads are not audited by default. A reporting query whose touched Entities have no `audited_reads: true` flag emits no audit entry.

### 7.2 With audited-reads Entities: one consolidated entry

A reporting query that touches one or more Entities declared `audited_reads: true` emits exactly ONE Audit Entry per query, regardless of how many such Entities are joined.

```
AuditEntry {
  actionFqn:        "kernel.reporting.query",
  invocationClass:  "Standard",
  subject:          SingleSubject {
                      tenant_id:     <bound tenant>,
                      entity_fqn:    <from.entity>,
                      identity_handle: "kernel.reporting.aggregate"   // sentinel; queries have no instance subject
                    },
  inputs:           {
                      queryFingerprint: <opaque>,
                      entities:         [ <list of touched audited-reads Entities> ],
                      query:            <ReportingQuery, redacted per RFC-007 §14>
                    },
  outputs:          {
                      rowCount:    <int>,
                      truncated:   <bool>          // true if pageSize cap hit
                    }
  ...               // standard fields per RFC-007 §2
}
```

`identity_handle: "kernel.reporting.aggregate"` is a sentinel for queries that have no single Subject. The Subject's `entity_fqn` is the query's `from` Entity for indexing; the `entities` input lists everything touched.

### 7.3 Primary audit failure aborts the query

Per RFC-007 §15.3 (read audit emission): if the primary sink fails to acknowledge the audit entry, the query fails with `AuditEmissionFailed`. The driver MUST NOT return results.

### 7.4 Per-chunk audit for paginated queries

Each page fetch is a separate `execute` call. If the query touches audited-reads Entities, each page emits its own audit entry (one per page, with `outputs.rowCount` reflecting the page). This is the same model as RFC-007 §15.4 for `Repository::iterate`.

### 7.5 `explain` is not audited

`explain` returns metadata, never values. It does not emit audit, even for audited-reads Entities.

---

## 8. `MaintenanceAction` declaration

### 8.1 Manifest declaration

MaintenanceActions are declared in the plugin manifest with the following required flags:

```
{
  "fqn":                       "billing.invoice.recompute_balances",
  "kind":                      "maintenance",
  "policy":                    "billing.invoice.recompute",
  "subject_required":          false,
  "acknowledges_bulk_lwm":     true,
  "audit_inputs_redact":       [ ... ],
  "estimated_max_subjects":    null | <int>,
  "skip_workflow_guards":      true | false
}
```

Required fields:

- `fqn`: action FQN per RFC-001 §2.1 conventions.
- `kind: "maintenance"`: the discriminator. Standard Actions omit or set `kind: "standard"`.
- `policy`: Policy FQN governing invocation.
- `acknowledges_bulk_lwm: true`: REQUIRED for any MaintenanceAction performing `Repository::updateMany` or `deleteMany`. RFC-002 §11.4 rejects bulk operations from MaintenanceActions that omit this flag.
- `skip_workflow_guards`: whether the action may skip per-Subject Workflow guard evaluation (per RFC-001 §2.4.1). Default `false`; setting `true` is the only way to bypass guards.

Optional fields:

- `estimated_max_subjects`: deployment hint for capacity planning; surfaced by `ausus:doctor`.
- `audit_inputs_redact`: per-Action additive redaction patterns (per RFC-007 §14.6).

### 8.2 Kernel enforcement at registration

Plugin registration of a `kind: "maintenance"` Action MUST satisfy:

1. The Action's effect signature MUST permit `PersistenceContext` access (standard Action signature; MaintenanceActions are not exempt).
2. If the Action's effect invokes `updateMany`, `deleteMany`, or `iterate` with chunk size larger than the driver's `maxBulkTransactionSize / 10`, the Compiler emits a warning at compile (RFC-001 §4.2).
3. If `acknowledges_bulk_lwm: false` (or unset) and the effect performs bulk operations, RFC-002 §11.4 raises at first invocation (`BulkWithoutAcknowledgement`).

### 8.3 `ausus:doctor` surface

MaintenanceActions are surfaced separately by `ausus:doctor`, listing:

- FQN
- `acknowledges_bulk_lwm` value
- `skip_workflow_guards` value
- `estimated_max_subjects`
- Last invocation timestamp (from audit log) — informational, not a constraint
- Whether the registered policy resolves to a valid Policy descriptor

This makes the platform's bulk-mutation surface auditable without inspecting code.

### 8.4 Reserved FQN namespace

The `kernel.*` Action namespace is reserved for the Kernel. Plugins MUST NOT register MaintenanceActions in `kernel.*`. Kernel-registered MaintenanceActions (RFC-003 `kernel.tenant.*`, RFC-007 `kernel.audit.*`) follow the same declaration grammar.

---

## 9. `MaintenanceAction` execution lifecycle

### 9.1 Invocation path

MaintenanceActions are invoked through the Invoker exactly like Standard Actions (Amendment-01 §A-1.4 §8.2.1). The Invoker's chain runs:

1. **Tenant Context check** (RFC-001 §8.2). Reject if outside Tenant Context.
2. **Policy chain** (RFC-001 §8.2). Reject on `Deny`. **No bypass.** MaintenanceActions are subject to the same Policy evaluation as Standard Actions.
3. **Workflow guard** (RFC-001 §8.2.1 step 3). If the action's manifest declares `skip_workflow_guards: true`, this step is skipped. Otherwise evaluated. This is the only step a MaintenanceAction may skip (Amendment-01 §A-1.4).
4. **Action effect**. Runs against the active `PersistenceContext` (RFC-002 §4). Effect MAY call `Repository::updateMany`, `Repository::deleteMany`, or `Repository::iterate` per RFC-002 §11.
5. **Audit emission**. Per Amendment-01 §A-1.6 + RFC-007. The Audit Entry uses `InvocationClass: "Maintenance"` and `Subject: BulkSubject`.

### 9.2 Transactional binding

Per RFC-002 §11.3, the bulk operations within a MaintenanceAction execute inside the Invoker transaction. The transaction holds for the entire MaintenanceAction. On primary audit failure, the entire bulk rolls back per Amendment-01 §A-1.6.

### 9.3 Synchronous execution

V1 MaintenanceActions execute synchronously. The Invoker call blocks until the effect completes and the audit is emitted. There is no "fire and forget" mode and no progress callback in V1.

This means a MaintenanceAction affecting 100,000 rows holds an open transaction for as long as the operation takes (driver-dependent: from seconds to minutes). Operators must size accordingly; drivers that cannot hold transactions that long advertise `maxBulkTransactionSize` (RFC-002 §13.2), and the operation raises `TransactionTooLarge` if the count exceeds the limit.

### 9.4 Subject argument

MaintenanceActions MAY or MAY NOT require a `Subject` argument:

- `subject_required: false` (typical): the action operates on a Filter-defined set; no single Subject is meaningful. The Invoker call passes `null` for Subject.
- `subject_required: true` (rare): the action operates on a single Subject and its computed dependents (e.g., "rebuild derived data for invoice X"). The Invoker call passes a `Reference`.

Either way, the audit `Subject` is `BulkSubject` because the *effect* affects multiple instances.

### 9.5 Cross-Tenant MaintenanceActions

Like all Actions, MaintenanceActions run under exactly one Tenant Context per invocation. A platform-wide MaintenanceAction (e.g., "rebuild caches for all Tenants") is implemented as a `system`-bound scheduled job that enumerates Tenants and invokes one MaintenanceAction per Tenant (per RFC-003 §11.4 explicit fan-out pattern). There is no implicit cross-Tenant MaintenanceAction.

### 9.6 Cross-Entity bulk?

A single MaintenanceAction MAY perform bulk operations against multiple Entities (e.g., `updateMany` on `billing.invoice` followed by `deleteMany` on `billing.invoice_line`). All run in the same Invoker transaction.

Per Amendment-01 §A-1.8, the Audit Entry's `BulkSubject` carries one `entity_fqn`. For multi-Entity MaintenanceActions, the convention is:

- The `entity_fqn` is the **primary** Entity, conventionally the largest-affected.
- `outputs.bulk_entities` lists all affected `(entity_fqn, affected_count)` pairs.

This deviates from the strict shape but preserves the single-entry-per-MaintenanceAction property. The `BulkSubject.entity_fqn` is the indexing key for audit search; the full breakdown is in `outputs`.

A future RFC may extend `BulkSubject` to a list shape; V1 takes the pragmatic single-Entity-with-outputs-breakdown form.

---

## 10. Bulk audit

### 10.1 Single entry per invocation

One `BulkSubject` Audit Entry per MaintenanceAction invocation, emitted by the Invoker as the final step (RFC-001 §8.2.1 step 5; RFC-007 §3.3). The entry shape per RFC-007 §2.1 with:

```
invocationClass:   "Maintenance"
subject: BulkSubject {
  tenant_id:       <active tenant>,
  entity_fqn:      <primary Entity per §9.6>,
  affected_count:  <exact total per BulkResult>,
  sample_handles:  <bounded per RFC-007 §13.1>
}
inputs:            { ... action-specific ..., bulk_entities: [...] }
outputs:           { ... action-specific ... }
```

### 10.2 Sample bound

`sample_handles` is bounded per RFC-007 §13.1: `min(100, sink.maxSampleHandles, config.max_sample_handles)`. Truncation is signalled via `outputs.bulk_truncated: true` (RFC-007 §13.1).

For multi-Entity MaintenanceActions, samples are drawn from the primary Entity only; secondary Entities are summarized in `outputs.bulk_entities` without samples. This is the V1 trade-off; a future RFC may extend.

### 10.3 No per-Subject audit

A MaintenanceAction affecting 10,000 rows emits one Audit Entry, not 10,000. Operators requiring per-Subject audit trails for bulk operations split the operation into per-Subject Invocations of a Standard Action (which audit individually). This is a deliberate cost trade-off and is documented at audit-consumer time.

### 10.4 Audit failure rolls back the bulk

Per Amendment-01 §A-1.6, primary audit sink failure aborts the Action. For a MaintenanceAction that just updated 10,000 rows, this means the transaction rolls back all 10,000. Operators choosing External primary sinks (RFC-007 §5.3) accept that bulk MaintenanceActions risk full rollback on transient audit failure; Transactional primaries make this risk negligible.

---

## 11. Partial failure semantics

### 11.1 Reporting queries

A `ReportingDriver::execute` call either:

- Returns a complete `ReportingResult` (possibly paginated; the page is complete).
- Raises an error.

There is no "partial result with errors embedded" envelope (RFC-004 §13.4 precedent). Pagination is the only mechanism for incremental retrieval; each page is complete or absent.

Error types:

```
ReportingError                       (abstract)
├── ReportingDriverUnbound
├── EntityReadDenied(entity_fqn, actor)
├── FieldVisibilityDenied(field_fqn, actor)
├── UnknownEntity(entity_fqn)
├── UnknownRelation(via, source)
├── EmptyProjection
├── UngroupedFieldProjection(alias)
├── HavingReferencesNonAggregate(alias)
├── IncompatibleAggregateCurrency(field, currencies)
├── JoinDepthExceeded(actual, limit)
├── GroupByWidthExceeded(actual, limit)
├── PageSizeOutOfBounds(requested, min, max)
├── QueryTimeout(elapsed_seconds, limit)
├── InvalidCursor(cursor)
├── AuditEmissionFailed                  (from RFC-007; restated here)
└── ReportingError.Driver(message, cause)
```

### 11.2 MaintenanceActions

Per RFC-002 §11.6 and §1.1.7: bulk operations within an Invoker transaction are all-or-nothing. `BulkResult::failedHandles()` is empty in V1.

There is no "this MaintenanceAction succeeded for 9,000 rows and failed for 1,000" outcome under transactional bulk. The operation either commits the full effect or rolls back entirely.

For operators needing partial-progress semantics (e.g., "process as many invoices as possible, log failures"), V1 prescribes splitting:

- Outer scheduled job enumerates Subjects.
- For each Subject, invokes a Standard Action (one per Subject).
- Failures per Subject are isolated; each emits its own audit.

The Standard-Action-per-Subject pattern has higher per-invocation overhead (one Invoker chain per Subject) but provides natural per-Subject failure isolation. This is the V1 documented pattern for partial-success-tolerant bulk work.

### 11.3 The MaintenanceAction never observes its own partial state

If the effect makes mid-execution decisions based on prior writes ("update 1,000 rows, then check the count, then decide next steps"), it sees consistent state per the driver's isolation level. RFC-002 §7 ensures the transaction is the unit of consistency. On rollback (e.g., audit failure), the effect's intermediate decisions are also reverted.

### 11.4 Read failure inside a MaintenanceAction effect

If the effect issues a read that fails (e.g., `ReportingDriver::execute` raises mid-effect), the MaintenanceAction Action effect propagates the error to the Invoker, which rolls back the transaction. Reading and writing within the same effect is permitted; the effect's structure is plugin-author choice.

---

## 12. Configuration

```
reporting:
  default_driver:           ausus.reporting.sql
  query_timeout_seconds:    60                  # default upper bound; per-query timeout may be lower
  max_page_size:            1000
  min_page_size:            1
  max_join_depth:           4                   # driver MUST advertise ≥ this; deployment may lower
  max_group_by_fields:      8

maintenance:
  default_acknowledgement_required: true        # if false, MaintenanceActions without acknowledges_bulk_lwm warn at registration (V1 default: true; can never become false)
  bulk_transaction_size_warning: 50000          # ausus:doctor warns at registration if estimated_max_subjects exceeds this
```

`maintenance.default_acknowledgement_required` is `true` and cannot be set to `false` in V1; the configuration key exists as a forward-compatibility marker.

---

## 13. Alternatives considered

### 13.1 Allow raw SQL via ReportingDriver

**Rejected.** Defeats the closed query grammar, breaks Field visibility enforcement (the driver cannot inspect arbitrary SQL for Field references reliably), and re-introduces the bypass that Amendment-01 §A-1.2 explicitly excluded.

### 13.2 Push aggregation to the application layer

**Rejected.** Application-side aggregation requires shipping all matching rows to the application, which (a) defeats the performance point of reporting, (b) makes Field visibility enforcement happen too late (rows are already shipped), and (c) duplicates aggregation logic in every plugin.

### 13.3 ReportingDriver returns Entity instances

**Rejected.** ReportingDriver projections are flat; they include aggregates, joined fields, and renamed aliases that no Entity descriptor matches. Forcing Entity-shaped results would either require synthesizing pseudo-Entities (confusing) or rejecting most legitimate aggregations.

### 13.4 Silent omission of denied Fields

**Rejected** (§5.2). Information inference attacks via aggregation make silent omission unsafe.

### 13.5 Allow MaintenanceActions to opt out of audit

**Rejected** by RFC-001 §8.3 + Amendment-01 §A-1.6. Restated.

### 13.6 Allow MaintenanceActions to bypass Policy

**Rejected** by RFC-001 §2.4.1. The Workflow-guard bypass is the only sanctioned skip; Policy and Tenant are unconditional.

### 13.7 Asynchronous MaintenanceActions in V1

**Rejected for V1** (§9.3). Async/deferred execution requires a separate progress + cancellation contract; deferred to post-V1.

### 13.8 Per-Subject MaintenanceAction audit

**Rejected** (§10.3). Cost outweighs benefit; the documented pattern for per-Subject audit is Standard-Action-per-Subject (§11.2).

### 13.9 Row-level Policies in V1

**Rejected** (§5.6). Expressible via filter; first-class row-level access control is a post-V1 RFC.

### 13.10 Window functions, recursive queries, percentile aggregates

**Rejected for V1**. Closed grammar; extensions require new RFC.

---

## 14. Trade-offs

1. **Loud Field-visibility failure** (§5.1) requires query authors to either remove denied Field references or request access. More friction than silent omission, but the right default for analytical contexts.
2. **Single audit entry per MaintenanceAction** (§10.3) saves storage and processing but loses per-Subject granularity. The documented Standard-Action-per-Subject pattern (§11.2) is the escape valve.
3. **Synchronous MaintenanceActions** (§9.3) hold transactions for long operations. Mitigation: `TransactionTooLarge` forces operators to split when limits are exceeded.
4. **All-or-nothing bulk** (§11.2) trades partial progress for simpler audit/rollback. Acknowledged.
5. **Single primary Entity in BulkSubject for multi-Entity MaintenanceActions** (§9.6) preserves Audit Entry shape but loses sample fidelity for secondary Entities. Outputs breakdown covers count but not samples.
6. **No row-level Policies** (§5.6). Plugin-author responsibility via filter. Acceptable for V1; future RFC may add.
7. **Reports do not participate in the active Invoker transaction** (§2.2). Reports see committed state. Effects that need uncommitted-state visibility cannot use the ReportingDriver; they read via Repository within the transaction.
8. **Single ReportingDriver per deployment** (§2.7). Heterogeneous reporting stores deferred to post-V1, like RFC-002's single-PersistenceDriver constraint.

---

## 15. Open questions

1. **RFC-002 Amendment-01** must update §12.1 PersistenceError taxonomy to include `BulkWithoutAcknowledgement` (raised when `acknowledges_bulk_lwm: false` and the effect issues `updateMany`/`deleteMany`). §8.2 of this RFC references it.
2. **RFC-007 Amendment** to accept `outputs.bulk_entities` shape for multi-Entity MaintenanceActions (§9.6, §10.2).
3. **Post-V1 — Reporting saved queries, scheduled reports, query budgets.** Out of V1.
4. **Post-V1 — Row-level Policies.** Documented absence in §5.6.
5. **Post-V1 — Asynchronous MaintenanceActions.** Documented absence in §9.3.
6. **Post-V1 — Heterogeneous reporting stores.** Multi-driver deployments.
7. **Post-V1 — Materialized views, query result caching with explicit invalidation.** Performance optimization; driver-internal in V1.
8. **The fingerprint format for `metadata.queryFingerprint` and audit `inputs.queryFingerprint`.** This RFC requires presence and opacity but does not normalize the algorithm. A follow-up RFC may standardize for cross-deployment query correlation.

---

## 16. Challenger review — attack matrix

Each contract attacked against: **layer violations**, **tenancy bypass**, **audit bypass**, **mutation bypass**, **policy bypass**, **visibility bypass**, **SemVer traps**.

### 16.1 `ReportingDriver` (§2)

| Attack | Defence |
|---|---|
| Layer violation: plugin reaches into ReportingDriver to acquire a write connection. | §2.5: contract has no write surface. Driver implementations bound to expose only the three methods; conformance test verifies. |
| Tenancy bypass: `execute(query, tenantA, actor)` returns rows from `tenantB`. | §5.5: implicit Tenant predicate. Drivers MUST add per-Entity Tenant scoping. Conformance test issues cross-Tenant query and verifies rejection / empty result. |
| Audit bypass: query touches audited-reads Entity but no audit emitted. | §7.2: one audit per query. §7.3 primary failure aborts. Conformance test wraps a known audited-reads Entity and verifies audit emission. |
| Mutation bypass: query syntax permits side effects. | The grammar (§3) has no write nodes. No INSERT/UPDATE/DELETE nodes exist in the tree. Drivers translating to SQL MUST NOT generate write SQL; conformance: spy on driver output, assert read-only. |
| Policy bypass: query targets an Entity the Actor cannot read. | §5.4: Entity-level Policy evaluated. Reject on Deny. |
| Visibility bypass: query references a denied Field via alias indirection. | §5.1: enforcement happens at field-reference resolution time; aliases are resolved before evaluation. Indirection does not hide the underlying Field FQN. |
| SemVer trap: new aggregate function added in V1.x. | Aggregate function set is closed (§3.5). New aggregates require new RFC and minor bump (additive). Existing consumers ignore unknown aggregates only at query-construction time; runtime queries with unsupported aggregates are rejected. |

### 16.2 `ReportingQuery` grammar (§3)

| Attack | Defence |
|---|---|
| Layer violation: query carries closure or callable. | Grammar has no callable types; tree is JSON-serializable, opaque values are typed per RFC-004 §4. |
| Tenancy bypass: filter references `tenant_id = '<other>'`. | §5.5: Tenant predicate is implicit AND enforced by rejecting tree references to a different Tenant. |
| Audit bypass | n/a; grammar is structural, audit is at execution boundary. |
| Mutation bypass | Grammar lacks any write node. |
| Policy bypass: query lies about which Entities it touches. | The driver walks `from` and `joins` to enumerate Entities; the tree is authoritative. Plugins cannot misreport. |
| Visibility bypass: filter references a denied Field embedded in `RelationExists.subFilter`. | §5.1 enforcement walks the entire tree, including nested filters. No corner. |
| SemVer trap: new `kind` for ProjectionItem. | Sealed sum type. New variants require minor bump if profile-supported, major otherwise (§3.5 logic). |

### 16.3 Field visibility enforcement (§5)

| Attack | Defence |
|---|---|
| Layer violation: policy evaluation runs at L3 driver but consults L0 contracts. | Policies are L0 descriptors; evaluator is owned by L2 Runtime; driver consults via the L0 Policy contract. Standard layering. |
| Tenancy bypass: visibility policy uses Tenant from Context to grant cross-Tenant view. | Policies receive the active Tenant; granting beyond the active Tenant is a plugin-author bug (the policy returns Permit irresponsibly). Not preventable by the driver; documented as plugin responsibility. |
| Audit bypass | n/a. |
| Mutation bypass | n/a. |
| Policy bypass: visibility policy returns Permit on `null` Subject regardless of Actor. | This is a plugin-author bug. Documented in §5.3: policies that require Subject for evaluation MUST return Deny on null Subject. Conformance examples ship. |
| Visibility bypass: query aggregates over a Field whose visibility denies for non-null Subjects but permits for null. | §5.3 mitigation: policy authors aware of the convention return Deny on null when row-level required. If the policy returns Permit on null but Deny on rows, the query returns no rows (Deny per row), which is loud failure or empty results — depends on how the driver evaluates rows. V1 driver SHOULD evaluate at query-validation time only (null Subject); per-row visibility is not in scope. |
| SemVer trap: changing the `kernel.field.read` sentinel FQN. | Frozen per Amendment-01 §A-1.2. Change requires major bump. |

### 16.4 Audit emission (§7)

| Attack | Defence |
|---|---|
| Layer violation: ReportingDriver bypasses Auditor and writes audit directly. | The Auditor is a Kernel contract; driver invokes it. Direct writes to audit storage by the driver are conformance failures. |
| Tenancy bypass: audit entry's `tenant` differs from bound tenant. | RFC-007 §20.1 `AuditTenantMismatch` raised. |
| Audit bypass: driver skips emission for audited-reads Entities. | §7.2 + §7.3: required and primary-failure-aborts. Conformance test required (§17.6). |
| Mutation bypass | Reads only. |
| Policy bypass | Audit is not a Policy concern. |
| Visibility bypass: audit entry's `inputs.query` reveals values for denied Fields via filter parameters. | Query was rejected before execution (§5.1); audit is never emitted for rejected queries. For accepted queries, only Permitted Fields are referenced; their values in filter parameters are not "denied" by definition. |
| SemVer trap: changing `actionFqn` for reporting audit. | `kernel.reporting.query` is frozen for V1. Change requires major bump. |

### 16.5 `MaintenanceAction` declaration (§8)

| Attack | Defence |
|---|---|
| Layer violation: plugin manifest declares a MaintenanceAction with `policy: null`. | §8.2 (1): policy is required. Compiler rejects at registration. |
| Tenancy bypass | MaintenanceActions inherit Tenant scoping from the Invoker (RFC-001 §8.2). Manifest cannot grant cross-Tenant scope. |
| Audit bypass: manifest declares `audited: false`. | No such field exists. Audit is always on (RFC-001 §8.3). Manifest cannot disable. |
| Mutation bypass: MaintenanceAction declared without `acknowledges_bulk_lwm` performs `updateMany`. | §8.2 (3): RFC-002 §11.4 raises `BulkWithoutAcknowledgement` at first invocation. Conformance: registration warns; runtime fails loudly. |
| Policy bypass: `skip_workflow_guards: true` on an unaudited or non-Maintenance Action. | The flag is meaningful only when `kind: "maintenance"`. Compiler rejects `skip_workflow_guards` on non-maintenance Actions. |
| Visibility bypass | n/a; declarations don't reference Fields directly. |
| SemVer trap: adding required manifest fields. | Adding required fields in V1.x is breaking. New fields are added as optional with defaults; new required fields require major bump. |

### 16.6 `MaintenanceAction` execution (§9, §10)

| Attack | Defence |
|---|---|
| Layer violation: effect reaches into PersistenceDriver to escape the transaction. | RFC-002 §3.2.1 binds plugin code to `PersistenceContext` only. Effect cannot acquire driver directly. |
| Tenancy bypass: effect performs `Ausus::elevate` and bulk-mutates another Tenant. | Elevation is permitted (RFC-003 §10) but audited; the bulk in the elevated context emits its own audit entry with `elevation` slot. No bypass; full audit trail. |
| Audit bypass: effect crashes mid-execution, no audit emitted. | Crash before audit emission means the transaction rolls back (RFC-002 §7.3). No data mutation observable; therefore no audit needed for non-effects. If audit emission itself failed (primary failure), rollback per Amendment-01 §A-1.6. |
| Mutation bypass | The MaintenanceAction is the mutation path; no bypass concept. |
| Policy bypass: effect invokes another Action via Invoker that doesn't require the original Actor's Policies. | Invoker re-evaluates Policy for every nested invocation (RFC-001 §8.2.1). No transitive Policy grants. |
| Visibility bypass: bulk audit's `sample_handles` includes handles for instances the original Actor could not see in detail. | Audit captures what was actually mutated; the Actor by definition had Permit to mutate (Policy chain passed). Visibility for read is orthogonal. |
| SemVer trap: changing how multi-Entity MaintenanceActions are summarized. | §9.6 documents the V1 convention (single `entity_fqn` + `outputs.bulk_entities`). Future RFC may extend; doing so is additive (new field), minor bump. |

### 16.7 Partial failure (§11)

| Attack | Defence |
|---|---|
| Layer violation: driver returns partial results with embedded error. | §11.1: forbidden. Conformance test verifies. |
| Tenancy bypass: error includes data from another Tenant in `cause.message`. | RFC-002 §18.10 attack: drivers MUST scrub error details to active Tenant. Restated. |
| Audit bypass: a failed MaintenanceAction emits no audit. | Failed because policy denied or transaction rolled back: per Amendment-01 §A-1.6, the audit may or may not have been emitted depending on which step failed. The Invoker emits a `failed-invocation` Audit Entry where appropriate; RFC-007 details. |
| Mutation bypass | n/a; failure is a non-mutation. |
| Policy bypass | n/a; failure honours policies. |
| Visibility bypass: a `FieldVisibilityDenied` error reveals the existence of a denied Field. | Acknowledged: the existence of a Field FQN is part of the Metadata Graph, which is queryable in other ways. The error names the FQN explicitly; this leak is intentional for the analytical context's need-to-know. A plugin author could see the Field exists by introspecting the graph. |
| SemVer trap: new error type in V1.x. | §11.1 taxonomy is closed for V1. Additions for genuinely new operations are minor; replacements are major. |

---

## 17. Acceptance criteria

This RFC is accepted when:

1. The three role signatories (architect, kernel, challenger) sign off on §2, §3, §5, §7, §8, §9, §10, §11.
2. RFC-002 Amendment-01 commits to adding `BulkWithoutAcknowledgement` to the PersistenceError taxonomy (§15.1, §16.5).
3. RFC-007 Amendment commits to accepting `outputs.bulk_entities` for multi-Entity MaintenanceAction audit (§15.2).
4. RFC-001 Amendment-02 (per RFC-007 §21.2) is sequenced before RFC-010 V1 ship, since `kernel.field.read` and `audited_reads` declarations live there.
5. A conformance test suite is scoped (not built) for `ReportingDriver`: at minimum, one test per "MUST" clause in §2, §5, §7, §11.1.
6. A conformance test suite is scoped for MaintenanceAction registration and execution: at minimum, one test per "MUST" clause in §8, §9.
7. Appendices B and C re-run before each subsequent draft.

Once accepted, this RFC is the source of truth for reporting and maintenance contracts. Any contradiction in a future RFC requires an amendment to this document or an explicit "supersedes."

---

## Appendix A — V1 public surface enumeration

```
Ausus\Kernel\Contracts\Reporting\
  ReportingDriver                     (interface)
  ReportingQuery                      (final value object)
  EntitySource, Join, ProjectionItem, FieldProjection, AggregateProjection,
  FieldRef, AggregateFilter, SortKey, Pagination, ParameterBinding
                                      (final value objects; sum-type members where indicated)
  ReportingResult                     (final value object)
  ReportingPlan                       (interface; opaque content)
  ReportingCapabilities               (final value object)

Ausus\Kernel\Contracts\Reporting\Errors\
  ReportingError                      (abstract)
  ReportingDriverUnbound,
  EntityReadDenied, FieldVisibilityDenied,
  UnknownEntity, UnknownRelation,
  EmptyProjection, UngroupedFieldProjection, HavingReferencesNonAggregate,
  IncompatibleAggregateCurrency,
  JoinDepthExceeded, GroupByWidthExceeded,
  PageSizeOutOfBounds, QueryTimeout, InvalidCursor,
  AuditEmissionFailed                 (shared with RFC-007 §15.3)

Ausus\Kernel\Contracts\Maintenance\
  MaintenanceActionManifest           (declaration shape per §8.1; consumed by Compiler)

Sentinels:
  kernel.field.read                   (RFC-001 Amendment-01 §A-1.2; consumed here)
  kernel.entity.read                  (RFC-007 §15; reused here)
  kernel.reporting.query              (this RFC §7.2)
  kernel.reporting.aggregate          (this RFC §7.2; identity handle sentinel)

Reserved Action namespace:
  kernel.reporting.*                  (no plugin may register)
```

---

## Appendix B — Contradiction scan

| ID    | Description | Status |
|-------|-------------|--------|
| C10-01 | §5.1 (loud fail on denied Field reference) vs RFC-004 §5.6 (silent omission in ViewSchema). | Consistent; different layers, different semantics. §5.2 justifies. |
| C10-02 | §2.2 (no Invoker transaction participation) vs §10.4 (audit failure rolls back bulk). | Consistent; rollback applies to the MaintenanceAction's Invoker transaction (data PersistenceDriver), not to the reporting read. Reports are not in the same transaction. |
| C10-03 | §9.6 (multi-Entity MaintenanceAction with single `BulkSubject.entity_fqn`) vs Amendment-01 §A-1.8 (`BulkSubject` is per-Entity). | Acknowledged divergence. §9.6 pragmatic V1 convention. §15.2 schedules RFC-007 Amendment to formalize `outputs.bulk_entities`. |
| C10-04 | §11.2 (no partial-success in V1) vs §11.4 (effect issues reads during execution). | Consistent; reads do not produce partial mutation state. Transaction wraps all writes. |
| C10-05 | §9.3 (synchronous MaintenanceActions) vs RFC-003 §13.3 (multi-phase strategy migration). | Consistent; strategy migration phases are separate Invoker calls, each synchronous. Long-running orchestration uses the scheduled-Action pattern (RFC-003 §11.4). |
| C10-06 | §7.3 (audit failure aborts the read) vs §2.2 (driver reads committed state). | Consistent; audit emission is at the Invoker boundary, not at the driver-storage boundary. Audit failure prevents the result from being returned. |
| C10-07 | §5.6 (no row-level Policies) vs RFC-001 §8.2 (Policy chain includes row-level enforcement via Subject). | Consistent; the Subject in §8.2 Policy evaluation is per-Action-instance; for reporting queries the per-row evaluation is not part of V1. Row-level filtering achievable via Filter. |
| C10-08 | §8.4 (`kernel.*` namespace reserved) vs §9.6 (Kernel-registered MaintenanceActions follow same grammar). | Consistent; Kernel reserves its own namespace and follows the same declaration rules. |
| C10-09 | §11.2 (Standard-Action-per-Subject for partial-success) vs RFC-007 §11 retry queue. | Independent mechanisms. Standard Actions for partial-success are user-driven; retry queue is audit-delivery-driven. No conflict. |
| C10-10 | `ReportingDriver` reads committed state (§2.2) vs RFC-002 §7 transaction semantics. | Consistent; ReportingDriver is outside the active transaction by construction. Reads commit boundary. |

**Result.** No real contradictions. One scheduled downstream amendment (C10-03 → §15.2) documented.

---

## Appendix C — Layer boundary scan

| Component | Layer | Inbound | Outbound | Result |
|---|---|---|---|---|
| `ReportingDriver` | L3 plugin | invoked by L2 (effects, view-data fetches per RFC-004 §11.2), L4 (analytical endpoints) | L0 contracts | OK |
| `ReportingQuery` and result types | L0 contracts | constructed by plugins (L7) | consumed by L3 | OK |
| MaintenanceActionManifest | L0 declaration | declared by plugins (L7), parsed by L1 Compiler | consumed by L2 Invoker at runtime | OK |
| MaintenanceAction execution | L2 Runtime (Invoker) | triggered by L4 / scheduled jobs | L3 driver via PersistenceContext, L2 Auditor | OK |
| Reporting audit emission | L2 Runtime (Auditor) | invoked by L3 ReportingDriver via Auditor contract | L3 audit sink | OK |

**Findings.**

| ID | Description | Resolution |
|---|---|---|
| L10-01 | ReportingDriver is invoked by L5 Presentation for data-bearing Projections (RFC-004 §11.2). Does this create a new dependency from L5 to L3? | L5 already depends on L3 via RFC-002 Repository for data-bearing Projections. ReportingDriver is the same direction. No new layer crossing. |
| L10-02 | The Field visibility evaluation in §5.1 happens at L3 driver but consults L0 Policy descriptors and the Authorization plugin (L7). | Standard pattern: L3 reads L0 contracts; L7 plugin provides the implementation bound at L7 via the L0 Authorization contract. Direction L3 → L0 ← L7. OK. |
| L10-03 | MaintenanceAction declarations live in plugin manifests but are validated by L1 Compiler. | Declarations are L0-shaped; Compiler (L1) reads them. Standard layering. |
| L10-04 | Multi-Entity MaintenanceAction outputs (`bulk_entities`) bridge driver bulk results (L3) and audit emission (L2). | Driver returns BulkResult per call; Action effect aggregates into a single payload; Invoker emits one audit with the aggregated payload. No new layer; effect-level composition. |
| L10-05 | `kernel.reporting.aggregate` sentinel identity handle in audit Subject (§7.2). | Sentinel constants are part of the L0 contract surface; the audit subject's `identity_handle` is a string per RFC-002 §6 opacity rules. The sentinel is well-known but treated opaquely by sinks. OK. |

**Result.** No layer violations. Five findings resolve under existing patterns.

---

## Appendix D — Example queries (informative)

### D.1 List active invoices with customer display name

```json
{
  "from": { "entity": "billing.invoice", "alias": "inv" },
  "joins": [
    { "via": "customer", "source": "inv", "alias": "cust", "kind": "inner", "filter": null }
  ],
  "filter": {
    "kind": "FieldEquals",
    "field": "inv.status",
    "value": "issued"
  },
  "project": [
    { "kind": "field", "source": "inv.id",          "alias": "invoice_id" },
    { "kind": "field", "source": "inv.number",      "alias": "number" },
    { "kind": "field", "source": "inv.amount_due",  "alias": "amount_due" },
    { "kind": "field", "source": "cust.display_name", "alias": "customer_name" }
  ],
  "groupBy": [],
  "having": null,
  "orderBy": [ { "source": "inv.due_at", "direction": "asc" } ],
  "pagination": { "kind": "cursor", "pageSize": 50, "cursor": null },
  "parameters": []
}
```

### D.2 Aggregate: outstanding balance per customer

```json
{
  "from": { "entity": "billing.invoice", "alias": "inv" },
  "joins": [
    { "via": "customer", "source": "inv", "alias": "cust", "kind": "inner", "filter": null }
  ],
  "filter": {
    "kind": "FieldIn",
    "field": "inv.status",
    "values": ["issued"]
  },
  "project": [
    { "kind": "field",     "source": "cust.id",            "alias": "customer_id" },
    { "kind": "field",     "source": "cust.display_name",  "alias": "customer_name" },
    { "kind": "aggregate", "function": "sum",
      "source": "inv.amount_due", "alias": "total_outstanding" },
    { "kind": "aggregate", "function": "count",
      "source": null, "alias": "invoice_count" }
  ],
  "groupBy": [ "cust.id", "cust.display_name" ],
  "having": {
    "kind": "FieldComparison",
    "field": "total_outstanding",
    "op": "gt",
    "value": { "amount": "10000.00", "currency": "USD" }
  },
  "orderBy": [ { "source": "total_outstanding", "direction": "desc" } ],
  "pagination": { "kind": "cursor", "pageSize": 20, "cursor": null },
  "parameters": []
}
```

### D.3 MaintenanceAction manifest

```json
{
  "fqn": "billing.invoice.recompute_balances",
  "kind": "maintenance",
  "policy": "billing.invoice.recompute",
  "subject_required": false,
  "acknowledges_bulk_lwm": true,
  "skip_workflow_guards": true,
  "audit_inputs_redact": [],
  "estimated_max_subjects": 50000
}
```
