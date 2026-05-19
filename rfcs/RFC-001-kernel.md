# RFC-001 — The AUSUS Kernel

| Field         | Value                                                     |
|---------------|-----------------------------------------------------------|
| Status        | Draft-02                                                  |
| Authors       | architect, domain, kernel, challenger                     |
| Date          | 2026-05-17                                                |
| Supersedes    | Draft-01 (amendments from `RFC-001-review-01.md` folded in) |
| Superseded by | —                                                         |
| Stability     | Foundational. Changes after acceptance require a follow-up RFC. |

**Changes from Draft-01.** The `View` primitive has been renamed to `Projection` and its scope restricted to domain intent. The L5 layer name has been changed from "Projection" to "Presentation" to avoid collision. The privileged bypass API has been removed entirely; legitimate cross-domain operations are served by a narrow `ReportingDriver` contract and a `MaintenanceAction` category. A new normative §5.8 locks DSL invariants. New §2.1.1 and §2.1.2 lock Entity identity and Tenant binding invariants. A new rule §3.2.8 closes the API-Surface bypass gap surfaced by the layer boundary scan. Three appendices (A, B, C) record the contradiction, terminology, and layer-boundary scans performed against this draft.

---

## 0. Problem statement

Filament, Nova, Retool, and Odoo each occupy part of the "build internal business software fast" space, but none of them is, simultaneously:

- Laravel-native (Odoo, Retool, Salesforce are not),
- domain-first rather than UI-first (Filament, Nova are not),
- multi-tenant from day one (Filament, Nova are not),
- decoupled into backend + frontend with a stable wire format (Filament, Nova are not),
- code-first and version-controlled (Retool, Salesforce are not),
- and viable for both an ERP-grade workflow engine and a one-screen internal tool.

AUSUS exists to occupy that intersection. To do so without collapsing into "yet another admin panel" after twelve months of feature pressure, the project needs a foundation that is opinionated about what belongs inside the platform and what does not. That foundation is the **Kernel**.

This RFC defines the Kernel: its responsibilities, its primitives, its lifecycle, its extension model, and the architectural rules that downstream RFCs are expected to respect. It does not specify implementations. It specifies the contracts and constraints that implementations must obey.

The horizon for these decisions is **ten years**. Where short-term developer ergonomics conflict with ten-year sustainability, this RFC sides with sustainability.

---

## 1. Kernel mission

### 1.1 Decision

The Kernel is responsible for **owning the metadata graph and the contracts that produce, validate, cache, resolve, and observe it**. Everything else is a layer above the Kernel or a plugin around it.

The Kernel owns:

1. **Contracts** — the interfaces that every primitive (Entity, Field, Relation, Action, Policy, Workflow, Projection, Plugin, Tenant) must satisfy.
2. **The Registry** — the in-memory catalog where plugins register descriptors during boot.
3. **The Compiler** — the deterministic process that turns registered descriptors into a validated, hashed Metadata Graph.
4. **The Resolver** — the read API that the rest of the platform uses to look up entities, fields, relations, actions, policies, workflows, and projections by Fully Qualified Name (FQN).
5. **The Plugin Lifecycle** — registration, discovery, dependency resolution, version compatibility checks, install / boot / upgrade / uninstall hooks.
6. **The Tenant Context** — a per-request ambient context that every domain operation must run inside.
7. **The Audit Spine** — a kernel-enforced contract that mutating Actions emit Audit Entries. Kernel does not store them; it requires them.
8. **Version negotiation** — kernel and plugin SemVer ranges, compatibility matrix, fail-fast on mismatch.
9. **The `ReportingDriver` contract** — a read-only, cross-Entity query interface that respects Field-level Policies and Tenant scope. The Kernel owns the contract; implementations ship as plugins.

### 1.2 Out of scope (must never live inside the Kernel)

- **HTTP routing, controllers, REST/JSON serialization.** Lives in the API Surface layer (L4).
- **React, ViewSchemas (the renderable wire format), and all UI hint resolution.** Live in the Presentation layer (L5). The Kernel knows `Projection` descriptors (domain-level intent); it does not know `ViewSchema` (presentation-level output).
- **SQL execution, query builders, Eloquent models, migrations.** Lives in Persistence Driver plugins.
- **Authentication strategies** (session, JWT, OAuth, SSO). The Kernel knows about Actors and Policies; it does not know how an Actor was identified.
- **Concrete Field Types** (TextField, DateField, MoneyField). Those are first-party plugins.
- **Concrete Roles, Permissions, ACL trees.** The Kernel knows Policies; Roles/Permissions are an authorization plugin.
- **Concrete Workflow engines** (state machine library, BPMN runtime). The Kernel knows Workflow descriptors; the execution semantics are pluggable.
- **Tenant storage strategies** (row-level / schema-per-tenant / db-per-tenant). The Kernel knows Tenant Context; isolation is a plugin contract.
- **Logging, queueing, mail, cache.** Use Laravel contracts; do not re-implement.
- **Code generation, scaffolding, `make:entity` commands.** The DSL **is** the source of truth.
- **Privileged bypass APIs.** No escape that disables Tenant, Policy, Audit, or Workflow simultaneously. Legitimate cross-domain operations go through `ReportingDriver` (read) or `MaintenanceAction` (write), each individually governed.

### 1.3 Alternatives considered

- **Kernel = everything** (Filament model). Rejected: couples evolution of unrelated concerns, breaks SemVer discipline, blocks third-party persistence drivers and renderers.
- **No kernel, only conventions** (Rails/Laravel "magic" model). Rejected: the platform's value depends on a *guaranteed* metadata graph; conventions are not a guarantee.
- **Kernel = DSL only, no runtime** (a code-generation model). Rejected: code generation forces a write-time view of the world and breaks runtime extensibility and live tenant overrides.

### 1.4 Trade-offs

The Kernel is intentionally narrow. This means:

- First-party features (persistence, auth, tenancy isolation, audit storage, reporting) ship as plugins. Operational complexity is higher than a monolith.
- A user who installs only the Kernel has nothing usable. There is no "hello world" without at least the persistence plugin and a Field Types plugin.
- We accept this cost in exchange for the ability to replace any layer without forking the platform.

---

## 2. Core abstractions

Each primitive is defined as a **descriptor** (a declarative, serializable object produced by the DSL and consumed by the Compiler). Descriptors are inert; behavior comes from the Runtime interpreting them.

### 2.1 Entity

A named domain concept identified by an FQN of the form `namespace.name` (e.g. `billing.invoice`). Aggregate root for its Fields, Relations, Actions, Policies, Workflows, and Projections.

- Tenant-scoped by default; explicitly marking an Entity as `system` is required for cross-tenant data.
- Stable identity across kernel versions: an Entity's FQN is part of the platform's public API.
- An Entity does not know how it is persisted, rendered, or transported.

#### 2.1.1 Entity identity invariants (normative)

1. Every Entity instance has an **identity handle** that is opaque to the Kernel, present from creation, and immutable for the instance's lifetime.
2. The identity handle is produced by the active Persistence Driver or by the application, never by the Kernel itself. The Kernel guarantees only that it exists and does not change.
3. The identity handle MUST be expressible as a value that round-trips through serialization. Live object handles, file descriptors, and process-local references are forbidden as identity.
4. A cross-Entity reference (the target of a Relation, the Subject of an Audit Entry, the argument to an Action) is canonically expressed as the tuple `(tenant_id, entity_fqn, identity_handle)`. Persistence Drivers MUST accept references in this form.
5. Concrete identity types (UUID, ULID, snowflake, composite keys) are chosen by RFC-002 and by individual Persistence Drivers. The Kernel does not constrain the choice beyond the invariants above.

#### 2.1.2 Tenant binding invariants (normative)

1. Every Entity instance is bound to exactly one owning Tenant at creation. The binding is immutable; moving an instance between Tenants requires deletion and recreation under audit.
2. The `tenant_id` discriminator is part of the canonical cross-Entity reference (§2.1.1.4). It is not part of the identity handle itself; identity handles are required to be unique only within `(tenant_id, entity_fqn)`.
3. Entities marked `system` (per §2.1) are bound to the `system` Tenant. Cross-Tenant Relations are forbidden unless both endpoints are `system`.

### 2.2 Field

A typed, named attribute of an Entity.

- Has a **Field Type** (resolved from a registered Field Type plugin: `string`, `money`, `date`, `enum`, `json`, etc.).
- Carries validation rules, default values, nullability, uniqueness, persistence binding, and (separately) UI hints.
- UI hints are a distinct sub-descriptor; the domain layer does not know they exist. Removing all UI hints must leave the Entity fully usable.

### 2.3 Relation

A named, directed edge between two Entities.

- Cardinality: `one-to-one`, `one-to-many`, `many-to-many`.
- Declares its inverse (or marks itself as one-way).
- Declares cascade semantics (`restrict`, `cascade`, `set-null`, `detach`).
- Tenant-aware: cross-tenant Relations are forbidden unless both Entities are marked `system` (per §2.1.2.3).
- Endpoints are expressed using the canonical reference tuple of §2.1.1.4.

### 2.4 Action

A named, invokable operation. Actions are the **only** way state is mutated. Reading state does not require an Action.

- Has an input contract (a set of typed parameters, possibly referencing Fields).
- Has an output contract.
- Has an attached Policy chain.
- Has audit semantics (`audited: true` by default for mutations).
- Can be attached to an Entity, to a set of Entities, or to no Entity (global Action).
- Idempotency is declared, not assumed.

#### 2.4.1 MaintenanceAction sub-category

A `MaintenanceAction` is a sub-category of Action for bulk or cross-instance operations (backfills, large administrative updates, schema-driven data corrections).

- MUST be declared explicitly in the plugin manifest.
- MUST be surfaced separately by `ausus:doctor` and tagged distinctly in the audit log.
- MAY bypass Workflow guards (a single MaintenanceAction may transition many instances across many Workflow states).
- MUST NOT bypass the Tenant Context, the Policy chain, or the Audit Spine.

A MaintenanceAction is the only mechanism by which Workflow guards may be skipped. There is no other authorized path.

### 2.5 Policy

A named authorization rule with the signature `(Actor, Action, Subject, Context) → Permit | Deny | Abstain`.

- Composable: Policies combine deterministically; the combinator is `Deny > Permit > Abstain` (any Deny short-circuits).
- Deny-by-default: if no Policy returns Permit, the operation is denied.
- Tenant-aware: Policies receive the active Tenant in the Context.
- Pure: a Policy must not produce side effects. Audit is the Kernel's responsibility, not the Policy's.

### 2.6 Workflow

A named state machine attached to an Entity.

- States are declared; an Entity instance is always in exactly one state of each Workflow attached to it.
- Transitions name a source state, target state, the Action that triggers them, and optional guards (Policies).
- Effects (other Actions triggered by a transition) are declared, not embedded in code.
- Multiple Workflows may attach to the same Entity (e.g. `invoice.approval` and `invoice.payment`).

### 2.7 Projection

A named, domain-level declaration of a legitimate read shape of an Entity (or composition of Entities). A Projection is **not** a UI concept; it states *what* is exposable, not *how* it is rendered.

A `Projection` descriptor declares:

- A name (FQN-scoped to its owning Entity, e.g. `billing.invoice.list`).
- The Fields it exposes.
- The Actions it exposes.
- The Filters it permits.
- The Policy chain that governs its visibility.

A `Projection` descriptor does **not** carry: ordering, layout, UI hints, target-renderer specifications, or any presentation directive. Those concerns belong to the Presentation layer (L5), which generates a `ViewSchema` by combining a Projection with UI hints, Actor context, locale, and renderer capabilities (see §4.5).

Multiple Projections may exist per Entity (`list`, `detail`, `edit`, plugin-defined). The Kernel uses Projections to validate that exposed Fields and Actions exist, to enforce Field-level Policy filtering at the boundary, and to give the Presentation layer a stable, discoverable contract.

### 2.8 Plugin

A versioned, self-describing bundle of contributions.

- A Plugin can contribute Entities, Field Types, Relations, Actions (including MaintenanceActions), Policies, Workflows, Projections, persistence drivers, tenancy isolation strategies, reporting drivers, audit sinks, or other plugins' extensions.
- Declares a kernel version range (`kernel: "^1.0"`) and dependencies on other plugins.
- Has install / boot / upgrade / uninstall lifecycle hooks.

### 2.9 Tenant

A named isolation boundary.

- Every domain operation runs inside a Tenant Context (or the explicit `system` Tenant).
- A Tenant has a resolution strategy (how a request is mapped to it: subdomain, header, JWT claim, etc.) and an isolation strategy (row / schema / database). Both are pluggable.
- The Kernel guarantees that a domain operation cannot read or write across Tenants without an explicit, audited elevation.

### 2.10 Alternatives considered

- **Make UI hints first-class on Field.** Rejected: it would mean every Field descriptor would have a presentation surface even when consumed by a non-UI client (a job, a sync, a webhook). UI hints stay in a separate sub-descriptor.
- **Make Roles a kernel concept.** Rejected: roles are an authorization model, and there are several legitimate ones (RBAC, ABAC, ReBAC). The Kernel commits only to Policies; roles are a plugin.
- **Make Workflow optional and outside the kernel.** Rejected: Workflow is the differentiator vs Filament/Nova. It is a kernel primitive, even if the executor is pluggable.
- **Keep `View` as a kernel primitive that also carries ordering/layout/hints** (Draft-01 model). Rejected: it leaks UI vocabulary into the domain and contradicts §1.2. Replaced by `Projection` (domain intent) at L0 + `ViewSchema` (presentation output) at L5.

### 2.11 Trade-offs

- Nine primitives is a lot to introduce simultaneously. We accept the learning curve because removing any one of them later would be a breaking change.
- Some primitives (Projection, Tenant) straddle layers. We define them in the Kernel because their *contract* must be stable even if their *implementation* lives in plugins or in higher layers.

---

## 3. Dependency rules

### 3.1 Layering

```
L0  Kernel (contracts, registry, compiler interface, resolver interface)
L1  Compiler (concrete compiler, validators, graph builder)
L2  Runtime (resolver implementation, tenant context, audit dispatcher)
L3  Persistence Driver(s) | Authorization | Tenancy Isolation | Reporting Driver(s)   ← plugin-facing
L4  API Surface (HTTP, REST/JSON, GraphQL adapters)
L5  Presentation (ViewSchema generator: consumes Projections, UI hints, Actor context)
L6  Renderer (React) — consumes ViewSchema JSON only, never imports backend code
L7  Domain Plugins (billing, inventory, crm, ...)
```

### 3.2 Rules

1. A lower layer must never import from a higher layer.
2. Domain plugins (L7) depend on the Kernel (L0) and on declared plugin peers. They must **not** depend on Runtime, Compiler, API Surface, or Presentation — they extend through Kernel contracts.
3. The Renderer (L6) is a separate codebase. It depends only on the **ViewSchema wire format** owned by the Presentation layer, which is versioned independently and is part of the public API.
4. Laravel is a dependency of L1 and above. The Kernel itself depends only on PHP and on Laravel **contracts** (Illuminate\Contracts\*), not on Laravel implementations.
5. Persistence drivers are interchangeable. A plugin **must not** import an Eloquent model directly; it must request data through the Repository contract provided by the persistence driver bound at runtime.
6. Reporting drivers (L3) are read-only. They MUST enforce Field-level Policies and Tenant scope; they MUST NOT provide a path to mutation.
7. No plugin may register a primitive whose FQN it does not own. Namespaces (`billing.*`) are reserved by the plugin that declares them in its manifest.
8. The API Surface (L4) MUST invoke domain operations only through the Runtime (Tenant Context, Policy chain, Audit Spine). It MUST NOT bypass the Runtime to call Persistence Drivers, Reporting Drivers, or Audit sinks directly. This rule closes the only architecturally possible sideways bypass and is enforced by code review and by static analysis on the kernel contracts package.

### 3.3 Alternatives considered

- **Hex/clean architecture with strict ports & adapters everywhere.** Rejected as overkill for the kernel itself — we adopt its spirit (layering, contract boundaries) without the ceremony of ports/adapters at every seam.
- **Allow plugins to import Runtime for convenience.** Rejected: it would freeze the Runtime API as a de facto public surface and prevent it from evolving.

### 3.4 Trade-offs

- Plugins cannot reach into Runtime internals, so some legitimate use cases (custom resolver behavior, custom audit dispatch) require new Kernel contracts. We accept this cost — every new contract is an intentional, reviewed extension point.

---

## 4. Runtime lifecycle

### 4.1 Registration

- During Laravel's boot phase, each Plugin's `register()` is called.
- `register()` adds **descriptors** to the Registry. It does not resolve them, validate cross-references, or read from the database.
- Registration is order-independent. Cross-plugin references are resolved at compile time.
- Registration is cheap and safe to repeat (idempotent). The DSL invariants in §5.8 are what make this idempotency claim defensible.

### 4.2 Compilation

- Triggered either eagerly (`php artisan ausus:compile`, expected in CI/deploy) or lazily on first resolver access (development only).
- The Compiler:
  1. Snapshots the Registry.
  2. Resolves cross-references: Relations to Entities, Workflow transitions to Actions, Action policies to Policy descriptors, Projection directives to Fields and Actions.
  3. Validates: dangling references, cyclic Relations of forbidden kinds, conflicting Policies, Tenant-scope violations, version mismatches, Projection coherence (every exposed Field and Action exists on the owning Entity).
  4. Produces the **Metadata Graph** — an immutable, serializable object.
  5. Computes a graph hash from the plugin manifest + kernel version + descriptor contents.
- Compilation is **deterministic**: same inputs produce the same hash. This is enforced by the DSL invariants in §5.8.
- Compilation failures are loud, structured, and refuse to fall back to a partial graph.

### 4.3 Caching

- The compiled Metadata Graph is cached by its hash. Default driver: filesystem (`storage/framework/ausus/graph.{hash}.php`).
- In production, the cache is populated by the build/deploy pipeline. The Runtime refuses to compile on a hot path unless explicitly allowed in config.
- Cache invalidation triggers: plugin install/update/remove, kernel upgrade, explicit `ausus:cache:clear`.
- The cache is read-only at runtime. There is no partial-update path.

### 4.4 Resolution

- The Resolver loads the compiled graph once per process (typically at boot).
- Lookups are by FQN: `Ausus::entity('billing.invoice')`, `Ausus::action('billing.invoice.approve')`, `Ausus::projection('billing.invoice.list')`.
- Tenant overrides (Fields, Policies, Workflows, Projections tweaked per Tenant) are layered at **resolution time**, not at compile time. The compiled graph contains the base; per-Tenant deltas are merged on access.
- Resolution of heavyweight sub-descriptors (full Policy chains, large Projection sets) is lazy.

### 4.5 Rendering

- Rendering is **not** a Kernel concern; it is the Presentation layer (L5) consuming Kernel output.
- A presentation request looks like: `(Tenant, Actor, Entity FQN, Projection name, locale) → ViewSchema JSON`.
- The Presentation layer is responsible for: resolving the named Projection, applying UI hints, evaluating Tenant overrides, evaluating Actor-visible Fields via Field-level Policies, and emitting JSON conforming to the ViewSchema wire format.
- The Renderer (L6, React) consumes the JSON. It does not call backend code beyond Actions, which are invoked through the API Surface (L4).

### 4.6 Alternatives considered

- **No compilation step; resolve everything reflectively at runtime.** Rejected: performance and predictability. A compiled graph also gives us validation as a deploy gate.
- **Compile per-Tenant ahead of time.** Rejected: combinatorial cache explosion and slow tenant onboarding. Per-Tenant deltas are applied lazily.

### 4.7 Trade-offs

- The compilation step is real operational overhead — CI must run it, deploys must ship the cache. We accept this; it is the price of a stable, validated graph.
- Lazy per-Tenant delta application means the first request per Tenant per process is slightly slower. Acceptable in exchange for cache simplicity.

---

## 5. Laravel integration

### 5.1 Service providers

- `Ausus\Kernel\KernelServiceProvider` — binds Kernel contracts to implementations, registers the Registry singleton.
- `Ausus\Kernel\CompilerServiceProvider` — binds the Compiler and its validators.
- `Ausus\Kernel\RuntimeServiceProvider` — binds the Resolver, the Tenant Context, the Audit dispatcher.
- Each Plugin ships its own service provider, which calls into the Registry during `register()`.
- Plugin service providers are discovered via Composer (`extra.laravel.providers`) and verified by AUSUS plugin discovery.

### 5.2 Contracts

All public extension points live in `Ausus\Kernel\Contracts\`. Headline contracts:

- `Kernel`, `Registry`, `Compiler`, `MetadataGraph`, `Resolver`
- `EntityDescriptor`, `FieldDescriptor`, `FieldType`, `RelationDescriptor`, `ActionDescriptor`, `PolicyDescriptor`, `WorkflowDescriptor`, `ProjectionDescriptor`
- `Plugin`, `PluginManifest`, `PluginLifecycle`
- `TenantContext`, `TenantResolver`, `TenantIsolationStrategy`
- `PersistenceDriver`, `Repository`
- `ReportingDriver`
- `Auditor`, `AuditEntry`, `AuditSink`

Every contract is SemVer-stable across major kernel versions. Breaking a contract requires a new major.

### 5.3 Facades

- `Ausus` — the single facade. Sub-namespaces accessed through it: `Ausus::entity('...')`, `Ausus::projection('...')`, `Ausus::tenant()`, `Ausus::audit(...)`, `Ausus::plugins()`.
- No proliferation of facades. One entry point keeps the public surface small.

### 5.4 Config

`config/ausus.php`:

```
kernel:
  version: (read from package)
compiler:
  strategy: eager | lazy
  cache:
    driver: file | redis | array
    path: storage/framework/ausus
runtime:
  strict_tenant: true            # forbid operations outside a Tenant Context
tenancy:
  default_resolver: ausus.tenancy.subdomain
  default_isolation: ausus.tenancy.row
plugins:
  autodiscovery: true
  disabled: []
audit:
  default_sink: ausus.audit.database
  redact: []                     # field name patterns to redact from inputs/outputs
reporting:
  default_driver: ausus.reporting.sql
