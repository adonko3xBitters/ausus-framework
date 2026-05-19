# RFC-003 — Tenancy model for AUSUS V1

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Draft                                                  |
| Authors       | architect, domain, kernel, challenger                  |
| Date          | 2026-05-18                                             |
| Depends on    | RFC-001 Draft-03 (incl. Amendment-01), RFC-002 Draft   |
| Supersedes    | —                                                      |
| Stability     | Foundational. Changes after acceptance require a follow-up RFC. |

---

## 0. Problem statement

RFC-001 promises that AUSUS is multi-tenant from day one (§2.9, §8.1) and that overrides "tweaked per Tenant" apply at resolution time (§4.4). Amendment-01 §A-1.3 restricts overrides to a closed additive/narrowing set and §A-1.7 requires installation-time validation. RFC-002 commits the `PersistenceDriver` to consult an unspecified `TenantIsolationStrategy` plugin at `context(...)` time (§13.1) and to advertise `supportedTenancyStrategies()` (§13.2). None of these documents specifies:

- how a request is mapped to a Tenant;
- what a Tenant is (identity, states, lifecycle);
- where overrides live, who installs them, and how the resolver discovers their current version without recompiling the base graph;
- how cross-Tenant elevation produces a coherent, auditable trace;
- how a deployment migrates a Tenant from one isolation strategy to another without losing data or breaking the §A-1.6 audit-rollback guarantee.

This RFC defines those contracts. It commits the V1 tenancy model to a small, named primitive set and to a closed lifecycle. It defines tenancy in terms of the existing Kernel — no new layer is introduced.

The ten-year horizon and SemVer discipline (RFC-001 §6.4) apply. Every contract here is part of the V1 public surface.

---

## 1. Scope and inherited constraints

### 1.1 Inherited

1. Every domain operation runs inside a Tenant Context, or explicitly in `system` (RFC-001 §8.1).
2. Tenant binding of an Entity instance is immutable; moving requires delete-and-recreate (RFC-001 §2.1.2.1).
3. Cross-Tenant Relations are forbidden unless both endpoints are `system` (RFC-001 §2.1.2.3).
4. Overrides are limited to the four allowed operations of Amendment-01 §A-1.3.
5. Override validation runs at installation time, against the compiled base graph; the resolver never sees an invalid override (Amendment-01 §A-1.7).
6. The base graph is read-only at runtime (RFC-001 §4.3). No runtime recompilation.
7. All mutations run through the `Invoker` (Amendment-01 §A-1.4) and emit audit; primary audit failure rolls back the data (§A-1.6).
8. The driver is single per deployment (RFC-002 §14). Tenancy never requires multi-driver coordination in V1.
9. The driver consults isolation strategy at `context(...)` time only (RFC-002 §13.1, §18.11).
10. `DriverCapabilities::supportedTenancyStrategies()` advertises one or more of `'row' | 'schema' | 'database'` (RFC-002 §13.2). The configured strategy MUST be supported by the bound driver.

### 1.2 Out of scope

- Authentication. The Kernel knows Actors and Policies; this RFC does not specify how an Actor proves identity (RFC-001 §1.2).
- The Authorization plugin. Roles, permissions, ABAC, etc., live in their own plugins (RFC-001 §8.2).
- Concrete persistence shapes for tenancy data. The catalog and override store consume the L3 driver (RFC-002), not a separate persistence path.
- Quotas, rate limiting, billing meters. Out of scope.
- The exact format of subdomain, header, or JWT resolver outputs. The contract is the resolver interface; implementations are plugins.

---

## 2. Tenant identity, states, lifecycle

### 2.1 `TenantId`

```
final class TenantId
{
  function value(): string;     // opaque, serializable, immutable
  function isSystem(): bool;    // true iff value() === '__system__'
}
```

`TenantId` values are produced by the Tenant catalog (§5) at bootstrap. They are stable for the lifetime of the Tenant. Per Amendment-01 §A-1.3, `tenant_id` (as used in the canonical reference tuple of RFC-001 §2.1.1.4) is exactly `TenantId::value()`.

### 2.2 `Tenant`

```
final class Tenant
{
  function id(): TenantId;
  function state(): TenantState;
  function isolationStrategy(): string;     // 'row' | 'schema' | 'database'
  function createdAt(): Timestamp;
  function archivedAt(): ?Timestamp;
  function overrideVersion(): int;          // monotonically increasing, used by §9
}
```

`Tenant` is the value object the Kernel passes to the Invoker, the PersistenceDriver, and the Presentation layer. It is the L0-canonical handle on a tenant; resolvers, isolation strategies, and override stores all consume and produce it.

### 2.3 `TenantState`

A closed enum with six values. State transitions are the only Action set permitted to modify Tenant state.

```
enum TenantState {
  PROVISIONING,   // catalog row exists; physical resources being created
  ACTIVE,         // accepts traffic
  SUSPENDED,      // resolver returns the Tenant, Kernel rejects operations
  MIGRATING,      // strategy migration in progress; see §13
  ARCHIVED,       // read-only via elevation; not reachable from normal resolvers
  DELETED         // catalog tombstone; physical resources reclaimed
}
```

State transitions form the closed automaton in Appendix B.

### 2.4 Lifecycle invariants

1. A Tenant is created in `PROVISIONING`. Bootstrap moves it to `ACTIVE`; failure leaves it in `PROVISIONING` for the janitor (§6.4).
2. `ACTIVE` → `SUSPENDED` → `ACTIVE` is reversible. `ARCHIVED` is reversible (`ARCHIVED` → `ACTIVE`) only via an explicit, audited un-archive Action.
3. `DELETED` is terminal. The catalog row remains as a tombstone (with `TenantId`, `archivedAt`, `deletedAt`); the `TenantId` value MUST NOT be reused.
4. `system` is bootstrapped during kernel installation and resides in `ACTIVE` permanently. It cannot transition to any other state.
5. Each transition is itself an Action invoked through the Invoker against the `system` Tenant. Each emits an Audit Entry (per §10 of this RFC for elevation shape).

---

## 3. `TenantResolver` contract

### 3.1 Contract

```
interface TenantResolver
{
  function context(): ResolverContext;      // HTTP | CLI | QUEUE | SCHEDULED
  function resolve(ResolutionInput $in): ?TenantId;
}

enum ResolverContext { HTTP, CLI, QUEUE, SCHEDULED }
```

`ResolutionInput` is a sum type over the four contexts, carrying only what the context naturally provides (HTTP request headers and URI; CLI argv and env; queue job payload; schedule entry). The interface is uniform; the input is context-typed so resolvers cannot pretend to be something they are not.

### 3.2 Invariants

1. A resolver MUST be registered for each context the deployment uses. A request in a context with no registered resolver MUST be rejected at boot, not at request time. `ausus:doctor` enforces.
2. `resolve()` MUST be pure with respect to the input: same input, same output, no I/O beyond consulting the Tenant catalog for existence (§5).
3. `resolve()` MUST NOT mutate, log, or audit. Resolution is a read.
4. Returning `null` is the only signal of "no Tenant for this request." The Kernel decides what to do (reject, or route to a system-bound public endpoint defined out of scope of this RFC).
5. A resolver MUST NOT return the `system` `TenantId` (§12.3). The `system` Tenant is unreachable via normal resolution; it is reached only by elevation (§10).
6. A resolver MUST NOT return a `TenantId` for a Tenant in `ARCHIVED` or `DELETED` state. The Kernel rejects `SUSPENDED` after the catalog lookup (§5.4); resolvers MAY filter early or defer.

### 3.3 Per-context resolvers

Per-context resolution mechanics are specified in §11.

### 3.4 Composition

A deployment MAY register a composite resolver for a context (e.g., HTTP first tries subdomain, falls back to header). Composition is a plugin concern; the Kernel sees one resolver per context.

---

## 4. `TenantIsolationStrategy` contract

### 4.1 Contract

