# AUSUS Implementation Plan

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Active (skeleton created 2026-05-19)                   |
| Mode          | Implementation (RFCs frozen)                           |
| Governs       | All packages under `packages/`, `renderer/`, `apps/`   |

This document is the engineering counterpart to the frozen RFC stack. It defines the workspace strategy, dependency rules, build order, critical path, and the first executable milestone. The RFCs are the **what**; this document is the **how**.

Hard rule: every decision below traces back to an accepted RFC clause. Where this document deviates from RFC text, the deviation is a finding and the RFC wins.

---

## 1. Composer workspace strategy

### 1.1 Decision

**Path-repository workspace** at the monorepo root.

Composer has no native workspace primitive (unlike npm). The path-repository pattern is the de-facto standard for PHP monorepos and is sufficient for our nine packages.

### 1.2 Mechanism

Root `composer.json` declares each package directory under `repositories[type: path]` and requires them at `@dev`. Running `composer install` at the root symlinks each package into the root `vendor/`. Cross-package edits are picked up without reinstallation.

### 1.3 Package publication

Each package's own `composer.json` is the source of truth for what gets published. The root `composer.json` is NOT published; it exists only for local dev coordination. When a release ships:

1. Each package is tagged independently per its SemVer.
2. Each package is published to packagist via its own repository OR via a satellite (Satis) — TBD; out of first milestone.
3. The starter template is published to packagist for `composer create-project` to work.

### 1.4 Rejected alternatives

- **Monorepo Builder / Shipmonk.** Adds a dependency we don't need yet. Path repositories suffice until cross-package release coordination becomes a felt pain.
- **Single `composer.json` for all sources.** Would force a single PSR-4 namespace and break the package-boundary model. Rejected.
- **Git submodules per package.** Operational overhead; loses atomic refactoring across packages. Rejected.

---

## 2. Package boundaries

### 2.1 Layer-to-package mapping

| Layer | Package(s)                                          | Responsibility (RFC-001 §3.1)                    |
|-------|------------------------------------------------------|---------------------------------------------------|
| L0    | `ausus/kernel`                                       | Contracts, value objects, DSL facade               |
| L1    | (inside `ausus/runtime-default`)                     | Compiler implementation                            |
| L2    | `ausus/runtime-default`                              | Invoker, Policy Engine, Workflow runtime, Effect dispatch |
| L3    | `ausus/persistence-sql`, `ausus/tenancy-row`, `ausus/audit-database`, `ausus/presentation-default` (ReportingDriver half), `ausus/auth-bridge` | Persistence, Tenancy, Audit, Reporting, Authorization |
| L4    | (deferred — minimal HTTP transport ships inside `ausus/presentation-default` and `ausus/runtime-default` for V1 first slice) | API Surface |
| L5    | `ausus/presentation-default` (ViewSchema generator half) | Presentation                                       |
| L6    | `renderer/react` (npm)                               | React renderer                                     |
| L7    | `packages/starter/src/app/Plugins/HelloInvoice` (demo) | Domain plugins                                     |

### 2.2 Hard package rules

1. **`ausus/kernel` is dependency-free of any AUSUS package.** Period. Only depends on PHP itself and Laravel contracts (`illuminate/contracts`).
2. **Every other package may depend ONLY on `ausus/kernel`.** No cross-L3 dependencies. `ausus/persistence-sql` does not import from `ausus/audit-database`; they communicate exclusively via kernel contracts.
3. **`ausus/runtime-default` depends ONLY on `ausus/kernel`.** It does NOT depend on `ausus/persistence-sql` directly — it consumes the `PersistenceDriver` contract from the kernel and the bound implementation from Laravel's container at runtime.
4. **`ausus/standard-stack` is a metapackage.** No source files. Only `require` declarations.
5. **`ausus/starter` is a project template.** Type `project`. Not installed by other packages.
6. **`renderer/react` is a Node package.** No PHP. Out of the Composer workspace.
7. **`apps/playground` is NOT a Composer package.** Not published. Internal CI / dev verification only.

### 2.3 Enforcement

Per-package `composer.json` declares its allowed AUSUS dependencies via `extra.ausus.deps-allowed`. CI runs a script that:

1. Parses each `composer.json`.
2. Verifies `require` references only AUSUS packages in `deps-allowed`.
3. Verifies `require` does NOT include forbidden Laravel packages (e.g., `ausus/kernel` MUST NOT require `illuminate/database`).
4. Fails the build on violation.

This script lives in `apps/playground/ci/check-deps.php` (built in milestone 1).

---

## 3. Dependency direction rules

### 3.1 Graph (Composer dependencies)

```
                        illuminate/contracts
                                ↑
                          ausus/kernel
                                ↑
       +───────────────────────+───────────────────────+
       │            │           │           │          │
ausus/runtime-  ausus/         ausus/    ausus/      ausus/
default         persistence-   tenancy-  audit-      presentation-
                sql            row       database    default
       ↑            ↑           ↑           ↑          ↑
       │            │           │           │          │
       │       ausus/auth-bridge  (independent — same layer)
       │            │           │           │          │
       +────────────┴───────────┴───────────┴──────────+
                                ↑
                         ausus/standard-stack
                                ↑
                          ausus/starter
                                ↑
                         apps/playground
```

Direction: top-down only. Lower layers do not import upper layers. No cycles.

### 3.2 Runtime dependencies (DI container)

At boot, Laravel's service container binds:

```
PersistenceDriver  → SqlPersistenceDriver   (from ausus/persistence-sql)
TenantIsolation    → RowTenantIsolation     (from ausus/tenancy-row)
TenantResolver(*)  → Resolver implementations (from ausus/tenancy-row)
TenantCatalog      → CatalogImpl            (from ausus/tenancy-row)
OverrideStore      → OverrideStoreImpl      (from ausus/tenancy-row)
ReportingDriver    → SqlReportingDriver     (from ausus/presentation-default)
ActorResolver      → ActorResolverImpl      (from ausus/auth-bridge)
Auditor            → AuditorImpl            (from ausus/runtime-default)
AuditSink (primary)→ DatabaseTransactionalSink (from ausus/audit-database)
Invoker            → InvokerImpl            (from ausus/runtime-default)
PolicyEngine       → PolicyEngineImpl       (from ausus/runtime-default)
WorkflowRuntime    → WorkflowRuntimeImpl    (from ausus/runtime-default)
```

`ausus/runtime-default` consumes contracts; concrete implementations bind themselves via their own service providers (Laravel auto-discovery via `extra.laravel.providers`).

### 3.3 Rejected patterns

- **Cross-L3 sibling imports.** `ausus/runtime-default` does NOT `use Ausus\Persistence\Sql\...`. It uses `Ausus\Kernel\Contracts\Persistence\...` and lets the container resolve.
- **Plugin-side imports of L3 internals.** Plugins use `EffectContext::persistence()`, never `Ausus\Persistence\Sql\SqlRepository`.
- **`require` of `laravel/framework`.** Only `illuminate/*` packages. `laravel/framework` is the consumer's choice (in `starter`).

---

## 4. Minimal CI strategy

### 4.1 First-milestone CI

Two GitHub Actions workflows:

**`.github/workflows/php.yml`** (PHP, per-package):
1. Matrix over PHP 8.3 and 8.4.
2. For each package: `composer install`, `composer test`, `composer stan`.
3. Root-level: `composer install`, dependency-direction check (§2.3 script).
4. Apps/playground: SQLite-backed integration test of the executable milestone.

**`.github/workflows/node.yml`** (Node, renderer):
1. Node 20 / 22.
2. `npm ci`, `npm test`, `npm run build`, `npm run lint`.

### 4.2 Deferred CI surface

- Postgres/MySQL matrix beyond SQLite: deferred to milestone 2.
- Package publishing pipelines: deferred to first stable tag.
- Code coverage gates: deferred. Tests must pass; coverage thresholds come after the codebase exists.
- Security scans (Snyk, Composer audit): added after first stable.

### 4.3 Local-dev parity

A `Makefile` at the root mirrors CI:

```
make install       # composer install at root + every package
make test          # phpunit across packages
make stan          # phpstan
make format        # php-cs-fixer
make playground    # boot apps/playground and run smoke test
```

Built during milestone 1.

---

## 5. Versioning strategy

### 5.1 Per RFC-001 §6.4 + RFC-012 §16

