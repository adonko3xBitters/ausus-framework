# RFC-005 — Policy Engine

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Draft                                                  |
| Authors       | architect, kernel, challenger                          |
| Date          | 2026-05-18                                             |
| Depends on    | RFC-001 Draft-04, RFC-002 Draft, RFC-003 Draft, RFC-004 Draft, RFC-007 Draft-02, RFC-010 Draft |
| Supersedes    | —                                                      |
| Stability     | Foundational. Changes after acceptance require a follow-up RFC. |

---

## 0. Problem statement

Five subsystems already commit to invoking Policies and consuming Permit / Deny / Abstain decisions:

- The Invoker step 2 chain (RFC-001 §8.2 + Amendment-01 §A-1.4) on every Action invocation.
- `FieldDescriptor.visibility` evaluation (Amendment-01 §A-1.2, §A-1.5, §A-1.9) for read filtering.
- Projection-level Policies (RFC-001 §2.7) for ViewSchema gating.
- Tenant-added Policies under the additive-narrowing override regime (Amendment-01 §A-1.3).
- ReportingDriver enforcement of Entity-read and Field-visibility Policies (RFC-010 §5).

RFC-001 §2.5 names the Policy primitive and its combinator (`Deny > Permit > Abstain`, deny-by-default) and asserts purity. It does not specify: the contract's exact signature, ordering rules across the five subsystems, Subject=null semantics, the Context shape, caching, failure semantics, side-effect bounds, bulk evaluation semantics, doctor checks, the error taxonomy, or the rejected alternatives that plugin authors would otherwise rediscover by accident.

Without this RFC, plugin authors writing Policies invent missing pieces: they introduce I/O, define ad-hoc Context bags, build inheritance hierarchies, embed network calls "just for IP allowlists," fail open under exception. RFC-010 already had to documente a "row-level only" Policy convention (§5.3) because the contract was underspecified.

This RFC owns every runtime and semantic detail required to make the five subsystems above fully executable without invention. It does not introduce authentication, identity providers, role storage, or UI authorization helpers. Those are Authorization plugin concerns (RFC-001 §8.2) outside the engine's scope.

The ten-year horizon and SemVer discipline (RFC-001 §6.4) apply. Every contract in this RFC is part of the V1 public surface.

---

## 1. Scope and inherited constraints

### 1.1 Inherited (non-negotiable)

1. Policies are the kernel's authorization primitive. Roles, permissions, ABAC attributes are not. (RFC-001 §8.2, §9.11.)
2. Composition: `Deny > Permit > Abstain`; deny-by-default. (RFC-001 §2.5.)
3. Policies MUST be pure. (RFC-001 §2.5.)
4. Tenant overrides may add Policies; the combinator guarantees additions can only narrow access. (Amendment-01 §A-1.3.)
5. The `kernel.field.read` sentinel is the ActionFqn passed when evaluating `FieldDescriptor.visibility`. (Amendment-01 §A-1.2.)
6. The `kernel.*` ActionFqn namespace is reserved for kernel-owned sentinels (Amendment-01 §A-1.2, Amendment-02 §A-1.11). Plugins MUST NOT register Policies that fire on plugin-claimed `kernel.*` Actions.
7. The Invoker (Amendment-01 §A-1.4) is the sole authorized call site for Policy evaluation during Action execution. The Presentation layer and ReportingDriver evaluate Policies through engine-exposed contracts, never by re-implementing the chain.
8. Read audit is opt-in per Entity via `audited_reads: true` (Amendment-02 §A-1.12). Policy evaluation is not gated by it; the audit subsystem is downstream of the engine.
9. Subject identity handles MAY be kernel-reserved synthetics in `kernel.*` (Amendment-02 §A-1.13), used in AuditEntry Subjects where no instance is meaningful. The engine treats synthetics opaquely.
10. Engine evaluations MUST be deterministic given identical inputs.

### 1.2 Out of scope

- **Authentication.** How an Actor proves identity. The engine receives an `Actor` value object; it does not produce one.
- **Identity providers.** Same.
- **Role storage.** The Authorization plugin owns role/permission storage and produces an `Actor` whose `roleHash()` is consumed by the engine's cache key.
- **UI authorization helpers.** Renderers consume ViewSchema (RFC-004); helpers like `@can()` blade directives are not engine concerns.
- **Workflow guards.** RFC-001 §8.2 step 3. Workflow guards use Policies via the engine; the engine does not define Workflow.
- **The Authorization plugin contract.** The engine consumes an `Actor` shape minimum (§1.3 below); the Authorization plugin's full contract is a separate RFC.

### 1.3 `Actor` minimum surface

The engine consumes Actors. The Authorization plugin provides them. The minimum surface the engine relies on:

```
interface Actor
{
  function ref(): ActorRef;        // RFC-007 §2.1 ActorRef; used in AuditEntry
  function roleHash(): string;     // opaque, deterministic; used in cache key
}
```

Additional accessors (`roles()`, `permissions()`, `attributes()`) are Authorization-plugin-defined. Policies that depend on them couple to the chosen Authorization plugin; that coupling is explicit and acceptable.

`roleHash()` MUST be a deterministic hash of the Actor's Policy-relevant attributes. Two Actors with identical Policy-relevant attributes MUST yield identical `roleHash`. Distinct attributes MUST yield distinct hashes. The Authorization plugin's conformance test verifies.

---

## 2. Policy contract

### 2.1 Signature (normative)

```
interface Policy
{
  function evaluate(
    Actor       $actor,
    string      $actionFqn,
    ?Subject    $subject,
    Context     $context
  ): Decision;
}

enum Decision { Permit, Deny, Abstain }
```

No alternate signatures. No defaults, no overloads, no variadic parameters. A Policy implementation that does not satisfy exactly this signature is rejected at registration with `PolicyContractViolation`.

### 2.2 `Subject`

```
final class Subject
{
  function tenantId(): string;
  function entityFqn(): string;
  function identityHandle(): string;
}
```

Subject corresponds to the canonical reference tuple of RFC-001 §2.1.1.4. When the Action is over a single Entity instance, Subject is non-null. When the Action has no instance Subject, Subject is `null` (per §4 of this RFC).

### 2.3 Identity and registration

Every Policy has an FQN of the form `namespace.policy_name` (RFC-001 §2.1 FQN conventions). FQNs in the `kernel.*` namespace are reserved (Amendment-02 §A-1.11); plugins MUST NOT register Policies in this namespace.

Plugins register Policies via `PolicyDescriptor`:

```
final class PolicyDescriptor
{
  function fqn(): string;
  function implementationClass(): string;        // FQN of class implementing Policy
  function cacheable(): bool;                    // default true (§6.2)
  function timeoutMs(): ?int;                    // null = use deployment default (§7.2)
  function declaredAttributes(): array;          // Authorization-plugin-defined; opaque to engine
}
```

The descriptor is serializable (RFC-001 §5.8.2). The implementation class is referenced by FQN string, never as a closure. The class is constructed once per process at boot via the Laravel container; the Policy instance is reused across evaluations.

### 2.4 Construction constraints

The Policy implementation class:

1. MUST be stateless across `evaluate` calls. State that survives one call leaks to the next; engine treats any in-memory mutation as a §10 side-effect violation.
2. MUST have a constructor accepting only its declared static configuration (passed at boot). No dynamic runtime dependencies.
3. MUST NOT have a destructor or magic methods that perform I/O.

These are enforced by the Policy conformance test suite (§19).