```

Configuration is read once at boot. Changing it at runtime has no effect.

### 5.5 Artisan commands

- `ausus:compile` — build and cache the Metadata Graph. Required in CI.
- `ausus:cache:clear` — invalidate the compiled graph cache.
- `ausus:graph:export [--format=json]` — dump the compiled graph for tooling.
- `ausus:plugin:list` — list installed plugins with version, kernel range, dependencies.
- `ausus:plugin:check` — verify plugin compatibility against the current kernel.
- `ausus:tenant:create`, `ausus:tenant:list`, `ausus:tenant:archive` — Tenant lifecycle (delegates to the active tenancy plugin).
- `ausus:audit:tail [--tenant=…]` — stream the audit log.
- `ausus:doctor` — run all health checks (graph valid, cache present, plugin compatibility, persistence reachable, reporting driver reachable, audit sink reachable, MaintenanceAction inventory).

There is **no** `ausus:make:entity` command. The DSL is the source.

### 5.6 Alternatives considered

- **Embed AUSUS as a hard fork of Laravel.** Rejected: defeats Laravel-native and makes upgrades hostile.
- **Multiple facades (`Entity::`, `Tenant::`, `Audit::`).** Rejected: facade sprawl pollutes static analysis and confuses IDE autocomplete.

### 5.7 Trade-offs

- Tying to Laravel contracts (rather than Laravel concrete classes) limits some ergonomic shortcuts but makes us robust across Laravel major versions.

### 5.8 DSL contract requirements (normative)

The DSL surface is not specified in this RFC. Any DSL bound to the Kernel must satisfy the following invariants. These invariants are load-bearing: they are what makes the Compiler's determinism claim (§4.2) and the Registry's idempotency claim (§4.1) defensible.

1. **Purity.** A DSL call must produce a descriptor without performing I/O, without reading database state, and without executing domain logic. Side effects during description are forbidden.
2. **Serializability.** Every descriptor produced by the DSL must be serializable to the format consumed by the Compiler. Closures, resource handles, and process-local references in descriptor payloads are forbidden.
3. **Determinism.** Identical DSL invocations must produce identical descriptors. FQN binding is derivable from the DSL call alone, without reference to runtime state, environment, or wall-clock time.
4. **No I/O at definition time.** The DSL MUST NOT open files, hit the database, call HTTP services, read environment variables outside declared config, or write logs while building descriptors.
5. **No domain logic at definition time.** Validation, computation, and authorization happen at runtime through Actions and Policies, never inside `->fields()`, `->relations()`, `->policies()`, `->workflows()`, `->projections()`, or any other DSL method.
6. **Declarative composition only.** Where the DSL composes other primitives (e.g. attaching Policies to Actions, exposing Actions on Projections), the composition MUST be expressible as references to FQNs, not as embedded behavior or closures.
7. **Idempotent registration.** Invoking the same DSL chain twice MUST register the same descriptor. Double registration with conflicting content is an error reported by the Compiler.

Future RFCs MAY specify the DSL surface (method names, fluent shape, builder ergonomics). They MUST NOT relax the invariants above.

---

## 6. Extensibility model

### 6.1 Plugin registration

A Plugin is a Composer package with an `extra.ausus` block:

```
"extra": {
  "ausus": {
    "name": "ausus/billing",
    "version": "1.4.2",
    "kernel": "^1.0",
    "dependencies": {
      "ausus/accounting": "^1.0"
    },
    "provider": "Ausus\\Billing\\BillingPlugin"
  }
}
```

The named provider must implement `Plugin` and `PluginLifecycle`.

### 6.2 Plugin discovery

- Auto-discovered from Composer's `installed.json` by scanning for the `extra.ausus` marker.
- Plugins can be disabled via `config/ausus.php` (`plugins.disabled`).
- Discovery is deterministic and ordered topologically by declared dependencies.

### 6.3 Plugin lifecycle

- `install()` — invoked once when the plugin is activated for the first time in an environment (run migrations, seed required data, register persistent resources). Idempotent. Runs outside the DSL/registration phase and is therefore exempt from §5.8's no-I/O rule.
- `boot()` — invoked on every kernel boot. Pure registration work only; no I/O. Subject to the DSL invariants in §5.8.
- `upgrade(from, to)` — invoked when the installed version differs from the recorded version. Migrations between versions live here. Runs outside the DSL/registration phase.
- `uninstall()` — invoked when the plugin is removed. Cleans up persistent resources.

### 6.4 Version compatibility

- Kernel and Plugins both follow SemVer.
- Each Plugin declares a kernel version range. The Kernel refuses to boot if any active Plugin is incompatible.
- Each kernel release ships a **Compatibility Matrix** artifact listing tested first-party plugin versions.
- Breaking changes to Kernel contracts require a major kernel version. A minor or patch must never change a contract's behavior in a way visible to a conformant Plugin.
- Plugins must declare conflicts with peer plugins where applicable.

### 6.5 Alternatives considered

- **No SemVer contract; pin versions exactly.** Rejected: forces consumers into lockstep upgrades and discourages a third-party ecosystem.
- **Convention-based discovery (PSR-4 scanning).** Rejected: too slow at boot and too permissive; explicit declaration is a feature.

### 6.6 Trade-offs

- SemVer discipline on contracts is a permanent governance cost. The Kernel team must review every contract change. We accept this as the price of an ecosystem.
- Plugin discovery requires a Composer post-install/update hook for best UX; we accept the install-step cost.

---

## 7. Performance model

### 7.1 Metadata caching

- One compiled artifact per `(kernel version, plugin manifest hash)`.
- Stored in a format chosen for fast deserialization (opcache-friendly PHP file by default; alternative: serialized JSON for portability).
- Production loads the artifact once per process and keeps it in memory for the process lifetime.
- Multi-process deployments (PHP-FPM) load independently; OS file cache amortizes disk reads.

### 7.2 Lazy loading

- Top-level Entity, Field, Relation, and Action descriptors hydrate eagerly on boot.
- Workflow definitions, full Policy chains, Projection directives, and any large sub-descriptors hydrate on first access.
- Per-Tenant overrides apply at resolution time, not compile time.
- ViewSchemas are generated per request by the Presentation layer; an optional second-tier cache keyed by `(graph hash, tenant, projection, actor-role hash, locale)` may store generated schemas.

### 7.3 Compilation strategy

- **Default in production**: eager AOT compilation in CI/deploy. Application boot never compiles.
- **Default in development**: JIT compilation on first request, with a file watcher invalidating the cache on DSL changes.
- Compilation is parallelizable per plugin during the descriptor phase; cross-reference resolution is single-pass.
- The Compiler refuses to produce a partial graph; on any error, the previous cached graph remains in place and the build fails.

### 7.4 Alternatives considered

- **Always-JIT compilation.** Rejected: unpredictable cold-start, validation failures surface in production.
- **OPcache preload of the compiled graph.** Considered for production; deferred to a follow-up RFC once the artifact format is final.

### 7.5 Trade-offs

- Eager compilation means the CI/deploy pipeline must know how to invoke `ausus:compile`. Documentation and a published reference pipeline are required.
- Lazy hydration of Workflows and Projection directives means the very first request that touches them is slower. Acceptable.

---

## 8. Security model

### 8.1 Tenant isolation

- Every domain operation must execute inside a Tenant Context. Operations without one must opt in to the `system` Tenant explicitly, and that opt-in is audited.
- The active Tenant is bound at request entry by the active `TenantResolver` (subdomain, header, JWT claim, CLI flag).
- Persistence drivers and reporting drivers receive the Tenant from the Resolver, not from the caller. A bug in a Plugin cannot silently bypass tenancy.
- Cross-Tenant Actions exist (admin tooling, sync jobs) but require an `Ausus::elevate($targetTenant, reason: …)` call that is itself audited. Elevation grants the caller temporary access to operate in a different Tenant for the duration of a call; it is distinct from moving an instance between Tenants (which per §2.1.2.1 requires delete-and-recreate).
- Isolation strategies are pluggable: row-level (default), schema-per-tenant, database-per-tenant. The contract is the same; the implementation differs.
- Tenant binding of Entity instances is governed by §2.1.2.

### 8.2 RBAC

- Policies are the authorization primitive. Gates and middleware are not used by the platform (host applications may still use them).
- Every Action invocation passes through:
  1. The Tenant Context check (operation belongs to the active Tenant or is explicitly elevated).
  2. The Policy chain (Entity-scoped + Tenant-scoped + global, in that order).
  3. The Workflow guard, if the Action triggers a Workflow transition. (Skipped only by a `MaintenanceAction`, per §2.4.1.)
- Policies are deny-by-default and combine `Deny > Permit > Abstain`.
- Roles and Permissions are **not** Kernel concepts. They are provided by an authorization plugin that implements Policies in terms of role/permission lookups. Other authorization models (ABAC, ReBAC) can ship as alternative plugins.

### 8.3 Auditability

- Every mutating Action emits an Audit Entry: `(Actor, Tenant, Action FQN, canonical Subject reference per §2.1.1.4, Inputs, Outputs, Timestamp, Correlation ID, Trace ID)`.
- The Kernel **enforces** emission. A Plugin cannot mark a mutating Action as non-audited; it can only mark it as **input-redacted** (specific fields excluded from the payload) via configured redaction patterns.
- MaintenanceActions emit Audit Entries tagged as such, including the count of affected Subjects.
- Audit sinks are pluggable (database, S3, Kafka, external SIEM). Multiple sinks may run in parallel.
- The audit log is append-only. Updates and deletes to audit entries are not supported by any first-party sink.
- Reads are not audited by default. An Entity may opt into read auditing. Reads via a `ReportingDriver` follow the same opt-in.

### 8.4 Alternatives considered

- **Tenant context as middleware concern.** Rejected: makes it possible to forget. The Kernel enforces it instead.
- **Audit as an opt-in cross-cutting concern.** Rejected: audit is a compliance requirement for ERP and SaaS users. It must be on by default and enforced by the Kernel.
- **RBAC as a Kernel feature.** Rejected: the Kernel commits to Policies, not to a specific authorization model.
- **A single privileged bypass API that returns the underlying repository.** Rejected: any API that disables all four kernel invariants simultaneously is incompatible with the platform's compliance and tenancy promises. Replaced by `ReportingDriver` (read-only, Policy- and Tenant-aware) and `MaintenanceAction` (writes, but Policy- and Tenant- and Audit-bound).

### 8.5 Trade-offs

- Mandatory audit emission has a write cost on every mutating Action. We accept it; it is differentiating and required.
- Strict tenancy enforcement makes "just write a quick script" awkward — scripts must declare a Tenant or opt into `system`. This is the intended friction.
- Legitimate cross-Entity reporting and bulk maintenance must go through named, governed mechanisms rather than ad-hoc bypass. This is the intended friction.

---

## 9. Anti-patterns

AUSUS must never become any of the following. Each is paired with a concrete rule.

1. **An admin panel framework.** Documentation, naming, and examples must avoid the "admin panel" framing. Positioning is "business application platform."
2. **A UI-first DSL.** No `Form::make()` or `Table::make()` at the domain layer. Domain primitives never name UI widgets.
3. **A monolithic codebase.** Everything beyond the Kernel ships as a plugin, including first-party persistence, tenancy isolation, reporting, audit sinks, and Field Types.
4. **A Blade-coupled framework.** No server-rendered admin views. The platform's renderer is React, consuming ViewSchemas over JSON.
5. **A code-generation tool.** No `php artisan make:entity` that scrapes templates. The DSL is the source; generated code would split the source of truth.
6. **A low-code product before V2.** Visual builders ship in V3. They consume ViewSchemas (V2). ViewSchemas consume the Metadata Graph (V1). Skipping the order produces a UI editor disconnected from the domain.
7. **An ORM.** The Kernel does not perform SQL. It depends on Persistence Driver plugins.
8. **A workflow engine.** The Kernel describes Workflows; their execution is pluggable.
9. **A magic framework.** No implicit conventions that hurt grep-ability. FQNs, explicit registrations, explicit contracts. If a future feature requires "scanning for files that look like X", reject it.
10. **A framework that pins a Laravel version forever.** AUSUS supports a sliding window of supported Laravel majors; the Kernel depends on Laravel **contracts**, not implementations.
11. **A framework that confuses Roles with Policies.** The Kernel knows only Policies. Roles are a plugin concept.
12. **A platform that lets a Plugin reach into Runtime.** Plugins use Kernel contracts only. Any need to "just import the Resolver" indicates a missing Kernel contract.
13. **A platform with a privileged bypass API.** No escape that disables Tenant, Policy, Audit, or Workflow simultaneously. Legitimate exceptions are narrow, named, and individually governed (`ReportingDriver` for reads; `MaintenanceAction` for writes that may skip Workflow guards but never Tenant, Policy, or Audit).
14. **A DSL that does work.** The DSL describes. It does not validate, compute, persist, or authorize. Any violation of §5.8 is a design defect, not a feature.

---

## 10. Challenger review

### 10.1 vs Filament

| Dimension | Filament | AUSUS |
|---|---|---|
| Primary axis | UI-first (PHP → UI) | Domain-first (DSL → Graph → UI) |
| Multi-tenancy | Add-on package, leaky | Kernel primitive, enforced |
| Workflows | Not a primitive | First-class primitive |
| Frontend | Livewire (server-rendered) | React (decoupled) |
| Time-to-first-CRUD | Minutes | Longer (compilation, plugin install) |
| Plugin SemVer | Inconsistent | Enforced |
| Ecosystem size | Large | Empty at launch |

**Risks for AUSUS**: longer onboarding, no community, no "show HN" demo in five minutes. **Mitigation**: ship first-party plugins (persistence, tenancy, RBAC, Field Types, Presentation, React renderer) together as a starter kit so the *out-of-box* experience is comparable, even if the architecture underneath is fundamentally different.

### 10.2 vs Laravel Nova

| Dimension | Nova | AUSUS |
|---|---|---|
| License | Paid | Open, governed |
| Multi-tenancy | Not built in | Kernel primitive |
| Workflows | Not built in | First-class |
| Frontend | Vue, coupled | React, decoupled |
| Backing | Laravel team | Independent governance |
| Extensibility | Cards, Resources | Plugins with SemVer |

**Risks for AUSUS**: no commercial backing, no Laravel-team blessing. **Mitigation**: not solvable from within the architecture; depends on community traction.

### 10.3 vs Retool

| Dimension | Retool | AUSUS |
|---|---|---|
| Surface | Visual builder | Code-first DSL |
| Data ownership | Vendor-hosted | Customer infrastructure |
| Version control | Limited | Native (DSL is code) |
| Speed for one-off tools | Very high | Lower until V3 |
| Custom domain logic | Limited (JS in cells) | Native PHP |

**Risks for AUSUS**: Retool's V0 is faster than AUSUS's V1 for the "I need a screen on top of a DB" use case. **Mitigation**: lean into the use cases where Retool collapses (heavy domain logic, regulated/auditable workflows, on-prem). Do not try to beat Retool on visual builder speed until V3.

### 10.4 vs Odoo

| Dimension | Odoo | AUSUS |
|---|---|---|
| Stack | Python + custom XML views | Laravel + React |
| Modules | 20+ years of ERP coverage | Empty |
| Multi-tenancy | Database-per-tenant only | Pluggable (row/schema/db) |
| API-first | Retrofitted | Native |
| Customization model | Inheritance/views | Plugin composition |

**Risks for AUSUS**: Odoo's depth of domain coverage cannot be matched in V1. **Mitigation**: deliberately do not compete on coverage at launch. Position as the platform on which a future ERP can be built, not as an ERP itself.

### 10.5 Cross-cutting weaknesses

1. **Empty ecosystem at launch.** Only solved by time, by seeding first-party plugins, and by making plugin development pleasant.
2. **Operational overhead from compilation.** Compilation in CI is a step many small teams will resist. Mitigation: graceful JIT fallback in dev and a single-command production setup.
3. **Strict layering limits prototyping.** Some users will demand escape hatches. The platform refuses to ship a privileged bypass. Mitigation: a narrow `ReportingDriver` contract for read-only cross-Entity queries (subject to Field-level Policies and Tenant scope) and a `MaintenanceAction` category for bulk operations (subject to Policy, Tenant, and Audit). No call site can bypass all four kernel invariants simultaneously. This mitigation is contingent on RFC-010 (§11.9) landing in V1; until it lands, V1 ships with neither mechanism and legitimate use cases use direct Actions.
4. **React-only renderer.** Excludes teams that want server-rendered admin. Intentional; cost accepted.
5. **SemVer governance burden.** Every contract change requires review. Solved only by discipline and a small, named kernel team.
6. **Audit cost on every mutation.** A real performance cost. Mitigation: batched async sinks for high-throughput Actions.

---

## 11. Open questions

The following questions are intentionally unresolved in this RFC and will be addressed in follow-up RFCs.

1. **RFC-002 — Persistence Driver contract.** What exactly does `PersistenceDriver` expose? Repository pattern, Unit of Work, query builder surface, transaction semantics, optimistic locking, concrete identity types (within the envelope of §2.1.1). Default driver is Eloquent-backed; the contract must not leak Eloquent semantics.
2. **RFC-003 — Tenant isolation strategies.** Detailed semantics of row / schema / db isolation, migration story for moving between strategies, cross-tenant elevation grammar.
3. **RFC-004 — ViewSchema wire format.** Exact JSON schema for the wire format emitted by the Presentation layer (L5) and consumed by the Renderer (L6). Versioning policy, backwards compatibility rules, capability negotiation between Presentation and Renderer.
4. **RFC-005 — Policy combinator and ABAC support.** Confirm `Deny > Permit > Abstain`, define attribute-based extension hooks.
5. **RFC-006 — Workflow execution semantics.** Sync vs async transitions, compensation/rollback, idempotency keys, observability, interaction with `MaintenanceAction`.
6. **RFC-007 — Audit sink contract and redaction.** Sink interface, ordering guarantees, redaction grammar, retention policy contract.
7. **RFC-008 — Plugin marketplace and signing.** How third-party plugins are distributed, verified, and updated. (Likely deferred to post-V1.)
8. **RFC-009 — Telemetry and observability.** Out of scope here; needed before V1.
9. **RFC-010 — Reporting and Maintenance contracts.** Specifies `ReportingDriver` (read-only, cross-Entity, Policy- and Tenant-aware) and the `MaintenanceAction` category (declaration, listing, audit tagging, Workflow-guard semantics). Until this RFC lands, V1 ships with neither, and legitimate use cases use direct Actions.
10. **RFC-011 — DSL surface.** The exact DSL surface (method names, fluent shape, builder ergonomics) operating inside the invariants of §5.8. The example in the brief (`Entity::make('Invoice')->fields()->relations()->...`) is illustrative, not normative.
11. **Laravel version support window.** How many concurrent Laravel majors do we support? Likely the current and the previous LTS. To be confirmed.

---

## 12. Acceptance criteria for this RFC

This RFC is accepted when:

- The four roles (architect, domain, kernel, challenger) sign off on §1, §2 (including §2.1.1 and §2.1.2), §3, §5.8, §8, and §9.
- The follow-up RFCs in §11 are scheduled, with RFC-002, RFC-003, RFC-004, RFC-006, RFC-010, and RFC-011 prioritized as load-bearing for V1.
- A concrete plugin-author scenario is walked end-to-end against this RFC and produces no contradictions (the walk-through should be appended as an appendix).
- The Compatibility Matrix format (§6.4) is sketched.
- Appendices A, B, and C are re-run before each subsequent draft of this RFC and any contradictions, terminology drift, or layer-boundary violations are resolved before publication.

Once accepted, any contradiction with this RFC in a future RFC requires either an amendment to this document or an explicit "supersedes" declaration.

---

## Appendix A — Contradiction scan

**Methodology.** Walk every pair of sections (§N, §M) and verify that statements in one do not contradict statements in the other. Particular attention to the boundary pairs: §1.1 (kernel owns) vs §1.2 (out of scope); §2.x internal cross-references; §8 (security) vs §10.5 (challenger weaknesses); §11 (open questions) vs §12 (acceptance criteria); and every reference to §5.8 (DSL invariants), §2.1.1 (identity), §2.1.2 (tenant binding), §2.4.1 (MaintenanceAction).

**Findings.**

| ID    | Description | Status |
|-------|-------------|--------|
| A-01  | §10.5.3 (mitigation via `ReportingDriver` + `MaintenanceAction`) depends on RFC-010 (§11.9) landing. §12 lists RFC-010 in V1 priority; §10.5.3 explicitly states the mitigation is contingent on this. | Dependency tracked. Not a contradiction. |
| A-02  | §2.4.1 (MaintenanceAction may bypass Workflow guards) interacts with §8.2 (Workflow guards in the standard chain). §8.2.3 is written to call out the MaintenanceAction exception explicitly. | Consistent. |
| A-03  | §2.1.2.1 (Tenant binding immutable; move = delete + recreate under audit) interacts with §8.1 (cross-Tenant elevation via `Ausus::elevate(...)`). §8.1 now clarifies elevation grants temporary access in another Tenant for the duration of a call and is distinct from instance transfer. | Consistent post-clarification. |
| A-04  | §5.8.4 (no I/O at definition time) interacts with §6.3 (`install()`, `upgrade()` run migrations). §6.3 now explicitly notes these hooks run outside the DSL/registration phase and are exempt from §5.8. | Consistent post-clarification. |
| A-05  | §3.2.5 (no direct Eloquent imports by plugins) interacts with §1.2 (no SQL execution in Kernel). Persistence drivers may use Eloquent internally; the constraint is on *plugins*, not on Persistence Drivers themselves. | Consistent. |
| A-06  | §11.10 (RFC-011 DSL surface) was not in §12's V1 priority list, but a V1 ship requires a published DSL surface. | Gap closed: §12 now includes RFC-011 in the V1 priority list. |
| A-07  | §1.2 forbids "privileged bypass APIs"; §9.13 restates this; §8.4 documents the rejection. No remaining named or unnamed bypass API survives in the document. | Consistent. |
| A-08  | §2.7 declares Projection is "not a UI concept"; §1.2 places ViewSchemas in L5; §4.5 describes the L5 generation step. The §1.2/§2.7 contradiction present in Draft-01 is resolved. | Resolved. |

**Result.** No logical contradictions. One ship-blocking dependency (A-06) promoted to acceptance criteria.

---

## Appendix B — Terminology consistency scan

**Methodology.** Enumerate every load-bearing term in the document and verify it appears in exactly one form throughout, with a defined meaning that does not drift between sections. Where two terms are intentionally distinct (e.g. `Projection` vs `Presentation`), verify they are never used interchangeably.

**Terms checked.**

| Term | Expected use | Result |
|------|--------------|--------|
| `View` (standalone, as a kernel primitive) | MUST NOT appear. | Zero occurrences. The only permitted form is `ViewSchema`. |
| `ViewSchema` | One word, refers to the presentation wire format emitted by L5. | Consistent. No `View Schema` (spaced) occurrences. |
| `Projection` | Kernel primitive (domain intent). Capitalized. | Consistent. Never used to refer to a layer. |
| `Presentation layer` / L5 | The layer that turns Projections + UI hints into ViewSchemas. | Consistent. Never abbreviated to "Projection layer." |
| `MaintenanceAction` | Sub-category of Action; may skip Workflow guards only. | Consistent. Always written as one word. |
| `ReportingDriver` | L0 contract; read-only, Policy- and Tenant-aware. | Consistent in prose. The L3 diagram in §3.1 uses the spaced form "Reporting Driver(s)" for visual parallelism with "Persistence Driver(s)" — acceptable convention. |
| Privileged bypass API by name | MUST NOT appear. | The historical bypass API name has been removed from §1.2, §8.4, and §9.13; only conceptual phrasing remains. |
| `Field`, `Fields` | Typed attribute(s) of an Entity. | Consistent. |
| `Tenant`, `Tenant Context`, `tenant_id` | Distinct: isolation boundary, ambient per-request context, discriminator value. | Consistent. |
| `FQN` | Fully Qualified Name; `namespace.name` form. | Consistent from §2.1 onward. |
| `Audit Entry`, `Audit Spine`, `Audit sink` | Distinct: a single record, the kernel-enforced contract, a pluggable destination. | Consistent. |
| `Plugin`, `PluginManifest`, `PluginLifecycle` | Distinct: the bundle, its declaration, its hooks. | Consistent. |
| `Compiler`, `Resolver`, `Registry` | Three distinct components, each named exactly once in §1.1 and used consistently. | Consistent. |
| `Action` vs `MaintenanceAction` | Sub-category relationship explicit in §2.4.1; MaintenanceActions are Actions. | Consistent. |
| `Policy` vs `Role` | Policy is a kernel primitive; Role is explicitly *not* (§8.2, §9.11). | Consistent. |

**Result.** Terminology consistent. No drift; no leftover Draft-01 vocabulary.

---

## Appendix C — Layer boundary scan

**Methodology.** Verify that every reference between layers respects §3.2 ("a lower layer must never import from a higher layer"), and that no layer is granted a sideways bypass that lets it skip an intermediate layer's invariants (notably the Runtime's enforcement of Tenant Context, Policies, and Audit).

**Layers and their declared inbound dependencies** (per §3.1, §3.2):

| Layer | May depend on |
|-------|---------------|
| L0 Kernel | PHP, Laravel contracts |
| L1 Compiler | L0 |
| L2 Runtime | L0, L1 |
| L3 Persistence / Authorization / Tenancy / Reporting | L0 |
| L4 API Surface | L0, L1, L2, L3 |
| L5 Presentation | L0, L2 |
| L6 Renderer | ViewSchema wire format only |
| L7 Domain Plugins | L0 + declared plugin peers |

**Findings.**

| ID    | Description | Status |
|-------|-------------|--------|
| C-01  | §3.2.2 forbids plugins from depending on Runtime / Compiler / API Surface / Presentation. | Rule explicit. |
| C-02  | §3.2.3 limits the Renderer to the ViewSchema wire format. | Rule explicit. |
| C-03  | §3.2.5 forbids direct Eloquent imports by plugins. | Rule explicit. |
| C-04  | §3.2.6 limits Reporting Drivers to read-only and requires Field-level Policy + Tenant scope enforcement. | Rule explicit. |
| C-05  | **Gap surfaced (pre-resolution):** L4 (API Surface) was not explicitly forbidden from bypassing L2 (Runtime) to call L3 (Persistence) directly. Such a bypass would silently disable Tenant Context, Policy, and Audit on the request path. | Resolved: new rule §3.2.8 requires L4 to invoke domain operations only through the Runtime. |
| C-06  | L5 (Presentation) reaches into L2 (Runtime) for Resolver and Tenant Context. Direction is downward, consistent with §3.2.1. | Consistent. |
| C-07  | L7 plugins MAY contribute L3 implementations (a Persistence Driver plugin, a Reporting Driver plugin). The dependency direction remains plugin → L0 contracts; the contributed code, once bound, runs at L3. | Consistent; documented in §2.8. |
| C-08  | L6 (Renderer) invokes Actions via L4 (API Surface), not via L2 directly. §4.5 explicitly states this. | Consistent. |
| C-09  | The Kernel (L0) does not import from any other layer. §1.1 and §3.2.4 confirm. | Consistent. |
| C-10  | No layer at any level is permitted to short-circuit the Audit Spine (§7 of kernel ownership; §8.3). The combination of §3.2.8 and §1.2's privileged-bypass exclusion closes every architectural route for doing so. | Consistent. |

**Result.** One boundary gap closed (C-05); no remaining violations.

---

## Appendix D — Plugin Author Walkthrough (`ausus/billing`)

**Purpose.** Mentally execute RFC-001 end-to-end through the lifecycle of a single, plausible plugin (`ausus/billing`). The purpose is **not** to demonstrate that the kernel works — it is to find statements in RFC-001 that fail to specify behaviour, contradict each other under realistic execution, or rest on undefined primitives. Findings are aggregated in §D.17.

**Scenario.** The plugin `ausus/billing` declares one Entity (`billing.invoice`) with Fields, Relations, Actions, Policies, one Workflow, and three Projections. A SaaS tenant `acme` installs it, customizes one Field via Tenant override, invokes Actions through the API, renders a list view in React, runs a MaintenanceAction to recompute balances, and executes a cross-Entity report.

**Roles in this walkthrough.** Architect (layering/contracts), domain (business correctness), kernel (invariants), challenger (failure analysis). Where a step exposes a finding, the responsible role is indicated.

DSL syntax in §D.3 is illustrative; RFC-011 has not yet specified the surface. Per §5.8, the syntax shown obeys the seven DSL invariants regardless of final shape.

---

### D.1 Plugin manifest

**Scenario.** `ausus/billing` v1.0.0 is declared as a Composer package.

**Expected input.**
```json
{
  "name": "ausus/billing",
  "type": "library",
  "require": {
    "php": "^8.3",
    "ausus/kernel": "^1.0"
  },
  "extra": {
    "ausus": {
      "name": "ausus/billing",
      "version": "1.0.0",
      "kernel": "^1.0",
      "dependencies": {},
      "provider": "Ausus\\Billing\\BillingPlugin"
    }
  }
}
```

**Kernel contracts touched.** `PluginManifest`.

**Invariants checked.** §6.1 (manifest shape), §6.4 (kernel SemVer range), §3.2.7 (namespace ownership: `billing.*` reserved to this plugin).

**Failure modes.**
- Kernel range incompatible with installed kernel → boot refused (§6.4).
- A second installed plugin also reserves `billing.*` → conflict.
- Provider class does not exist or does not implement `Plugin` + `PluginLifecycle` → boot refused.

**Contradiction scan.**

- **F-D1 (architect).** §3.2.7 reserves namespaces "by the plugin that declares them in its manifest." RFC-001 does not specify **when** this reservation is enforced: at plugin discovery (§6.2), at plugin install (§6.3.`install()`), or at compile time (§4.2.3). The three timings imply different failure shapes (refuse to load vs refuse to install vs refuse to compile). Unspecified.
- **F-D2 (architect).** `ausus/billing` needs *some* `PersistenceDriver` implementation to be useful. §6.1's `dependencies` block accepts named plugins. There is no mechanism for declaring a dependency on a **contract** (any plugin satisfying `PersistenceDriver`). The plugin must either name a specific driver (coupling) or assume one is installed (silent failure if not). Unspecified.
- **F-D3 (kernel).** `ausus/billing` will use Field Types (`string`, `money`, `date`, `enum`) declared by a Field Types plugin. §1.2 places "concrete Field Types" in first-party plugins, but §6.1 has no syntax for declaring a dependency on a specific Field Type. A missing Field Type would surface only at compile time as a dangling reference.

---

### D.2 Provider registration

**Scenario.** `BillingPlugin` is bound during Laravel boot.

**Expected input.** `BillingPlugin implements Plugin, PluginLifecycle`. Registered either through `extra.laravel.providers` (Laravel auto-discovery) or through the AUSUS plugin-discovery scan of `extra.ausus`.

**Kernel contracts touched.** `Plugin`, `PluginLifecycle`.

**Invariants checked.** §5.1 (each plugin ships a service provider), §6.1 (provider implements both interfaces), §6.3 (`boot()` is registration-only, subject to §5.8 invariants).

**Failure modes.**
- Plugin's `boot()` performs I/O → §5.8.4 violation; behaviour at runtime: undefined (no enforcement mechanism specified in RFC-001).
- Plugin registered twice (once via Laravel auto-discovery, once via AUSUS discovery) → §5.8.7 covers double-registration of descriptors, but not double-registration of the plugin itself.

**Contradiction scan.**

- **F-D4 (architect).** §6.1 names a `provider` that "must implement `Plugin` and `PluginLifecycle`." §5.1 says "each Plugin ships its own service provider, which calls into the Registry during `register()`." These describe two different objects with overlapping responsibilities: an AUSUS `Plugin` class (kernel contract) and a Laravel `ServiceProvider` class (framework class). RFC-001 does not state whether these are the same class, related classes, or independent. A plugin author cannot know whether to write one class or two.
- **F-D5 (kernel).** §5.8 invariants apply to "the DSL" and to `boot()` per §6.3. A plugin's Laravel `ServiceProvider::register()` may also perform binding work. RFC-001 does not state whether §5.8 applies to the Laravel provider's `register()` method or only to AUSUS-level `boot()`. Ambiguous.

---

### D.3 DSL declaration

**Scenario.** `BillingPlugin::boot()` declares `billing.invoice` and its dependents.

**Expected input (illustrative — RFC-011 will fix syntax).**
```php
Entity::make('billing.invoice')
  ->fields([
    Field::id('id'),                                    // identity handle
    Field::system('tenant_id'),
    Field::string('number')->uniqueWithinTenant(),
    Field::reference('customer_id', 'billing.customer'),
    Field::enum('status', ['draft','issued','paid','void'])->default('draft'),
    Field::money('amount_due')->currency('currency'),
    Field::enum('currency', ['USD','EUR','GBP']),
    Field::datetime('issued_at')->nullable(),
    Field::datetime('due_at')->nullable(),
    Field::timestamps(),
  ])
  ->relations([
    Relation::manyToOne('customer', 'billing.customer'),
    Relation::oneToMany('lines',    'billing.invoice_line')->cascade('delete'),
    Relation::oneToMany('payments', 'billing.payment'),
  ])
  ->actions([
    Action::make('create')    ->policy('billing.invoice.create'),
    Action::make('update')    ->policy('billing.invoice.edit_draft'),
    Action::make('issue')     ->policy('billing.invoice.issue'),
    Action::make('mark_paid') ->policy('billing.invoice.mark_paid'),
    Action::make('void')      ->policy('billing.invoice.void'),
  ])
  ->policies([
    Policy::make('billing.invoice.view'),
    Policy::make('billing.invoice.create'),
    Policy::make('billing.invoice.edit_draft'),
    Policy::make('billing.invoice.issue'),
    Policy::make('billing.invoice.mark_paid'),
    Policy::make('billing.invoice.void'),
  ])
  ->workflows([
    Workflow::make('billing.invoice.lifecycle')
      ->states(['draft','issued','paid','void'])
      ->transition('draft',  'issued', via: 'issue',     guard: 'billing.invoice.issue')
      ->transition('issued', 'paid',   via: 'mark_paid', guard: 'billing.invoice.mark_paid')
      ->transition('draft',  'void',   via: 'void',      guard: 'billing.invoice.void')
      ->transition('issued', 'void',   via: 'void',      guard: 'billing.invoice.void'),
  ])
  ->projections([
    Projection::make('list')
      ->fields(['id','number','customer_id','status','amount_due','currency','due_at'])
      ->actions(['create','void'])
      ->filters(['status','customer_id','due_at:range'])
      ->policy('billing.invoice.view'),
    Projection::make('detail')
      ->fields('*')
      ->actions(['update','issue','mark_paid','void'])
      ->policy('billing.invoice.view'),
    Projection::make('edit')
      ->fields(['number','customer_id'])
      ->actions(['update'])
      ->policy('billing.invoice.edit_draft'),
  ]);
