# RFC-001 — Review 01 (Challenger + Architect)

| Field    | Value                                              |
|----------|----------------------------------------------------|
| Status   | Open — pending acceptance into RFC-001 as amendments |
| Authors  | challenger, architect                              |
| Date     | 2026-05-16                                         |
| Targets  | RFC-001 §1.2, §2.1, §2.7, §5, §10.5, §11.8         |

This review challenges four decisions in RFC-001. Each section produces: objections, alternatives considered, and a recommended amendment. The aim is governance, not redesign — if RFC-001 cannot defend a decision against the objections below, the decision changes before V1.

A latent contradiction in RFC-001 also surfaces here and is called out at the end.

---

## Decision 1 — Should `View` remain a Kernel primitive?

**RFC-001 currently says:** Yes. §2.7 makes `View` one of the nine core abstractions.

### Objections

1. **It contradicts §1.2.** §1.2 explicitly excludes "React, View Schemas, UI hint resolution" from the Kernel and assigns them to the Projection layer. §2.7 then re-imports the same concern under a different name. The two sections cannot both be right.
2. **The word "View" leaks UI semantics into the domain.** The whole architectural thesis is that the domain layer does not know UI exists. Naming a kernel primitive `View` puts a UI word at the center of the domain graph, exactly the mistake §9.2 forbids.
3. **The current scope mixes two distinct concerns.** §2.7 says a View describes "which Fields to show, in what order, with what hints, with what filters and Actions." *Which Fields and which Actions are legitimate* is a domain question (what is exposable, to whom, in what context). *Order, layout, hints* is a presentation question. Bundling them is the Filament trap.
4. **The argument "renderer stability requires kernel ownership" is weaker than it looks.** The contract that must be stable is the **View Schema wire format** (JSON consumed by React). That contract can be owned by the Projection layer with its own SemVer, independent of the Kernel.
5. **An API-only consumer never asks for a View.** A batch job, a webhook subscriber, a partner ETL, or a CLI consumes Entities and Actions directly. If a primitive is invisible to half of the platform's legitimate consumers, it does not belong in the Kernel.

### Alternatives considered

- **(A) Remove `View` from the Kernel entirely.** Put it in L5 (Projection). Pros: cleanest layering, resolves the §1.2/§2.7 contradiction. Cons: loses the ability for the Compiler to validate that declared projections reference real Fields and Actions; loses a kernel-level discoverability point ("what projections does this Entity expose?").
- **(B) Keep a kernel primitive, but split it.** A kernel-level **`Projection`** (domain concept: "this named subset of Fields and Actions is a legitimate, discoverable read shape of this Entity, subject to these Filters") + a Projection-layer **`ViewSchema`** (presentation concept: ordering, layout, hints, Actor-scoped Field visibility). Pros: keeps Compiler validation, kills the UI-leak, resolves the contradiction. Cons: introduces a new concept name.
- **(C) Keep `View` in the Kernel as written, document the §1.2/§2.7 conflict as intentional.** Pros: smallest churn. Cons: requires a justification that does not exist, and the conflict will recur in every downstream RFC.

### Recommended amendment

Adopt **(B)**. Specifically:

- Rename the kernel primitive from `View` to `Projection`.
- Restrict the `Projection` descriptor to: a name, the Fields it exposes, the Actions it exposes, the Filters it permits, and the Policy chain that governs its visibility. Nothing about order, layout, hints, or rendering targets.
- Move ordering, layout, UI hints, and target-renderer concerns into the Projection layer (L5) under the name `ViewSchema`.
- Update §1.2 to read: "View Schemas (the renderable output) and all UI hint resolution live in the Projection layer; the Kernel knows `Projection` descriptors (domain-level), not `ViewSchema` (presentation-level)."
- Update §2.7 accordingly. Update §11.4 (currently "RFC-004 — View Schema specification") to clarify it specifies the **wire format** owned by the Projection layer, not a kernel contract.

This change costs one rename. It buys: no UI vocabulary in the Kernel, no §1.2/§2.7 contradiction, no leak of presentation into the domain, and the same compile-time validation we wanted from `View`.

---

## Decision 2 — Should `Ausus::raw()` exist?

**RFC-001 currently says:** Yes, "one escape hatch, documented as a deliberate exit from platform guarantees" (§10.5.3).

### Objections