- Every package follows SemVer independently.
- `ausus/kernel` major drives ecosystem major. Stack 1.x for kernel 1.x.
- `ausus/standard-stack` requires compatible component ranges; coordinates the meta version.
- Contracts on `ausus/kernel` are SemVer-stable across majors. Breaking a contract requires a kernel major bump.

### 5.2 Pre-V1 versioning

During the implementation phase before the first stable tag, every package versions as `0.x` and the root requires them at `@dev`. After acceptance of the first executable milestone (§9), packages tag `1.0.0-alpha.1` and the meta-package coordinates.

### 5.3 Provisional contracts (RFC-012 §16.5)

Four contracts are provisional even though their RFCs are accepted:

- DSL syntax (RFC-011 — formalized; consumed by `Ausus\` facade in kernel)
- Workflow runtime (RFC-006 — formalized; implemented in `ausus/runtime-default`)
- ActionEffect interface (RFC-013 — formalized; consumed in `ausus/runtime-default`)
- Actor extensions beyond minimum (RFC-014 — formalized; implemented in `ausus/auth-bridge`)

All four are now RFC-accepted (per the spec stack as of 2026-05-18 → 19). The "provisional" label remains operational: when a future amendment lands, the affected package releases a major bump per RFC-012 §16.5 mechanics. Plugin authors targeting V1 accept this exposure.

---

## 6. Coding standards

### 6.1 PHP

- **PSR-12** formatting, enforced by `friendsofphp/php-cs-fixer`.
- **`declare(strict_types=1);`** in every file.
- **PHPStan level 8** (highest) on every package.
- **`final` by default** on every class except where polymorphism is explicitly required (kernel value objects are sealed sums via interfaces; concrete implementations are `final`).
- **No service-locator from inside Policy / Effect / Actor** (RFC-005 §10, RFC-013 §10.4, RFC-014 §14.12). Runtime spy in `ausus/runtime-default` enforces.
- **No closures in descriptor payloads** (RFC-001 §5.8.6). Detected at registration.

### 6.2 PHP namespace conventions

| Package                       | Root namespace                   |
|-------------------------------|----------------------------------|
| `ausus/kernel`                | `Ausus\` (facades) + `Ausus\Kernel\Contracts\` (interfaces) |
| `ausus/runtime-default`       | `Ausus\Runtime\`                 |
| `ausus/persistence-sql`       | `Ausus\Persistence\Sql\`         |
| `ausus/tenancy-row`           | `Ausus\Tenancy\Row\`             |
| `ausus/audit-database`        | `Ausus\Audit\Database\`          |
| `ausus/auth-bridge`           | `Ausus\Auth\Bridge\`             |
| `ausus/presentation-default`  | `Ausus\Presentation\`            |

Plugin-author-facing facades (`Ausus\Plugin`, `Ausus\Dsl`, `Ausus\Field`, `Ausus\Action`, `Ausus\Policy`, `Ausus\Effect`, `Ausus\EffectContext`, `Ausus\Decision`, etc.) all live in `ausus/kernel`'s `Ausus\` root namespace per RFC-011 §4.1.

### 6.3 TypeScript (renderer/react)

- **ESLint** with `eslint-plugin-react`, `@typescript-eslint`.
- **Strict TypeScript** (`"strict": true`).
- **No backend imports.** Lint rule rejects any import from `../packages/` or any AUSUS PHP-side path.
- **React 18+ functional components only.** No class components, no `forwardRef` boilerplate unless required.

### 6.4 Forbidden patterns (universal)

- Abstract base classes with overridable behavior (RFC-005 §14.2, RFC-013 §10.3, RFC-014 §14.13).
- `app()`, `resolve()`, framework facades inside Policy / Effect / Actor classes (detection per §6.1).
- Magic methods (`__call`, `__get`, `__set`) on kernel contract implementations — they obscure the interface.
- Mutable static state.
- Inheritance trees > 1 level in plugin code.

---

## 7. Testing strategy

### 7.1 Layered tests

Three categories, each running per-package and consolidated by `apps/playground` for integration:

1. **Unit tests** — per package, mocking kernel contracts as needed. Fast (< 1s per test).
2. **Conformance tests** — per package, verifying RFC clause-by-clause adherence. Examples:
   - `ausus/persistence-sql`: every "MUST" clause in RFC-002 §3–§13 has one test.
   - `ausus/audit-database`: every test vector in RFC-007 §13 runs; `preservesElevation()` is verified.
   - `ausus/auth-bridge`: every RFC-014 §3.6 test vector runs and matches the locked hex.
3. **Integration tests** — `apps/playground` boots a real Laravel app with the full stack, invokes Actions through the Invoker, asserts audit emission + ViewSchema generation end-to-end.

### 7.2 Test framework choice

- **PHPUnit 11** for PHP. (Pest is appealing but adds a layer; deferred.)
- **Vitest** for TypeScript.
- **No browser-based E2E in V1 first milestone.** ViewSchema render correctness verified by unit tests on the React renderer + integration test on the PHP side asserting the wire-format payload.

### 7.3 Conformance vector packages

Per RFC-014 §12.3 and RFC-002 §19.5: separate Composer packages ship the conformance test vectors. Scoped here:

- `ausus/auth-conformance-tests` — RFC-014 §3.6 vectors.
- `ausus/persistence-conformance-tests` — RFC-002 §19.5 vectors.

Both deferred to milestone 2.

### 7.4 Coverage philosophy

Don't pursue coverage percentage targets. Pursue **clause coverage**: every "MUST" / "MUST NOT" in an RFC has at least one test asserting the behavior. Track via a `docs/conformance-matrix.md` (built in milestone 3).

---

## 8. Package matrix — RFC ownership

| Package                       | Owns RFC(s) — full or partial                                                              | Dependency-free of other AUSUS packages? | Public surface? |
|-------------------------------|---------------------------------------------------------------------------------------------|------------------------------------------|------------------|
| `ausus/kernel`                | RFC-001 (full); RFC-005 §2 (contracts); RFC-013 §2 (contracts); RFC-014 §2 (contracts); contract surfaces of RFC-002, RFC-003, RFC-004, RFC-006, RFC-007, RFC-010 | **Yes** — depends on `illuminate/contracts` only | **Public** (DSL facade + all kernel contracts) |
| `ausus/runtime-default`       | RFC-001 §A-1.4 (Invoker); RFC-005 §3–§13 (Policy Engine); RFC-006 (Workflow runtime); RFC-013 (Effect dispatch); built-in `RoleRequired` / `PermissionRequired` / `RolesRequired` / `CreateEffect` / `TransitionEffect` | **Yes** — depends only on `ausus/kernel`  | **Mostly private** (only the ServiceProvider + Laravel-side commands are public consumption) |
| `ausus/persistence-sql`       | RFC-002 (full)                                                                              | **Yes** — depends only on `ausus/kernel`  | **Mostly private** (only the ServiceProvider; the public surface is the kernel's `PersistenceDriver` contract) |
| `ausus/tenancy-row`           | RFC-003 (row strategy only); `kernel.tenant.*` Actions                                       | **Yes** — depends only on `ausus/kernel`  | **Mostly private** (ServiceProvider + CLI commands for tenant lifecycle) |
| `ausus/audit-database`        | RFC-007 Draft-02 + Amendment-01 (`TransactionalSink` impl)                                  | **Yes** — depends only on `ausus/kernel`  | **Private** (ServiceProvider only) |
| `ausus/auth-bridge`           | RFC-014 (full); built-in `RoleRequired` Policy class implementations                         | **Yes** — depends only on `ausus/kernel`  | **Public** (Actor extensions consumed by plugin authors via `Ausus\` facade) |
| `ausus/presentation-default`  | RFC-004 (full); RFC-010 (full); RFC-011 §8.1 standard Field Types; RFC-004 §10 `react.web.v1` profile registration | **Yes** — depends only on `ausus/kernel`  | **Public** (Field Type fluent builders consumed by DSL) |
| `ausus/standard-stack`        | RFC-012 (full meta-package)                                                                  | **No** — requires all of the above        | **Public** (the install entry point) |
| `ausus/starter`               | RFC-012 §12 (starter template)                                                              | **No** — requires `ausus/standard-stack`  | **Public** (`composer create-project` target) |
| `renderer/react` (npm)        | RFC-004 (renderer-side); RFC-004 §10.2 widget set                                            | n/a (Node)                                | **Public** (npm `@ausus/renderer-react`) |
| `apps/playground`             | None (test harness)                                                                          | n/a                                       | Private (CI / dev only) |

---

## 9. Implementation roadmap

### 9.1 Build order (critical path)

```
[Milestone 1 — "Skeleton compiles + smoke test"]
  1.  ausus/kernel              — contracts, value objects, DSL facade
  2.  ausus/persistence-sql     — SQL PersistenceDriver (SQLite first)
  3.  ausus/tenancy-row         — row isolation + minimal resolvers + catalog
  4.  ausus/audit-database      — TransactionalSink + kernel_audit_log table
  5.  ausus/auth-bridge         — stub mode only
  6.  ausus/runtime-default     — Invoker + Policy Engine + Workflow runtime + Effect dispatch
  7.  ausus/standard-stack      — meta dependencies aligned
  8.  apps/playground           — hand-authored test that invokes one Action end-to-end

[Milestone 2 — "Plugin author can ship Invoice in <30 min"]
  9.  ausus/presentation-default  — ViewSchema generator + react.web.v1 profile + Field Types
  10. renderer/react              — React components for ViewSchema consumption
  11. ausus/starter               — pre-wired Laravel app + HelloInvoice demo + frontend
  12. ausus:up command            — bootstrap automation

[Milestone 3 — "RFC-000 V0 Real Pass returns GO"]
  13. Conformance test vector packages (RFC-014 §3.6, RFC-002 §19.5)
  14. Postgres/MySQL CI matrix
  15. ausus:doctor extensions (full RFC-005 §12 checks)
  16. Publish packages to packagist
  17. Re-run RFC-000 V0 Real Pass — expected: GO
```

### 9.2 Critical path

The kernel is the critical path. Every other package blocks on it. Until `ausus/kernel` ships compilable contracts, nothing else can be developed.

Within the kernel, the critical sub-path is:

1. `Ausus\Kernel\Contracts\` interfaces (PersistenceDriver, Repository, Policy, Effect, Auditor, AuditSink, etc.) — sequenced first because every other package imports them.
2. `Ausus\` facade classes (Plugin, Dsl, Field, Action, Decision, etc.) — sequenced after contracts because they reference them.
3. Reflection-based DSL compiler (registers descriptors into the Metadata Graph) — last in kernel because all primitives must exist first.

### 9.3 Parallelization opportunities

Once the kernel contracts are stable (target: end of milestone 1 week 1), the following packages can be developed in parallel by independent contributors:

- `ausus/persistence-sql`
- `ausus/tenancy-row`
- `ausus/audit-database`
- `ausus/auth-bridge`
- `ausus/runtime-default`
- `ausus/presentation-default` (when started; M2)
- `renderer/react` (when started; M2)

Each consumes only `ausus/kernel`, so changes in one do not invalidate work in another. The integration point is `apps/playground` at the end of each milestone.

---

## 10. Dependency graph (definitive)

### 10.1 Compile-time dependencies

```
ausus/kernel
    ↑
    ├── ausus/runtime-default
    ├── ausus/persistence-sql
    ├── ausus/tenancy-row
    ├── ausus/audit-database
    ├── ausus/auth-bridge
    └── ausus/presentation-default

ausus/standard-stack  (requires all of the above)
    ↑
ausus/starter         (requires ausus/standard-stack + laravel/framework)
    ↑
apps/playground       (path-installs ausus/starter for testing)

renderer/react        (npm — independent toolchain; depends only on React peers)
```

### 10.2 Runtime dependencies (DI container bindings)

Resolved at boot by Laravel's container. Documented in §3.2.

### 10.3 No circular dependencies

Verified by `apps/playground/ci/check-deps.php` (built in milestone 1).

---

## 11. First executable milestone — definition of done

**Name:** M1 — Kernel + Runtime + Persistence + Audit boot end-to-end.

**Goal:** A hand-authored test in `apps/playground` invokes one Action through the full Invoker chain, mutates one row, and emits one Audit Entry. No HTTP. No React. No DSL ergonomics polish. Pure proof-of-life.

**Concrete deliverables:**

1. `ausus/kernel`:
   - All kernel contract interfaces compile.
   - `Ausus\Plugin`, `Ausus\Dsl`, `Ausus\Field`, `Ausus\Action`, `Ausus\Decision`, `Ausus\Effect`, `Ausus\EffectContext` facades exist and are usable from a hand-authored plugin file.
   - `Ausus\Kernel\Compiler` produces a Metadata Graph from a registered Plugin's DSL declarations.
   - One unit test per public interface verifying instantiation and basic contract shape.

2. `ausus/persistence-sql`:
   - `SqlPersistenceDriver` implements `PersistenceDriver`.
   - `Repository::find`, `create`, `update`, `delete` work against a SQLite database.
   - Optimistic locking via `_version` column functions.
   - `Transactional` lifecycle (begin / commit / rollback) honors the Invoker's ownership.
   - Schema derivation from a single registered Entity descriptor works (DDL applied to SQLite).

3. `ausus/tenancy-row`:
   - `RowTenantIsolationStrategy` adds `WHERE tenant_id = ?` predicates.
   - `TenantCatalog::create` bootstraps a single Tenant.
   - One `TenantResolver` (CLI flag, simplest case).

4. `ausus/audit-database`:
   - `DatabaseTransactionalSink` writes to `kernel_audit_log`.
   - `preservesElevation()` returns `true`.
   - One Audit Entry emitted by an Invoker call lands in the table.

5. `ausus/auth-bridge`:
   - Stub-mode `ActorResolver` returns a hardcoded `Actor` with roles `['admin']`.
   - `Actor::roleHash()` computes the canonical hash per RFC-014 §3 and matches the §3.6 empty-input and single-role vectors.

6. `ausus/runtime-default`:
   - `Invoker::invoke` runs the 5-step chain.
   - `PolicyEngine` evaluates a single attached Policy (deny / permit).
   - `WorkflowRuntime` evaluates one transition (DRAFT → ISSUED).
   - `EffectContext` exposes the eight methods of RFC-013 §3.1.
   - The de facto `Ausus\Effect` interface's `execute()` is dispatched.

7. `ausus/standard-stack`:
   - Meta-package's `composer install` resolves all the above at compatible `@dev` versions.

8. `apps/playground`:
   - One PHPUnit integration test:
     - Boots a minimal Laravel app with the Standard Stack.
     - Registers a one-Entity plugin (`test.invoice` with `status` enum DRAFT/ISSUED/CANCELLED).
     - Bootstraps a Tenant.
     - Invokes `test.invoice.create` → row exists with status DRAFT.
     - Invokes `test.invoice.issue` → row's status is ISSUED.
     - Asserts two AuditEntries exist in `kernel_audit_log` with correct shapes.
   - All assertions pass.

**Acceptance:** `make test` from the repo root returns 0. `apps/playground/tests/IntegrationTest.php::testFullChain` passes.

**Not in M1:**

- ViewSchema generation (M2).
- React rendering (M2).
- HTTP routing (M2).
- Multiple Tenants (M3).
- Postgres support (M3).
- DSL ergonomics polish beyond the RFC-011 §2.1 worked example (M3).
- `ausus:up` CLI command (M2).
- Plugin template scaffolding (M2).
- Conformance test vector packages (M3).
- Production deployment story (post-M3).

---

## 12. First sprint breakdown (week 1 of M1)

A two-week sprint targeting M1 completion. Week 1 plan; week 2 absorbs slippage and integration work.

### 12.1 Day-by-day (week 1)

| Day | Focus                                                          | Owner suggestion |
|-----|----------------------------------------------------------------|------------------|
| 1   | `ausus/kernel`: skeleton + `PersistenceDriver`, `Repository`, `Reference`, `Version`, `IdentityHandle`, `Filter`, `PersistenceContext` contracts | Kernel maintainer |
| 1   | `ausus/kernel`: `Policy`, `Decision`, `Subject`, `Context`, `Actor`, `ActorRef`, `ActorResolver` contracts | (parallel) |
| 2   | `ausus/kernel`: `Effect`, `EffectContext`, `Invoker` contracts | Kernel maintainer |
| 2   | `ausus/kernel`: `Auditor`, `AuditEntry`, `AuditSink` (incl. SinkRole, SinkKind, SinkCapabilities) contracts | (parallel) |
| 3   | `ausus/kernel`: `Tenant`, `TenantId`, `TenantState`, `TenantResolver`, `TenantIsolationStrategy`, `TenantCatalog`, `OverrideStore` contracts | Kernel maintainer |
| 3   | `ausus/kernel`: `WorkflowDescriptor`, `TransitionDescriptor`, `ReportingDriver`, `ReportingQuery` contracts | (parallel) |
| 4   | `ausus/kernel`: `Ausus\Plugin` base class + `Ausus\Dsl` builder + `Ausus\Field` / `Ausus\Action` fluent builders | Kernel maintainer |
| 4   | `ausus/kernel`: `Ausus\Kernel\Compiler` (descriptor registration + cross-reference resolution + graph hash) | (parallel) |
| 5   | `ausus/kernel`: unit tests (one per contract); CI green | Kernel maintainer |
| 5   | `ausus/persistence-sql`: SqlPersistenceDriver skeleton; SQLite migration for one test Entity | Persistence maintainer (starts) |

### 12.2 Day-by-day (week 2)

| Day | Focus                                                                                              |
|-----|----------------------------------------------------------------------------------------------------|
| 6–7 | `ausus/persistence-sql` + `ausus/audit-database` complete; their unit tests pass.                  |
| 6–7 | `ausus/tenancy-row` + `ausus/auth-bridge` stub mode complete; unit tests pass.                     |
| 8   | `ausus/runtime-default`: Invoker + Policy Engine. Tests pass in isolation.                         |
| 9   | `ausus/runtime-default`: Workflow runtime + Effect dispatch + `EffectContext`. Tests pass.         |
| 10  | `apps/playground`: integration test wires everything; resolves bugs surfaced by integration.       |

End of sprint: M1 acceptance check (§11 deliverables verified).

### 12.3 Roles assumed

The sprint plan assumes 2–3 concurrent maintainers. With one maintainer, multiply by ~2.5x. With four+ maintainers, the kernel critical path bottlenecks days 1–4; additional capacity helps after day 4.

---

## 13. Explicit "do not build yet" list

The following are in the RFCs but NOT in milestones 1–3. Building any prematurely violates the implementation-mode constraint and creates abstractions ahead of need.

1. **Schema-per-tenant or db-per-tenant isolation strategies** (RFC-003). V1 ships row-only. Separate packages `ausus/tenancy-schema` / `ausus/tenancy-database` are post-V1.
2. **External audit sinks** (S3, Kafka, SIEM per RFC-007 §5.4). Only the database sink for V1. External sinks require the 3-phase prepare/confirm/cancel protocol of RFC-007 §6.2 — useful but not first-cut critical.
3. **Audit retry queue + dead-letter worker** (RFC-007 §11). Only relevant when secondary sinks exist; V1 has none. The contracts exist in the kernel; the implementation is no-op for M1–M3.
4. **Orphan reconciliation worker** (RFC-007 §12). Only relevant for External primary sinks. Transactional primary cannot orphan.
5. **MaintenanceAction examples in the starter** (RFC-010 §8). The HelloInvoice demo uses Standard Actions only. MaintenanceAction support is in `ausus/runtime-default` (because it dispatches Effects regardless of kind); demonstrating it is M3.
6. **Multi-currency `money` field handling** (RFC-004 §4 + RFC-012 §7). Demo uses USD only. Cross-currency aggregation arithmetic is deferred.
7. **Composite primary keys** (RFC-002 §6.3). ULID single-key only in V1.
8. **Multi-PersistenceDriver / multi-ReportingDriver deployments** (RFC-002 §14, RFC-010 §2.7). V1 single-driver only.
9. **GraphQL or alternative API surfaces** (RFC-001 §1.2). HTTP/REST only.
10. **Vue or mobile renderer profiles** (RFC-004 §10). `react.web.v1` only.
11. **Spatie\Permission integration** (RFC-012 §9.3). Stub-mode auth in M1; Laravel-bridge mode in M2; Spatie integration is M3.
12. **Impersonation** (RFC-014 §9). Out of V1 entirely.
13. **Tenant strategy migration** (RFC-003 §13). Out of M1–M3; relevant when multiple strategies exist.
14. **Online (zero-downtime) anything.** No async, no streaming, no live updates. Synchronous request/response only.
15. **ABAC attribute-dependent Policies in the demo.** The HelloInvoice demo uses role-based Policies only. Attribute-dependent Policies + their `cacheable: false` plumbing exist in the runtime (because RFC-005 §8.6 requires) but are not exercised by the demo.
16. **Plugin marketplace, plugin signing** (RFC-001 §11.7). Post-V1.
17. **Telemetry (RFC-009)**. Out of scope until after first stable.
18. **`composer create-project ausus/plugin-template`** (RFC-012 §13). Starter template only in M2; plugin template in M3.
19. **DSL formatter / linter** (RFC-011 §16.4). Tooling concern; post-M3.
20. **Workflow visualization** (RFC-006 §17.7). Post-V1.
21. **Decision-trace export** (RFC-005 §17.7). Post-V1.
22. **`ausus/auth-azure-ad` or any third-party Authorization plugin.** First, prove the contract works with `ausus/auth-bridge`; then external plugins.
23. **Distributed Policy Engine cache** (RFC-005 §8.8). Process-local cache only.
24. **OPcache preload of compiled graph** (RFC-001 §7.4). Default file-cache only.

Items 1–24 are deferred deliberately. Building them ahead of need violates the implementation-mode rule "minimal executable implementations over abstraction."

---

## 14. Risks and watchpoints

### 14.1 Specification creep

The RFC stack is frozen, but the temptation during implementation is to surface "while I'm in here, let me add..." patterns. Hard rule: every new public symbol must trace back to an RFC clause. If implementation reveals a missing clause, file a finding before writing code.

### 14.2 Service-locator leakage

PHP makes service-locator patterns easy. `app()`, `resolve()`, Laravel facades inside Policy / Effect / Actor classes will silently work but violate RFC-005 §10, RFC-013 §10.4, RFC-014 §14.12. The runtime spy in `ausus/runtime-default` catches some; PHPStan rules catch more; conformance tests catch the rest.

### 14.3 Eloquent leakage in persistence-sql

`ausus/persistence-sql` uses Eloquent internally. The contract surface (Repository return types, Entity instances, Reference, Filter) must remain Eloquent-free. Conformance test: every public method's return type assertion verifies no `Illuminate\Database\Eloquent\*` types escape.

### 14.4 DSL non-determinism

RFC-001 §5.8.3 requires deterministic DSL output. PHP's `array` ordering, `microtime()` calls, random UUIDs at registration time, etc. all break determinism. The Compiler hashes its input; any non-determinism manifests as cache thrash. Tests must verify two compilations of the same source produce byte-identical graph hashes.

### 14.5 Test-vector freezing

RFC-014 §3.6 test vectors have hex values "to be computed and locked at acceptance." Compute these values once, write them as constants in `ausus/auth-bridge` and the conformance package, and verify CI fails if they change. Failure to freeze means non-conformance is invisible.

### 14.6 Implicit cross-package coupling

Even with `extra.ausus.deps-allowed: ["ausus/kernel"]` declarations, implementations may pick up implicit coupling via Laravel's container (e.g., `runtime-default` resolves a class that only exists in `persistence-sql`). The dependency-direction CI check (§2.3) verifies declared composer deps; integration tests in `apps/playground` verify the bound container resolves correctly without runtime coupling.

---

## 15. What to do next

The skeleton is created. The path forward, in order:

1. **Tag this state** as `0.0.0-skeleton`. Establishes a baseline.
2. **Start M1 day 1**: kernel contracts, beginning with the persistence interfaces (most-required first).
3. **Re-read RFC-001 + Amendments** before writing any code in `ausus/kernel`. Every public symbol must trace.
4. **At end of week 1**: kernel contracts should compile + have basic unit tests. If they don't, slip the sprint by one week; do NOT begin M1 packages until the kernel is stable.
5. **At end of M1**: re-run `apps/playground` integration test. Green = M1 complete. Then start M2.
6. **At end of M2**: ViewSchema generates, React renderer consumes, starter app boots. The "30-minute TTFS" target of RFC-012 §15 becomes measurable.
7. **At end of M3**: RFC-000 V0 Real Pass re-runs. Expected determination: **GO**.

The implementation phase is not over until M3 acceptance.