```
interface TenantIsolationStrategy
{
  function name(): string;                                // 'row' | 'schema' | 'database'
  function scopeFor(Tenant $tenant): IsolationScope;
  function capabilities(): IsolationCapabilities;
}

sealed type IsolationScope =
  | RowScope    (tenant_id: string)
  | SchemaScope (schema: string)
  | DatabaseScope (connection: string)
```

`IsolationScope` is what `PersistenceDriver::context(...)` (RFC-002 §3.1) consumes to bind a `PersistenceContext` to a Tenant. The driver MUST accept all three scope shapes if it advertises all three strategies in its `DriverCapabilities`; it MAY accept a strict subset.

### 4.2 `IsolationCapabilities`

```
final class IsolationCapabilities
{
  function supportsFieldAdditions(): bool;
  function supportsProjectionAdditions(): bool;
  function supportsPolicyAdditions(): bool;
  function supportsWorkflowTransitionAdditions(): bool;
  function requiresDdl(): bool;
}
```

Different isolation strategies permit different override operations naturally. Row-level isolation cannot per-Tenant ALTER a shared table without contention; schema-per-tenant can; database-per-tenant can. The override installation validator (§8) consults `IsolationCapabilities` for the Tenant's strategy before accepting an override. Boot-time validation rejects any deployment whose configured strategy advertises capabilities incompatible with the override operations the plugin manifest declares it will need.

`requiresDdl()` signals whether override installation may issue DDL. Drivers consult this to split installation into transactional and non-transactional phases (§8.5).

### 4.3 Invariants

1. The deployment configures exactly one strategy class. Multiple Tenants on the same strategy class is the V1 norm; per-Tenant strategy diversity is supported only through strategy migration (§13).
2. The configured strategy's `name()` MUST appear in the driver's `DriverCapabilities::supportedTenancyStrategies()`. Boot fails otherwise.
3. `scopeFor(systemTenant)` returns a strategy-appropriate `IsolationScope` for the `system` Tenant (§12.2).
4. `capabilities()` is invariant for the strategy class; it MUST NOT vary per-Tenant. Per-Tenant capability differences mean the wrong strategy is bound.

---

## 5. Tenant catalog

### 5.1 Contract

```
interface TenantCatalog
{
  function load(TenantId $id): ?Tenant;
  function list(?TenantStateFilter $filter = null): iterable;     // generator
  function create(TenantId $id, string $strategyName): Tenant;     // PROVISIONING
  function transition(TenantId $id, TenantState $to, array $context = []): Tenant;
  function bumpOverrideVersion(TenantId $id): int;                 // returns new version
}
```

### 5.2 Storage

The catalog is persisted through the bound `PersistenceDriver`. It is itself a Kernel-owned set of Entities (`kernel.tenant`, `kernel.tenant_state_log`) registered by the Kernel's own service provider, not by a plugin. Catalog operations therefore inherit all RFC-002 invariants: Tenant scope (the catalog itself lives in `system`), optimistic locking, transactions, audit.

### 5.3 Invariants

1. The catalog is the **single source of truth** for Tenant existence and state. Resolvers do not cache Tenant existence beyond the cache-invalidation rules of §9.
2. Catalog writes execute only through the Invoker, against the `system` Tenant. Direct catalog manipulation is forbidden, including from plugins that ship Tenant lifecycle tooling (their tooling must go through the named Actions of §6, §7, §13).
3. `transition(...)` rejects transitions not permitted by the state automaton (Appendix B). The check is performed inside the Invoker call's effect, before audit emission.
4. `bumpOverrideVersion(...)` is the only way to advance a Tenant's `overrideVersion`. It is called by override install (§8) and override uninstall (§8) inside their respective Invoker transactions.

### 5.4 State-based admission

After resolution (§3), the Kernel calls `TenantCatalog::load(resolvedId)` and admits the operation per state:

- `ACTIVE` — admit.
- `SUSPENDED` — reject with `TenantSuspended`. Operation does not enter the Invoker.
- `PROVISIONING` — reject with `TenantNotReady`.
- `MIGRATING` — reject with `TenantMigrating` (V1; future RFCs may permit proxied operation).
- `ARCHIVED` — reject from normal flow. Reachable only via elevation (§10).
- `DELETED` — reject with `TenantNotFound` (indistinguishable from never-existed by design).

State admission is the Kernel's responsibility, not the resolver's (§3.2.5).

---

## 6. Tenant bootstrap

### 6.1 Bootstrap Action

A Kernel-registered Action `kernel.tenant.bootstrap` creates a Tenant. It is a `MaintenanceAction` (RFC-001 §2.4.1) because it may issue DDL when `IsolationCapabilities::requiresDdl()` is true.

### 6.2 Phased execution

To respect Amendment-01 §A-1.6 (audit-rollback) under non-transactional DDL, bootstrap runs in three phases, each a separate Invoker call:

1. **Phase 1 — catalog insert.** Transactional. `TenantCatalog::create(id, strategyName)` produces a `PROVISIONING` row. Audit emission and rollback apply normally. If primary audit fails, rollback removes the row; no physical resource exists yet.
2. **Phase 2 — physical resource creation.** Non-transactional when `requiresDdl()` is true: create schema, provision database, run initial migrations. Audited at phase boundaries. If this phase fails partway, the Tenant remains in `PROVISIONING`; the janitor (§6.4) handles cleanup.
3. **Phase 3 — activation.** Transactional. `TenantCatalog::transition(id, ACTIVE)` after verifying phase 2 succeeded. If primary audit fails, rollback returns state to `PROVISIONING`.

A bootstrap that completes phase 1 but fails phase 2 or phase 3 leaves a `PROVISIONING` Tenant. The Tenant is unreachable by any resolver (per §5.4) and is the janitor's responsibility.

### 6.3 Atomicity boundary

The atomicity boundary is the phase, not the bootstrap. This is the only honest answer: DDL is non-transactional in every relational store the Kernel targets. Splitting the operation into phases makes the failure modes nameable and auditable.

### 6.4 Janitor

A Kernel-registered scheduled Action `kernel.tenant.bootstrap_janitor` runs periodically (deployment-configured) and finalizes or reverses Tenants stuck in `PROVISIONING` for longer than a deployment-configured grace period. The janitor's reversal path issues `kernel.tenant.bootstrap_rollback`, which deletes the catalog row and (when possible) the partially-created physical resources. Both the janitor and the rollback emit audit.

---

## 7. Suspension, archival, deletion

### 7.1 Suspension

`kernel.tenant.suspend` transitions `ACTIVE` → `SUSPENDED`. Single-phase, transactional. The Kernel's state-admission check (§5.4) immediately blocks all operations against the Tenant. Open transactions complete normally; new ones are rejected.

`kernel.tenant.resume` transitions `SUSPENDED` → `ACTIVE`. Same shape.

### 7.2 Archival

`kernel.tenant.archive` transitions `ACTIVE | SUSPENDED` → `ARCHIVED`. Phased like bootstrap, in reverse:

1. **Phase 1 — state change.** `ARCHIVED` is set; `archivedAt` is recorded. Transactional, audited.
2. **Phase 2 — optional export.** A separately-registered Action (e.g. `archive.export.s3` shipped by an archival plugin) MAY be triggered to export Tenant data. Out of scope for the Kernel.

Archived Tenants are reachable only via elevation (§10). All overrides remain installed; the override version is frozen.

### 7.3 Un-archival

`kernel.tenant.unarchive` transitions `ARCHIVED` → `ACTIVE` after an explicit operator confirmation captured in the Action's inputs. Single-phase, transactional, audited.

### 7.4 Deletion

`kernel.tenant.delete` transitions `ARCHIVED` → `DELETED`. Phased:

1. **Phase 1 — retention check.** Transactional. Verify the Tenant has been in `ARCHIVED` for at least the deployment-configured minimum retention period. Reject otherwise.
2. **Phase 2 — physical purge.** Issues a `BulkSubject` audit (Amendment-01 §A-1.8) reporting the count of Entity instances destroyed across all Entity FQNs in the Tenant. The driver performs the strategy-appropriate purge (`DELETE WHERE tenant_id = ?`, `DROP SCHEMA`, `DROP DATABASE`). Non-transactional under DDL-bearing strategies; janitor cleanup applies as in §6.4 if interrupted.
3. **Phase 3 — tombstone.** Transactional. `DELETED` state set; `deletedAt` recorded. `TenantId` value is reserved permanently to prevent reuse.

### 7.5 Deletion vs archival

These are distinct operations with distinct audit trails. Deletion without prior archival is forbidden; the state machine rejects `ACTIVE` → `DELETED`. This guarantees a minimum retention window for compliance and an opportunity to halt deletion before it is irreversible.

---

## 8. Override storage and installation

### 8.1 `OverrideDescriptor`

```
final class OverrideDescriptor
{
  function tenantId(): TenantId;
  function operations(): OverrideOperation[];          // see §8.2
  function declaredBy(): string;                       // Actor FQN
  function reason(): string;                           // free-text rationale, audited
}

sealed type OverrideOperation =
  | AddField(entityFqn: string, FieldDescriptor)
  | AddProjection(entityFqn: string, ProjectionDescriptor)
  | AddPolicy(target: PolicyAttachment, PolicyDescriptor)
  | AddWorkflowTransition(workflowFqn: string, source: string, target: string, viaAction: string, ?guardPolicy: string)
```

The four operation cases are exactly the four allowed by Amendment-01 §A-1.3. Removal, rename, type change, detachment, cross-Tenant Relation addition — none are expressible by the type system. The validator (§8.3) does not need to police what the type cannot express.

### 8.2 `OverrideStore`

```
interface OverrideStore
{
  function load(TenantId $id): OverrideDescriptor[];
  function install(TenantId $id, OverrideDescriptor $desc): OverrideRef;
  function uninstall(TenantId $id, OverrideRef $ref): void;
  function list(TenantId $id): OverrideRef[];
  function get(TenantId $id, OverrideRef $ref): ?OverrideDescriptor;
}
```

The OverrideStore is a Kernel-owned persistence surface, distinct from the Tenant catalog. It is realized through the bound `PersistenceDriver` against a Kernel-owned Entity (`kernel.tenant_override`). It lives in the `system` Tenant; per-Tenant overrides are addressed by `tenantId` inside the descriptor.

### 8.3 Installation validator

`kernel.tenant.override_install` is a Kernel-registered Action invoked through the Invoker. Its effect, in order:

1. **Validate** the `OverrideDescriptor` against:
   - The compiled base graph (Amendment-01 §A-1.7): every `AddField` Field Type exists; every `AddProjection` reference resolves; every `AddPolicy` attachment target exists; every `AddWorkflowTransition` source state exists.
   - The active `IsolationCapabilities` for the Tenant's strategy (§4.2): operations the strategy cannot realize are rejected with `OverrideUnsupportedByStrategy`.
   - The current installed override set for the Tenant: duplicate-name collisions and incompatible additions (e.g., two added Fields with the same FQN) are rejected with `OverrideConflict`.
2. **Persist** through `OverrideStore::install(...)`. Transactional via RFC-002 §7.
3. **Realize physical resources** if `IsolationCapabilities::requiresDdl()` and the operation requires it (e.g., `AddField` on a schema-per-tenant strategy → ALTER TABLE in the tenant schema). Non-transactional; phased as in §6.2.
4. **Bump override version** via `TenantCatalog::bumpOverrideVersion(...)`. Transactional, in the same transaction as step 2 for DDL-free overrides; in a third phase for DDL-bearing ones.
5. **Audit emission** per Amendment-01 §A-1.6. Primary sink failure rolls back steps 2 and 4. Step 3 (DDL) is governed by the same janitor pattern as §6.4.

Validation runs **before** persistence. An invalid override never reaches the OverrideStore and never bumps the version. Per Amendment-01 §A-1.7, the resolver therefore never sees an invalid override.

### 8.4 Uninstallation

`kernel.tenant.override_uninstall` reverses an installed override. Its effect:

1. **Reverse-realize** physical resources if applicable (e.g., DROP COLUMN in the tenant schema). Non-transactional; phased.
2. **Remove** from `OverrideStore`. Transactional.
3. **Bump override version.** Transactional.
4. **Audit.**

Uninstallation of an `AddField` that already has data: the field's data is destroyed. This is treated as a bulk mutation; the audit is `BulkSubject` with `affected_count` from the driver. Operators MUST confirm via an Action input flag.

### 8.5 DDL-phase atomicity

DDL-bearing override operations cannot share a transaction with the catalog write that bumps the override version. The two-phase pattern is:

- T1: validate + OverrideStore insert (transactional, audited).
- DDL: realize physical resources (non-transactional, audited at phase boundary).
- T2: bump override version (transactional, audited).

Resolvers MUST NOT observe the new override (T1 result) until T2 completes; this is guaranteed because the resolver keys its cache on `overrideVersion` (§9), not on the OverrideStore's presence. Until T2 bumps the version, every resolver continues to see the previous version.

This makes T2 the moment of overriding-set visibility, regardless of how long DDL takes.

### 8.6 Write path is exclusively the Invoker

Per §1.1.7 inherited: all override mutations go through the Invoker, against the `system` Tenant. There is no API to write to the OverrideStore directly. Plugins shipping admin tooling invoke the named Actions (`kernel.tenant.override_install`, `_uninstall`).

---

## 9. Override cache invalidation

### 9.1 Decision

Cache invalidation is **version-based**, not push-based or TTL-based. Each Tenant carries an integer `overrideVersion` in the catalog (§2.2). Caches key on `(TenantId, overrideVersion)`. A version bump invalidates the cache by construction — old keys are simply never queried again.

### 9.2 Resolver cache

The Kernel's per-request resolution layer caches the merged graph as `(TenantId, overrideVersion) → MergedView`. The merged view contains the base graph's lookups overlaid with that Tenant's overrides.

Cache lifetime: per-process (typically per PHP-FPM worker or per Octane process). Invalidation is automatic on version mismatch.

### 9.3 Cheap version probe

On every request that requires Tenant context, the Kernel reads the current `overrideVersion` for the Tenant. This MUST be a cheap, single-row read; the catalog implementation MUST optimize this path (e.g., cover-index on `(tenant_id, override_version)`).

The Kernel MAY further amortize the probe via a deployment-configured maximum probe staleness (e.g., 1 second). Within that window, the cached version is trusted without re-reading. The configured maximum staleness is bounded — over the bound, the kernel rejects to load — to ensure overrides become visible to all workers within the bound.

### 9.4 No runtime recompilation

The base graph is read-only at runtime (RFC-001 §4.3). The merged view is *not* a recompilation; it is a per-Tenant overlay computed at first access. Override installation does not invalidate the base graph cache; only the per-Tenant overlay cache. This preserves the no-runtime-recompilation hard constraint.

### 9.5 Out-of-process invalidation

A version bump committed in one process becomes visible to other processes through the catalog read (§9.3). There is no pub/sub, no broadcast, no message bus required for correctness. Push-based invalidation MAY be added by plugins for staleness-window minimization but is not part of the Kernel contract.

---

## 10. Cross-Tenant elevation

### 10.1 `Ausus::elevate`

```
Ausus::elevate(TenantId $target, string $reason, ?\Closure $scope = null): ElevatedContext
```

`elevate` opens an `ElevatedContext` bound to the target Tenant. Operations performed within the scope are executed against the target Tenant. Elevation is the **only** sanctioned cross-Tenant mechanism; per RFC-001 §8.1, ad-hoc cross-Tenant operations are forbidden.

### 10.2 Lifecycle