1. **An escape hatch shipped in V1 is part of the V1 public API.** It cannot be removed in a minor or patch under the SemVer commitment in §6.4. We will live with it for a full major cycle minimum, which under the project's 10-year horizon is years.
2. **It silently violates four kernel invariants simultaneously.** Tenancy (§8.1: "mandatory"), Policies (§8.2: "deny-by-default"), Audit (§8.3: "Kernel enforces emission"), and Workflow guards. RFC-001 spends six sections building these invariants and then provides a function to skip all four at once.
3. **It contradicts §9.12.** §9 forbids "a platform that lets a Plugin reach into Runtime." `raw()` lets *any* caller — Plugin, application, controller, CLI script — reach into the persistence driver. It is a strictly worse violation than the one §9 already forbids.
4. **The stated justification is weak.** §10.5.3 frames `raw()` as relief for "users who will demand escape hatches" during prototyping. Prototyping pressure should never shape the public surface of a 10-year platform. If the platform is too rigid for prototyping, the answer is a documented "untrusted scratch mode," not a permanent kernel API.
5. **The "users will fork the persistence binding anyway" argument cuts the other way.** A fork is visible in the project's code — reviewers see it, it shows up in audits, it breaks loudly on persistence-driver upgrades. `raw()` is invisible in code review and never breaks. The wrong default is the *easier* path.
6. **The legitimate use cases (backfills, reports, migrations) are real but addressable without a single all-bypass door.** Each has a narrower, more honest contract.

### Alternatives considered

- **(A) No escape hatch.** Hard line: every operation goes through Actions, Policies, Tenant, Audit. Pros: invariants are real. Cons: legitimate ops (cross-entity reports, large backfills) require ceremony.
- **(B) Replace `raw()` with two narrow, named mechanisms:**
  - A **`ReportingDriver`** contract: read-only, cross-entity queries against the metadata graph. Respects Field-level Policies (rows the Actor cannot read are filtered or denied). Tenant-scoped by default; cross-tenant requires explicit elevation.
  - A **`MaintenanceAction`** category: declared in plugin manifests, runs through Policies and Tenant and Audit but is permitted to bypass Workflow guards and to operate on bulk subjects. Listed in `ausus:doctor`, surfaced in audit.
- **(C) Keep `raw()` but restrict scope** to CLI / queue context (never HTTP), require an explicit kernel-level grant per environment, and audit every call.
- **(D) Provide `raw()` only behind a `dev` config flag** that refuses to load in production.

### Recommended amendment

Adopt **(B)**. Specifically:

- Remove `Ausus::raw()` from §10.5.3.
- Add to §1.1 ("Kernel owns"): "A `ReportingDriver` contract for read-only, cross-entity queries that respects Field-level Policies and Tenant scope."
- Add to §2.4 ("Action"): a sub-category `MaintenanceAction` — same contract as Action, but explicitly tagged in audit, surfaced separately in `ausus:doctor`, and permitted to bypass Workflow guards. Cannot bypass Tenant, Policy, or Audit.
- Schedule **RFC-010 — Reporting and Maintenance contracts** in §11 to specify both. Until that RFC lands, V1 ships with neither: the legitimate use cases use direct Actions, even if verbose.

This is stricter than what RFC-001 currently proposes. The cost is real friction for backfills and reports in V1. The benefit is that none of the four kernel invariants has an authorized bypass, ever.

**(C)** and **(D)** are rejected: a "dev only" or "CLI only" escape hatch still exists in the codebase and will be reached for. The only durable answer is: there is no escape hatch.

---

## Decision 3 — Should a normative DSL skeleton be locked in RFC-001?

**RFC-001 currently says:** No. §11.8 defers DSL ergonomics to a separate RFC.

### Objections

1. **The DSL is the platform's only visible surface for most users.** A user who never reads RFC-001 will form their entire mental model from the DSL. Leaving it unspecified means the first published example becomes the de facto contract.
2. **Backward compatibility is non-negotiable per the brief.** Backward compatibility starts at the surface, not at the contracts behind it. If RFC-001 commits to SemVer (§6.4) without constraining the DSL's properties, then the DSL has no SemVer obligations at all until RFC-N lands — which may be after V1 has shipped.
3. **Every downstream RFC depends on DSL shape.** RFC-002 (Persistence), RFC-003 (Tenancy), RFC-006 (Workflow execution) all need to reference how a Plugin author *expresses* persistence intent, tenancy intent, workflow intent. If the DSL shape is undefined, each RFC invents a local convention, and the conventions will not align.
4. **The Kernel's invariants depend on DSL hygiene.** If a `->fields()` call performs I/O at definition time, the registration phase ceases to be "cheap and safe to repeat" (§4.1). If the DSL is mutable across calls, the Compiler's determinism claim (§4.2) becomes a hope rather than a guarantee.

