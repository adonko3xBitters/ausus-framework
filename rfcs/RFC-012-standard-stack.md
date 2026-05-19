# RFC-012 — Standard Stack

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Draft                                                  |
| Authors       | architect, kernel, DX, challenger                      |
| Date          | 2026-05-18                                             |
| Depends on    | RFC-001 Draft-04, RFC-002, RFC-003, RFC-004, RFC-005, RFC-007 Draft-02, RFC-010 |
| Validates     | RFC-000 (the V0 vertical slice — this RFC unblocks F-V0-05, F-V0-14) |
| Mission       | Define the official batteries-included AUSUS distribution. |
| Hard constraint | No new kernel primitives. Only packaging, defaults, presets, scaffolding. |
| Hard target   | Time-to-first-screen ≤ 30 minutes from `composer create-project`. |

---

## 0. Problem statement

RFC-000 demonstrated that the V0 slice cannot ship without seven reference packages (F-V0-05, F-V0-14). Appendix UX-1 confirmed that the resulting time-to-first-screen is ~8 hours — 16× Filament — and the concept count before first render is ~100 (Adoption-blocker band). The kernel surface is sound; the integration and reference-implementation surfaces are absent.

This RFC defines the **Standard Stack**: the official batteries-included distribution of L3+ implementations and scaffolding that turns the accepted kernel into a runnable platform. It introduces no new kernel primitives. Every component is an implementation of a contract already accepted in RFC-002, RFC-003, RFC-004, RFC-005, RFC-007, RFC-010, or RFC-001 itself. The contributions are packaging, defaults, presets, and project-level scaffolding.

The single quantitative target is time-to-first-screen ≤ 30 minutes for a developer with prior Laravel + React familiarity, measured from `composer create-project` to a browser rendering a custom Projection of a custom Entity authored by that developer. UX-1's other Dangerous-band findings (boilerplate, imports, FQN density, reservation surface) are bounded by the kernel and not addressable here; they are documented but not in scope for this RFC.

Two RFC-001 anti-patterns frame what this RFC may and may not do:

- **RFC-001 §9.5 — no `make:entity` scaffolding.** This RFC respects the rule. No command generates domain metadata. **Project-level** and **plugin-level** templates are permitted (they create file skeletons, not domain definitions); **Entity-level** scaffolding is not.
- **RFC-001 §1.2 — no privileged bypass APIs.** This RFC adds no escape hatches; every component runs through the kernel contracts unchanged.

---

## 1. Scope

### 1.1 In scope

- Eight component packages, each implementing one accepted RFC contract.
- A meta-package that pins compatible versions.
- A starter project (`composer create-project ausus/starter`).
- A plugin template (`composer create-project ausus/plugin-template`).
- Default values for every `config/ausus.php` key so the file is **optional** in fresh installs.
- One bootstrap command (`ausus:up`) that compresses §7 of RFC-000's setup steps into one invocation.
- A doctor bundle that extends `ausus:doctor` (RFC-001 §5.5) with stack-specific health checks.
- An authentication bridge that supplies the `Actor` contract minimum (RFC-005 §1.3).
- Compatibility matrix and versioning policy across the eight component packages.

### 1.2 Out of scope

- New kernel primitives. Hard rule.
- Modifications to any accepted RFC. (Friction observations are logged in §17 as future-RFC concerns, not amendments here.)
- DSL surface specification (deferred to RFC-011). The Standard Stack ships a **provisional** DSL marked unstable; when RFC-011 lands, the Standard Stack adopts it via a minor bump.
- Workflow execution specification (deferred to RFC-006). The Standard Stack ships a simple Workflow runtime as part of `ausus/runtime-default`; when RFC-006 lands, the runtime is replaced.
- `ActionEffect` formal contract (currently RFC-000 §13 F-V0-02). The Standard Stack defines a de facto `ActionEffect` interface in `ausus/runtime-default`; formalization is a future RFC.
- Authorization plugin formal contract (currently RFC-005 §1.3 minimum). The Standard Stack ships an auth bridge that satisfies the minimum; formalization is a future RFC.
- Migration tooling beyond derived-from-graph (RFC-002 §17.1 — full migration RFC deferred).
- Production observability, telemetry (RFC-009 deferred).

These exclusions are intentional. The Standard Stack does not anticipate future RFCs; when they land, the affected component package adopts them via a minor or major bump per its own SemVer.

---

## 2. Package map

The Standard Stack is **ten Composer packages and one npm package**. Plugin authors typically install one Composer meta-package; the rest is transitive.

| Package                          | Implements                                       | Layer | Provides                                                                                 |
|----------------------------------|--------------------------------------------------|-------|------------------------------------------------------------------------------------------|
| `ausus/standard-stack`           | meta — requires all below                        | meta  | One-line install of the whole stack                                                       |
| `ausus/persistence-sql`          | RFC-002 PersistenceDriver                        | L3    | SQL PersistenceDriver (Postgres / MySQL via Eloquent under the hood; no Eloquent leakage per RFC-002 §13) |
| `ausus/tenancy-row`              | RFC-003 TenantIsolationStrategy                  | L3    | Row-level isolation + subdomain HTTP resolver + CLI / queue / scheduled resolvers          |
| `ausus/audit-database`           | RFC-007 TransactionalSink                        | L3    | Transactional primary sink writing to the same DB as `ausus/persistence-sql`              |
| `ausus/reporting-sql`            | RFC-010 ReportingDriver                          | L3    | SQL-based ReportingDriver with the closed grammar of RFC-010 §3                            |
| `ausus/runtime-default`          | RFC-001 Invoker + RFC-005 Policy Engine + Workflow runtime + ActionEffect dispatch | L2    | The reference runtime: Invoker chain steps 1–5, Policy Engine, Workflow runtime, Effect dispatch |
| `ausus/field-types-standard`     | RFC-001 §2.2 / RFC-002 §5.2 FieldType            | plugin| Eleven standard Field Types (§7 of this RFC)                                              |
| `ausus/renderer-react`           | RFC-004 §10 `react.web.v1` profile + L5 Presentation layer | L5+plugin | Backend profile registration + ViewSchema generator                                       |
| `@ausus/renderer-react` (npm)    | RFC-004 wire-format consumer                     | L6    | React components, hooks, widget set for `react.web.v1`                                    |
| `ausus/auth-bridge`              | RFC-005 §1.3 Actor minimum surface               | plugin| Laravel `Auth::user()` → AUSUS `Actor`; stub mode for dev, bridge mode for prod           |
| `ausus/doctor-bundle`            | Extends `ausus:doctor` (RFC-001 §5.5)            | CLI   | ~30 additional health checks specific to the Standard Stack                                |