1. **Open.** The Kernel verifies the caller's Actor holds a Policy permitting elevation to `target`. Policy denial raises `ElevationDenied`. (The Policy is provided by the Authorization plugin; the Kernel does not ship a default.)
2. **Audit emission for elevation grant.** A dedicated Audit Entry of class `Standard` with Action `kernel.tenant.elevate` is emitted, carrying the canonical Subject reference to the target Tenant's catalog row, the source and target Tenants, and the reason. Primary audit failure aborts elevation; no `ElevatedContext` is returned.
3. **Execute.** Operations inside the `$scope` closure (or, for non-closure form, until the `ElevatedContext` is closed) run under `target` Tenant. Each operation is itself an Invoker call, generating its own Audit Entry. Each elevated entry MUST carry the `Elevation` slot (§10.5).
4. **Close.** Closing the `ElevatedContext` emits a closing Audit Entry (`kernel.tenant.elevate_close`). The closing entry is always emitted, even if the scope threw, so that the elevation window is unambiguously bounded.

### 10.3 No nested elevation

`elevate` inside an already-elevated context raises `ElevationAlreadyActive`. Nested elevation would produce an Actor→Tenant→Tenant chain that the audit shape cannot represent in V1; the constraint forces a flat audit trail.

### 10.4 Archived-Tenant access

Reading from an `ARCHIVED` Tenant is permitted via `elevate(archivedTenantId, reason)`; mutations are still subject to the state-admission rule (§5.4) and are rejected. This means `elevate` to `ARCHIVED` opens a read-only window.

### 10.5 Audit Entry `Elevation` slot

The Audit Entry shape of Amendment-01 §A-1.8 gains an optional `Elevation` slot for V1:

```
AuditEntry := (
  Actor,
  Tenant,                          // the target Tenant for elevated operations
  ActionFqn,
  Subject := SingleSubject | BulkSubject,
  Inputs,
  Outputs,
  Timestamp,
  CorrelationId,
  TraceId,
  InvocationClass := Standard | Maintenance,
  Elevation := null | { from: TenantId, reason: string, elevateCorrelationId: CorrelationId }
)
```

This is an additive extension to the V1 Audit Entry. Implementations and sinks that ignore the slot are still conforming for non-elevated traffic; sinks that target compliance use cases MUST persist it.

### 10.6 Elevation is not transfer

Elevation grants temporary cross-Tenant access; it never transfers an Entity instance to another Tenant. Per RFC-001 §2.1.2.1, instance Tenant binding is immutable.

---

## 11. Resolver behaviour per execution context

### 11.1 HTTP

The HTTP resolver runs in middleware ahead of any application code. The middleware:

1. Calls `TenantResolver::resolve(HttpResolutionInput)`.
2. If `null`, rejects the request with the `system`-bound endpoint allowlist (out of scope for this RFC) as the only exception.
3. If a `TenantId` is returned, performs state-admission (§5.4) and binds the active Tenant Context for the request lifetime.

Common HTTP resolver shapes (subdomain, header, JWT claim) are plugin implementations. The Kernel ships no default; a deployment MUST install at least one HTTP resolver if it serves HTTP.

### 11.2 CLI

A CLI invocation MUST declare its Tenant. The Kernel ships a resolver that accepts:

- `--tenant=<TenantId>` argument, or
- `AUSUS_TENANT` environment variable, or
- `--system` flag (which selects the `system` Tenant for the invocation; permitted only for Actions declared `system_invocable: true`; always audited).

CLI invocations without one of the above MUST be rejected at command-bootstrap, not at first Action invocation.

### 11.3 Queue (background jobs)

Tenant Context is captured at dispatch and restored at execution.

- **Dispatch.** The dispatching code is itself in a Tenant Context. The Kernel-provided dispatcher embeds the current `TenantId` into the job payload as a reserved field `__ausus_tenant`. Application payload MUST NOT use that reserved field name.
- **Execution.** The Kernel-provided queue worker reads `__ausus_tenant` from the payload, restores it as the active Tenant Context, performs state-admission, and only then invokes the job's handler.
- Jobs dispatched outside any Tenant Context are rejected at dispatch unless they are explicitly `system`-bound (e.g., a Kernel housekeeping job).

This makes queue traffic indistinguishable from synchronous traffic from the Kernel's perspective; the same Invoker, the same Tenant rules apply.

### 11.4 Scheduled jobs

A schedule entry is a static declaration: `(cron, action_fqn, tenant_binding)`. The `tenant_binding` MUST be either a `TenantId` literal or the special value `system`. Fan-out across Tenants is **not** an implicit feature; a deployment that wants "run for every active Tenant" implements it as a `system`-bound scheduled job that enumerates `TenantCatalog::list(ACTIVE)` and dispatches one Tenant-bound Action per Tenant via the queue (§11.3).

This explicit pattern keeps Tenant context unambiguous in every scheduled invocation and prevents the silent fan-out failure modes (one Tenant fails mid-loop; should the rest run? operator decides per loop).

### 11.5 Anti-pattern: implicit Tenant from the environment

Resolvers MUST NOT consult ambient process state (e.g., a thread-local set by some other code) to derive a Tenant. The resolution input shapes (HTTP request, CLI argv/env, queue payload, schedule entry) are exhaustive. Ambient-state resolvers are a documented anti-pattern.

---

## 12. `system` Tenant semantics

### 12.1 Existence

The `system` Tenant exists from kernel installation. Its `TenantId::value()` is the reserved string `__system__`. The catalog row for `system` is created by the Kernel's own bootstrap (not by `kernel.tenant.bootstrap`) and is immune to suspension, archival, and deletion.

### 12.2 Storage

Each isolation strategy reserves a strategy-appropriate `system` location:

- Row-level: `tenant_id = '__system__'`.
- Schema-per-tenant: a fixed schema named `system`.
- Database-per-tenant: a fixed connection conventionally named `system` (deployment-configurable).

`TenantIsolationStrategy::scopeFor(systemTenant)` returns the strategy-appropriate scope (§4.1).

### 12.3 Resolution

`TenantResolver::resolve()` MUST NOT return the `system` Tenant. The `system` Tenant is reachable only via:

- Elevation (§10), audited.
- The CLI `--system` flag (§11.2), audited.
- Kernel-internal Actions (catalog operations, override-store operations, janitor operations) that the Kernel itself invokes against `system`. Each of these is an audited Invoker call.

There is no other path. This forbids the "accidental system request" failure mode where a misconfigured resolver routes external traffic into `system`.

### 12.4 Cross-Tenant Relations in `system`

Per RFC-001 §2.1.2.3, cross-Tenant Relations are allowed only between two `system` Entities. The driver enforces (RFC-002 §9.4). Override `AddField` operations that would introduce a cross-Tenant Relation outside the `system`-`system` case are rejected by the override validator (§8.3) as `OverrideCrossTenantRelation`.

---

## 13. Strategy migration

### 13.1 Scope

V1 supports migration between any two strategies the bound driver advertises in `DriverCapabilities`. A migration is direction-agnostic at the contract level (row → schema, schema → database, database → row, etc.), although operational difficulty differs.

### 13.2 Operating mode

V1 uses **suspend-mode** migration. The Tenant is rejected for all operations during migration. Plugins requiring zero-downtime migration must build it on top of this primitive in a follow-up; the Kernel does not commit to a proxy/dual-write mechanism in V1.

### 13.3 Action

`kernel.tenant.migrate_strategy` is a `MaintenanceAction` whose effect is:

1. **Phase 1.** Transition `ACTIVE` → `MIGRATING`. Transactional, audited.
2. **Phase 2.** Snapshot the Tenant's data through the source strategy's scope. The catalog records the snapshot identifier.
3. **Phase 3.** Provision destination resources (DDL under DDL-bearing strategies). Non-transactional. Janitor reverses on hang.
4. **Phase 4.** Copy data. Streamed via `Repository::iterate` (RFC-002 §11.5) under the source scope, written under the destination scope. Per-batch audit entries with `BulkSubject` shape.
5. **Phase 5.** Validate. Row counts per Entity FQN MUST match; sample-based field equality MUST hold; integrity (uniqueness, FK closure within the Tenant) MUST hold. Mismatch raises `MigrationValidationFailed`.
6. **Phase 6.** Atomic cutover. `TenantCatalog::transition(MIGRATING → ACTIVE)` and isolation strategy name updated in the same transaction. Optimistic lock on the catalog row prevents concurrent cutovers.
7. **Phase 7.** Source data is **retained** in the source location, marked as superseded. A second `kernel.tenant.migrate_strategy_purge_source` operation, run after a deployment-configured retention period, performs the source-side delete. Until that purge runs, the migration is reversible.