### Counter-objections (for keeping deferred)

1. **A premature DSL freezes choices before implementation feedback.** True for the *surface* (method names, fluent ordering). Not true for the *properties* (purity, determinism, serializability).
2. **RFC-001 is already large.** A full DSL specification would double it. Also true.

### Alternatives considered

- **(A) Lock the full DSL surface in RFC-001.** Rejected for the reasons above — too large, too premature.
- **(B) Lock the DSL's *properties* without locking its *surface*.** Define what the DSL must guarantee (purity, no I/O at definition time, serializability of all outputs, idempotent registration, no execution of domain logic during description, FQN binding rules). Leave method names and chaining shape to a follow-up RFC, but constrain that follow-up so it cannot break the Kernel.
- **(C) Defer entirely, as currently written.** Rejected — leaves the Kernel's own invariants undefended.

### Recommended amendment

Adopt **(B)**. Add a new section to RFC-001:

> **§5.8 DSL contract requirements (normative)**
>
> The DSL surface is not specified in this RFC. Any DSL bound to the Kernel must satisfy the following properties:
>
> 1. **Pure description.** A DSL call must produce a descriptor without performing I/O, without reading database state, and without executing domain logic. Side effects during description are forbidden.
> 2. **Serializable output.** Every descriptor produced by the DSL must be serializable to the format consumed by the Compiler. Closures and resource handles in descriptor payloads are forbidden.
> 3. **Idempotent registration.** Invoking the same DSL chain twice must register the same descriptor; double registration is an error reported by the Compiler.
> 4. **Deterministic FQN binding.** A descriptor's FQN must be derivable from the DSL call alone, without reference to runtime state.
> 5. **No domain logic at definition time.** Validation, computation, and authorization happen at runtime through Actions and Policies, never inside `->fields()`, `->relations()`, etc.
> 6. **Declarative composition.** Where the DSL composes other primitives (e.g. attaching Policies to Actions), the composition must be expressible as references to FQNs, not as embedded behavior.
>
> Future RFCs MAY specify the DSL surface (method names, fluent shape, builder ergonomics). They MUST NOT relax the properties above.

Keep §11.8 as the follow-up RFC for the *surface*, but it now operates inside a fixed envelope.

This costs one section in RFC-001. It buys: the Compiler's determinism claim is defensible, plugin authors can be told what guarantees the DSL has, and the surface-level RFC has a non-negotiable spec to design within.

---

## Decision 4 — Should Entity identity and persistence invariants move into RFC-001?

**RFC-001 currently says:** Identity is partially specified (FQN-based naming in §2.1). Persistence invariants are deferred to RFC-002.

### Objections

1. **"Persistence is swappable" is a promise the Kernel makes but does not back.** If two Persistence Drivers can choose different identity shapes (UUID vs auto-increment vs composite), then a Plugin built against driver A is incompatible with driver B. The swap is theoretical.
2. **Tenancy enforcement (§8.1) references "tenant_id" as if it is well-defined.** It is not. RFC-001 talks about a `tenant_id` discriminator without specifying whether it is part of Entity identity, a sibling field, opaque to the Kernel, or visible to the resolver. Each choice produces a different security boundary.
3. **Relations cross persistence boundaries.** A Relation from `billing.invoice` (stored in Postgres via Eloquent) to `crm.account` (stored in Mongo via a custom driver) must serialize to *something*. If RFC-001 doesn't define what a cross-Entity reference looks like at the contract level, the Compiler cannot validate Relations and the Resolver cannot dereference them.
4. **Optimistic concurrency, soft-delete semantics, and the audit trail's notion of "the same subject across time" all depend on identity stability.** §8.3 (Audit) names "Subject FQN + id" in the Audit Entry. The `id` is undefined here.
5. **Deferring this to RFC-002 means RFC-002 owns a Kernel-level decision.** Any of the above points is load-bearing for kernel invariants, not for persistence ergonomics. They should be owned by RFC-001.