Plus two project templates (not packages, but `composer create-project` targets):

| Template                         | Purpose                                                                                       |
|----------------------------------|-----------------------------------------------------------------------------------------------|
| `ausus/starter`                  | Pre-wired Laravel app with `ausus/standard-stack`, a demo plugin, a configured React frontend |
| `ausus/plugin-template`          | Empty plugin scaffold: composer.json, Plugin/ServiceProvider class skeleton, sample DSL with TODO markers, sample Policy + Effect skeletons, README |

The starter template ships zero domain metadata in user-editable files; the demo plugin lives under `app/Plugins/HelloInvoice/` and is a working example to delete or copy from.

---

## 3. `ausus/persistence-sql`

### 3.1 Contract conformance

Implements `PersistenceDriver` (RFC-002 §3), `PersistenceContext` (§4), `Repository` (§5), with the closed Filter grammar of RFC-002 §10.1 and the closed error taxonomy of RFC-002 §12.1. Uses Laravel's database connection but never returns Eloquent types across the contract boundary (RFC-002 §13 conformance).

### 3.2 Capability advertisement

```
DriverCapabilities {
  supportedTenancyStrategies() -> ['row']            // V1 ships row-only; schema/db deferred
  supportsSnapshotReads()      -> true               // Postgres MVCC; MySQL InnoDB MVCC
  maxNestedSavepoints()        -> 8                  // RFC-002 §7.4 minimum
  maxBulkTransactionSize()     -> 100000             // configurable: persistence_sql.max_bulk
  identityShape()              -> 'ulid'             // configurable: persistence_sql.identity
}
```

### 3.3 Identity generation

Default: ULID (Crockford base32, sortable). Configurable to UUID v7 or snowflake via `persistence_sql.identity` config. Per RFC-002 §6.1, the handle is opaque to the Kernel; the choice is the driver's.

### 3.4 Schema derivation

Plugin authors do not write migrations for their Entity tables. The driver derives the SQL schema from the compiled Metadata Graph at `ausus:migrate` time:

- Field types → SQL column types per a fixed mapping (§7 of this RFC).
- `tenant_id` column on every Tenant-scoped Entity (per `ausus/tenancy-row`).
- `_version` column (`text` / `varchar(36)`) for optimistic locking (RFC-002 §8).
- `created_at`, `updated_at` for `Field::timestamps()`.
- Indexes: composite `(tenant_id, id)` PK; per-Field indexes for fields marked `uniqueWithinTenant`; index on every Filter-able Field.

This is **derivation, not generation**. No `.php` migration file is produced; the driver inspects the graph and applies DDL directly. Plugin authors who need custom DDL (joins to legacy tables, custom indexes) write standard Laravel migrations alongside the derived schema.

### 3.5 Out of the box

- Postgres ≥ 14 and MySQL ≥ 8.0 supported.
- SQLite supported for dev / tests only; advertised via doctor warning.

### 3.6 What this package does NOT do

- Does not implement `ReportingDriver` — that is `ausus/reporting-sql`, a separate package.
- Does not implement Tenancy strategy — that is `ausus/tenancy-row`.
- Does not write audit — that is `ausus/audit-database`.
- Does not handle migrations beyond derived-from-graph.

---

## 4. `ausus/tenancy-row`

### 4.1 Contract conformance

Implements `TenantIsolationStrategy` (RFC-003 §4) returning `RowScope { tenant_id }`. Also implements `TenantResolver` (RFC-003 §3) for all four `ResolverContext` values: HTTP, CLI, QUEUE, SCHEDULED.

### 4.2 Resolvers

- **HTTP** (`AususTenancyRow\Resolvers\Http\SubdomainResolver`): extracts subdomain from `Host` header. `acme.app.example` → `TenantId("acme")`. Configurable suffix.
  - Composite resolver included: `SubdomainResolver` → `HeaderResolver(X-Tenant-ID)` → `JwtClaimResolver(tenant_id)`.
- **CLI** (`AususTenancyRow\Resolvers\Cli\FlagResolver`): `--tenant=<id>` or `AUSUS_TENANT` env var; `--system` for system-bound invocations (audited per RFC-003 §11.2).
- **QUEUE** (`AususTenancyRow\Resolvers\Queue\PayloadResolver`): reads `__ausus_tenant` per RFC-003 §11.3.
- **SCHEDULED** (`AususTenancyRow\Resolvers\Scheduled\StaticResolver`): reads from the schedule entry's `tenant_binding` per RFC-003 §11.4.

### 4.3 Capabilities

```
IsolationCapabilities {
  supportsFieldAdditions()              -> false    // row-level cannot per-Tenant ALTER shared table
  supportsProjectionAdditions()         -> true
  supportsPolicyAdditions()             -> true
  supportsWorkflowTransitionAdditions() -> true
  requiresDdl()                         -> false    // no DDL for additions in row-level
}
```

Per RFC-003 §4.2, the override validator rejects per-Tenant Field additions on this strategy. This is a known operational ceiling of row-level isolation, surfaced loudly at override-install time rather than silently failing later.

### 4.4 Catalog binding

Provides the `TenantCatalog` implementation backed by `ausus/persistence-sql`. The catalog Entities (`kernel.tenant`, `kernel.tenant_state_log`, `kernel.tenant_override`, `kernel.audit_pending`) live in the same database as user data, in the `system` Tenant.

---

## 5. `ausus/audit-database`

### 5.1 Contract conformance

Implements `TransactionalSink` (RFC-007 §4.2). Writes to `kernel_audit_log` table in the same database connection as `ausus/persistence-sql`. Per RFC-007 §5.3, this is the recommended default — no orphans architecturally possible.