### 13.4 Reversibility

Between phase 6 (cutover) and phase 7 (source purge), migration is reversible via `kernel.tenant.migrate_strategy_rollback`, which performs a cutover back to the source. This is the only V1 escape hatch when post-migration testing surfaces issues. After source purge, the migration is irreversible.

### 13.5 Override interaction

Overrides are independent of isolation strategy storage but their **physical realization** is strategy-bound (§4.2 `IsolationCapabilities`). Migration to a destination strategy whose `IsolationCapabilities` cannot realize an installed override MUST be rejected at phase 1 with `OverrideIncompatibleWithDestinationStrategy`. The operator can either uninstall the override before migration or pick a different destination strategy.

### 13.6 Audit shape during migration

Each phase emits at least one Audit Entry. Cross-strategy data copy emits per-batch `BulkSubject` entries; for a 100k-row Tenant migrated at 1k-batch size, expect 100 bulk audit entries plus phase-boundary single entries. The audit log is the migration's authoritative history.

---

## 14. Alternatives considered

### 14.1 Resolver returns the full `Tenant` (not just `TenantId`)

**Rejected.** Coupling the resolver to the catalog at resolution time would force every resolver implementation to consult the catalog, multiplying I/O and conflating "request → identifier" with "identifier → record." The chosen split (resolver returns `TenantId`; Kernel reads catalog) keeps resolvers simple and centralizes state admission.

### 14.2 Push-based override invalidation

**Rejected for the Kernel contract.** A push-based mechanism (Redis pub/sub, broadcast queue) requires deployment infrastructure the Kernel cannot assume. Version-based pull (§9) is correct without infrastructure and bounded by configured staleness. Plugins MAY add push to minimize staleness; the Kernel does not require it.

### 14.3 Allow override removal under operator confirmation

**Rejected.** Amendment-01 §A-1.3 forbids removal. The legitimate use case (a Tenant-specific Field that became sensitive) is served by adding a `visibility` Policy that always denies for that Tenant (Amendment-01 §A-1.2). Removal would defeat the Compiler's coherence validation for everything that referenced the removed element.

### 14.4 Single resolver across all contexts

**Rejected.** HTTP, CLI, queue, and scheduled inputs differ structurally. A single resolver would either accept a union type (lossy and hard to type) or coerce one shape into another (fragile). Per-context resolvers (§3, §11) make the input typed and the failure surface obvious.

### 14.5 Implicit fan-out scheduled jobs ("for every tenant")

**Rejected.** Documented in §11.4. Implicit fan-out hides per-Tenant failure handling and produces unauditable mass invocations. Explicit enumeration via a `system`-bound dispatcher job preserves audit clarity.

### 14.6 Online (zero-downtime) strategy migration in V1

**Rejected for V1.** Online migration requires a proxy that dual-writes during cutover, or change-data-capture, or eventual-consistency tolerance — all of which are large designs. V1 ships suspend-mode (§13.2) and defers online to a post-V1 RFC. Acknowledged operational cost.

### 14.7 Per-Tenant isolation strategy by configuration

**Rejected for V1.** Per-Tenant strategy diversity (some Tenants on row, others on schema) is supported only via migration (§13). A single Tenant on a single strategy at any time keeps `DriverCapabilities` resolution unambiguous and avoids per-request strategy dispatch overhead.

### 14.8 OverrideStore lives in each Tenant's scope

**Rejected.** Storing a Tenant's overrides inside its own data scope (row, schema, or db) couples override visibility to the tenant's storage and complicates strategy migration. Centralizing in `system`-scoped storage (§8.2) keeps overrides uniform across strategies and migratable independently of data.

### 14.9 `system` is reachable from a normal HTTP route

**Rejected.** §12.3 forbids any resolver from returning `system`. Reachability via elevation, CLI `--system`, and Kernel internals is exhaustive. The deployment may declare specific HTTP routes as "system-bound" (e.g., a deployment-status endpoint) outside the resolver contract, but those routes are not Tenant-resolved; they execute under `system` by construction with explicit, audited entry.

---

## 15. Trade-offs

1. **Per-context resolvers** (§11) increase configuration surface. A small deployment must register at least one HTTP resolver and possibly a CLI resolver. Accepted; the surface is bounded and the failure modes are clearer than implicit resolution.
2. **Suspend-mode migration** (§13.2) means downtime. Mitigation: explicitly framed as the V1 baseline with deferred online RFC; operators can plan windows.
3. **Phased lifecycle for DDL-bearing operations** (§6.2, §8.5, §13.3) splits a logically atomic operation into multiple Invoker calls and depends on the janitor (§6.4) for cleanup. Accepted; DDL is non-transactional in every relational store the Kernel targets, and the alternative (pretending it is transactional) hides failure modes.
4. **Version-based cache invalidation** (§9) is correct under partition but has bounded staleness. Acknowledged; deployments needing zero staleness add push-based invalidation as a plugin.
5. **Explicit fan-out scheduled jobs** (§11.4) requires more code per recurring per-Tenant task. Accepted; the failure handling is clearer.
6. **Audit Entry gains an `Elevation` slot** (§10.5). Modest schema additive change; RFC-007 must accept.
7. **`MIGRATING` rejects all traffic** (§5.4). Coarse but unambiguous.
8. **Override realization is strategy-bound** (§4.2, §13.5). Operators discover at migration time that some destination strategies cannot host their installed overrides; the rejection at §13.5 phase 1 surfaces this loudly rather than failing mid-migration.

---

## 16. Open questions

1. **RFC-007 — Audit sink contract.** Must accept the `Elevation` slot (§10.5).
2. **RFC-009 — Telemetry and observability.** Per-Tenant traces, the relationship between `CorrelationId` and `Elevation.elevateCorrelationId`.
3. **Post-V1 — Online strategy migration.** Dual-write, CDC, or eventual-consistency variants.
4. **Post-V1 — Per-Tenant resource quotas.** Out of scope; needs its own contract.
5. **Post-V1 — Sandboxed elevation.** Read-only elevation as a first-class category, or elevation with input-only Policies, for compliance-driven access patterns.
6. **Override conflict resolution under concurrent install.** §8.3 forbids duplicates; concurrent attempts at duplicate installs collide via optimistic locking on the OverrideStore's catalog row. The error surface (`OverrideConflict` vs `ConcurrencyConflict`) needs disambiguation in implementation; spec is intentionally permissive.
7. **State-admission for `MIGRATING`** (§5.4) — V1 rejects all traffic; a future RFC may permit read-only proxied traffic.

---

## 17. Challenger review — attack matrix

Each contract is attacked against the seven categories the brief requires: **layer violations**, **tenancy bypass**, **override coherence bypass**, **audit bypass**, **cache incoherence**, **strategy migration data loss**, **SemVer traps**.

### 17.1 `TenantResolver` (§3)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Resolver imports Runtime / Compiler / API Surface to enrich its decision. | §3.2.2 of RFC-002 propagates: resolvers live at L7 plugins. They depend on L0 contracts only. `resolve()` is forbidden I/O beyond catalog lookup (§3.2.2 of RFC-003). |
| Tenancy bypass | Resolver returns `system` for an external request. | §3.2.5 / §12.3: forbidden. The catalog admission step verifies the returned id is not `system`. |
| Override coherence bypass | Resolver consults overrides directly and short-circuits. | Resolvers MUST NOT consult overrides (§3.2.2: resolution is a read of identity, not of merged graph). |
| Audit bypass | Resolver mutates and skips audit. | §3.2.3: resolvers MUST NOT mutate. |
| Cache incoherence | Resolver caches `TenantId` for a request lifetime that survives a Tenant deletion. | Per-request lifetime only; the Kernel re-resolves on each request. The catalog admission (§5.4) catches deleted Tenants. |
| Strategy migration data loss | n/a. | — |
| SemVer trap | New `ResolverContext` value added in V1.x. | Closed enum (§3.1). New values require new RFC and major bump. |