---

## 3. Policy registration and identity

### 3.1 Attachment points

Policies are attached at four kernel-defined attachment points:

1. **Action attachment**: declared in the Action descriptor's `policy` field (RFC-010 §8.1, RFC-001 §2.4).
2. **Field attachment**: declared in the Field descriptor's `visibility` field (Amendment-01 §A-1.2).
3. **Projection attachment**: declared in the Projection descriptor's `policy` field (RFC-001 §2.7).
4. **Entity-level read attachment**: implicit when an Entity declares `audited_reads: true` and a Policy is attached; uses sentinel `kernel.entity.read` ActionFqn (RFC-007 §15.1).

A fifth attachment is the global Policy chain (RFC-001 §8.2 "global"), declared by the Authorization plugin at boot; it applies to every Invoker call.

### 3.2 No other attachment points

The engine evaluates Policies only at the five attachment points above. Plugins MUST NOT invent attachment points (e.g., "this Policy fires on every Repository call"). Such invention has no engine support and would require a new RFC.

### 3.3 Tenant-added attachments

Per Amendment-01 §A-1.3, Tenant overrides MAY add Policies to existing attachment points 1, 2, 3. They MAY NOT remove or detach. The added Policy joins the chain at the position defined by §4.

Tenant overrides MAY NOT add to attachment 4 (Entity-level read) because read auditing is Entity-level and not subject to per-Tenant variation (Amendment-02 §A-1.12.4).

Tenant overrides MAY NOT add global Policies. Global Policies live with the Authorization plugin and apply uniformly.

### 3.4 No runtime registration

Policies are registered during plugin `boot()` (RFC-001 §5.8). Runtime registration is forbidden; the metadata graph is immutable per process (RFC-001 §4.3, §A-1.13.6). Attempting to register a Policy after boot raises `PolicyRuntimeRegistration` (§13).

### 3.5 Challenger attack — registration

- **Layer violations:** Policy class lives in plugin L7; engine instantiates via L2 container; no cross-layer violation.
- **Hidden runtime coupling:** none. Boot-time only. Implementation class FQN is descriptor data.
- **SemVer expansion:** `PolicyDescriptor` is part of V1 surface; additions require minor bump.
- **Tenancy bypass:** Tenant-added Policies cannot remove base Policies; combinator's Deny precedence ensures additions narrow.
- **Audit bypass:** registration is a graph-compile concern; not audit-affecting.

---

## 4. Evaluation ordering

### 4.1 The chain assembly rule (normative)

For a given evaluation `(Actor, ActionFqn, Subject, Context)`, the engine assembles a Policy chain by concatenating these segments in strict order:

```
chain := (
  Action-attached base Policies,
  Action-attached Tenant-added Policies,
  Entity-level base Policies         (when ActionFqn is "kernel.entity.read" or implies one),
  Entity-level Tenant-added Policies,
  Field-attached base Policy         (single, when ActionFqn is "kernel.field.read"),
  Field-attached Tenant-added Policies,
  Projection-attached base Policies  (when evaluating a Projection),
  Projection-attached Tenant-added Policies,
  Global Policies                    (Authorization plugin, applied to every Invoker call)
)
```

Each segment's intra-order:

- **Base Policies**: declaration order in the plugin manifest. Plugin authors control the order at registration; the Compiler preserves it (§4.3).
- **Tenant-added Policies**: ordered by `(install_timestamp_asc, policy_fqn_lex_asc)`. Install timestamp is part of the override descriptor metadata (RFC-003 §8); ties break on FQN lexicographic ascending.

### 4.2 Segment applicability

Not every evaluation triggers every segment:

| Evaluator | Segments evaluated |
|-----------|---------------------|
| Invoker step 2 (Standard Action) | Action (base + Tenant), Global |
| Invoker step 2 (MaintenanceAction) | Action (base + Tenant), Global |
| Invoker pre-elevate (RFC-003 §10.2) | Action `kernel.tenant.elevate` (base + Tenant), Global |
| Projection visibility (ViewSchema gen, RFC-004 §11.2 step 2) | Projection (base + Tenant), Global |
| Field visibility at Projection emission (RFC-004 §11.2 step 3) | Field (base + Tenant) |
| Field visibility at ReportingDriver (RFC-010 §5.1) | Field (base + Tenant) |
| Entity read check at ReportingDriver (RFC-010 §5.4) | Entity-level (base + Tenant), Global |
| Read audit on `audited_reads` Entity (RFC-007 §15) | Entity-level (base + Tenant), Global |

Segments not listed are not evaluated; e.g., Projection-attached Policies do not fire on Action invocation, because Projections are read-shape declarations, not Actions.

### 4.3 Compiler-enforced determinism

The Compiler (RFC-001 §4.2) serializes each Policy chain into the Metadata Graph at compile time. The serialization is canonical: ordered exactly per §4.1. Two compilations of the same source produce byte-identical chain serializations (RFC-001 §5.8.3 determinism).

At runtime, the engine reads the precompiled chain; it does not re-sort. Tenant-added Policies are merged into the precompiled base at resolution time (RFC-003 §9), using the install-timestamp ordering above.

### 4.4 No alphabetical merging

The chain is NOT sorted alphabetically across segments. Segment order (§4.1) is the structural rule; intra-segment order is declaration or install-time. Cross-segment alphabetical merging would break the "Tenant additions narrow, never reorder base" property.

### 4.5 Challenger attack — ordering

- **Layer violations:** ordering is L0 graph property, applied by L2 engine; clean.
- **Hidden runtime coupling:** none. Order is graph-resident.
- **SemVer expansion:** the ordering rule is part of V1 surface. Changes require RFC.
- **Tenancy bypass:** Tenant-added Policies always evaluate AFTER base, but Deny short-circuits — a base Deny prevents Tenant additions from contributing. Tenant additions can only ADD a Deny; they cannot override a base Permit to permit more. Combinator math preserves narrowing.
- **Audit bypass:** ordering does not affect emission; audit is independent.

---

## 5. Composition

### 5.1 Combinator (normative)

```
combine(d1, d2) := Deny    if d1 == Deny or d2 == Deny
                   Permit  if (d1, d2) ∈ {(Permit, Permit), (Permit, Abstain), (Abstain, Permit)}
                   Abstain if d1 == Abstain and d2 == Abstain
```

The engine combines decisions left-to-right along the chain. Short-circuits on Deny: once a Deny is observed, remaining Policies are NOT evaluated. (This is observable to Policy authors only via the absence of side effects from skipped Policies, which is moot because Policies have no side effects per §10.)

### 5.2 Associativity (proved)

`combine(combine(a, b), c) == combine(a, combine(b, c))` for all `(a, b, c) ∈ {Permit, Deny, Abstain}³`.

Proof by exhaustive case (27 cases). The combinator is a function of the set of decisions present (Deny dominates; Permit dominates Abstain; Abstain is identity for the Permit-vs-Abstain operation). Grouping order does not matter.

Truth tables in Appendix C.

### 5.3 Identity element

`Abstain` is the identity: `combine(a, Abstain) == combine(Abstain, a) == a`.

### 5.4 Empty chain

An empty Policy chain yields `Abstain`. The engine then applies the **deny-by-default** rule: a final result of `Abstain` from the chain is converted to `Deny` before returning to the caller.

This conversion happens once, at the top of the engine call, not within the combinator. The combinator's algebra remains pure (Abstain is identity). Deny-by-default is policy applied after composition.