### 5.2 Capabilities

```
SinkCapabilities {
  supportsDedupByEntryId() -> true            // UNIQUE constraint on entry_id
  maxSampleHandles()       -> 100             // matches RFC-007 §13.1 default
  maxInputsBytes()         -> 65536
  maxOutputsBytes()        -> 65536
  preservesInsertionOrder()-> true            // sequence per correlation_id
  preservesElevation()     -> true            // RFC-007 Amendment-01 §A-7.2; column for elevation JSON
}
```

### 5.3 Schema

`kernel_audit_log` columns: `entry_id` (PK, ULID), `sequence`, `actor_*`, `tenant_id`, `action_fqn`, `subject_kind`, `subject_*`, `inputs` (JSONB), `outputs` (JSONB), `timestamp`, `correlation_id`, `trace_id`, `invocation_class`, `elevation` (JSONB nullable), `emitter_version`. `outputs.bulk_entities` lives inside the `outputs` JSONB per RFC-007 Amendment-01 §A-7.1.

### 5.4 Retry queue and reconciliation

For a Transactional primary, the retry queue is used for secondary sinks only (and only if any are configured — the stack's default has none). The reconciler (RFC-007 §12) is a no-op because Transactional primaries cannot orphan.

### 5.5 Append-only enforcement

Database role for the application has `INSERT` only on `kernel_audit_log`; no `UPDATE` or `DELETE`. The migration script grants the appropriate role. Enforced at the storage layer, not just the contract.

### 5.6 Configuration

```
audit.primary_sink: ausus.audit.database     # default; no user action required
```

No further user configuration required for the default deployment shape.

---

## 6. `ausus/runtime-default`

### 6.1 What it bundles

- **Invoker implementation** (RFC-001 §8.2 + Amendment-01 §A-1.4 §8.2.1). The reference Invoker performing the five-step chain: Tenant check → Policy chain → Workflow guard → effect → audit emit.
- **Policy Engine implementation** (RFC-005 in full): chain assembly (§4.1), composition (§5), two-tier cache (§8), failure semantics (§9), side-effect detection (§10), bulk evaluation (§11).
- **Workflow runtime**: loads current Subject state, evaluates guard Policies, dispatches the Action's effect, applies post-effect state mutation, validates transition legality against the Workflow descriptor. This is the **simple Workflow runtime** of §1.2; it is replaced when RFC-006 lands.
- **ActionEffect dispatch**: defines the de facto `ActionEffect` interface (single method `handle(PersistenceContext, ?Reference, array, Context): array`) and the Invoker's mechanism for resolving the Action's `effect` class FQN to a container instance. This is the de facto contract of §1.2; it is replaced when the ActionEffect formal RFC lands.

### 6.2 De facto `ActionEffect` interface

```
namespace Ausus\Runtime\Effects;

interface ActionEffect
{
    public function handle(
        \Ausus\Kernel\Contracts\Persistence\PersistenceContext $ctx,
        ?\Ausus\Kernel\Contracts\Persistence\Reference $subject,
        array $inputs,
        \Ausus\Kernel\Contracts\Policy\Context $context
    ): array;
}
```

This interface is **not part of the kernel**. It lives in `ausus/runtime-default` and is consumed by plugin authors. When the formal ActionEffect RFC lands, this interface is the proposed shape; if the RFC adopts a different shape, `ausus/runtime-default` releases a major bump migrating plugins.

### 6.3 De facto Workflow runtime

When an Action invocation triggers a Workflow transition (the Action's FQN matches a `via` on some transition of an attached Workflow), the runtime:

1. Loads the Subject's current state from a `_workflow_<workflow_local_name>_state` column on the Entity table (derived by `ausus/persistence-sql` for every Workflow-attached Entity).
2. Verifies the transition `(current_state, action_fqn)` exists in the Workflow descriptor.
3. Evaluates the transition's guard Policy (if declared); rejects on Deny.
4. Dispatches the Action's effect.
5. Updates the Workflow state column to the transition's target state in the same transaction as the effect.

This is the simple runtime. Effects DO NOT manually mutate state; the runtime does. (RFC-000's worked example mutated state inside the effect as a shim for the missing Workflow runtime; the Standard Stack removes that shim.)

When RFC-006 lands, this section's "simple Workflow runtime" is replaced. Plugin authors writing for the Standard Stack today author effects that do not touch state columns; the runtime handles transitions.

### 6.4 Container side-effect spy

Per RFC-005 §10.3, the Policy Engine implementation installs container-level interceptors on `PersistenceContext`, `ReportingDriver`, and `Invoker` bindings. Any Policy class's evaluation that touches one of these raises `PolicyForbiddenSideEffect`. Best-effort under PHP's lack of sandboxing; acknowledged limitation per RFC-005 §16.3.

### 6.5 Configuration

```
policy_engine.default_timeout_ms:    100
policy_engine.cache.tier1_max_entries: 10000
policy_engine.cache.tier2_max_entries: 100000
```

All defaulted. No user action required.

---

## 7. `ausus/field-types-standard`

### 7.1 Bundled Field Types

Eleven Field Types, each implementing the FieldType plugin contract (RFC-001 §2.2):

| Field Type    | DSL builder              | SQL column type            | ViewSchema type       | Defaults                              |
|---------------|--------------------------|-----------------------------|------------------------|---------------------------------------|
| `string`      | `Field::string($name)`   | `varchar(maxLength)` / `text` | `string`               | `maxLength: 255`                      |
| `integer`     | `Field::integer($name)`  | `int` / `bigint`            | `integer`              | `bigint: false`                       |
| `decimal`     | `Field::decimal($name)`  | `numeric(p, s)`             | `decimal`              | `precision: 12`, `scale: 2`           |
| `boolean`     | `Field::boolean($name)`  | `boolean`                   | `boolean`              | —                                     |
| `date`        | `Field::date($name)`     | `date`                      | `date`                 | —                                     |
| `datetime`    | `Field::datetime($name)` | `timestamp with time zone`  | `datetime`             | —                                     |
| `time`        | `Field::time($name)`     | `time`                      | `time`                 | —                                     |
| `enum`        | `Field::enum($name, $values)` | `varchar(64)` + CHECK   | `enum` + options       | —                                     |
| `money`       | `Field::money($name)`    | `numeric(14, 2)` + `varchar(3)` for currency | `money` + currency | `currency: USD` (override per Field) |
| `json`        | `Field::json($name)`     | `jsonb` / `json`            | `json`                 | —                                     |
| `reference`   | `Field::reference($name, $targetEntityFqn)` | `varchar(36)` + FK | `reference`            | `mode: 'reference-only'`              |

Plus three system Field Types not directly authored by plugins but emitted by the DSL helpers:

| System Field Type | DSL builder            | SQL column type       | Purpose                                                |
|-------------------|------------------------|------------------------|--------------------------------------------------------|
| `identity`        | `Field::id()`          | `varchar(26)` (ULID)   | Primary identity handle                                |
| `timestamps`      | `Field::timestamps()`  | two `timestamp with tz`| `created_at`, `updated_at`                             |
| `version`         | `Field::version()`     | `varchar(36)`          | `_version` opaque version per RFC-002 §8               |

### 7.2 Currency enforcement

`money` fields with mixed currencies raise `IncompatibleAggregateCurrency` (RFC-010 §4.3) only at aggregate time. For Repository writes, mixed currencies within a single column are stored as-is; conversion is plugin-author responsibility.

### 7.3 Plugin-authored Field Types

The kernel permits plugin-authored Field Types. The Standard Stack covers the eleven types above; plugins needing geo, vector, or domain-specific types ship them in their own packages.

---

## 8. `ausus/renderer-react`

### 8.1 Two halves

- **Composer package `ausus/renderer-react`**: registers the `react.web.v1` renderer profile with the L5 Presentation layer (RFC-004 §10), provides the L5 ViewSchema generator, and exposes the HTTP endpoint that serves ViewSchemas.
- **npm package `@ausus/renderer-react`**: React components, hooks, theme tokens, widget implementations for the `react.web.v1` profile.

### 8.2 `react.web.v1` profile content

```
{
  "fqn": "react.web.v1",
  "acceptedSchemaVersions": ["1.0.0"],
  "widgets": [
    "text", "textarea", "number", "money",
    "date-picker", "datetime-picker", "time-picker",
    "select", "multi-select", "checkbox", "badge",
    "json-viewer", "reference-card", "icon"
  ],
  "actionHints": ["primary", "secondary", "danger", "navigation"],
  "operators": ["equals", "in", "comparison", "range", "null",
                "string-match", "relation-exists", "reference-equals"],
  "capabilities": {
    "embeddedRelations":  true,
    "inlineValidation":   true,
    "confirmationModal":  true,
    "iconography":        true,
    "paginationKinds":    ["cursor"]
  }
}
```

### 8.3 React surface

```
import { ViewSchemaConsumer, useAction, AususProvider } from '@ausus/renderer-react';

function App() {
  return (
    <AususProvider apiBaseUrl="/api" tenant="acme">
      <ViewSchemaConsumer projection="billing.invoice.summary" locale="en-US" />
    </AususProvider>
  );
}
```

`<ViewSchemaConsumer>` fetches the ViewSchema, renders fields per the `react.web.v1` profile, surfaces filters and actions, handles cursor pagination, dispatches actions through `useAction` which calls the L4 API surface.

### 8.4 Theming

Standard Stack ships one theme (`@ausus/renderer-react/themes/default`) using Tailwind CSS utility classes. Themes are CSS-only swaps; component structure is fixed by the profile.

### 8.5 Dev server integration

`composer create-project ausus/starter` includes `frontend/` with Vite + React + `@ausus/renderer-react` pre-installed. `php artisan ausus:dev` starts both the Laravel server and the Vite dev server in parallel.

---

## 9. `ausus/auth-bridge`

### 9.1 Two modes

- **Stub mode** (`AUSUS_AUTH_MODE=stub`, default in development): hardcoded users in `config/ausus-auth-stub.php`. CLI commands manage them:
  - `php artisan auth:stub:create <username> --tenant=<id> --roles=<csv>`
  - `php artisan auth:stub:list`
  - `php artisan auth:stub:delete <username>`
- **Bridge mode** (`AUSUS_AUTH_MODE=laravel`, default in production): wraps Laravel's `Auth::user()` to produce AUSUS `Actor`. Roles read from a configurable source (Spatie\Permission, model attribute, custom resolver).

### 9.2 `Actor` shape

Satisfies RFC-005 §1.3 minimum:

```
class StandardActor implements Actor
{
    public function ref(): ActorRef            { /* (type, id, homeTenant) */ }
    public function roleHash(): string         { /* deterministic SHA-256 of sorted roles + permissions */ }

    // beyond minimum, exposed for Policies that opt in:
    public function roles(): array             { /* string[] */ }
    public function permissions(): array       { /* string[] */ }
    public function attribute(string $key): mixed { /* opaque attribute by key */ }
}
```

The bridge defines `Actor::roles()`, `::permissions()`, `::attribute()` beyond RFC-005's minimum. Plugins coupling to these methods are coupling to the bridge, not to the kernel. Documented as the expected V1 coupling per RFC-005 §1.3.

### 9.3 Role-source configuration

```
auth.role_source: spatie | model | custom
auth.role_source.spatie.guard: web
auth.role_source.model.attribute: roles    # JSON column or relationship
auth.role_source.custom.resolver: AppNamespace\Acme\CustomRoleResolver
```

Default: `spatie` if `spatie/laravel-permission` is installed; otherwise `model` reading from a `roles` JSON column.

### 9.4 Session-Tenant binding

The auth bridge integrates with `ausus/tenancy-row`'s resolvers: if Laravel's authenticated User has a `tenant_id` column or a `currentTenant()` method, the HTTP resolver consults it as a fallback when subdomain / header / JWT all fail. Configurable.

### 9.5 What this is NOT

Not an Authorization plugin. The bridge produces an `Actor`; Policies decide authorization. Spatie\Permission's `Gate::allows()` style is not invoked by the kernel. Plugin-authored Policies that use `$actor->roles()` make the authorization decision themselves.

---

## 10. `ausus/doctor-bundle`

### 10.1 What it adds

`ausus:doctor` (RFC-001 §5.5) already aggregates kernel-level checks. The bundle adds ~30 stack-specific checks:

| Category               | Check                                                                          |
|------------------------|--------------------------------------------------------------------------------|
| Package versions       | All Standard Stack packages installed at compatible versions per Appendix D    |
| Database               | Database reachable, migrations up-to-date, audit table append-only role grant   |
| Tenancy                | At least one Tenant in `ACTIVE` state; `system` Tenant bootstrapped             |
| Resolvers              | HTTP / CLI / QUEUE / SCHEDULED resolvers all registered                         |
| Renderer profile       | `react.web.v1` registered; npm package version compatible                       |
| Authentication         | Auth bridge mode set; stub users exist in dev OR Laravel Auth configured        |
| ReportingDriver        | Reachable; advertised capabilities consistent with config                       |
| Audit                  | Primary sink reachable; `preservesElevation: true`                              |
| Policy engine          | Default timeout reasonable; cache sizes within memory budget                     |
| Reserved namespaces    | No plugin has registered Action, identity, or Field name in reserved namespaces |
| Demo                   | (dev only) Demo plugin loaded; demo tenant `demo` bootstrapped                   |

### 10.2 Severity output

Same severity bands as the kernel doctor: error (boot fails), warning (boot continues), notice (informational). Bundle-added checks contribute predominantly notices in production; warnings in development; errors only for misconfiguration that would prevent the stack from running.

### 10.3 Exit codes

`0` on no errors; `1` on any error; `2` on warnings only (configurable to `0`). CI pipelines can gate deploys on `0`.

---

## 11. Defaults

### 11.1 `config/ausus.php` is optional

In a fresh `composer create-project ausus/starter` install, `config/ausus.php` does NOT exist. Every kernel and stack config key has a sensible default supplied by the package's service provider. Publishing the config file is only required to **override** a default.

### 11.2 Complete defaults table

| Key                                          | Default value                          | Source RFC                          |
|----------------------------------------------|----------------------------------------|--------------------------------------|
| `kernel.version`                             | (read from package)                    | RFC-001 §5.4                         |
| `compiler.strategy`                          | `'eager'` in prod, `'lazy'` in dev     | RFC-001 §5.4                         |
| `compiler.cache.driver`                      | `'file'`                               | RFC-001 §5.4                         |
| `compiler.cache.path`                        | `storage_path('framework/ausus')`      | RFC-001 §5.4                         |
| `runtime.strict_tenant`                      | `true`                                 | RFC-001 §5.4                         |
| `tenancy.default_resolver.http`              | `'ausus.tenancy.http.composite'`       | RFC-003 §3                           |
| `tenancy.default_resolver.cli`               | `'ausus.tenancy.cli.flag'`             | RFC-003 §11.2                        |
| `tenancy.default_resolver.queue`             | `'ausus.tenancy.queue.payload'`        | RFC-003 §11.3                        |
| `tenancy.default_resolver.scheduled`         | `'ausus.tenancy.scheduled.static'`     | RFC-003 §11.4                        |
| `tenancy.default_isolation`                  | `'ausus.tenancy.row'`                  | RFC-003 §4                           |
| `plugins.autodiscovery`                      | `true`                                 | RFC-001 §6.2                         |
| `plugins.disabled`                           | `[]`                                   | RFC-001 §6.2                         |
| `audit.primary_sink`                         | `'ausus.audit.database'`               | RFC-007 §16.1                        |
| `audit.secondary_sinks`                      | `[]`                                   | RFC-007 §16.1                        |
| `audit.redact`                               | `['*.password','*.token','*.secret','*.api_key']` | RFC-007 §14.1            |
| `audit.primary_ack_timeout_ms`               | `5000`                                 | RFC-007 §6.3                         |
| `audit.retry_max_attempts`                   | `100`                                  | RFC-007 §11.2                        |
| `audit.retry_base_ms`                        | `1000`                                 | RFC-007 §11.3                        |
| `audit.retry_max_delay_ms`                   | `3600000`                              | RFC-007 §11.3                        |
| `audit.max_sample_handles`                   | `100`                                  | RFC-007 §13.1                        |
| `audit.reconcile_window`                     | `'1 hour'`                             | RFC-007 §12                          |
| `audit.reconcile_interval`                   | `'5 minutes'`                          | RFC-007 §12                          |
| `audit.retry_worker_interval`                | `'30 seconds'`                         | RFC-007 §11.2                        |
| `audit.retry_reservation_ttl`                | `'5 minutes'`                          | RFC-007 §11.5                        |
| `reporting.default_driver`                   | `'ausus.reporting.sql'`                | RFC-010 §17                          |
| `reporting.query_timeout_seconds`            | `60`                                   | RFC-010 §12                          |
| `reporting.max_page_size`                    | `1000`                                 | RFC-010 §12                          |
| `reporting.min_page_size`                    | `1`                                    | RFC-010 §12                          |
| `reporting.max_join_depth`                   | `4`                                    | RFC-010 §12                          |
| `reporting.max_group_by_fields`              | `8`                                    | RFC-010 §12                          |
| `maintenance.default_acknowledgement_required` | `true`                               | RFC-010 §12                          |
| `maintenance.bulk_transaction_size_warning`  | `50000`                                | RFC-010 §12                          |
| `policy_engine.default_timeout_ms`           | `100`                                  | RFC-005 §16                          |
| `policy_engine.cache.tier1_max_entries`      | `10000`                                | RFC-005 §16                          |
| `policy_engine.cache.tier2_max_entries`      | `100000`                               | RFC-005 §16                          |
| `persistence_sql.identity`                   | `'ulid'`                               | RFC-002 §6                           |
| `persistence_sql.max_bulk`                   | `100000`                               | RFC-002 §11                          |
| `auth.mode`                                  | `'stub'` in dev, `'laravel'` in prod   | This RFC §9                          |
| `auth.role_source`                           | `'spatie'` if installed, else `'model'` | This RFC §9                          |

**38 default keys.** Plugin authors override zero of these for the V0 slice.

### 11.3 Environment-based defaults

Where defaults differ between dev and prod (compiler.strategy, auth.mode), the package detects via Laravel's `APP_ENV`. No conditional config code in user files.

---

## 12. `ausus/starter` project template

### 12.1 Layout

```
myapp/
├── app/
│   └── Plugins/
│       └── HelloInvoice/
│           ├── HelloInvoicePlugin.php          # full DSL declaration
│           ├── Policies/
│           │   ├── CanCreateInvoice.php
│           │   ├── CanIssueInvoice.php
│           │   ├── CanCancelInvoice.php
│           │   └── CanReadInvoice.php
│           ├── Effects/
│           │   ├── CreateInvoice.php
│           │   ├── IssueInvoice.php           # no state mutation (runtime handles)
│           │   └── CancelInvoice.php
│           └── README.md                       # "delete or copy this to start your own"
├── config/
│   └── (no ausus.php — defaults are sufficient)
├── frontend/
│   ├── index.html
│   ├── package.json                            # @ausus/renderer-react preinstalled
│   ├── vite.config.ts
│   └── src/
│       ├── App.tsx                             # <ViewSchemaConsumer projection="billing.invoice.summary" />
│       └── main.tsx
├── composer.json                               # requires ausus/standard-stack
├── README.md                                   # 5-minute orientation
└── .env.example                                # AUSUS_AUTH_MODE=stub
```

### 12.2 Demo plugin: `HelloInvoice`

A complete working plugin that mirrors RFC-000 §2's worked example. Exists so the user can:

- See real DSL syntax (until RFC-011 lands, this IS the syntax).
- See real Policy and Effect implementations.
- Delete it and start their own, OR copy it as a template.

The README of `HelloInvoice/` says explicitly: "This is the demo plugin. Delete this directory once you have authored your own plugin. The demo data this plugin produces is namespaced under tenant `demo` and is not visible from any other tenant."

### 12.3 What the starter does NOT do

- Does not generate domain metadata via `make:entity` (forbidden by RFC-001 §9.5).
- Does not pre-install a UI framework other than React + Tailwind (theme is swappable).
- Does not assume Postgres vs MySQL — the user picks at install time via `composer create-project ausus/starter myapp -- --db=postgres|mysql|sqlite`.
- Does not ship a CI configuration. That is the user's choice.

---

## 13. `ausus/plugin-template` project template

### 13.1 Purpose

`composer create-project ausus/plugin-template my-plugin` produces a standalone Composer package skeleton — a plugin authored OUTSIDE the host application, intended to be required into one or more starter projects (or production apps).

### 13.2 Layout

```
my-plugin/
├── composer.json                  # extra.ausus block, kernel range, provider FQN
├── src/
│   ├── MyPluginPlugin.php         # Plugin + PluginLifecycle + ServiceProvider; EMPTY DSL with TODO markers
│   ├── Policies/
│   │   └── SamplePolicy.php       # one example Policy with extensive comments
│   ├── Effects/
│   │   └── SampleEffect.php       # one example Effect with extensive comments
│   └── Fields/                    # placeholder for custom Field Types (rarely needed)
├── tests/
│   ├── PluginRegistrationTest.php
│   └── PolicyEvaluationTest.php
├── phpunit.xml
└── README.md                      # "fill in the DSL, run composer require ../my-plugin in your starter"
```

### 13.3 Pre-filled vs TODO

The skeleton is **pre-filled with structure** (imports, class skeletons, method signatures) but contains **TODO markers** wherever domain content goes:

```php
public function boot(): void
{
    Entity::make('mynamespace.myentity')      // TODO: change FQN
        ->fields([
            Field::id('id'),
            Field::system('tenant_id'),
            // TODO: add your fields here
        ])
        ->actions([
            // TODO: declare your actions here
        ])
        ->policies([
            Policy::make('mynamespace.myentity.policy.read')
                ->implementedBy(\MyNamespace\Policies\SamplePolicy::class),
            // TODO: declare more policies
        ])
        ->workflows([
            // TODO: declare workflows (optional)
        ])
        ->projections([
            Projection::make('summary')
                ->fields([/* TODO */])
                ->policy('mynamespace.myentity.policy.read'),
        ]);
}
```

### 13.4 Path-based local install

The template README guides the user through linking the local plugin into the starter:

```
cd ../myapp
composer config repositories.my-plugin path ../my-plugin
composer require my-vendor/my-plugin:@dev
php artisan ausus:compile
```

The local plugin is then live in the starter app.

---

## 14. `ausus:up` bootstrap command

### 14.1 What it does

A single command that compresses RFC-000 §7 setup steps 4–11 into one invocation. Runs in this order:

1. **Migrate.** `php artisan migrate --force` (kernel tables, stack tables, plugin tables derived from graph).
2. **Bootstrap `system` Tenant** if not present.
3. **Bootstrap demo Tenant** `demo` if `APP_ENV=local` and the demo plugin is loaded.
4. **Bootstrap stub user** `demo-user` in stub mode if no auth users exist.
5. **Compile** the Metadata Graph (`php artisan ausus:compile`).
6. **Doctor** (`php artisan ausus:doctor`) — fail bootstrap on errors; surface warnings.
7. **Open browser** at `http://demo.localhost:8000/` (dev only; configurable).

### 14.2 Idempotent

Re-running `ausus:up` is safe: migrations are idempotent, tenant/user creation skips if present, compile re-emits only on graph hash change.

### 14.3 Output

Streams human-readable progress; on error, prints the failed step and the doctor output. Exit code 0 on full success; non-zero on failure.

### 14.4 Non-goals

- Not for production deployment. Production deployments run `ausus:compile` in CI; `ausus:up` is dev-time only.
- Does not seed application-domain data. The demo plugin's `boot()` is the only seed; user-domain seeding is a separate Laravel seeder convention.

---

## 15. Time-to-first-screen — measurement and target

### 15.1 Target

**≤ 30 minutes** from `composer create-project ausus/starter` to a browser rendering a Projection of a **custom-authored** Entity. (Not the demo Entity — the user must have written something.)

### 15.2 Measurement methodology

The clock starts at the first `composer create-project` invocation. The clock stops when the user observes their own Projection rendered with at least one row of their own data. The developer's local environment is assumed: PHP 8.3+, Composer, Node 20+, Postgres or MySQL or SQLite installed and configured, browser available, IDE of choice.

### 15.3 Budget breakdown

| Step                                                  | Budget   |
|-------------------------------------------------------|----------|
| `composer create-project ausus/starter myapp`         | 3 min    |
| `cd myapp && php artisan ausus:up`                    | 4 min    |
| Open browser, see demo plugin rendering               | 1 min    |
| Read `app/Plugins/HelloInvoice/README.md` orientation | 4 min    |
| `composer create-project ausus/plugin-template ../my-plugin` | 2 min |
| Edit `MyPluginPlugin.php` (DSL based on TODO markers) | 8 min    |
| Edit one Effect class (one CRUD operation)            | 4 min    |
| Edit one Policy class (return Permit)                 | 2 min    |
| `composer config repositories... && composer require` | 1 min    |
| `php artisan ausus:compile`                           | <1 min   |
| Browser shows own plugin's Projection                 | 1 min    |
| **Total**                                             | **~30 min**|

Budget is tight. Items the budget does NOT include:

- Reading any RFC.
- Writing tests.
- Customizing the React theme.
- Setting up auth roles beyond the stub default.
- Production deployment.

The budget assumes the developer is new to AUSUS but fluent in Laravel and React. A developer also new to Laravel needs to add Laravel onboarding time on top (typically 1–2 hours). A developer also new to React needs to add React onboarding time.

### 15.4 Comparison

| Platform         | Time-to-first-screen with own Entity |
|------------------|---------------------------------------|
| Retool           | 10 minutes                            |
| Filament         | 30 minutes                            |
| Nova             | 45 minutes                            |
| AUSUS V0 (RFC-000) | ~8 hours                            |
| AUSUS Standard Stack (this RFC) | **~30 minutes**          |

The Standard Stack brings AUSUS into parity with Filament on TTFS. The other UX-1 measurements (boilerplate, concept count, FQN density) remain Dangerous-band; this RFC does not claim to address them.

### 15.5 Validation gate

This RFC's acceptance is conditioned on a measured TTFS run by a developer who has read this RFC and `ausus/starter`'s README but no other AUSUS documentation, recording elapsed time per the §15.3 budget. If the measured TTFS exceeds 35 minutes (5-minute buffer over the 30-minute target), the RFC is REJECTED and the Standard Stack composition is revisited.

---

## 16. Versioning

### 16.1 Stack version tracks kernel major

The meta-package `ausus/standard-stack` has the same major version as `ausus/kernel`. Standard Stack 1.x for kernel 1.x.

### 16.2 Component packages SemVer independently

Each component package (`ausus/persistence-sql`, etc.) follows independent SemVer. The meta-package requires compatible ranges:

```json
{
  "name": "ausus/standard-stack",
  "version": "1.0.0",
  "require": {
    "ausus/kernel":                "^1.0",
    "ausus/persistence-sql":       "^1.0",
    "ausus/tenancy-row":           "^1.0",
    "ausus/audit-database":        "^1.0",
    "ausus/reporting-sql":         "^1.0",
    "ausus/runtime-default":       "^1.0",
    "ausus/field-types-standard":  "^1.0",
    "ausus/renderer-react":        "^1.0",
    "ausus/auth-bridge":           "^1.0",
    "ausus/doctor-bundle":         "^1.0"
  }
}
```

### 16.3 npm package version pinning

`@ausus/renderer-react` (npm) has its own semver; `ausus/renderer-react` (composer) declares a peer dependency range. Mismatch is detected by `ausus:doctor` at boot.

### 16.4 Compatibility matrix

Each Standard Stack release publishes a compatibility matrix (Appendix D) listing kernel + plugin versions tested together. Plugin authors target a Standard Stack version; that version pins the kernel and components.

### 16.5 Provisional contracts (until RFC-006, RFC-011, ActionEffect RFC, Authorization RFC land)

Three contracts in this stack are PROVISIONAL:

- **DSL syntax** (consumed throughout): provisional until RFC-011.
- **Workflow runtime** (in `ausus/runtime-default`): provisional until RFC-006.
- **`ActionEffect` interface** (in `ausus/runtime-default`): provisional until ActionEffect RFC.
- **`Actor` extensions beyond minimum** (in `ausus/auth-bridge`): provisional until Authorization RFC.

Each provisional contract is marked in source with `@provisional` and in documentation with a callout. When the formal RFC lands, the affected package releases a major bump and the migration path is documented.

Plugin authors writing against the Standard Stack accept this provisional surface. The Standard Stack version triple (`X.Y.Z`) communicates compatibility; major bumps signal provisional-contract changes among other things.

---

## 17. Trade-offs

1. **Provisional surfaces** (§16.5) mean plugin authors writing today against the Standard Stack will face a refactor when the four pending RFCs land. Honest about this; the alternative (waiting for all RFCs before shipping a stack) loses 6+ months of community traction.
2. **Single-driver across the stack** (Postgres / MySQL / SQLite via `ausus/persistence-sql`). Heterogeneous storage waits for the post-V1 multi-driver RFC.
3. **Row-only tenancy in V1** (`ausus/tenancy-row`). Schema-per-tenant and database-per-tenant strategies are RFC-003-supported but no Standard Stack package implements them in V1. Plugin authors needing those write their own strategy plugin or wait for `ausus/tenancy-schema` / `ausus/tenancy-database` (out of V1 scope).
4. **One renderer profile** (`react.web.v1`). Vue, Svelte, native mobile profiles are RFC-004-supported but not in V1.
5. **One audit sink** (`ausus/audit-database`). Production deployments wanting SIEM / Kafka / S3 fan-out add secondary sinks (RFC-007 supports zero-or-more) but no first-party package ships them in V1.
6. **`ausus:up` is dev-only.** Production deployment is `ausus:compile` in CI + standard Laravel deploy. Not opinionated about the production path.
7. **No `ausus:make:entity`.** RFC-001 §9.5 forbids; this RFC respects. The plugin template (§13) is the workaround — it scaffolds a plugin, not an Entity.
8. **Defaults override risk.** With 38 defaults and zero required config, a developer can ship a misconfigured production deployment. Doctor catches the common cases; the rest is documentation responsibility.
9. **Stub auth in dev is convenient but a foot-gun in prod.** `AUSUS_AUTH_MODE=stub` in production allows trivial impersonation. The bridge refuses to start in production with `mode=stub` unless `AUSUS_AUTH_STUB_FORCE_PROD=true` is set. The override exists for legitimate cases (read-only demos) but is loud.
10. **Container side-effect spy is best-effort.** RFC-005 §10.3 acknowledged. The Standard Stack documents what is and is not detected.

---

## 18. Open questions

1. **RFC-006 (Workflow execution).** The simple Workflow runtime in `ausus/runtime-default` (§6.3) ships as a provisional. RFC-006 fixes the formal contract; `ausus/runtime-default` major-bumps to adopt.
2. **RFC-011 (DSL surface).** The DSL in the demo plugin and the plugin template is provisional. RFC-011 fixes the surface; the stack major-bumps.
3. **ActionEffect formal RFC.** §6.2's de facto interface is provisional. Future RFC fixes; stack major-bumps.
4. **Authorization plugin RFC.** §9's `Actor::roles()`, `::permissions()`, `::attribute()` beyond RFC-005 minimum are provisional. Future RFC fixes; `ausus/auth-bridge` major-bumps.
5. **Post-V1 — additional renderer profiles** (Vue, mobile, PDF).
6. **Post-V1 — additional tenancy strategies** (`ausus/tenancy-schema`, `ausus/tenancy-database`).
7. **Post-V1 — additional audit sinks** (`ausus/audit-kafka`, `ausus/audit-s3`, `ausus/audit-siem`).
8. **Post-V1 — `ausus/reporting-clickhouse` and other analytical-store ReportingDrivers** for deployments where reporting traffic doesn't fit on the OLTP database.
9. **A formal "Plugin Author Handbook"** consolidating onboarding material across the seven kernel RFCs. Out of this RFC's scope; addressed by documentation work, not packaging.

---

## 19. Acceptance criteria

This RFC is accepted when:

1. The four role signatories (architect, kernel, DX, challenger) sign off on §2 (package map), §6 (runtime-default with provisional contracts), §11 (defaults), §12 (starter), §15 (TTFS target).
2. A measured TTFS run (§15.5) returns ≤ 35 minutes from `composer create-project` to first-screen-of-own-Projection.
3. All ten Composer packages and one npm package are built and published.
4. The `ausus/starter` and `ausus/plugin-template` repositories are public and `composer create-project`-installable.
5. The compatibility matrix (Appendix D) is published with at least one tested combination of kernel + components.
6. `ausus:doctor` from the `ausus/doctor-bundle` reports zero errors and zero warnings on a fresh `ausus/starter` install (notices permitted).
7. RFC-000 is re-run as a real implementation pass against the Standard Stack; the determination shifts from `BLOCKED` to `GO`.

Conditional acceptance: if §15.5's measured TTFS exceeds 35 minutes, this RFC is **REJECTED**, and the Standard Stack composition is revisited before re-submission. The TTFS target is non-negotiable; it is the entire purpose of this RFC.

---

## Appendix A — Package map

```
Composer:
  ausus/standard-stack                  meta-package
    ├── ausus/kernel                    (existing)
    ├── ausus/persistence-sql           §3
    ├── ausus/tenancy-row               §4
    ├── ausus/audit-database            §5
    ├── ausus/reporting-sql             §10 of RFC-010; this RFC §2
    ├── ausus/runtime-default           §6
    ├── ausus/field-types-standard      §7
    ├── ausus/renderer-react            §8 (composer half)
    ├── ausus/auth-bridge               §9
    └── ausus/doctor-bundle             §10

npm:
  @ausus/renderer-react                 §8 (npm half)

Composer project templates:
  ausus/starter                         §12
  ausus/plugin-template                 §13
```

---

## Appendix B — Defaults table

The 38 defaults of §11.2 in full. The defaults table IS the source of truth for what a fresh install configures without user action. Plugin authors override these only when their domain requires.

---

## Appendix C — TTFS budget (per §15.3)

| #  | Step                                                          | Budget   | Cumulative |
|----|---------------------------------------------------------------|----------|------------|
| 1  | `composer create-project ausus/starter myapp`                 | 3 min    | 3 min      |
| 2  | `cd myapp && php artisan ausus:up`                            | 4 min    | 7 min      |
| 3  | Browser shows demo plugin                                     | 1 min    | 8 min      |
| 4  | Read `HelloInvoice/README.md`                                 | 4 min    | 12 min     |
| 5  | `composer create-project ausus/plugin-template ../my-plugin`  | 2 min    | 14 min     |
| 6  | Edit DSL based on TODO markers                                | 8 min    | 22 min     |
| 7  | Edit one Effect                                               | 4 min    | 26 min     |
| 8  | Edit one Policy (return Permit)                               | 2 min    | 28 min     |
| 9  | `composer config repositories... && composer require`         | 1 min    | 29 min     |
| 10 | `php artisan ausus:compile`                                   | <1 min   | 30 min     |
| 11 | Browser shows own Projection                                  | 1 min    | **~30 min**|

---

## Appendix D — Compatibility matrix (initial)

| Kernel | Standard Stack | persistence-sql | tenancy-row | audit-database | reporting-sql | runtime-default | field-types-standard | renderer-react (composer / npm) | auth-bridge | doctor-bundle | Tested PHP | Tested DB              |
|--------|----------------|------------------|-------------|----------------|---------------|-----------------|----------------------|----------------------------------|-------------|---------------|------------|------------------------|
| 1.0.x  | 1.0.x          | 1.0.x            | 1.0.x       | 1.0.x          | 1.0.x         | 1.0.x           | 1.0.x                | 1.0.x / 1.0.x                    | 1.0.x       | 1.0.x         | 8.3, 8.4   | Postgres 15+, MySQL 8.0+, SQLite 3.40+ |

Future kernel versions extend the matrix. Plugin authors targeting a Standard Stack version inherit the tested combination.