### Counter-objections (for keeping deferred)

1. **Concrete identity types (UUID v7? ULID? snowflake?) are persistence-driver decisions.** True, and they should stay in RFC-002.
2. **Composite keys vs single keys is a driver decision.** True for the *shape*, but the Kernel can require a single opaque identity *handle* regardless of underlying shape.

### Alternatives considered

- **(A) Move all persistence into RFC-001.** Rejected — RFC-002's scope is correct; identity is a subset.
- **(B) Add an "identity contract" section to RFC-001 specifying what the Kernel requires of any Persistence Driver, while leaving concrete identity types, key shapes, and migration tooling to RFC-002.**
- **(C) Leave as written and let RFC-002 surface the gaps.** Rejected — discovering identity ambiguity in RFC-002 means RFC-002 either contradicts RFC-001 or amends it.

### Recommended amendment

Adopt **(B)**. Add to §2.1:

> **§2.1.1 Entity identity invariants (normative)**
>
> 1. Every Entity instance has an **identity handle** that is opaque to the Kernel, present from creation, and immutable for the instance's lifetime.
> 2. The identity handle is produced by the active Persistence Driver or by the application, never by the Kernel itself. The Kernel guarantees only that it exists and does not change.
> 3. The identity handle MUST be expressible as a value that round-trips through serialization. Live object handles, file descriptors, and process-local references are forbidden as identity.
> 4. A cross-Entity reference (the target of a Relation, the Subject of an Audit Entry, the argument to an Action) is canonically expressed as the tuple `(tenant_id, entity_fqn, identity_handle)`. Persistence Drivers MUST accept references in this form.
> 5. Concrete identity types (UUID, ULID, snowflake, composite keys) are chosen by RFC-002 and by individual Persistence Drivers. The Kernel does not constrain the choice beyond the invariants above.
>
> **§2.1.2 Tenant binding invariants (normative)**
>
> 1. Every Entity instance is bound to exactly one owning Tenant at creation. The binding is immutable; moving an instance between Tenants requires deletion and recreation under audit.
> 2. The `tenant_id` discriminator is part of the canonical cross-Entity reference (§2.1.1.4). It is not part of the identity handle itself; identity handles are not required to be globally unique across Tenants, only unique within `(tenant_id, entity_fqn)`.
> 3. Entities marked `system` (per §2.1) are bound to the `system` Tenant. Cross-Tenant Relations are forbidden unless both endpoints are `system`.

This is approximately one page of additions. It buys: the "swappable persistence" promise becomes defensible, Relations across drivers have a defined contract, Audit Entries have a defined Subject shape, and RFC-002 inherits a clear envelope instead of inheriting a vacuum.

---

## Latent contradiction surfaced by this review

Decision 1 above exposed a contradiction between §1.2 (which excludes "View Schemas" from the Kernel) and §2.7 (which makes `View` a Kernel primitive). Decision 1's recommended amendment resolves it by renaming `View` to `Projection`, restricting its scope to domain-level intent, and moving presentation concerns to L5.

This contradiction is the kind of thing that proves the value of a challenger review on the first RFC. The remaining four decisions reviewed here are judgment calls; this one was a defect.

---

## Summary of recommended amendments to RFC-001

| # | Section(s) | Amendment |
|---|------------|-----------|
| 1 | §1.2, §2.7, §11.4 | Rename `View` → `Projection`; restrict to domain intent; move `ViewSchema` to L5; resolve §1.2/§2.7 contradiction. |
| 2 | §1.1, §2.4, §10.5.3, §11 | Remove `Ausus::raw()`. Introduce `ReportingDriver` contract and `MaintenanceAction` category. Schedule RFC-010. |
| 3 | new §5.8 | Lock DSL **properties** (purity, serializability, determinism, no I/O, no domain logic, declarative composition). Leave surface to §11.8. |
| 4 | new §2.1.1, §2.1.2 | Lock Entity identity invariants and Tenant binding invariants. Leave concrete types to RFC-002. |

All four amendments narrow the Kernel rather than expand it. None of them introduce new implementation work — they constrain the design space so that downstream RFCs cannot accidentally re-open closed questions.

If accepted, RFC-001 should be reissued as Draft-02 with these amendments folded in, and this review document archived as `RFC-001-review-01.md`.