### 5.5 Determinism

Given identical inputs (Actor, ActionFqn, Subject, Context, chain), `combine` yields identical results. Combined with §4.3's deterministic chain, the engine's result is fully deterministic.

### 5.6 Duplicate Policy behavior

If the same Policy FQN appears multiple times in a chain (e.g., once in base, once as Tenant addition), each instance is evaluated independently. The combinator is idempotent on identical decisions: `combine(Permit, Permit) == Permit`, `combine(Deny, Deny) == Deny`. No special dedup; the chain is the chain.

Note: duplicate registrations of the same Policy FQN at the SAME attachment point (e.g., Action attaches Policy P twice) are rejected at registration with `PolicyDuplicateAttachment`. Duplicates ACROSS attachments (Action vs Tenant addition) are permitted (the engine cannot distinguish "Tenant intended this addition" from "Tenant accidentally duplicated base").

### 5.7 Challenger attack — composition

- **Layer violations:** combinator is pure value-level math; no layer concerns.
- **Hidden runtime coupling:** none. Short-circuit is engine-internal optimization; observable equivalent: full evaluation produces the same result.
- **SemVer expansion:** the combinator semantics are V1 frozen. Changing the precedence rule is a major bump.
- **Tenancy bypass:** Tenant-added Policies extend the chain; they cannot remove. Composition preserves the narrowing property.
- **Audit bypass:** composition is decision-only; audit is downstream of decision.

---

## 6. Subject=null semantics

### 6.1 When Subject MAY be null

Subject is `null` in these defined cases:

1. **Pre-create operations.** An Action that creates a new Entity instance has no Subject before creation. The Action's Policy is evaluated with `Subject = null`.
2. **Subject-less Actions.** Actions declared `subject_required: false` (RFC-010 §8.1 for MaintenanceActions; same flag for any Action that has no single Subject).
3. **MaintenanceAction with `subject_required: false`** (RFC-010 §8.1, §9.4). The Action operates on a Filter-defined set; null Subject.
4. **ReportingDriver query validation** (RFC-010 §5.3). At query-validation time the driver has no instance Subject; null.
5. **ViewSchema metadata-time Field visibility** (RFC-004 §11.2 when no specific Subject is in scope, e.g., list-Projection schema generation before data fetch).

Subject is non-null in all other cases.

### 6.2 Policy obligation under Subject=null

A Policy receiving `Subject = null`:

1. MUST NOT dereference Subject. Calling `subject->tenantId()` on a null Subject in PHP raises a TypeError; the engine catches and treats as `Deny` per §7.3 (fail-closed on exception). Policy authors who anticipate null Subject MUST guard.
2. MAY return any Decision based on Actor + ActionFqn + Context alone.
3. SHOULD return `Deny` if the Policy's authorization rule fundamentally requires a Subject (the "row-level only" documented pattern from RFC-010 §5.3).

This convention is what makes RFC-010 §5.1's loud-failure work: Policies designed for per-row evaluation return Deny when called with null Subject; ReportingDriver propagates that as `FieldVisibilityDenied`.

### 6.3 Misuse: passing non-null Subject when the Action does not have one

If a caller passes a non-null Subject to an Action declared `subject_required: false`, the engine accepts it (the Subject is just data the Policy may read). However, the Action's Policy SHOULD NOT depend on a Subject for a subject-less Action; doing so couples the Policy to caller invariants the kernel does not enforce.

### 6.4 Misuse: passing null Subject when the Action requires one

If a caller passes `null` to an Action declared `subject_required: true`, the engine raises `PolicySubjectRequired` BEFORE evaluating any Policy. This is a contract violation by the caller, not a Policy decision.

### 6.5 Challenger attack — null Subject

- **Layer violations:** null is a value-level concept; no layer impact.
- **Hidden runtime coupling:** the documented Subject=null pattern is widely depended on (RFC-010 §5.3); this RFC formalizes it. No new coupling.
- **SemVer expansion:** the cases in §6.1 are V1 frozen; adding new cases is minor (additive).
- **Tenancy bypass:** Tenant is in Context regardless of Subject; null Subject does not loosen Tenant enforcement.
- **Audit bypass:** Audit emission shape supports synthetic Subjects (Amendment-02 §A-1.13). Null Subject at policy evaluation does not bypass audit; the audit Subject is filled per the Action's emission rules.

---

## 7. Context shape

### 7.1 Closed enumeration (normative)

```
final class Context
{
  function tenant(): Tenant;                          // RFC-003 §2.2; active Tenant; never null
  function trace(): ?TraceId;                         // W3C trace ID; null if absent
  function correlation(): CorrelationId;              // RFC-007 §9.1; never null inside Invoker call
  function elevation(): ?ElevationRef;                // RFC-003 §10.5 + RFC-007 §2.1; null if not elevated
  function resolverContext(): ResolverContext;        // RFC-003 §3.1; HTTP|CLI|QUEUE|SCHEDULED
  function clock(): Instant;                          // request-pinned UTC; deterministic within an Invoker call
  function requestId(): ?string;                      // L4 transport-level ID; null outside HTTP
  function locale(): ?string;                         // BCP-47; null in non-presentation contexts
}
```

Closed for V1. Adding a key requires a new RFC. Removing or renaming requires a major kernel bump.

### 7.2 Immutability

A `Context` instance is immutable across the lifetime of a single `evaluate` call. The engine constructs one Context per evaluation; the same instance is passed to every Policy in the chain. Policies that mutate Context (e.g., reflection abuse) are violating §10 side-effect bounds.

### 7.3 Per-call Clock pin

`clock()` returns a single Instant pinned at the start of the Invoker call (or, for non-Invoker evaluations like ReportingDriver query validation, at the start of the engine call). Every Policy in the chain sees the same Clock value, ensuring determinism within the evaluation.

Different evaluations (different Invoker calls) see different Clock values. This is what makes time-conditional Policies (e.g., "permit only during business hours") possible without breaking determinism within a single evaluation.

### 7.4 What Context does NOT contain

- **HTTP request object.** L4 transport. Policies that need request inspection are coupling to L4 — forbidden.
- **Database connection / Repository / ReportingDriver.** §10 forbids; not in Context.
- **Logger / metrics handle.** §10 forbids; engine emits diagnostics on Policy completion, not from inside Policy.
- **Workflow state machine handle.** Workflow guards are RFC-001 §8.2 step 3, not Policy evaluation.
- **Other Policies' decisions.** Chain composition is engine-internal; Policies see only their own evaluation.

### 7.5 Challenger attack — Context

- **Layer violations:** Context exposes only L0 value objects (Tenant, ElevationRef) and primitives. No L3+ handles.
- **Hidden runtime coupling:** the eight keys are explicit; closed set prevents grab-bag growth.
- **SemVer expansion:** adding a key is minor; removing is major. V1 frozen.
- **Tenancy bypass:** `tenant()` is mandatory non-null; Policies always know the active Tenant.
- **Audit bypass:** Context exposes elevation and correlation for Policy use; audit emission downstream of the engine reads the same.

---

## 8. Caching

### 8.1 Two-tier cache (normative)

The engine caches at two tiers:

**Tier 1 — Chain resolution cache.** Maps `(graphHash, tenantId, overrideVersion, attachmentKey)` to the ordered list of Policy FQNs comprising the chain. Invalidated by graph hash change or `overrideVersion` bump (RFC-003 §9.1). Process-local.

