# Product Vision — AUSUS 2.0 (Entity Engine)

> This document states the real identity of AUSUS 2.0 as it follows from the
> decisions actually present in the code, the RFCs, the reference applications,
> and the documentation. It contains no roadmap, no future feature, and no
> proposal — only what has already been decided and demonstrated.

---

## Why AUSUS exists

Enterprise applications keep re-implementing the same machinery: storage mapping,
per-record authorization, read shapes, state transitions, multi-tenancy, and a
UI — once per domain, by hand, each team getting a different subset wrong.

AUSUS exists to express an application **as declarative metadata** and to run it.
You declare entities, fields, actions, projections, and authorization rules; the
engine compiles that declaration to a frozen, content-addressed schema and
executes it — persisting data, enforcing authorization that reads the data,
exposing an HTTP API, and rendering a generic UI. You describe *what* the
application is; the engine provides the *how*, once, centrally.

This is the founding stance, unchanged since the project's first principles:
**metadata-first, domain-first, plugin-first, tenant-first, API-first; React is
only a rendering engine; no UI-first abstractions; no DX shortcut that hurts
scalability.**

---

## What problems it solves

The three reference applications — a CRM (5 entities), a hotel PMS (10 entities),
and a hospital system, SGH (12 entities) — were each built **only** from the DSL
and ViewDefinition, with **no change to the framework**, and each runs its full
domain workflow end to end. From that evidence, AUSUS 2.0 solves, concretely:

- **Declaring a domain instead of coding it.** Entities, state machines (as a
  field plus transition actions), references, and read shapes are metadata, not
  hand-written controllers, ORM models, or templates.
- **Authorization that reads the data.** Guards are declared predicates over
  facts (`actor`, `tenant`, `subject`, `input`), evaluated **fail-closed**. SGH
  demonstrates rules across all four fact dimensions.
- **Multi-tenancy as a structural property.** Every entity is tenant-scoped; the
  runtime carries the tenant through reads and writes.
- **Deterministic, content-addressed compilation.** A definition compiles to one
  `EntitySchema` per entity, addressed by a hash of its canonical form. Same
  semantics ⇒ same hash. Binding and execution **never recompile**; an
  application reloads from its compiled `.ausus` artifacts unchanged.
- **A presentation-agnostic surface.** The same compiled domain is reachable
  through a generic HTTP API and rendered by a generic React UI that has **no
  knowledge of the domain** — a newly compiled entity becomes visible without UI
  code.

Relative to the alternatives it was measured against: it removes the controller/
template/ORM glue of hand-written Laravel; unlike Filament or Nova, the model is
**data, not UI-bound code**; unlike Retool, the domain is **declared and
portable** (a PHP package, an HTTP contract, a React renderer) rather than a
hosted builder; and against suites like Odoo or Salesforce it is a **small open
kernel**, not a platform product.

---

## What it deliberately does NOT solve

These exclusions are demonstrated by the code and the validations, not aspirational:

- **It is not a general-purpose framework.** It runs *declared domains*; it does
  not aim to host arbitrary application code.
- **It does not impose an ORM.** The Entity is canonical; an ORM is merely one
  possible `PersistenceDriver` adapter. The engine is ORM-independent.
- **It does not generate code.** Compilation produces a schema, not source files.
- **It is not a UI framework.** React is a rendering engine over the HTTP
  contract; the engine holds no presentation.
- **It does not run arbitrary logic in authoring.** The DSL is a *closed*
  notation whose only product is an `EntityDefinition`; a static scan rejects
  forbidden symbols before evaluation.
- **It does not couple the runtime to storage or to compilation.** The runtime
  depends only on the driver contract and never recompiles.

---

## Founding principles

1. **Metadata-first.** The application is data (`EntityDefinition`), not code.
2. **A frozen, irreducible kernel.** Seven concepts — Field, Entity,
   AuthorizationRule, Action, Projection, Context, Driver — and nothing more.
3. **Content-addressed compilation.** Definition → canonical normal form →
   hashed `EntitySchema`; same semantics ⇒ same address.
4. **Authoring and runtime are separated.** A closed DSL produces metadata; a
   pure compiler freezes it; a driver-agnostic runtime executes it.
5. **Driver independence.** `EntityEngine::bind(schema, driver)` — the concrete
   persistence is injected, never assumed.
6. **Tenant-first and fail-closed by construction.** Tenancy is structural;
   authorization denies on any unresolved fact.
7. **One rendering contract.** Presentation consumes only the HTTP API; it knows
   nothing of the kernel, the compiler, or the repository.

---

## Assumed limits of version 2.0

AUSUS 2.0 reasons about **one entity and its raw foreign keys**, and does not
compute derived data. The following limits are accepted, documented, and each
reproduced by a reference application:

- **Expand depth = 1.** Relational reads follow a single hop; nested chains are
  not one read.
- **No cross-entity invariants.** A guard sees only its own subject's fields; a
  rule that depends on a *related* entity's state is not expressible.
- **Single-field transitions.** A transition flips one state field and cannot
  stamp other fields at the same time.
- **No aggregation or computed fields.** Projections expose stored fields only.
- **Deferred `read()` selection.** Filter, sort, and pagination parameters are
  not yet applied.
- **Limited runtime integrity validation.** Enum inputs and required references
  are not enforced at write time.
- **Limited actor attributes.** Guards read `actor.type/id/homeTenant` only.

These are boundaries of the current model, stated plainly so they are never
mistaken for defects.

---

## Philosophy of evolution

AUSUS evolves by **re-derivation around an irreducible core, not by accretion**.
The 2.0 *Entity Engine* is the second generation of the same framework — same
values, same repository, same package namespace — obtained by reducing the model
to its smallest frozen kernel and rebuilding the pipeline (authoring → compiler →
runtime → API → renderer) around it. The previous generation is preserved, not
deleted; the new one adds, it does not remove. Identity is conserved across
generations; the internal shape is what changes.

The discipline that governs change is visible in the work itself: each capability
was specified, frozen, implemented, and then validated against three independent
real domains before being considered done. The single implementation defect those
validations found was corrected without altering any contract or RFC. Capability
is added only when the kernel does not already make it reconstructible.

---

## Commitments that are now fixed

These are not promises of new work; they are invariants the architecture already
enforces and on which a consumer can rely:

- **The kernel is frozen.** The seven concepts and the L0 contracts
  (`EntityEngine`, `RuntimeEntity`, `SchemaRepository`, `AuthorizationEvaluator`,
  `Context`, `PersistenceDriver`) are stable; changes to them are major-version
  events, never silent.
- **Layers do not invert.** Each package depends only on lower layers; the kernel
  has zero dependencies.
- **Drivers stay interchangeable.** The runtime is bound to the persistence
  contract, never to a concrete driver.
- **The DSL stays closed.** Authoring produces only an `EntityDefinition`; it
  carries no business logic and no external capability.
- **Compilation stays content-addressed and the runtime never recompiles.** A
  schema's identity is its semantics; reload is free.
- **Authorization stays fail-closed.** Absence of a fact denies, by default.

---

*This vision is timeless by intent: it describes what AUSUS is, not what it might
become. It is grounded exclusively in decisions already taken — the founding
principles, the frozen EE-RFC-011/EE-RFC-012 kernel, the closed-DSL RFCs, and the CRM,
Teranga PMS, and SGH validations.*