```

**Kernel contracts touched.** `EntityDescriptor`, `FieldDescriptor`, `FieldType`, `RelationDescriptor`, `ActionDescriptor`, `PolicyDescriptor`, `WorkflowDescriptor`, `ProjectionDescriptor`.

**Invariants checked.** §5.8.1 (purity), §5.8.2 (serializability), §5.8.3 (determinism), §5.8.4 (no I/O), §5.8.5 (no domain logic), §5.8.6 (declarative composition), §5.8.7 (idempotent registration). §2.1.1 (identity handle declared via `Field::id`). §2.1.2.2 (tenant binding implicit via Entity's tenant-scoped default).

**Failure modes.**
- `Field::money('amount_due')->currency('currency')` requires a runtime-resolvable companion field. If declared as a closure, violates §5.8.6. If declared as an FQN reference, acceptable.
- `Field::datetime('due_at')->default(now()->addDays(30))` would evaluate `now()` at definition time, violating §5.8.3 (determinism). The DSL must accept defaults as deferred expressions, not values. Not addressed in RFC-001.
- Workflow transition from `draft` to `void` is duplicated for `issued` → `void`. If the DSL deduplicates, it must do so deterministically.

**Contradiction scan.**

- **F-D6 (domain).** §2.2 declares Fields carry "validation rules, default values, nullability, uniqueness." §5.8.5 forbids "validation, computation, and authorization" inside `->fields()`. RFC-001 does not draw the line between *declaring* a validation rule (allowed) and *executing* it at definition time (forbidden). For `uniqueWithinTenant`, the declaration is acceptable; uniqueness check must happen at Action time. RFC-001 does not name where this check runs.
- **F-D7 (kernel).** Fields can carry **default values**. A literal default (`'draft'`) is deterministic and serializable. A computed default (`now()`, `Uuid::v7()`) is neither. §5.8.3 implies the latter is forbidden at definition time, but the legitimate need to express "default = current time at row creation" is not addressed. The DSL must offer a deferred-expression representation; RFC-001 does not specify what shape it takes (string DSL? sentinel object? marker function?).
- **F-D8 (kernel).** `Projection::make('list')->policy('billing.invoice.view')` attaches a single Policy to a Projection. §2.7 says a Projection's descriptor carries "the Policy chain that governs its visibility." Singular Policy or chain? Consistent if the chain is constructed by combining the Projection's declared Policy with Entity-scoped, Tenant-scoped, and global Policies at runtime. Not stated.
- **F-D9 (kernel, significant).** §2.7 governs Projection-level visibility. §4.5 talks about "evaluating Actor-visible Fields via **Field-level Policies**." §3.2.6 requires `ReportingDriver` to enforce **Field-level Policies**. But §2.2 (Field) does **not** list Policy as a Field attribute. Field-level Policies are referenced in three places (§4.5, §3.2.6, §1.1.9) without being defined as a primitive in §2. **Real gap — possibly a contradiction.** A plugin author cannot declare Field-level Policies because the descriptor has no slot for them.

---

### D.4 Registry registration

**Scenario.** All descriptors emitted by §D.3 are added to the in-memory Registry.

**Kernel contracts touched.** `Registry`.

**Invariants checked.** §4.1 (registration cheap, order-independent, idempotent), §5.8.7 (idempotent registration with conflict detection).

**Failure modes.**
- A second plugin registers a descriptor under `billing.invoice` → namespace collision; per §3.2.7 forbidden. Surfaced when?
- The same descriptor registered twice with conflicting payload → error reported by Compiler (§5.8.7), not by Registry.

**Contradiction scan.**

- **F-D10 (kernel).** §4.1 says "registration is cheap and safe to repeat (idempotent)." §5.8.7 says "double registration with conflicting content is an error reported by the Compiler." Consistent for the happy case. Unspecified: does the Registry deduplicate identical re-registrations silently, or store both and let the Compiler reject? The choice affects boot performance under multi-process reloads.
- **F-D11 (architect).** §4.1 places registration in Laravel's boot phase. Laravel's boot runs once per request in non-octane setups and once per worker in octane/swoole. RFC-001 assumes the latter (long-lived process, expensive boot amortized). Behaviour under per-request boot is not addressed.

---

### D.5 Compiler snapshot

**Scenario.** `php artisan ausus:compile` (CI) snapshots the Registry.

**Kernel contracts touched.** `Compiler`, `Registry`, `MetadataGraph`.

**Invariants checked.** §4.2.1 (snapshot), §4.2 (determinism).

**Failure modes.**
- Registry mutated mid-compile by another plugin's deferred `boot()` → §4.2 implies atomic snapshot, but no mechanism is specified.
- Concurrent compilation (two CI jobs, two workers) → both produce the same hash (good), but race on cache write (see §D.8).

**Contradiction scan.**

- **F-D12 (kernel).** §4.2 calls compilation "deterministic." For determinism to hold, the iteration order over the Registry must be deterministic across runs. RFC-001 does not require the Registry to preserve insertion order or to sort by FQN. Two compilations of the same descriptor set could produce different intermediate orderings; the hash computation must therefore canonicalize. Unspecified.

---

### D.6 Cross-reference resolution

**Scenario.** Compiler walks every descriptor and resolves references.

References to resolve in `ausus/billing`:
- `Field::reference('customer_id', 'billing.customer')` → must resolve to Entity `billing.customer`.
- `Relation::manyToOne('customer', 'billing.customer')` → idem.
- `Action::make('issue')->policy('billing.invoice.issue')` → resolve Policy.
- Workflow transition `via: 'issue'` → resolve Action.
- Workflow transition `guard: 'billing.invoice.issue'` → resolve Policy.
- Projection `actions(['create','void'])` → resolve Actions on owning Entity.
- Projection `fields([...])` → resolve Fields on owning Entity.

**Kernel contracts touched.** `Compiler`, `MetadataGraph`.

**Invariants checked.** §4.2.2 (resolves cross-references), §4.2.3 (validates).

**Failure modes.**
- `billing.customer` does not exist (plugin not installed) → dangling reference, fail compile.
- Workflow references Action `issue` not declared on the Entity → fail compile.
- Projection exposes `tenant_id` which is system-managed → allowed or refused? Unspecified.
- Cascading cycle in Relations of forbidden kind → fail compile per §4.2.3.

**Contradiction scan.**

- **F-D13 (domain).** Projection `list` exposes `customer_id` but not `customer` (the resolved Relation). A consumer cannot render the customer's display name without an extra round-trip. RFC-001 does not specify whether Projections must, may, or must not include Relation targets. Up to the plugin author. Not a contradiction; flagged as a usability cliff.
- **F-D14 (architect).** §4.2.3 lists validation checks but does not include "Policy referenced by an Action exists." Implicit in "dangling references" — but not enumerated. Implementation will catch it; the spec under-promises.

---

### D.7 Graph hashing

**Scenario.** The Compiler computes a deterministic hash over `(kernel version, plugin manifest hash, descriptor contents)`.

**Kernel contracts touched.** `Compiler`.

**Invariants checked.** §4.2.5 (hash inputs), §7.1 (artifact keyed by `(kernel version, plugin manifest hash)`).

**Failure modes.**
- Two installations with the same plugins at the same versions produce different hashes due to non-canonical descriptor serialization → cache thrash, identical artifacts not shared.
- Patch-level upgrade of a peer plugin (e.g. `ausus/accounting` 1.0.0 → 1.0.1) changes the manifest hash → entire graph cache invalidated even though no descriptor changed.

**Contradiction scan.**

- **F-D15 (architect).** §4.2.5 says the hash is derived from "the plugin manifest + kernel version + descriptor contents." §7.1 says one artifact per `(kernel version, plugin manifest hash)` — descriptor contents are not in the key. These two statements describe different keys. If the §7.1 key is correct, a plugin patch with no descriptor change invalidates the cache (expected). If §4.2.5 is correct, descriptor changes that don't change a manifest version still invalidate the cache (also expected). The two are reconcilable only by interpreting "plugin manifest hash" in §7.1 as covering descriptors transitively — but that conflates manifest (Composer-level) with descriptor (runtime-level). Mild inconsistency.

---

### D.8 Cache generation

**Scenario.** The compiled `MetadataGraph` is serialized and written to `storage/framework/ausus/graph.{hash}.php`.

**Kernel contracts touched.** Cache driver (Laravel contract).

**Invariants checked.** §4.3 (filesystem default, read-only at runtime), §7.3 (on error, previous cache survives, build fails).

**Failure modes.**
- Disk full → cache write fails. RFC-001 §7.3 covers compile-time errors but not cache-write errors.
- Two CI jobs writing the same hash concurrently → file race.
- Permissions wrong → cache write fails silently if exceptions are swallowed.

**Contradiction scan.**

- **F-D16 (kernel).** §7.3 says "the previous cached graph remains in place and the build fails." This addresses Compiler errors. A successful compile followed by a failed cache write is a distinct case; RFC-001 does not specify behaviour. The build either succeeds (with no cache, so the next boot will JIT — but §4.3 says production refuses to JIT) or fails (correct, but unstated).
- **F-D17 (architect).** §4.3 does not specify cache-write atomicity. Two compilations racing on the same path could produce a truncated file readable by a third process. Implementation-level concern, but the invariant "compiled cache is always valid or absent" should be stated.

---

### D.9 Runtime resolution

**Scenario.** A request enters the application; the Resolver loads the compiled graph (once per process) and serves lookups.

**Expected calls.**
- `Ausus::entity('billing.invoice')` → `EntityDescriptor`.
- `Ausus::action('billing.invoice.issue')` → `ActionDescriptor`.
- `Ausus::projection('billing.invoice.list')` → `ProjectionDescriptor`.

**Kernel contracts touched.** `Resolver`, `MetadataGraph`, `TenantContext`.

**Invariants checked.** §4.4 (FQN lookup; Tenant overrides at resolution time), §8.1 (Tenant Context required when `strict_tenant=true`).

**Failure modes.**
- FQN not in graph → exception.
- Resolution called outside Tenant Context with `strict_tenant=true` → forbidden; behaviour: exception (implied, not stated).
- Tenant override invalidates a Projection (e.g. removes a Field the Projection exposes) → unspecified.

**Contradiction scan.**

- **F-D18 (kernel, significant).** §4.4 says Tenant overrides for "Fields, Policies, Workflows, Projections" apply at resolution time. RFC-001 does not specify the **scope** of overrides: may a Tenant add a Field? Remove a Field? Change a Field's type? Remove a Projection's exposed Field? The Compiler's coherence validation (§4.2.3) runs at compile time against the base graph. A Tenant override can produce a per-Tenant graph state that the Compiler never validated. RFC-001 does not say who re-validates or whether overrides are constrained to "safe" mutations. A real gap that affects every override use case.
- **F-D19 (kernel).** §4.4 says "Tenant overrides are layered at resolution time, not compile time." Where do they live? Database table managed by the Tenancy plugin? Per-Tenant config file? In-memory cache? Not specified. Deferred to RFC-003, presumably — but not labelled as such.

---

### D.10 API invocation

**Scenario.** `POST /api/billing/invoices/{id}/issue` invokes Action `billing.invoice.issue`.

**Expected input.** HTTP request with Actor (resolved by auth plugin), Tenant (resolved by `TenantResolver`), Subject (the invoice's identity handle from `{id}`), inputs (`due_at`).

**Kernel contracts touched.** API Surface (L4), `TenantContext`, `Resolver`, Action invocation path, Policy chain, Workflow guard, `Auditor`.

**Invariants checked.** §3.2.8 (L4 invokes through Runtime only), §8.1 (Tenant), §8.2 (Tenant check → Policy chain → Workflow guard), §2.4 (Actions are the only mutation path), §2.1.1.4 (Subject reference is `(tenant_id, entity_fqn, identity_handle)`).

**Failure modes.**
- Unauthenticated → 401, no Action runs.
- Tenant not resolvable → reject.
- Policy denies → 403. **Is the denial audited?** RFC-001 §8.3 audits "every mutating Action" — but a denied Action did not mutate.
- Workflow guard fails (e.g. invoice is already `paid`) → reject.

**Contradiction scan.**

- **F-D20 (architect, significant).** §1.1 lists Resolver, Tenant Context, Audit dispatcher as Runtime-owned. It does **not** name an **Action executor** — the component that, given an Action FQN, an Actor, a Tenant, a Subject, and inputs, runs the Tenant check + Policy chain + Workflow guard + the Action's effect + Audit emission. §8.2 describes the chain but assigns ownership to nobody. The API Surface (L4) must invoke this — through what contract? `Resolver`? A separate `Invoker`? Unspecified.
- **F-D21 (kernel, significant).** §8.3 says "every mutating Action emits an Audit Entry." Denied Actions did not mutate. Real-world compliance often requires auditing *attempts* (denied or not). RFC-001 is silent on denial audit. The challenger reads this as: V1 will either over-audit (every attempt) or under-audit (only successful mutations). Both are choices; the spec must make one.
- **F-D22 (architect).** §3.2.8 forbids L4 from bypassing L2 to call L3. It does **not** forbid L4 from invoking domain operations through an undocumented path (e.g. a Plugin's exported helper function). The rule's enforcement is by code review per §3.2.8. Acceptable but worth noting as a "policy not mechanism" — same governance burden as §6.4 SemVer.

---

### D.11 ViewSchema generation

**Scenario.** React renderer requests `(tenant=acme, actor=user42, entity=billing.invoice, projection=list, locale=en-US)` → ViewSchema JSON.

**Kernel contracts touched.** Presentation layer (L5), `ProjectionDescriptor` (L0), Tenant override layer (L2), Field-level Policy chain.

**Invariants checked.** §4.5 (request shape), §2.7 (Projection has no UI hints in its descriptor), §3.2 (L5 depends on L0+L2 only).

**Failure modes.**
- Projection not found → 404.
- Actor lacks Projection-level Policy → 403.
- Actor lacks read access to one of the exposed Fields → field omitted, or 403, or schema marked with capability flags? Unspecified.
- Tenant override removes a Field the Projection exposes → ViewSchema invalid? Empty cell? Unspecified.

**Contradiction scan.**

- **F-D23 (kernel, significant).** §4.5 says Presentation "applies UI hints" but §2.2 places UI hints as "a distinct sub-descriptor" of Field. RFC-001 does not state **where the UI hints descriptor is declared** (separate `->hints()` call on Field? Separate `UiHints::make()` block? Separate file?) nor **where it lives in the graph** (attached to Field? Attached to Projection? Attached to Tenant?). RFC-011 may address declaration site, but graph-residence is a kernel concern. Unspecified.
- **F-D24 (kernel, significant; restates F-D9).** §4.5 invokes "Field-level Policies." §2.5 defines Policy as `(Actor, Action, Subject, Context)`. What `Action` is passed when evaluating per-Field read visibility? An implicit `read` Action per Field? An attribute on the Policy descriptor saying "this Policy gates Field reads"? Not defined.
- **F-D25 (kernel).** §4.5 emits "JSON conforming to the ViewSchema wire format." The wire format is RFC-004's responsibility, but L5's *contract* — what it consumes, what it emits — is part of the Kernel boundary. RFC-001 sketches it but does not name a `PresentationDriver` contract on the L0 contracts list (§5.2). The Presentation layer is implied but not contracted.

---

### D.12 React renderer consumption

**Scenario.** React app fetches the ViewSchema JSON, renders the list. User clicks "Issue" on a draft invoice; the renderer invokes the Action via L4.

**Kernel contracts touched.** ViewSchema wire format (RFC-004), API Surface (L4) Action invocation endpoint.

**Invariants checked.** §3.2.3 (Renderer depends only on ViewSchema wire format), §4.5 (Actions invoked through API Surface).

**Failure modes.**
- Wire format version mismatch → no graceful-degradation contract specified.
- Renderer encounters a UI hint widget type it does not know → fallback unspecified.
- Action invocation fails (Workflow guard, Policy) → error envelope shape not specified at kernel level.

**Contradiction scan.**

- **F-D26 (architect).** §3.2.3 says the Renderer "depends only on the ViewSchema wire format." But §4.5 confirms the Renderer invokes Actions through L4. So its real dependency surface is **ViewSchema wire format + API Surface contract** (the shape of Action invocation requests/responses). §3.2.3 understates this by one contract. The contract for "how Actions are invoked over HTTP" is not named on the L0 contracts list (§5.2); presumably owned by L4 and standardized by an L4 RFC. Worth a follow-up.
- **F-D27 (challenger).** §3.2.3 says the Renderer "never imports backend code." But the Renderer must construct Action-invocation requests whose **shape** is defined by Action descriptors (parameter names, types). Either the wire format embeds enough of the Action descriptor for the Renderer to construct calls (likely — Actions are exposed on Projections per §2.7), or the Renderer must fetch Action shapes separately. The latter creates a second dependency. RFC-001 does not specify which.

---

### D.13 Audit emission

**Scenario.** Action `billing.invoice.issue` succeeded. An Audit Entry is emitted.

**Expected payload.** `(actor=user42, tenant=acme, action=billing.invoice.issue, subject=(acme, billing.invoice, inv_01HXYZ...), inputs={due_at: '2026-06-17'}, outputs={status: 'issued'}, timestamp, correlation_id, trace_id)`.

**Kernel contracts touched.** `Auditor`, `AuditEntry`, `AuditSink`.

**Invariants checked.** §8.3 (kernel-enforced emission, append-only sinks, redaction rules), §2.1.1.4 (canonical Subject reference).

**Failure modes.**
- Sink unreachable → **does the Action succeed or fail?** Unspecified.
- Sink writes but later loses the entry → durability contract unspecified.
- A redacted input is needed for downstream replay → unrecoverable.

**Contradiction scan.**

- **F-D28 (kernel, significant).** §8.3 enforces emission. RFC-001 does not specify **what happens if emission fails**. Fail-closed (no audit → no mutation, transaction rolled back) and fail-open (mutate, queue audit for retry) are both legitimate. The choice is a compliance decision; V1 must pick. Not specified.
- **F-D29 (architect).** §8.3 supports multiple sinks running in parallel. If sink A succeeds and sink B fails, what is the result? Unspecified.
- **F-D30 (kernel).** §5.4 declares `audit.redact: []` as a **global** pattern list. §2.4 (Action) does not name a per-Action redaction. A `billing.invoice.create` Action whose inputs include a sensitive `notes` field cannot redact only its own `notes` — it must add `notes` to the global list, affecting every Action. Granularity gap.

---

### D.14 Tenant override

**Scenario.** Tenant `acme` requires a custom field `acme.external_ref` on `billing.invoice` and customizes the `billing.invoice.void` Policy to require dual approval.

**Expected mechanism.** The Tenancy plugin stores a per-Tenant override descriptor; the Resolver merges it onto the base descriptor at resolution time.

**Kernel contracts touched.** `Resolver`, `TenantContext`, `TenantIsolationStrategy`, override storage (unnamed in RFC-001).

**Invariants checked.** §4.4 (per-Tenant overrides at resolution time), §7.2 (lazy override application).

**Failure modes.**
- Override adds a Field whose Field Type is not installed → resolution-time dangling reference.
- Override removes a Field exposed by a Projection → Projection invalid for this Tenant.
- Override conflicts with a Workflow guard (e.g. removes the Field that the guard reads) → undefined.
- Override permits a transition the base Workflow forbids → undefined.

**Contradiction scan.**

- **F-D31 (kernel, significant; restates F-D18).** RFC-001 does not constrain what a Tenant override may modify, does not specify who validates the merged per-Tenant graph, and does not specify the consequences of an override that breaks Projection or Workflow coherence. The challenger reads §4.4's "tweaked per Tenant" as load-bearing language that is not backed by a contract.
- **F-D32 (architect).** Override storage is implied (must exist somewhere) but not located in any layer. The Tenancy plugin (L3) is the natural owner; RFC-001 does not say so explicitly.
- **F-D33 (domain).** A SaaS use case where Tenant `acme` adds a Field is common; one where Tenant `acme` removes a Field declared by the plugin author is rare but possible. The two cases have different security implications (adding is local; removing changes the platform's promise to other consumers like APIs and reports). RFC-001 does not distinguish.

---

### D.15 MaintenanceAction execution

**Scenario.** `billing.invoice.recompute_balances` is a MaintenanceAction that recalculates `amount_due` for every `issued` invoice in the active Tenant (10,000 rows for `acme`).

**Kernel contracts touched.** Action (sub-category `MaintenanceAction`), Policy chain, Tenant Context, Audit Spine.

**Invariants checked.** §2.4.1 (MAY bypass Workflow guards; MUST NOT bypass Tenant / Policy / Audit), §8.3 (Audit Entry tagged with affected count).

**Failure modes.**
- Long-running (minutes/hours) → no timeout contract, no progress contract, no chunking contract.
- Mid-run crash → atomicity unspecified. Did 4,000 rows get updated and 6,000 not?
- Audit count vs per-Subject audit detail → §2.4.1 / §8.3 say "count of affected Subjects." Compliance frameworks frequently require per-Subject detail for material updates.

**Contradiction scan.**

- **F-D34 (kernel, significant — direct contradiction).** §8.3 specifies the Audit Entry shape as `(Actor, Tenant, Action FQN, canonical Subject reference per §2.1.1.4, Inputs, Outputs, ...)`. The "canonical Subject reference" is a singleton tuple. §2.4.1 / §8.3 say a MaintenanceAction emits an entry with the **count** of affected Subjects. A count is not a Subject reference. The two statements describe incompatible shapes. RFC-001 does not reconcile: is the Subject field nullable for MaintenanceActions? Replaced by an array? Augmented with a count? Direct contradiction.
- **F-D35 (architect).** §2.4.1 says a MaintenanceAction "may transition many instances across many Workflow states." If it transitions some but not all (mid-run crash, transient failures per Subject), what is the partial result? RFC-001 does not say.
- **F-D36 (domain).** A MaintenanceAction's Policy chain runs **once per invocation** (per §8.2 the chain runs on the Action). For 10,000 affected invoices, is the Actor checked once (efficient, but allows access escalation per-Subject) or per Subject (correct, but expensive)? Unspecified.

---

### D.16 ReportingDriver query

**Scenario.** A report joining `billing.invoice` and `crm.account` to compute outstanding balance per account, scoped to the active Tenant.

**Kernel contracts touched.** `ReportingDriver` (L0 contract; L3 implementation), Policy chain (Field-level), Tenant Context.

**Invariants checked.** §1.1.9 (Field-level Policies + Tenant scope), §3.2.6 (read-only; must enforce Field-level Policies), §8.1 (Tenant binding).

**Failure modes.**
- Query attempts mutation → driver rejects.
- Query crosses Tenants without explicit elevation → driver rejects.
- Query exposes a Field the Actor cannot read → filtered, denied, or partial result? Unspecified.
- Reporting Driver does not exist at runtime → V1 use cases must fall back to direct Actions (per §11.9 caveat).

**Contradiction scan.**

- **F-D37 (kernel, significant; restates F-D9 and F-D24).** §3.2.6 requires ReportingDrivers to enforce "Field-level Policies." Field-level Policies are not a defined primitive (§2.2 has no Policy slot on Field). The ReportingDriver contract cannot be authored against a primitive that does not exist in the kernel.
- **F-D38 (architect).** §11.9 says "Until RFC-010 lands, V1 ships with neither" of ReportingDriver / MaintenanceAction. §12 lists RFC-010 in V1 priority. So either RFC-010 lands before V1 (acceptance gate), or V1 ships without two of the mitigations §10.5.3 depends on. The dependency is tracked but warrants visibility.
- **F-D39 (domain).** Cross-Entity reports often need to **join** across plugins (`billing.invoice` and `crm.account` live in different plugins). The ReportingDriver must understand the canonical reference tuple (§2.1.1.4) across plugins. RFC-001 says drivers MUST accept references in this form (§2.1.1.4 last sentence) — covered for **PersistenceDriver**. ReportingDriver is not explicitly bound by the same clause. Mild gap.

---

### D.17 Summary of findings

The walkthrough surfaced 39 findings. Severity is the challenger's reading: **C** = direct contradiction, **G** = gap (RFC-001 silent on a question the walkthrough proves must be answered), **A** = ambiguity (RFC-001 admits two readings), **N** = note (worth recording, not blocking).

| ID    | Step | Severity | Section(s) implicated | Finding |
|-------|------|----------|-----------------------|---------|
| F-D1  | 1    | G        | §3.2.7, §6.2, §6.3, §4.2.3 | Namespace-reservation enforcement timing unspecified. |
| F-D2  | 1    | G        | §6.1 | No syntax for declaring a dependency on a **contract** (e.g. "any PersistenceDriver"). |
| F-D3  | 1    | G        | §6.1 | No syntax for declaring a dependency on a Field Type plugin. |
| F-D4  | 2    | C        | §5.1 vs §6.1 | "Plugin provider" and "Laravel service provider" both exist; relationship unspecified. |
| F-D5  | 2    | A        | §5.8, §6.3 | Scope of §5.8 invariants over Laravel `ServiceProvider::register()` unclear. |
| F-D6  | 3    | A        | §2.2 vs §5.8.5 | "Validation rules" on Field vs "no validation at definition time" — line not drawn. |
| F-D7  | 3    | G        | §5.8.3, §2.2 | No specified representation for deferred default values (`now()`, `Uuid::v7()`). |
| F-D8  | 3    | A        | §2.7 | Single Policy vs Policy chain on Projection — chain construction unspecified. |
| F-D9  | 3    | G/C      | §2.2, §3.2.6, §4.5, §1.1.9 | **Field-level Policies** referenced in three places; never defined as a primitive. Major. |
| F-D10 | 4    | A        | §4.1 vs §5.8.7 | Registry dedup vs Compiler reject for identical re-registrations. |
| F-D11 | 4    | N        | §4.1 | Per-request boot behaviour (non-octane) unaddressed. |
| F-D12 | 5    | G        | §4.2 | Registry iteration order not required to be deterministic; canonicalization unstated. |
| F-D13 | 6    | N        | §2.7 | Projections may expose `customer_id` without `customer` Relation; usability cliff. |
| F-D14 | 6    | N        | §4.2.3 | Validation list under-enumerated (Action→Policy reference not listed). |
| F-D15 | 7    | A        | §4.2.5 vs §7.1 | Hash key includes "descriptor contents" (§4.2.5) but artifact key (§7.1) does not. |
| F-D16 | 8    | G        | §7.3 | Compile-success + cache-write-failure case unspecified. |
| F-D17 | 8    | G        | §4.3 | Cache-write atomicity invariant unstated. |
| F-D18 | 9    | G        | §4.4 | Scope of Tenant overrides undefined (what may be added / removed / changed). |
| F-D19 | 9    | G        | §4.4 | Per-Tenant override storage location not named. |
| F-D20 | 10   | G        | §1.1, §8.2 | **Action executor** component implied but never named or contracted. Major. |
| F-D21 | 10   | G        | §8.3 | Denied-Action audit semantics unspecified. |
| F-D22 | 10   | N        | §3.2.8 | "L4 must invoke through Runtime" is policy not mechanism; relies on code review. |
| F-D23 | 11   | G        | §2.2, §4.5 | UI hints sub-descriptor location in the graph unspecified. |
| F-D24 | 11   | G        | §2.5, §4.5 | Field-level Policy evaluation contract (what `Action` parameter is passed) unspecified. Restates F-D9. |
| F-D25 | 11   | G        | §5.2 | Presentation layer contract not named on the L0 contracts list. |
| F-D26 | 12   | A        | §3.2.3 | Renderer "depends only on ViewSchema wire format" understates by one contract (API Surface). |
| F-D27 | 12   | G        | §3.2.3, §4.5 | Whether the wire format embeds enough of Action descriptors for the Renderer to construct calls is unspecified. |
| F-D28 | 13   | G        | §8.3 | Behaviour on Audit-sink failure (fail-closed vs fail-open) unspecified. Major. |
| F-D29 | 13   | G        | §8.3 | Multi-sink partial-success semantics unspecified. |
| F-D30 | 13   | G        | §5.4, §2.4 | Per-Action redaction not expressible; only global patterns. |
| F-D31 | 14   | G        | §4.4 | Tenant override coherence validation unspecified. Restates F-D18. Major. |
| F-D32 | 14   | N        | §4.4 | Override storage layer not located (implicitly L3 Tenancy plugin). |
| F-D33 | 14   | N        | §4.4 | Add-Field vs Remove-Field override classes have different security implications; undistinguished. |
| F-D34 | 15   | **C**    | §8.3 vs §2.4.1 | Audit Entry shape singular "canonical Subject reference" contradicts MaintenanceAction "count of affected Subjects." Direct contradiction. |
| F-D35 | 15   | G        | §2.4.1 | MaintenanceAction partial-failure semantics unspecified. |
| F-D36 | 15   | G        | §8.2, §2.4.1 | MaintenanceAction Policy-chain evaluation cardinality (once vs per-Subject) unspecified. |
| F-D37 | 16   | G        | §3.2.6 | ReportingDriver bound to enforce a primitive (Field-level Policies) that is not defined. Restates F-D9. |
| F-D38 | 16   | N        | §10.5.3, §11.9, §12 | V1 mitigation depends on RFC-010; tracked as priority but reiterated here. |
| F-D39 | 16   | G        | §2.1.1.4 | ReportingDriver not explicitly required to accept the canonical reference tuple (only PersistenceDriver is). |

**Severity totals.** Contradictions: 2 (F-D4, F-D34). Gaps: 25. Ambiguities: 5. Notes: 7.

**The two direct contradictions.**

1. **F-D4** — the "Plugin provider" of §6.1 and the "Laravel service provider" of §5.1 are described as two distinct objects with overlapping responsibilities, with no statement of their relationship. A plugin author cannot proceed.
2. **F-D34** — §8.3 specifies the Audit Entry payload with a singular "canonical Subject reference per §2.1.1.4." §2.4.1 / §8.3 specify that MaintenanceActions emit entries with "the count of affected Subjects." The two shapes are incompatible. A V1 implementation must pick one and break the other section.

**The two highest-impact gaps.**

1. **F-D9 / F-D24 / F-D37** — "Field-level Policies" are required by §3.2.6, §4.5, and §1.1.9 but never defined as a primitive in §2. The kernel cannot ship `ReportingDriver` enforcement, cannot ship per-Field Presentation filtering, and cannot ship the L5 contract surface without inventing a primitive that RFC-001 does not authorize.
2. **F-D18 / F-D31** — Tenant overrides ("Fields, Policies, Workflows, Projections tweaked per Tenant") are referenced in §4.4 and §7.2 with no constraints on what may be overridden, no specification of override storage, and no contract for re-validating coherence per Tenant. This affects every multi-tenant use case AUSUS exists to serve.

**Recommended disposition.** Per the brief, this appendix proposes no amendments to RFC-001. The findings table is the deliverable. Acceptance of RFC-001 should be conditioned on triage of the 2 contradictions and the 4 "Major" gaps (F-D9, F-D18, F-D20, F-D28) before §12 sign-off, and on tracking the remaining gaps as constraints on RFC-002, RFC-003, RFC-004, RFC-006, RFC-010, and RFC-011.
