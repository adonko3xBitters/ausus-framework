# SemVer Contract — AUSUS v0.1

**Status:** ratified for v0.1.x
**Companion document:** [`API-GOVERNANCE.md`](API-GOVERNANCE.md) — full
tier matrix of every public symbol
**Applies to:** every package shipped by this monorepo
(`ausus/kernel`, `ausus/persistence-sql`, `ausus/runtime-default`,
`ausus/api-http`, `ausus/starter`, `ausus/standard-stack`,
`@ausus/renderer-react`).

---

## 0. The promise

> **STABLE surfaces** (as classified in `API-GOVERNANCE.md`) follow
> [Semantic Versioning 2.0](https://semver.org/) once we reach v1.0.0.
> During the v0.x series, we honor SemVer §4 — anything can break in a
> MINOR — but we minimize that and call it out explicitly in
> `CHANGELOG.md`.

This document tells you what "STABLE" means in practice across the
runtime, the persistence layer, the HTTP surface, the renderer, and
the DSL.

---

## 1. Version-bump rules

### 1.1 Post-1.0 strict rules

| Change | Bump |
|---|---|
| Add a method on a STABLE class with default arg, OR add a new export, OR widen a parameter type | **MINOR** |
| Add a new optional field to a JSON wire payload | **MINOR** |
| Tag a STABLE symbol as `@deprecated` | **MINOR** |
| Fix a bug without changing a STABLE contract | **PATCH** |
| Remove or rename a STABLE symbol | **MAJOR** |
| Narrow a parameter / widen a return type on a STABLE method | **MAJOR** |
| Remove a JSON field, or change a JSON field type | **MAJOR** |
| Rename or remove an HTTP route, header, or `error.kind` string | **MAJOR** |
| Bump a peer-dependency major (React 18→20, PHP 8.3→9.0) | **MAJOR** |

### 1.2 v0.x interim rules

In v0.x:

- The above rules apply, but a MINOR bump MAY ship a breaking change
  if it's flagged at the **top** of the corresponding `CHANGELOG.md`
  under a `## ⚠️ Breaking changes` heading.
- A new MAJOR will not be issued before v1.0; instead we keep moving
  in MINORs and document migrations.
- PATCH bumps in 0.x.* are reserved for non-API-affecting fixes.

When we cut v1.0.0, every existing STABLE surface enters the strict
SemVer regime above. EXPERIMENTAL and INTERNAL surfaces remain outside
SemVer indefinitely.

---

## 2. Per-tier guarantees

| Tier | API freeze | JSON wire-format freeze | Behavior freeze | MINOR may add fields? | MINOR may break? |
|---|---|---|---|---|---|
| **STABLE** | yes | yes (versioned via `schemaVersion`) | invariants in §3 | yes (optional) | only with §1.2 escape |
| **EXPERIMENTAL** | no | no | best-effort | n/a | yes — called out in CHANGELOG |
| **INTERNAL** | no | n/a | no | n/a | any release |
| **ACCIDENTAL** | no | n/a | no | n/a | any release, but listed in §6 of API-GOVERNANCE |
| **EXAMPLE** | no | n/a | no | n/a | replaced freely |

A consumer that relies on STABLE surfaces only is guaranteed:

1. their source builds on every v0.1.x.
2. every wire payload they send/receive is interpretable.
3. every catchable exception kind they handle continues to be raised
   under the same circumstances.

---

## 3. Invariants — runtime (`ausus/runtime-default`)

The Invoker chain is the heart of the framework. Its STABLE invariants:

### 3.1 Invocation order (RFC-005 §3)

For every `Invoker::invoke($actionFqn, $subject, $inputs)`, the five
steps execute in this fixed order:

```
1. Tenant check          — Reference.tenantId == Invoker.tenant
2. Policy chain          — PolicyEngine.evaluateAction → DENY short-circuits
3. Workflow guard        — WorkflowRuntime.evaluate → unique applicable
                            transition per Workflow (RFC-006 Amendment-01)
4. Effect                — EffectDispatcher.dispatch + Effect.execute
5. Audit                 — DefaultAuditor.emit within the same DB transaction
```

No future MINOR may re-order, merge, or skip any of these steps for
STABLE consumers. A new step (e.g. a rate-limit middleware) may be
inserted only if it is non-blocking by default.

### 3.2 Tenant boundary (RFC-003)

Any `Reference` whose `tenantId !== invoker.tenant.value()` raises
`TenantBoundaryViolation` **before** any policy, workflow, or effect
runs. This is non-negotiable for STABLE consumers.

### 3.3 Policy chain semantics

- `Permit` advances.
- `Deny` raises `PolicyDenied` immediately.
- `Abstain` is treated as `Deny` ("deny by default" per RFC-005 §5).
- An exception thrown inside a Policy is converted to `Deny`
  ("fail-closed") — observable behavior, kept stable.

### 3.4 Workflow source-state matching

For every Workflow attached to an Action, the WorkflowRuntime selects
**exactly one** applicable transition matching the current state. The
match is `t.source === current` OR `t.source === '*'`.

- Zero matches → `WorkflowStateMismatch`.
- More than one match → `WorkflowAmbiguousTransition` (e.g., `*` + a
  specific source for the same Action — see hardening report §RT-09).

### 3.5 Audit atomicity (RFC-007 Amendment-01)

Audit entries are written by `DatabaseAuditSink::writeInTransaction`
**inside** the Effect's data-write transaction. Orphan audit entries
are architecturally impossible.

### 3.6 Identity monotonicity

`Ulid::generate()` returns ULIDs that are strictly monotonic within a
single PHP process. Cross-process ordering follows wall-clock
timestamps.

---

## 4. Invariants — renderer (`@ausus/renderer-react`)

### 4.1 Export stability

The 10 named exports + 7 named types listed in `API-GOVERNANCE.md §7.1`
are STABLE for v0.1.x. Adding new exports is allowed; removing or
renaming requires a MAJOR.

### 4.2 Schema-version compatibility (RFC-004 §11)

`useViewSchema` enforces compatibility: if `schema.schemaVersion`
doesn't start with `"1.0"`, the hook returns an `error` instead of the
schema. View components themselves (`ListView`, `DetailView`) do **not**
gate on schema version — they accept any schema-shaped object so
prefetched / test scenarios work.

### 4.3 Defensive rendering

`ListView` and `DetailView` (post-hardening §R-03 / §R-14) never throw
out of `renderToString` on malformed input:

- Missing / non-array `data.items` → renders empty state.
- Missing `metadata` block → renders with empty title.
- `data.item: null` → renders "Item not found."

These fallbacks are STABLE behavior.

### 4.4 WorkflowBadge palette

The four canonical state colors are STABLE class-name conventions:

```
DRAFT     → ausus-badge ausus-badge--gray
ISSUED    → ausus-badge ausus-badge--blue
PAID      → ausus-badge ausus-badge--green
CANCELLED → ausus-badge ausus-badge--red
(other)   → ausus-badge ausus-badge--default
```

Consumers may add more entries via CSS without rebuilding. The set of
recognized values may grow in MINORs; existing classes remain.

### 4.5 Hook return shapes

- `useViewSchema`: `{ schema: ViewSchema | null, loading: boolean, error: {message: string} | null, refetch: () => void }`
- `useAction`:     `{ invoke: (args) => Promise<ActionResult>, pending: boolean, lastError: {kind, message} | null }`

Adding fields is non-breaking. Removing or renaming is breaking
(MAJOR post-1.0).

### 4.6 ESM emit format

The published `dist/` is **strict Node ESM**: every relative import
specifier carries an explicit `.js` extension. This is enforced at
build time by `moduleResolution: "NodeNext"` in `tsconfig.json` (see
RFC-000 V0R2 remediation §B-1).

---

## 5. Invariants — HTTP (`ausus/api-http`)

### 5.1 Frozen routes

```
GET     /_health                  liveness + graph hash
GET     /projections/{fqn}        ViewSchema (RFC-004)
POST    /actions/{fqn}            invoke Action; ActionResult
OPTIONS *                         CORS preflight
```

- `{fqn}` is URL-decoded by the Router.
- Path prefix is configurable via the Router constructor (default `/api`).
- No new routes added in v0.1.x without a MINOR bump + CHANGELOG entry.

### 5.2 Required headers — STABLE

- `X-Tenant-ID` is required on `/projections/*` and `/actions/*`. Missing
  → `400 BadRequest`.
- `Content-Type: application/json` is required on POST. Missing → `400`.

### 5.3 Error-kind ↔ HTTP-status mapping — STABLE

| `error.kind` | HTTP |
|---|---|
| `BadRequest`               | 400 |
| `MalformedDescriptor`      | 400 |
| `MalformedBody`            | 400 |
| `PolicyDenied`             | 403 |
| `TenantBoundaryViolation`  | 403 |
| `NotFound`                 | 404 |
| `ProjectionNotFound`       | 404 |
| `ActionNotFound`           | 404 |
| `WorkflowStateMismatch`    | 409 |
| `ConcurrencyConflict`      | 409 |
| `EffectFailure`            | 500 |
| `InternalError`            | 500 |

Adding new `error.kind` strings is **additive** (consumers ignoring an
unknown kind degrade gracefully). Reassigning an existing kind to a
different HTTP status is **breaking** (MAJOR post-1.0).

### 5.4 CORS — V0 demo policy

`Access-Control-Allow-Origin: *` is the V0 default. Production
deployments override via middleware. The default is documented as
EXPERIMENTAL in `API-GOVERNANCE §8.5`; tightening it in v0.2 is allowed.

---

## 6. Invariants — DSL (`ausus/kernel` DSL facades)

### 6.1 Hash equivalence (RFC-011 §11)

> For any plugin authored via the DSL facades (`Dsl`, `Action::*`,
> `Field::*`), the compiled `MetadataGraph.hash` is byte-identical to
> the hash of the same plugin authored via the descriptor-array form.

This invariant is regression-tested by `apps/playground/run.php` test
10 (manual + DSL produce equal hashes). Breaking it requires a MAJOR.

### 6.2 KPI thresholds (RFC-011 §11.1–§11.3)

V0 KPIs measured by the playground:

| KPI | V0 limit |
|---|---|
| DSL body LOC (per plugin)            | ≤ 40 |
| Imports per DSL plugin                | ≤ 10 |
| Framework FQNs surfaced per plugin    | ≤ 3 (e.g. `Dsl`, `Field`, `Action`) |

These are aspirational targets, not contract; relaxing them is
allowed in any release. Tightening them is non-breaking.

---

## 7. Invariants — persistence (`ausus/persistence-sql`)

### 7.1 Tenant isolation (RFC-003 row strategy)

Every `Repository::find` and every read inside `Repository::update`
appends `WHERE tenant_id = ?` to the SQL. The driver itself rejects
contexts whose tenant differs from the active session.

> **There is no API to query across tenants in v0.1.** Multi-tenant
> read APIs are deferred to a future driver.

### 7.2 Optimistic locking

`Repository::update($ref, $patch, Version $expected)` enforces
`WHERE id = ? AND _version = ?`. Mismatch → `ConcurrencyConflict`.

`_version` values are ULIDs (Crockford base32, 26 chars), regenerated
on every successful update.

### 7.3 Schema derivation idempotency

`SchemaDeriver::deriveAll($graph)` returns idempotent DDL — every
statement uses `CREATE TABLE IF NOT EXISTS`, so running the bootstrap
twice is safe.

### 7.4 Audit atomicity

`DatabaseAuditSink::writeInTransaction` writes into the SAME PDO
transaction that the Effect mutates. Audit + data writes commit
together or roll back together. Orphan audits are not representable.

---

## 8. Invariants — compiler (`ausus/kernel`)

### 8.1 Deterministic hash (RFC-001 §6.4)

```
hash = sha256(
  json_encode({
    actions:       sorted_action_fqns,
    entities:      sorted_entity_fqns,
    kernelVersion: string,
    policies:      sorted_policy_fqns,
    projections:   sorted_projection_fqns,
    workflows:     sorted_workflow_fqns,
  }, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
)
```

Same plugin set → same hash, bit-for-bit. The canonical form sorts
FQN-keys; field order within a node does NOT affect the hash. The hash
is **public**; consumers may use it as a graph-version identifier.

### 8.2 Validation prevented at compile-time (post-hardening pass)

The Compiler raises:

| Condition | Exception |
|---|---|
| Empty FQN on any node      | `MalformedDescriptor` |
| Duplicate FQN within kind  | `DuplicateRegistration` |
| Action → unknown Policy/Entity | `DanglingReference` |
| Workflow → unknown Entity OR state-field not on entity | `WorkflowCoherence` |
| Projection → unknown field or unknown actionFqn | `DanglingReference` |

These guarantees ship as part of the STABLE Compiler contract.

---

## 9. JSON wire-format versioning

### 9.1 `ViewSchema.schemaVersion`

The `schemaVersion` field embedded in every ViewSchema is the SemVer
of the wire format, **not** of the renderer package. v0.1 ships
`schemaVersion: "1.0.0"`. The renderer's `useViewSchema` hook accepts
`1.0.x` and rejects `2.x.x` with an error.

The renderer-side hook owns version gating. View components (ListView,
DetailView) accept any payload — see §4.2.

### 9.2 Audit log row schema (RFC-007)

Per-row columns are frozen for v0.1.x:
`(entry_id, sequence, actor, tenant, action_fqn, subject, inputs,
outputs, timestamp, correlation_id, trace_id, invocation_class,
emitter_version)`.

`emitter_version` is the version of the framework that emitted the
row. Consumers may key on it to handle schema evolution.

---

## 10. Out-of-scope surfaces

The following are **not** covered by this SemVer contract. Changes to
them never trigger a version bump.

| Surface | Why |
|---|---|
| `apps/playground/*`            | example / demo code; replaced freely |
| `packages/starter/src/Hello*`  | template starter — consumers replace it |
| `scripts/*`                    | tooling; CI invariants are tracked separately |
| `docs/*`, `rfcs/*`             | prose; not API |
| `.github/workflows/*`          | CI infrastructure |
| Internal exception types       | (`PolicySubjectRequired`, `ActorRequired`, `TenantContextRequired`) — never raised in working code |
| Built-in Effect classes        | (`CreateEffect`, `TransitionEffect`) — accessed via marker strings, not by class name |

---

## 11. Migration policy

When a STABLE surface needs to change post-1.0, the procedure is:

1. **Announce.** Tag the symbol `@deprecated` in source; add a
   `## ⚠️ Deprecation notice` section to the corresponding
   `CHANGELOG.md` in the MINOR that introduces the deprecation.
2. **Coexist.** Ship the new surface alongside the old one. Tests
   cover both paths. Minimum 1 MINOR cycle of coexistence.
3. **Remove.** Drop the deprecated surface in the next MAJOR. The
   CHANGELOG entry for that MAJOR points back to the deprecation
   notice MINOR.

EXPERIMENTAL and INTERNAL surfaces skip the deprecation cycle entirely.

---

## 12. Final determination

**GO — SemVer safety achieved for v0.1.x.**

Every STABLE surface listed in `API-GOVERNANCE.md` is now covered by:

1. an explicit tier
2. a documented invariant or set of invariants
3. a regression path (playground / trace / hardening / integration)

No symbol is in an undefined state. Accidental exposures are tagged
`@internal` in their docblocks. JSON wire formats are versioned via
`schemaVersion`. The runtime invariants are runtime-tested.

A consumer pinning `^0.1.0` can expect:

- their PHP source compiles against every v0.1.x patch.
- their TypeScript source type-checks against every v0.1.x patch.
- every JSON payload they send/receive parses identically.
- every catchable exception kind they handle continues to fire under
  the same circumstances.