**Tier 2 — Evaluation result cache.** Maps the §8.2 cache key to a `Decision`. Invalidated by any input change (cache key change). Process-local.

### 8.2 Cache key (normative)

```
EvaluationCacheKey := (
  graphHash:                string,                     // RFC-001 §4.2.5
  tenantId:                 string,                     // active Tenant; RFC-003 §2.1
  overrideVersion:          int,                        // RFC-003 §9.1
  attachmentKey:            string,                     // see §8.4
  actorRoleHash:            string,                     // Actor::roleHash()
  subjectClass:             "null" | "instance-bound",  // see §8.3
  decisionContextHash:      string                      // see §8.5
)
```

### 8.3 `subjectClass` and uncacheability

`subjectClass = "null"`: the evaluation's Subject was null. Result is cacheable; future calls with the same key reuse the decision.

`subjectClass = "instance-bound"`: Subject was a specific instance. Result is **NOT cached** (the cache key would have to include the identity handle, making the cache pointless for distinct instances). Tier 2 lookup is skipped; evaluation runs every time.

This is the load-bearing rule for cache utility: subject-less Policy evaluation (the common case for Projections, Action-level checks, ReportingDriver) is cached; per-row Policy evaluation (rare in V1) is not.

### 8.4 `attachmentKey`

The attachment-point key, identifying the chain to resolve:

- For Action evaluation: `"action:<actionFqn>"`.
- For Field visibility: `"field:<entityFqn>.<fieldName>"`.
- For Projection visibility: `"projection:<projectionFqn>"`.
- For Entity-level read: `"entity-read:<entityFqn>"`.

Global Policies are included in every chain implicitly; they do not have a separate attachment key.

### 8.5 `decisionContextHash`

A deterministic hash of the **decision-affecting** fields of Context. Excludes observability fields whose value should not affect a Policy's decision:

Included in hash:
- `tenant().id().value()`
- `elevation()` (null or fully serialized; an elevated evaluation can produce a different decision than a non-elevated one)
- `resolverContext()` (HTTP vs CLI vs QUEUE vs SCHEDULED may legitimately gate access)
- `locale()`

Excluded from hash:
- `trace()` (observability)
- `correlation()` (observability)
- `clock()` (per-call pin; Policies that depend on Clock declare `cacheable: false` per §8.6)
- `requestId()` (observability)

### 8.6 Per-Policy `cacheable` flag