### 17.2 `TenantIsolationStrategy` (§4)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Strategy reaches into the driver's connection pool to bypass scoping. | Strategy is L7 plugin; consumes L0 contracts. Strategy returns `IsolationScope` (an L0 value), nothing more. |
| Tenancy bypass | `scopeFor(tenantA)` returns a scope addressing `tenantB`'s data. | The driver enforces every read carries the correct `tenant_id` (RFC-002 §5.3.1, §13.1); a misbehaving strategy is caught at first cross-Tenant row return. |
| Override coherence bypass | Strategy claims `supportsFieldAdditions()` it cannot actually realize. | Misadvertisement is a conformance failure caught at first installation attempt that fails to realize physically. Plugins MUST advertise truthfully (§4.3.4). |
| Audit bypass | n/a; strategy is consulted, it does not invoke audited operations. | — |
| Cache incoherence | Strategy varies `capabilities()` per call. | §4.3.4: invariant per process. |
| Strategy migration data loss | Strategy fails mid-migration. | §13.3 phases + janitor (§6.4) detect and reverse before source purge (§13.4). |
| SemVer trap | New `IsolationScope` variant added in V1.x. | Sealed sum type; new variants require new RFC and major bump. |

### 17.3 `TenantCatalog` (§5)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Plugin writes directly to `kernel.tenant` Entity. | §5.3.2: catalog mutations only via named Kernel Actions. Plugin attempts to call Repository on `kernel.tenant` are rejected by the Repository's enforcement that mutations to Kernel-owned Entities go through Kernel-registered Actions (RFC-001 §2.4 — Actions are the only mutation path; this Entity registers only Kernel Actions). |
| Tenancy bypass | Catalog itself lives in `system`; a non-`system` Invoker call could in principle reach it. | Kernel-registered Actions for catalog mutation are declared `system_invocable: true` (§11.2 model). Non-`system` callers see `kernel.tenant` only via system-bound elevation (§10). |
| Override coherence bypass | `transition(id, ARCHIVED)` while overrides are mid-install. | The override install Action and the catalog transition both run through the Invoker and acquire transactional locks on the catalog row via the Tenant's optimistic version. Conflict raises `ConcurrencyConflict`; one of the two aborts. |
| Audit bypass | `bumpOverrideVersion` runs outside an Invoker call. | §5.3.4: only called from within `kernel.tenant.override_install` / `_uninstall` Action effects. Direct callers raise `UnauthorizedTransactionControl` (RFC-002 §4.2.3). |
| Cache incoherence | Two workers see different `overrideVersion` between bump commit and cache eviction window. | Acknowledged. Bounded by the §9.3 maximum probe staleness. Operators set the bound per their compliance tolerance. |
| Strategy migration data loss | Catalog row corruption during cutover. | §13.3 phase 6 uses RFC-002 optimistic locking; concurrent cutover attempts fail one of them. |
| SemVer trap | Adding a new `TenantState` value in V1.x. | Closed enum (§2.3). New states require new RFC and major bump. |

### 17.4 Bootstrap (§6)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Bootstrap plugin issues DDL outside the strategy's reach. | DDL is the strategy's responsibility (§6.2 phase 2). Bootstrap Action delegates; it does not issue DDL directly. |
| Tenancy bypass | Bootstrap runs in a Tenant other than `system`. | Bootstrap is `system_invocable: true`; the Invoker rejects calls under any other Tenant. |
| Override coherence bypass | Bootstrap installs overrides as part of provisioning. | Bootstrap does not install overrides. New Tenants start with the base graph; overrides install via §8 only after `ACTIVE`. |
| Audit bypass | Phase 2 DDL succeeds, phase 3 audit fails; catalog row is `PROVISIONING` with realized resources. | §6.4 janitor reverses, emitting compensating audit entries. The compensating audit *is* the audit trail; A-1.6 is satisfied at the phase boundary. |
| Cache incoherence | n/a; new Tenant not yet in any cache. | — |
| Strategy migration data loss | n/a; no data exists. | — |
| SemVer trap | Adding a phase 0 or phase 4 to bootstrap. | New phases additive; semantics preserved if old phases retain their numbers. Documented as additive in the public surface. |

### 17.5 Archival, deletion (§7)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Plugin archives by directly setting a column. | §5.3.2: catalog writes through named Actions only. |
| Tenancy bypass | Archive of `system`. | §2.4.4: `system` cannot transition. |
| Override coherence bypass | Archive while override install in progress. | Same lock contention as §17.3. One aborts. |
| Audit bypass | Phase 2 of deletion (physical purge) is non-transactional; partial purge leaves rows without audit. | Phase 2 emits `BulkSubject` audit at the phase boundary; partial-purge failure leaves a janitor task to complete. The audit captures the count at success. |
| Cache incoherence | A worker holds a cached `(tenantId, overrideVersion)` for a Tenant whose catalog row is `DELETED`. | Probe (§9.3) returns `null` from catalog → cache key invalid → eviction. Bounded by probe staleness. |
| Strategy migration data loss | Deletion mid-migration. | State machine forbids `MIGRATING → DELETED` (Appendix B). |
| SemVer trap | Retention period type changes (int → duration). | Frozen as a kernel config value; type changes require migration. |

### 17.6 Override storage and installation (§8)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Plugin imports `OverrideStore` and writes directly. | §8.6: write path is exclusively the Invoker via named Actions. `OverrideStore` is a Kernel-internal contract; plugins call Actions, not the store. |
| Tenancy bypass | Override targets `tenantB` from a session in `tenantA`. | `kernel.tenant.override_install` runs under `system`; the override's `tenantId` is an input. The Policy on the install Action governs who may target which Tenants. Cross-Tenant misuse is a Policy concern, not a Kernel concern; defaults are conservative. |
| Override coherence bypass | Concurrent installs both validate, then both persist, producing an incoherent merged view. | Optimistic lock on the OverrideStore catalog row for the target Tenant. Concurrent installs conflict; one aborts. |
| Audit bypass | DDL phase succeeds; T2 (version bump) audit fails; the override is realized but invisible. | Resolvers do not observe the override until T2 (§8.5). Realized DDL with un-bumped version is benign: the new column / schema exists but the merged graph does not reference it. Janitor (§6.4) reverses DDL on prolonged staleness. |
| Cache incoherence | Worker observes T2 commit then immediately reads stale `OverrideStore` due to read replica lag. | Probe reads `overrideVersion` from the catalog (single source); store reads happen only on cache miss. If store read returns stale data, the merged view is incomplete; the next probe corrects within staleness window. Loud failure mode: an override referenced in the version but absent from the store raises `OverrideStoreInconsistent` and refuses to serve. |
| Strategy migration data loss | n/a; overrides are independent of strategy data. | — |
| SemVer trap | Adding a fifth `OverrideOperation` variant. | Sealed sum type; new variants require Amendment-02 to Amendment-01 §A-1.3 and a major bump. |

### 17.7 Override cache invalidation (§9)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | A plugin reaches into the resolver's merged-view cache to invalidate manually. | The cache is Kernel-internal. Plugins cannot import it; invalidation is automatic via version bump. |
| Tenancy bypass | Cache leaks merged view across Tenants. | Cache key includes `TenantId`; cross-Tenant lookup misses by construction. |
| Override coherence bypass | A bumped version is observed but the new OverrideStore content is not visible (replication lag). | Loud failure: `OverrideStoreInconsistent` (§17.6) refuses to serve. |
| Audit bypass | n/a. | — |
| Cache incoherence | The whole point of this section. | §9.3 staleness bound, §9.5 optional push augmentation. |
| Strategy migration data loss | n/a; cache invalidation does not move data. | — |
| SemVer trap | Switching from version-based to push-based invalidation in V1.x. | Version-based is the Kernel contract; push-based is plugin-additive. No surface change. |