A `PolicyDescriptor` with `cacheable: false` bypasses tier 2 cache entirely; evaluation runs every call. This is the escape valve for time-conditional or non-deterministic Policies (Policies that read external state via the Authorization plugin's Actor, for instance, when that state changes within a session).

If ANY Policy in the chain has `cacheable: false`, the entire chain's evaluation is uncached. The engine MUST NOT partially cache a chain.

### 8.7 Elevation interaction

During elevation (RFC-003 §10), the active Tenant in Context is the **target** Tenant, not the origin. The `tenantId` in the cache key reflects the target. `elevation()` is included in the decision context hash, so an elevated evaluation has a distinct cache key from a non-elevated evaluation against the same target Tenant.

This ensures Policies that read `elevation()` to gate behavior (e.g., "permit only during elevation by a non-tenant admin") produce correct, separately-cached decisions.

### 8.8 Cache scope and eviction

Both tiers are process-local. There is no distributed cache for Policy decisions; the cost of cross-process invalidation would exceed the cost of re-evaluation.

Eviction: tier 1 (chain resolution) on `overrideVersion` change. Tier 2 (evaluation result) on any input change (cache key miss).

LRU bounds: deployment-configured (`policy_engine.cache.tier1_max_entries`, `tier2_max_entries`). Engine MUST evict via LRU when bounds are exceeded. Eviction MUST NOT alter correctness; it only affects performance.

### 8.9 Challenger attack — caching

- **Layer violations:** cache lives at L2 engine; no layer crossing.
- **Hidden runtime coupling:** cache hit/miss is invisible to Policy code; results are identical (within determinism). No coupling.
- **SemVer expansion:** cache key shape is V1 frozen. New components require minor (additive); removing components is major.
- **Tenancy bypass:** `tenantId` in key prevents cross-Tenant cache collision. Combined with `overrideVersion` per RFC-003 §9, Tenant updates invalidate correctly.
- **Audit bypass:** caching is decision-only; audit emission is downstream of the engine and not cached.

---

## 9. Failure semantics

### 9.1 Universal rule — fail-closed (normative)

Every engine failure mode produces `Deny`. There is no fail-open path. The engine wraps every failure into a structured error type (§13) and returns `Deny` to the caller.

Fail-open is an explicit anti-pattern (§14). Plugin authors who want fail-open behavior MUST implement it in their Policy (return `Permit` on error inside the Policy); the engine itself never grants on error.

### 9.2 Policy timeout

Each Policy evaluation has a timeout. Source of timeout (in priority order):

1. `PolicyDescriptor::timeoutMs()` if set.
2. Deployment config `policy_engine.default_timeout_ms` (default: 100ms).

On timeout, the engine cancels the Policy's evaluation (where the runtime permits), emits `PolicyTimeout` (§13), and treats the result as `Deny` for chain composition. Chain evaluation continues with subsequent Policies (which may themselves Deny, Permit, or Abstain); the timed-out Policy contributes `Deny`, so the chain's final result is at minimum `Deny`.

In PHP, cancellation is best-effort (no preemptive interrupt). The engine records the elapsed time and rejects the Policy's response if it returns after timeout. This is enough to make slow Policies observable and to bound chain latency in practice.

### 9.3 Exception

A Policy that throws any exception from `evaluate` is treated as `Deny`. The engine catches at the call boundary, emits `PolicyException(fqn, cause)` (§13), and contributes `Deny` to the chain.

This includes exceptions from null-Subject dereferencing (§6.2 paragraph 1).

### 9.4 Malformed return

A Policy that returns a value not in `{Permit, Deny, Abstain}` is treated as `Deny`. The engine emits `PolicyMalformedReturn(fqn, returned_value_repr)` (§13). PHP type assertions on the return value make this rare in practice but the engine still validates.

### 9.5 Unknown Policy FQN

At registration, every PolicyDescriptor's class is resolved. A descriptor referencing a non-existent class is rejected at boot with `PolicyClassNotFound`.

At runtime, an Action / Field / Projection referencing an unregistered Policy FQN is rejected at boot (the Compiler validates every chain). Runtime occurrence is impossible for a correctly compiled graph; if it nevertheless occurs (corrupted cache, hand-modified graph), the engine emits `PolicyUnknownFqn(fqn)` and treats as `Deny`.

### 9.6 Recursive Policy invocation

A Policy MUST NOT recursively call back into the Policy engine. Per §10, Policies have no engine handle. If a Policy somehow obtains one (reflection abuse, container leakage), the engine detects recursion via a thread-local depth counter incremented at each `evaluate` call. Depth > 1 raises `PolicyRecursionDetected(fqn, call_stack)` and treats as `Deny`.

### 9.7 Circular dependencies between Policies

Policies do not reference other Policies directly. Composition is via chain attachment (§3), not via "this Policy invokes that Policy." Therefore circular dependencies between Policies are structurally impossible.

If a Policy's implementation class has a constructor dependency on another Policy class (a code-organization choice), Laravel container resolution would detect the cycle at boot. This is a plugin-author concern, not an engine one. `ausus:doctor` reports detected cycles.

### 9.8 Engine-internal failure

If the engine itself fails (cache corruption, chain resolution error, internal invariant violation), it raises `PolicyEngineInternal(message)` and treats as `Deny`. Operators are notified via the standard error reporting path.

### 9.9 Challenger attack — failure

- **Layer violations:** all failures wrapped at the engine boundary; no plugin-native exceptions propagate to callers.
- **Hidden runtime coupling:** timeout enforcement requires elapsed-time measurement; minimal coupling to Clock.
- **SemVer expansion:** the failure modes in §9 are V1 frozen. New ones require minor bump (additive failure types).
- **Tenancy bypass:** failure produces Deny; cannot grant cross-Tenant access by failing.
- **Audit bypass:** failure to evaluate a Policy on a mutating Action produces Deny, which produces no mutation, which produces no audit entry to bypass. For audited reads, the audit emission is downstream of the engine; engine failure denies the read, which means no read happens, which means no read audit needed.

---

## 10. Side effects

### 10.1 Forbidden operations (normative)

A Policy implementation MUST NOT, during `evaluate`:

1. Read from `PersistenceDriver`, `Repository`, or `PersistenceContext` (RFC-002).
2. Read from `ReportingDriver` (RFC-010).
3. Invoke any Action through `Invoker` (Amendment-01 §A-1.4).
4. Read from or write to any file, network socket, queue, or external service.
5. Read from or write to Laravel cache, session, or other framework storage.
6. Emit log records, metrics, or telemetry events. (The engine emits one diagnostic per Policy evaluation; that is the sole telemetry channel for evaluation.)
7. Mutate any state outside the Policy's local stack frame. (Implies: no global variables, no static class state, no service container mutation, no Context modification, no Actor modification, no Subject modification.)
8. Sleep, busy-wait, or otherwise consume time beyond computation.

### 10.2 Permitted operations

A Policy MAY:

1. Read the four `evaluate` arguments (Actor, ActionFqn, Subject, Context) and any data exposed by their methods.
2. Read Authorization-plugin-provided Actor attributes (e.g., `actor->roles()`, `actor->permissions()`). These attributes MUST be precomputed by the Authorization plugin before Actor reaches the engine; the Authorization plugin's contract guarantees freshness.
3. Read its own static configuration injected at boot via the constructor.
4. Perform pure computation (string match, set membership, integer comparison, etc.).

### 10.3 Enforcement

V1 enforcement is multi-layered, none perfect:

- **Conformance test suite** (scoped per §19): every shipped Policy is run with a doubled / spied harness that asserts no PersistenceContext, ReportingDriver, Invoker, or framework cache instance is accessed. Failures are conformance failures, not runtime errors.
- **Runtime detection — best effort**: the engine spies on its own `Auditor`, `Repository`, `Invoker` bindings via boot-time monkey patching. If a Policy successfully obtains one (e.g., via container leakage), the spy raises `PolicyForbiddenSideEffect` and the evaluation is treated as `Deny`.
- **Static analysis** (recommended, not enforced): PHPStan / Psalm rules forbidding `use Illuminate\\...`, `app(...)`, `resolve(...)` inside any class implementing `Ausus\\Kernel\\Contracts\\Policy\\Policy`. Distributed as a Kernel-provided ruleset; deployment opt-in.

PHP's lack of true sandboxing means these defences are not airtight. The engine commits to the rule and provides detection at all reachable boundaries; complete prevention is a documented limitation.

### 10.4 Why so strict

Side effects in Policies break:

- **Determinism**: a Policy that reads the database can return different decisions for identical inputs.
- **Cache correctness**: cached decisions become stale when their hidden inputs change.
- **Composition algebra**: §5's combinator properties (associativity, identity) assume pure functions.
- **Performance**: per-evaluation I/O multiplies under the chain (one Action evaluation = up to N Policies × I/O each).
- **Audit reasoning**: a Policy that writes is itself a mutation requiring audit, but Policies are not Actions; the audit-spine commitment (RFC-001 §1.1.7) is bypassed.

The strict rule is the only way to keep the engine's V1 commitments truthful.

### 10.5 Challenger attack — side effects

- **Layer violations:** the prohibition spans every other layer; Policies are L7 in pure-function form only.
- **Hidden runtime coupling:** the spying defence couples the engine to detection of specific bindings, but the detection is bounded and well-defined.
- **SemVer expansion:** the prohibition is V1 frozen. Relaxing is a major bump (would invalidate every cache).
- **Tenancy bypass:** no I/O means no path to read cross-Tenant data inside a Policy.
- **Audit bypass:** Policies cannot write; cannot bypass audit.

---

## 11. Bulk and MaintenanceAction evaluation

### 11.1 Bulk Action: invocation-level evaluation

For a MaintenanceAction performing bulk operations (RFC-010 §11, RFC-002 §11), the engine evaluates the Action's Policy chain **once per invocation**, NOT per-Subject. The Subject argument is `null` (per §6.1 case 3).

This is the load-bearing rule for bulk-operation Policy cost. A MaintenanceAction touching 100,000 Subjects evaluates its Policy chain once, in O(chain length), not 100,000 × O(chain length).

### 11.2 No per-Subject Policy for bulk

Per-Subject Policy enforcement on bulk operations is impossible under V1 transactional semantics (RFC-002 §11.6 all-or-nothing). If per-Subject Policy is required, the deployment uses the Standard-Action-per-Subject pattern (RFC-010 §11.2):

- Each Subject is invoked as a separate Standard Action.
- Each Standard Action evaluates the Policy chain with `Subject = <instance>`.
- Per-Subject failures are isolated.

This trades throughput for per-Subject Policy granularity; the trade-off is explicit at the manifest level.

### 11.3 Cross-Entity MaintenanceAction

A MaintenanceAction whose effect mutates more than one Entity (RFC-010 §9.6, Amendment-02 §A-1.14) evaluates its Action-attached Policy chain once. The chain Policies see the Action FQN and may inspect Action-level static configuration; they do not see per-Entity context (because the engine evaluates once, before the effect runs).

Per-Entity write authorization granularity is not in V1. If required, plugin authors split the Action into per-Entity Standard Actions, each with its own Policy chain.

### 11.4 ReportingDriver query

Per RFC-010 §5, every `ReportingDriver::execute` call triggers:

1. Entity-level Policy evaluation for each Entity in `from` + joins. Each evaluation: ActionFqn = `kernel.entity.read`, Subject = null.
2. Field-level visibility evaluation for each referenced Field. Each evaluation: ActionFqn = `kernel.field.read`, Subject = null.

Engine cost is O(unique Entities + referenced Fields). Cacheable per §8.

### 11.5 Read-audit emission Policy

When an Entity declares `audited_reads: true` (Amendment-02 §A-1.12) and a Repository read occurs, the Entity-level Policy is evaluated with ActionFqn = `kernel.entity.read` and Subject = the read target's reference (non-null). This is the rare per-Subject evaluation case for reads.

Such evaluations are NOT cached (subjectClass = "instance-bound" per §8.3).

### 11.6 Challenger attack — bulk / maintenance

- **Layer violations:** evaluation count is engine-internal; no layer impact.
- **Hidden runtime coupling:** the "once per invocation" rule is normative; bulk callers can rely on bounded Policy cost.
- **SemVer expansion:** behavior is V1 frozen.
- **Tenancy bypass:** bulk evaluation still consults Tenant Context; cannot span Tenants in a single Invoker transaction (RFC-002 §7.5).
- **Audit bypass:** Policy denies bulk → no effect → no audit. Permit → bulk proceeds → audit emits one `BulkSubject` entry (RFC-007 §13).

---

## 12. `ausus:doctor` checks

`ausus:doctor` (RFC-001 §5.5) MUST report:

1. **Unreachable Policies.** PolicyDescriptors registered but not referenced from any Action, Field, Projection, or Entity-read attachment, and not declared global by the Authorization plugin. Likely indicates dead code or registration typo. Severity: warning.
2. **Duplicate registrations.** Two PolicyDescriptors with identical FQN. Severity: error; boot fails.
3. **Reserved `kernel.*` collisions.** Plugin attempts to register a Policy in the `kernel.*` namespace. Severity: error; boot fails.
4. **Implementation-class constructor cycles.** Detected via Laravel container's cycle detection. Severity: error; boot fails.
5. **Non-deterministic ordering.** A diagnostic running on each compile: re-compile twice and verify chain serializations are byte-identical. Mismatch is a Compiler bug (RFC-001 §5.8.3 violation). Severity: error.
6. **Cacheability mismatch.** A Policy declared `cacheable: true` whose implementation depends on `Context::clock()` (detected via static analysis if enabled). Severity: warning.
7. **`PolicyForbiddenSideEffect` history.** If any `PolicyForbiddenSideEffect` was recorded in the audit log since the last `doctor` run, list the offending Policy FQNs. Severity: warning.
8. **Timeout regression.** Policies whose p99 evaluation time exceeds 50% of their configured timeout. Severity: warning.
9. **Chain length outliers.** Policy chains with more than 10 Policies. Likely indicates over-attachment. Severity: notice.

`doctor` failures (severity error) MUST abort boot. Warnings and notices are surfaced but do not block.

---

## 13. Error taxonomy (closed for V1)

```
PolicyEngineError                              (abstract)
├── PolicyContractViolation(class_fqn, reason)        (registration: signature mismatch, missing interface)
├── PolicyClassNotFound(class_fqn)                    (registration: descriptor's class FQN unresolvable)
├── PolicyDuplicateAttachment(fqn, attachmentPoint)   (registration: same Policy attached twice at same point)
├── PolicyDuplicateRegistration(fqn)                  (registration: same Policy FQN registered twice)
├── PolicyReservedNamespace(fqn)                      (registration: plugin tried to register in kernel.*)
├── PolicyRuntimeRegistration(fqn)                    (registration after boot: forbidden)
├── PolicyTimeout(fqn, elapsed_ms, limit_ms)          (runtime: Policy exceeded timeout)
├── PolicyException(fqn, cause)                       (runtime: Policy threw)
├── PolicyMalformedReturn(fqn, returned_repr)         (runtime: Policy returned non-Decision)
├── PolicyUnknownFqn(fqn)                             (runtime: corrupted-graph; should be unreachable)
├── PolicyRecursionDetected(fqn, depth)               (runtime: Policy called back into engine)
├── PolicyForbiddenSideEffect(fqn, operation)         (runtime: spy detected forbidden binding access)
├── PolicySubjectRequired(actionFqn)                  (runtime: caller passed null Subject to subject_required Action)
└── PolicyEngineInternal(message)                     (runtime: engine invariant violation)
```

All engine-raised errors extend `PolicyEngineError`. PHP-native exceptions thrown by Policies are wrapped in `PolicyException(fqn, cause)`. Plugin code MUST NOT see native exceptions from inside the engine.

Plugins consuming evaluation results see `Decision`. They never see PolicyEngineError instances; the engine logs the error and returns `Deny`. The Invoker's caller (L4) sees `Deny`-driven rejection envelopes per RFC-005's downstream RFCs (API Surface).

---

## 14. Explicit rejections

The following patterns are explicitly rejected for V1. Each rejection is normative — plugin code attempting any is non-conforming.

### 14.1 RBAC baked into kernel

**Rejected.** Roles, permissions, ACL trees are Authorization-plugin concerns (RFC-001 §8.2, §9.11). The engine consumes `Actor::roleHash()` as an opaque cache-key contributor; it does not know what a role is.

### 14.2 Policy inheritance trees

**Rejected.** Policies are flat values composed via the chain combinator. No `extends Policy`, no abstract Policy base classes with overridden behavior, no Policy mixin pattern. Plugin authors that want shared logic factor it into pure helper functions called from each Policy's `evaluate`.

### 14.3 Dynamic scripting

**Rejected.** Policies are PHP classes registered at boot. No runtime `eval`, no DSL interpreter, no SpEL / OPA / Rego embedding. Plugin authors that want external Policy specifications maintain them as PHP source and rely on plugin-deploy cycles.

### 14.4 Policy mutation at runtime

**Rejected.** The Metadata Graph is immutable per process (RFC-001 §4.3). Policy chains are graph-resident. Runtime mutation would invalidate caches, break determinism, and violate the audit-spine commitment that Policies are decisions made over a known-stable graph.

### 14.5 Fail-open authorization

**Rejected explicitly.** Every engine failure produces `Deny` (§9). Plugin authors that want fail-open behavior implement it as a Policy returning `Permit` on error inside the Policy's body — never via engine misconfiguration.

### 14.6 Network calls during evaluation

**Rejected** (§10). The engine commits to non-I/O Policy evaluation. Plugin authors needing remote authorization checks precompute results (e.g., via Authorization plugin's Actor enrichment at session start) and surface them on the Actor's attributes.

### 14.7 Per-Policy audit emission

**Rejected.** Policies do not emit audit. The Invoker emits one AuditEntry per Action invocation (RFC-007 §3.3). Per-Policy audit would produce N × M entries per Action invocation; the audit log is for decisions, not for the reasoning steps that produced them. Decision-trace observability is RFC-009 telemetry territory.

### 14.8 Policy invocation outside the engine

**Rejected.** A plugin that imports a Policy class and calls `evaluate()` directly bypasses the chain composition, the deny-by-default rule, the caching, the failure semantics, and the audit linkage. The engine is the sole authorized invocation site. Direct invocation is a §10 side-effect violation by extension.

---

## 15. Alternatives considered

### 15.1 Asynchronous Policy evaluation

**Rejected.** Async evaluation introduces non-determinism in ordering and complicates the timeout model. V1 commits to synchronous evaluation. Async is a post-V1 RFC at best, blocked on a use case the engine has not yet seen.

### 15.2 Policy chains with explicit weights

**Rejected.** The `Deny > Permit > Abstain` combinator is sufficient; weights add expressiveness at the cost of an algebra no one will master correctly across plugins. The narrowing-only Tenant addition rule (Amendment-01 §A-1.3) already gives operators the only legitimate "stronger Policy wins" pattern.

### 15.3 Policy-author-defined Context keys

**Rejected.** Closed Context (§7.1) prevents grab-bag growth. A Policy that wants additional context computes it from Actor or static configuration.

### 15.4 ABAC as kernel primitive

**Rejected** (RFC-001 §A-1.2 alternatives). ABAC is one authorization model; the engine commits only to the Policy primitive, which is general enough to express ABAC via Authorization-plugin-defined Actor attributes.

### 15.5 Compiled Policy chains (bytecode)

**Rejected.** Premature optimization. PHP's interpretation is fast enough for the V1 chain lengths (target < 10 Policies per chain per §12 doctor). If chains grow large, a future RFC may add bytecode caching.

### 15.6 Policy versioning per Action

**Rejected.** Policies are versioned by their plugin's version (RFC-001 §6.4). A versioned-Policy-per-Action would let one Action change its Policy without the plugin major bumping, defeating SemVer. The legitimate pattern: a new Policy FQN; the Action declares which.

### 15.7 Conditional evaluation skips

**Rejected.** A Policy that says "skip the next N Policies in the chain" breaks composition algebra and audit reasoning. The combinator's short-circuit on Deny is the only sanctioned skip.

---

## 16. Trade-offs

1. **No cross-process distributed cache** (§8.8) means each process re-evaluates the same key once. Acceptable; per-process eviction keeps memory bounded and cross-process invalidation cost would dominate.
2. **Best-effort cancellation on timeout** (§9.2) under PHP's lack of preemptive interrupts means a malicious slow Policy can hold its slot. Mitigation: timeouts are observed, repeat offenders surface via doctor (§12 #8).
3. **Best-effort side-effect detection** (§10.3) under PHP's lack of sandboxing means determined plugin authors can bypass. Mitigation: conformance test suite + static analysis + runtime spy at the common boundaries.
4. **Subject=null caching only** (§8.3) means per-row visibility decisions don't cache. Acceptable; ReportingDriver's loud-failure on null Subject (RFC-010 §5.1) makes per-row evaluation rare.
5. **Closed Context** (§7.1) requires a new RFC to add keys. Cost: feature requests aggregate; benefit: no grab-bag bloat.
6. **No Policy inheritance** (§14.2) forces composition over inheritance. More verbose for plugin authors; clearer for engine reasoning.
7. **No per-Policy audit** (§14.7) means Policy-decision traces are not available in the audit log. Telemetry (RFC-009) covers it; audit is for outcomes, not reasoning.
8. **Fail-closed always** (§9.1) means a misconfigured Policy can lock out all access. Mitigation: doctor catches at boot; runtime errors are denials, not crashes; operators recover by hot-fix and reboot.

---

## 17. Open questions

1. **RFC-009 (Telemetry).** Per-Policy evaluation latency, hit/miss cache rates, deny/permit distributions. Out of this RFC.
2. **RFC for Authorization plugin contract.** Fully specifies `Actor`, `actor->roles()`, `actor->permissions()`, attribute precomputation freshness. Currently §1.3 names the minimum surface; a follow-up RFC fixes the full shape.
3. **Post-V1 — Async Policy evaluation.** Use cases unclear; deferred.
4. **Post-V1 — Distributed cache.** Use cases involve very large Policy chains; deferred.
5. **Post-V1 — Policy bytecode compilation.** If chain lengths exceed V1 doctor thresholds in production.
6. **Post-V1 — Per-row write authorization.** V1 has Field visibility for reads; write-side per-row authorization (e.g., "this Actor can update this row's amount field but not others") is not in V1. Plugin authors split into per-Field Actions; future RFC may add primitive.
7. **Post-V1 — Decision trace export.** A debug mode where the engine surfaces "which Policies fired with which decisions" via telemetry. Out of V1 to keep V1 tight.

---

## 18. Acceptance criteria

This RFC is accepted when:

1. The three role signatories (architect, kernel, challenger) sign off on §2, §4, §5, §6, §7, §8, §9, §10, §14.
2. RFC-007 confirms that engine failure emits diagnostics through the audit subsystem only when the failure is associated with a mutation that proceeded (which the engine guarantees does not happen — denial prevents mutation, no audit needed for denied attempts unless RFC-007 §15 read-audit applies).
3. RFC-009 (Telemetry) inherits the metrics surface in §17.1.
4. A conformance test suite for `Policy` implementations is scoped (not built) before V1: at minimum, one test per "MUST" clause in §2, §6, §10.
5. A conformance test suite for engine evaluation is scoped: at minimum, one test per error type in §13.
6. Appendices C and D below are reviewed for combinator-truth-table completeness and ordering-proof exhaustiveness.

Once accepted, this RFC is the source of truth for the Policy Engine.

---

## Appendix A — Contract summary

```
Ausus\Kernel\Contracts\Policy\
  Policy                                (interface)
    evaluate(Actor, string, ?Subject, Context): Decision
  Decision                              (closed enum: Permit, Deny, Abstain)
  Subject                               (final value object)
  Context                               (final value object; closed §7.1)
  Actor                                 (interface; minimum surface §1.3)

  PolicyDescriptor                      (final value object)

Ausus\Kernel\Contracts\Policy\Errors\
  PolicyEngineError                     (abstract)
  PolicyContractViolation,
  PolicyClassNotFound,
  PolicyDuplicateAttachment,
  PolicyDuplicateRegistration,
  PolicyReservedNamespace,
  PolicyRuntimeRegistration,
  PolicyTimeout,
  PolicyException,
  PolicyMalformedReturn,
  PolicyUnknownFqn,
  PolicyRecursionDetected,
  PolicyForbiddenSideEffect,
  PolicySubjectRequired,
  PolicyEngineInternal

Reserved sentinels (already kernel-owned):
  kernel.field.read                     (Amendment-01 §A-1.2)
  kernel.entity.read                    (RFC-007 §15.1)

Configuration (deployment):
  policy_engine.default_timeout_ms       100
  policy_engine.cache.tier1_max_entries  10000
  policy_engine.cache.tier2_max_entries  100000
```

Nothing else is part of the V1 surface.

---

## Appendix B — Error taxonomy summary

Engine-native failures form a closed taxonomy (§13). Every failure is wrapped; no PHP-native exception escapes the engine to a caller. PolicyEngineErrors are not re-thrown to callers; callers see `Decision::Deny`. The errors are emitted to the diagnostic channel only (logs / telemetry).

| Error                          | When raised                                          | Engine response             |
|--------------------------------|------------------------------------------------------|----------------------------|
| PolicyContractViolation        | registration (signature/interface mismatch)          | boot failure               |
| PolicyClassNotFound            | registration (descriptor class FQN unresolvable)     | boot failure               |
| PolicyDuplicateAttachment      | registration (same Policy attached twice at point)   | boot failure               |
| PolicyDuplicateRegistration    | registration (same FQN registered twice)             | boot failure               |
| PolicyReservedNamespace        | registration (plugin in `kernel.*`)                  | boot failure               |
| PolicyRuntimeRegistration      | post-boot register attempt                           | runtime throw              |
| PolicyTimeout                  | runtime (Policy exceeded timeout)                    | contribute Deny            |
| PolicyException                | runtime (Policy threw)                               | contribute Deny            |
| PolicyMalformedReturn          | runtime (Policy returned non-Decision)               | contribute Deny            |
| PolicyUnknownFqn               | runtime (corrupted graph)                            | contribute Deny            |
| PolicyRecursionDetected        | runtime (depth > 1)                                  | contribute Deny            |
| PolicyForbiddenSideEffect      | runtime (spy detection)                              | contribute Deny            |
| PolicySubjectRequired          | caller passed null to subject_required Action        | runtime throw              |
| PolicyEngineInternal           | engine invariant violation                           | contribute Deny + log      |

---

## Appendix C — Evaluation truth tables

### C.1 Pairwise combinator

| left \ right | Permit  | Deny    | Abstain |
|--------------|---------|---------|---------|
| **Permit**   | Permit  | Deny    | Permit  |
| **Deny**     | Deny    | Deny    | Deny    |
| **Abstain**  | Permit  | Deny    | Abstain |

### C.2 Associativity verification (selected cases)

| (a, b, c)                       | combine(combine(a,b), c) | combine(a, combine(b,c)) | match |
|----------------------------------|--------------------------|--------------------------|-------|
| (Permit, Permit, Permit)         | Permit                   | Permit                   | ✓     |
| (Permit, Permit, Deny)           | Deny                     | Deny                     | ✓     |
| (Permit, Permit, Abstain)        | Permit                   | Permit                   | ✓     |
| (Permit, Deny, Permit)           | Deny                     | Deny                     | ✓     |
| (Permit, Deny, Deny)             | Deny                     | Deny                     | ✓     |
| (Permit, Deny, Abstain)          | Deny                     | Deny                     | ✓     |
| (Permit, Abstain, Permit)        | Permit                   | Permit                   | ✓     |
| (Permit, Abstain, Deny)          | Deny                     | Deny                     | ✓     |
| (Permit, Abstain, Abstain)       | Permit                   | Permit                   | ✓     |
| (Deny, Permit, Permit)           | Deny                     | Deny                     | ✓     |
| (Deny, Permit, Deny)             | Deny                     | Deny                     | ✓     |
| (Deny, Permit, Abstain)          | Deny                     | Deny                     | ✓     |
| (Deny, Deny, Permit)             | Deny                     | Deny                     | ✓     |
| (Deny, Deny, Deny)               | Deny                     | Deny                     | ✓     |
| (Deny, Deny, Abstain)            | Deny                     | Deny                     | ✓     |
| (Deny, Abstain, Permit)          | Deny                     | Deny                     | ✓     |
| (Deny, Abstain, Deny)            | Deny                     | Deny                     | ✓     |
| (Deny, Abstain, Abstain)         | Deny                     | Deny                     | ✓     |
| (Abstain, Permit, Permit)        | Permit                   | Permit                   | ✓     |
| (Abstain, Permit, Deny)          | Deny                     | Deny                     | ✓     |
| (Abstain, Permit, Abstain)       | Permit                   | Permit                   | ✓     |
| (Abstain, Deny, Permit)          | Deny                     | Deny                     | ✓     |
| (Abstain, Deny, Deny)            | Deny                     | Deny                     | ✓     |
| (Abstain, Deny, Abstain)         | Deny                     | Deny                     | ✓     |
| (Abstain, Abstain, Permit)       | Permit                   | Permit                   | ✓     |
| (Abstain, Abstain, Deny)         | Deny                     | Deny                     | ✓     |
| (Abstain, Abstain, Abstain)      | Abstain                  | Abstain                  | ✓     |

All 27 cases match. Combinator is associative.

### C.3 Identity verification

For all `a ∈ {Permit, Deny, Abstain}`:

- `combine(a, Abstain)` reads column 3 of the C.1 table: `Permit, Deny, Abstain` = `a` for each row. ✓
- `combine(Abstain, a)` reads row 3 of the C.1 table: `Permit, Deny, Abstain` = `a` for each column. ✓

Abstain is two-sided identity.

### C.4 Deny-by-default post-composition

| Chain combined result | Engine return |
|----------------------|---------------|
| Permit               | Permit        |
| Deny                 | Deny          |
| Abstain (incl. empty chain) | Deny    |

This conversion is engine-applied at the top of the call, not inside the combinator (§5.4).

---

## Appendix D — Ordering proofs

### D.1 Determinism of chain assembly (§4.1)

Given a Metadata Graph `G` with hash `gh`, a Tenant `t` with override version `v`, and an attachment key `ak`, the assembled chain is deterministic:

1. Base Policies at `ak` come from the compiled graph at `gh`. RFC-001 §5.8.3 guarantees the compile is deterministic, so the base Policy list is byte-identical across compiles.
2. Tenant-added Policies at `ak` are merged in order `(install_timestamp_asc, policy_fqn_lex_asc)`. Both install_timestamp and policy_fqn are stored per override row; ordering is total (no equal-timestamp policies have equal FQNs because §3 forbids duplicate FQN registration at the same point).
3. Segment order (§4.1) is fixed by the RFC; no runtime variation.

Conclusion: identical `(gh, t, v, ak)` produces identical chain orderings. ✓

### D.2 Cache key determinism (§8.2)

The cache key is composed of:
- `graphHash`: deterministic per RFC-001 §4.2.5.
- `tenantId`: stable per RFC-003 §2.1.
- `overrideVersion`: monotonic integer per RFC-003 §9.1.
- `attachmentKey`: fixed-format string per §8.4.
- `actorRoleHash`: deterministic per §1.3.
- `subjectClass`: `"null"` or `"instance-bound"` — closed enum.
- `decisionContextHash`: deterministic hash of decision-affecting Context fields per §8.5.

All components are deterministic; their composition is deterministic. ✓

### D.3 Composition does not depend on chain length

The combinator (§5.1) is associative and has an identity. Therefore folding `combine` left-to-right over a chain of any length yields the same result as folding right-to-left, and equal-length chains with the same multiset of decisions yield the same result regardless of order. Short-circuit on Deny is an optimization, not a semantic change. ✓

### D.4 Narrowing-only Tenant addition (§3.3 + §5)

Claim: a Tenant-added Policy in the chain cannot loosen a base denial.

Proof: by §5, if any Policy in the chain returns `Deny`, the combinator's result is `Deny`. A base Policy returning `Deny` therefore overrides any Tenant addition. A Tenant addition returning `Permit` cannot upgrade an `Abstain`-or-`Deny` base into `Permit` because the `Deny` short-circuits. The Tenant addition can only change `Abstain`-or-`Permit` base outcomes into `Deny` (by itself returning `Deny`), strictly narrowing access. ✓

Therefore §A-1.3's "additive narrowing only" promise is preserved by the engine's composition rule. ✓

---

## Appendix E — Rejected alternatives summary

| ID | Alternative | Reason rejected |
|----|-------------|-----------------|
| E.1 | RBAC baked into kernel | Plugin concern; engine commits only to Policy primitive (§14.1). |
| E.2 | Policy inheritance trees | Composition via chain, not OO; flat values (§14.2). |
| E.3 | Dynamic scripting (eval / SpEL / OPA-embed) | Boot-time PHP only (§14.3). |
| E.4 | Policy mutation at runtime | Graph is immutable; cache correctness (§14.4). |
| E.5 | Fail-open authorization | Always Deny on error (§14.5, §9.1). |
| E.6 | Network calls during evaluation | Determinism + caching (§14.6, §10). |
| E.7 | Per-Policy audit emission | Audit for decisions, not reasoning (§14.7). |
| E.8 | Direct Policy invocation outside engine | Bypasses composition, caching, audit (§14.8). |
| E.9 | Async Policy evaluation | Non-determinism in ordering (§15.1). |
| E.10 | Weighted Policy chains | Combinator sufficient; narrowing already supported (§15.2). |
| E.11 | Policy-author-defined Context keys | Closed set prevents grab-bag (§15.3). |
| E.12 | ABAC as kernel primitive | Plugin-implementable via Actor attributes (§15.4). |
| E.13 | Compiled bytecode chains | Premature optimization (§15.5). |
| E.14 | Per-Action Policy versioning | Defeats SemVer; use distinct FQN (§15.6). |
| E.15 | Conditional skip directives in chains | Breaks composition algebra (§15.7). |

Every rejected alternative is rejected normatively. Plugin code attempting any is non-conforming.