### 17.8 Cross-Tenant elevation (§10)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | A plugin opens elevation through a Laravel facade alias bypassing the Kernel. | `Ausus::elevate` is the only published path; the implementation is sealed in `Ausus\Kernel\Runtime`. Static analysis on plugins forbids `Illuminate\*` paths to elevation logic (no such paths exist by construction). |
| Tenancy bypass | Caller elevates without the required Policy. | §10.2 step 1: Policy denial raises `ElevationDenied`. No path opens an `ElevatedContext` without Policy success. |
| Override coherence bypass | Elevated operation observes overrides from the home Tenant. | The `ElevatedContext` binds the active Tenant to the target. Resolver / merged-view lookups use the target Tenant. Home-Tenant overrides are not consulted. |
| Audit bypass | Elevation grant emitted, scope thrown, close audit lost. | §10.2 step 4: closing audit is emitted unconditionally. |
| Cache incoherence | Two consecutive elevations cache a merged view that outlives state changes on the target. | Cache key includes the target Tenant's `overrideVersion`; probe applies normally during the elevation. |
| Strategy migration data loss | Elevation to a `MIGRATING` Tenant. | §5.4: `MIGRATING` rejects all traffic, including elevated traffic. Elevation grant is recorded but operations are denied. |
| SemVer trap | Permitting nested elevation in V1.x. | §10.3 explicitly forbids; relaxation requires audit-shape extension and major bump. |

### 17.9 Resolver per context (§11)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | A queue worker imports the HTTP resolver. | Per-context registration is enforced at boot; cross-context use is structurally impossible (different `ResolutionInput` shapes). |
| Tenancy bypass | A job is dispatched without `__ausus_tenant`. | §11.3: dispatcher rejects. |
| Override coherence bypass | Scheduled fan-out across Tenants caches the merged view between iterations. | Fan-out is explicit per §11.4; each dispatched job opens its own Tenant Context with its own cache key. No accidental sharing. |
| Audit bypass | A CLI `--system` invocation forgets to audit. | The `--system` resolver feeds a `system`-bound invocation through the standard Invoker; audit emission is automatic. |
| Cache incoherence | Long-running Octane worker holds stale merged view between HTTP requests. | Probe per request (§9.3); staleness bounded. |
| Strategy migration data loss | A queued job dispatched before `MIGRATING` runs during `MIGRATING`. | §5.4: rejected with `TenantMigrating`. The job is dead-lettered or retried; this is a queue-runtime concern, not a Kernel one. |
| SemVer trap | A new context (e.g., gRPC server) added in V1.x. | Adding a new `ResolverContext` value is a major bump (§17.1). |

### 17.10 `system` Tenant (§12)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Plugin bypasses elevation by constructing a `Tenant` for `system` and binding it. | The Kernel's Tenant Context binding rejects any `system` binding originating outside elevation, CLI `--system`, or Kernel internals. |
| Tenancy bypass | A misconfigured HTTP resolver returns `system`. | §3.2.5: the catalog admission step rejects `system` at the resolver boundary. |
| Override coherence bypass | An override is installed on `system`. | The validator (§8.3) rejects override installation against `system` with `OverrideTargetSystemForbidden`. (Base-graph evolution on Kernel-owned Entities is RFC territory, not override territory.) |
| Audit bypass | `system` operations skip audit. | `system` operations are standard Invoker calls; audit emission is unconditional. |
| Cache incoherence | `system` has overrides cached. | N/A: `system` has no overrides. |
| Strategy migration data loss | Migration of `system`. | §13 does not permit migrating `system`; its strategy is deployment-wide and bound at install. |
| SemVer trap | Changing the reserved `system` `TenantId` value. | Frozen as `__system__`. Change requires new RFC and major bump. |

### 17.11 Strategy migration (§13)

| Attack | Vector | Defence |
|---|---|---|
| Layer violation | Plugin runs its own copy without using the migration Action. | The catalog rejects strategy changes outside `kernel.tenant.migrate_strategy`'s effect; transitions through `MIGRATING` are gated by the state machine. |
| Tenancy bypass | Source data accidentally read into a different target Tenant. | Driver enforcement (RFC-002 §5.3.1) catches at first cross-Tenant row. Migration is one Tenant at a time. |
| Override coherence bypass | Migration succeeds; destination strategy cannot realize an installed override. | §13.5: rejection at phase 1. |
| Audit bypass | Mid-migration crash leaves untracked partial copy. | Phased audit (§13.6) and janitor cleanup; source data retained until source purge (§13.4), so reversal is always possible until phase 7. |
| Cache incoherence | Workers see destination strategy for some tenants, source for others during cutover. | Cutover is atomic on the catalog row (§13.3 phase 6); workers see the new strategy on the next probe. Bounded staleness applies. |
| Strategy migration data loss | Validation (phase 5) returns success on an incomplete copy. | Validation MUST check row counts per Entity FQN and sample field equality; insufficient validation is a conformance failure. Reversibility window (§13.4) provides additional safety. |
| SemVer trap | Adding a new migration phase. | Phases are public; additions are minor if order and semantics of existing phases are preserved. Removals are major. |

---

## 18. Acceptance criteria

This RFC is accepted when:

1. The four role signatories (architect, domain, kernel, challenger) sign off on §2, §3, §4, §5, §8, §10, §13.
2. RFC-002 confirms `DriverCapabilities::supportedTenancyStrategies()` returns exactly the set `{'row', 'schema', 'database'}` (already accepted).
3. RFC-007 (Audit sink contract) commits to the `Elevation` slot addition (§10.5).
4. The Appendix-D-style plugin walkthrough is rerun against this RFC for the `acme` Tenant lifecycle (bootstrap → override install → elevation → archive → strategy migration → delete); no new contradictions surface.
5. The conformance test suite for `TenantIsolationStrategy` is scoped (not built) before V1: at minimum, one test per "MUST" clause in §4, §6, §7, §8, §13.
6. Appendices C and D below are re-run before each subsequent draft.

Once accepted, this RFC is the source of truth for the V1 tenancy model.

---

## Appendix A — V1 public surface enumeration

```
Ausus\Kernel\Contracts\Tenancy\
  TenantId                          (final value object)
  Tenant                            (final value object)
  TenantState                       (closed enum)
  ResolverContext                   (closed enum)
  ResolutionInput                   (sealed sum type)
  IsolationScope                    (sealed sum type)
  IsolationCapabilities             (final value object)
  OverrideDescriptor                (final value object)
  OverrideOperation                 (sealed sum type; exactly four variants)
  OverrideRef                       (final value object)
  ElevatedContext                   (sealed; constructed only by Ausus::elevate)

  TenantResolver                    (interface; one impl per context per deployment)
  TenantIsolationStrategy           (interface; one impl per deployment)
  TenantCatalog                     (interface; one Kernel-provided impl, swappable)
  OverrideStore                     (interface; one Kernel-provided impl, swappable)

Ausus\Kernel\Contracts\Tenancy\Errors\
  TenancyError                      (abstract base)
  TenantNotFound, TenantSuspended, TenantNotReady, TenantMigrating,
  ElevationDenied, ElevationAlreadyActive,
  OverrideUnsupportedByStrategy, OverrideConflict,
  OverrideCrossTenantRelation, OverrideTargetSystemForbidden,
  OverrideStoreInconsistent,
  OverrideIncompatibleWithDestinationStrategy,
  MigrationValidationFailed

Ausus\Kernel\Actions\Tenancy\        (Kernel-registered Action FQNs)
  kernel.tenant.bootstrap
  kernel.tenant.bootstrap_rollback
  kernel.tenant.bootstrap_janitor
  kernel.tenant.suspend
  kernel.tenant.resume
  kernel.tenant.archive
  kernel.tenant.unarchive
  kernel.tenant.delete
  kernel.tenant.override_install
  kernel.tenant.override_uninstall
  kernel.tenant.elevate
  kernel.tenant.elevate_close
  kernel.tenant.migrate_strategy
  kernel.tenant.migrate_strategy_rollback
  kernel.tenant.migrate_strategy_purge_source
```

Anything not enumerated is not part of the V1 surface and may change without notice.

---

## Appendix B — Tenant state machine

```
                                 +----------------+
                                 |  PROVISIONING  |
                                 +-------+--------+
                                         |
                          bootstrap success
                                         |
                                         v
   suspend                +---------------------+        archive
  <-------------          |        ACTIVE        | -------------->
   resume                 +-+--------+----------+                 \
                            ^        |                            v
                            |        | migrate_strategy           +---------+
                       unarchive     v                            | ARCHIVED|
                            |   +-----------+   migration         +----+----+
                            |   | MIGRATING |   rollback               |
                            |   +-----+-----+                      delete
                            |         | migration success              |
                            |         v                                v
                            |     +-------+                        +---------+
                            |     | ACTIVE|                        | DELETED |
                            |     +-------+                        +---------+
                            |
                       +----+----+
                       |SUSPENDED|
                       +---------+

Transitions not shown are forbidden and rejected by TenantCatalog::transition.

Specifically forbidden:
- PROVISIONING → SUSPENDED / ARCHIVED / DELETED
- ACTIVE       → PROVISIONING / DELETED
- SUSPENDED    → PROVISIONING / MIGRATING / DELETED
- MIGRATING    → SUSPENDED / ARCHIVED / DELETED
- ARCHIVED     → SUSPENDED / MIGRATING / PROVISIONING
- DELETED      → anything (terminal)
- system Tenant: no transitions ever
```

---

## Appendix C — Contradiction scan

**Methodology.** Walk every pair of sections in RFC-003 and verify no two statements contradict. Cross-check against RFC-001 Draft-03, Amendment-01, and RFC-002 Draft.

| ID    | Description | Status |
|-------|-------------|--------|
| C3-01 | §1.1.6 ("base graph is read-only") vs §4.4 (merged view per Tenant). §9.4 distinguishes overlay from recompilation. | Consistent. |
| C3-02 | §A-1.3 forbids removal; §8.4 permits override `uninstall` which removes an installed override. | Consistent. Uninstall removes a previously-added (additive) override, not a base-graph element. The base graph is untouched. |
| C3-03 | §A-1.6 requires audit-rollback for primary sink failure; §6.2 phase 2 is non-transactional DDL. | Consistent. Phases isolate non-transactional steps; each phase honours A-1.6 within its boundary; janitor (§6.4) handles cross-phase failure. |
| C3-04 | §10.5 adds `Elevation` to the Audit Entry shape vs Amendment-01 §A-1.8 fixed shape. | Compatible additive extension. Sinks ignoring it remain conforming for non-elevated traffic. Listed in §16.1 as an acceptance dependency on RFC-007. |
| C3-05 | §11.4 (no implicit fan-out) vs §13.3 phase 4 (per-batch entries during migration). | Consistent. Fan-out across Tenants is explicit; per-batch within one Tenant's migration is internal to one Invoker call. |
| C3-06 | §12.3 ("resolver MUST NOT return `system`") vs §11.2 (CLI `--system`). | Consistent. CLI `--system` is not a `TenantResolver`; it is a Kernel-provided privileged entry, audited, with its own resolver implementation that is excluded from the resolver-cannot-return-system rule by being a kernel-internal resolver. Resolvers in the §3 sense are resolvers for external traffic. |
| C3-07 | §13.4 reversibility vs §A-1.3 immutable Tenant binding. | Consistent. Reversibility reverts the strategy, not Entity Tenant bindings. Entities remain bound to the same `TenantId`. |
| C3-08 | RFC-002 §7.5 (single-Tenant transactions) vs §13.3 phase 4 (data copy across scopes). | Consistent. Phase 4 reads under source scope, writes under destination scope, but reads and writes are separate Repository calls in separate transactions. No multi-Tenant transaction is opened. |
| C3-09 | §2.4.5 (every transition is an Invoker call) vs §6.2 phase 2 (non-transactional DDL). | Consistent. Phase 2 is itself a MaintenanceAction-bearing Invoker call. The DDL is the Action's effect; non-transactional means the data-driver's transaction does not cover the DDL, not that the Invoker is bypassed. |
| C3-10 | §8.6 (write path is the Invoker) vs §5.3.4 (`bumpOverrideVersion` is the only way to advance). | Consistent. `bumpOverrideVersion` is invoked from within the override-install Action effect, which is itself an Invoker call. |

**Result.** No contradictions. The phased-DDL pattern is consistent across §6, §7, §8, and §13.

---

## Appendix D — Layer boundary scan

**Methodology.** Verify every new contract sits at the correct layer and that no contract permits a sideways bypass of an intermediate layer's invariants.

| Component | Layer | Inbound dependencies | Outbound dependencies | Result |
|---|---|---|---|---|
| `TenantId`, `Tenant`, `TenantState` | L0 | — | — | OK |
| `TenantResolver` | L7 (plugin) | invoked by Kernel middleware (L2) | L0 (catalog read) | OK |
| `TenantIsolationStrategy` | L7 (plugin) | invoked by `PersistenceDriver` (L3) | L0 contracts | OK |
| `IsolationScope`, `IsolationCapabilities` | L0 | — | — | OK |
| `TenantCatalog` | L2 (Runtime); implementation realized via L3 driver | invoked by Kernel middleware, by Kernel Actions | L3 (driver), L0 contracts | OK |
| `OverrideStore` | L2 (Runtime); realized via L3 driver | invoked by override Actions | L3 driver | OK |
| Override Actions (`kernel.tenant.*`) | L0 declarations; effects in L2 | invoked by Invoker | L2 catalog, L2 store, L3 driver | OK |
| Per-context resolvers (HTTP middleware, CLI bootstrap, queue worker, scheduler) | L2 entry points + L7 resolver plugins | external traffic | L7 resolvers, L0 catalog | OK |
| `ElevatedContext` | L2 | constructed by `Ausus::elevate` | L0 contracts | OK |

**Findings.**

| ID | Description | Resolution |
|---|---|---|
| L3-01 | `TenantCatalog` and `OverrideStore` are realized via L3 driver but logically belong to L2 Runtime. Does this break §3.2.5 (no Eloquent in plugins)? | No. The Kernel's own service provider registers Kernel-owned Entities for the catalog and store. These are not plugins; they are part of the Kernel package and use the Repository contract like any other Action effect. |
| L3-02 | The strategy is consulted by L3 driver but is registered as an L7 plugin. Does this make the driver depend on a plugin? | No. The driver depends on the L0 `TenantIsolationStrategy` contract; the plugin provides the implementation bound at runtime. Direction remains L3 → L0. |
| L3-03 | Per-context resolvers run at entry points (HTTP middleware, CLI, queue, scheduler). Are these entry points L4? | The HTTP entry point is L4. CLI / queue / scheduler entry points are deployment-tier and not part of the L0–L6 stack; they are kernel-coordinated activation paths into L2. The contract (`TenantResolver` invocation, state admission, Tenant Context binding) is uniform across them. |
| L3-04 | The new Audit Entry `Elevation` slot is consumed by L0 audit contract and produced by L2 elevation logic. | Direction is L2 → L0. OK. |
| L3-05 | Override realization (DDL) bridges L2 (override Action effect) and the strategy (L7 plugin) to issue DDL. | The Action effect calls the strategy via L0 contract; the strategy issues DDL through its own driver-internal path. No new sideways path is opened. |

**Result.** All five findings resolve cleanly. No new sideways bypass is introduced. The phased-DDL pattern is the only place where a "logical" operation crosses transaction boundaries; the layering is preserved across the phases.
