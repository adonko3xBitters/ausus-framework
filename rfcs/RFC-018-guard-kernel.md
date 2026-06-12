# RFC-018 — Guard Kernel (Data-Aware Authorization)

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Draft                                                  |
| Authors       | architect, kernel, challenger                          |
| Date          | 2026-06-11                                             |
| Depends on    | RFC-001 (kernel primitives), RFC-005 (Policy Engine), RFC-006 (Workflow guard), RFC-007 (Audit), RFC-011 (DSL), RFC-013 (Action Effect) |
| Supersedes    | —                                                      |
| Stability     | Foundational. Every contract named here is V1 public surface; changes after acceptance require a follow-up RFC. |

> This document is written *from the implemented code* (`packages/kernel/src/kernel.php`,
> `packages/kernel/src/dsl.php`, `packages/runtime-default/src/runtime.php`,
> `packages/api-http/src/api.php`) and its tests (`packages/kernel/tests/phase2-actor-attributes.php`,
> `phase3-guard-closure.php`, `packages/runtime-default/tests/phase4-guard-runtime.php`).
> It describes only what is implemented. It introduces no mechanism absent from the code.

---

## 0. Problem statement

RFC-005 makes the kernel's authorization primitive the `Policy`: a pure predicate over
**identity** — an `Actor`, an action FQN, an optional `Subject` (an instance *reference*:
`tenantId / entityFqn / identityHandle`), and a `Context` (`tenant / correlationId /
traceId / clock`). A `Policy` is deliberately given no entity-field data and no repository;
RFC-005 §1.1 forbids I/O inside a Policy so the decision stays pure and cacheable by the
actor's role hash.

This is sufficient for role-gating ("an adjuster may approve") but cannot express any rule
whose decision depends on **the data being acted upon or on a numeric actor attribute**:

- approve a claim only when `claim.claim_amount <= adjuster.authority_limit`;
- act only when `subject.risk_score < threshold`;
- proceed only when an operation input is within a declared bound.

These are authorization decisions that read **subject field values** and **structured actor
attributes** at decision time. RFC-005's identity-only `Policy` cannot read either: the
`Subject` carries no field values, the `Context` carries no entity data, and `Actor` exposed
only string `roles()` / `permissions()`. Encoding the rule in a custom `Policy` fails (no
field data, no repository); encoding it in an `Effect` fails (it would authorize *after*
mutation begins, violating "authorize before act" and corrupting the error taxonomy).

This RFC adds a second, complementary authorization mechanism — the **Guard** — that runs
*after* the role Policy and *inside* the action's transaction, where the subject row can be
read consistently. A Guard is a pure, declarative predicate over **declared facts** drawn
from a fixed set of provenances. It does not replace the Policy: the Policy keeps its pure,
pre-transaction, role-decision role; the Guard adds the data-aware refinement that the
Policy contract cannot express.

The mechanism is intentionally narrow. It introduces no general rule engine, no external
calls, no aggregates, no history, and no relation traversal. Facts are scalar and
serializable; predicates are a closed set of operators; every fact a Guard reads must be
statically declared and validated at compile time.

---

## 1. Scope and inherited constraints

### 1.1 Inherited (non-negotiable)

1. `Policy` remains the identity-level authorization primitive (RFC-005). Guards do **not**
   modify, wrap, or replace it.
2. Composition is deny-overrides, fail-closed (RFC-001 §2.5, RFC-005): an authorization
   decision that is not a positive Permit is a denial.
3. "Authorize before act": no Effect (mutation) runs until both the Policy and the Guard
   chain have permitted (RFC-001 §8.2 / RFC-013).
4. Audit is in-transaction (RFC-007): a committed action emits exactly one audit entry in
   the same transaction as its writes.

### 1.2 Owned by this RFC

The kernel value/contract types (`Provenance`, `FactRef`, `Fact`, `FactSet`, `Cond`,
`Guard`, `Actor::attribute()`), the DSL surface (`Dsl::actorAttributes()`,
`ActionBuilder::requireThat()`), the compile-time closure check
(`Compiler::validateGuardClosure()`), the runtime (`ImmutableFactSet`, `CondEvaluator`,
`CondGuard`, `FactResolver`, `GuardComposer`), the Invoker insertion point and ordering,
the fail-closed rules, and the `AuditEntry.decisionBasis` carrier.

### 1.3 Out of scope

Authentication, identity resolution, and the trust boundary that supplies actor attributes
are not specified here (operational concern; see §11). No relation / aggregate / history /
external fact provenance. No visibility rules, completion gates, or approval chains.

### 1.4 Requirements labels

- **R-1** — Kernel contracts are pure data: facts are scalar and serializable, predicates do
  no I/O and are never closures, and the kernel guard types reference only other kernel
  symbols (no kernel → DSL dependency).
- **R-2** — Actor attributes are *server-resolved* scalars, deliberately excluded from the
  role hash so they never influence the role-decision cache key.

---

## 2. Terminology

| Term | Meaning |
|------|---------|
| **Fact** | An observed `⟨provenance, key, value⟩` triple. `value` is scalar (`int\|string\|float\|bool\|null`). |
| **FactRef** | A declared reference `⟨provenance, key⟩` — the unit of *closure*. |
| **Provenance** | The origin of a fact: `Actor`, `SubjectIdentity`, `SubjectField`, `OperationInput`, `Context`. |
| **Cond** | A declarative predicate tree over `FactRef`s and scalar literals. Pure data; serializable; never a closure. |
| **Guard** | A pure predicate bound to an operation: `⟨declared facts, predicate⟩ → Permit \| Deny \| Abstain`. |
| **Closure** | The set of `FactRef`s a Guard declares it reads (`Cond::factRefs()`). |
| **FactSet** | An immutable, runtime-resolved snapshot of facts read by a Guard. |
| **Decision basis** | The list of resolved `Fact`s captured at evaluation time (`FactSet::all()`). |

---

## 3. Data models

### 3.1 Provenance (kernel)

An open enum of fact origins (R-1 invariant: extensible):

```
enum Provenance: string {
    case Actor           = 'actor';          // roles / permissions / server-resolved attributes
    case SubjectIdentity = 'subject.id';     // tenantId / entityFqn / identityHandle
    case SubjectField    = 'subject.field';  // the subject's own declared fields
    case OperationInput  = 'op.input';       // the action's proposed inputs
    case Context         = 'context';        // clock / tenant / correlationId
}
```

### 3.2 FactRef and Fact (kernel)

```
final readonly class FactRef {
    public function __construct(public Provenance $provenance, public string $key) {}
}

final readonly class Fact {
    public function __construct(
        public Provenance $provenance,
        public string $key,
        public int|string|float|bool|null $value,   // SCALAR (R-1)
    ) {}

    public static function subject(string $key): FactRef { /* SubjectField */ }
    public static function actor(string $key): FactRef   { /* Actor */ }
    public static function input(string $key): FactRef   { /* OperationInput */ }
}
```

`Context` and `SubjectIdentity` references are constructed directly, e.g.
`new FactRef(Provenance::Context, 'tenant')`.

### 3.3 Cond (kernel)

A declarative predicate tree. Pure data, inspectable by the compiler and serializable.
Operands are `FactRef | Cond | scalar | array` (the array form is for `in`).

```
final readonly class Cond {
    public function __construct(public string $op, public array $args) {}

    public static function eq(mixed $a, mixed $b): self;
    public static function ne(mixed $a, mixed $b): self;
    public static function lte(mixed $a, mixed $b): self;
    public static function lt(mixed $a, mixed $b): self;
    public static function gte(mixed $a, mixed $b): self;
    public static function gt(mixed $a, mixed $b): self;
    public static function in(mixed $a, array $literals): self;
    public static function mul(mixed $a, float $k): self;   // numeric intermediate operand
    public static function not(Cond $c): self;
    public static function and(Cond ...$c): self;
    public static function or(Cond ...$c): self;

    /** Recursively collect declared FactRefs — the closure surface. */
    public function factRefs(): array;   // list<FactRef>
}
```

The eleven operators are exactly: `eq`, `ne`, `lt`, `lte`, `gt`, `gte`, `in`, `not`, `and`,
`or`, `mul`.

### 3.4 FactSet and Guard (kernel)

```
interface FactSet {
    public function get(Provenance $p, string $key): int|string|float|bool|null;
    public function has(Provenance $p, string $key): bool;
    public function all(): array;   // list<Fact> — the captured decision basis
}

interface Guard {
    public function reads(): array;            // list<FactRef> — declared closure
    public function decide(FactSet $facts): Decision;   // Permit | Deny | Abstain
}
```

### 3.5 Actor attribute (kernel)

`Actor` gains one method (R-2):

```
interface Actor {
    public function ref(): ActorRef;
    public function roleHash(): string;
    public function roles(): array;        // string[]
    public function permissions(): array;  // string[]
    public function attribute(string $key): int|string|float|bool|null;   // RFC-018
}
```

`StubActor` stores attributes as a defaulted, scalar-valued map. Attributes are **excluded
from `roleHash()`** so they never influence the role-decision cache key. The constructor
parameter is final and defaulted: `new StubActor($ref, $roles[, $permissions])` keeps
compiling unchanged.

### 3.6 Decision basis carrier (kernel)

`AuditEntry` gains a defaulted field that carries the resolved facts of a permitted,
guarded action:

```
public array $decisionBasis = [];   // list<Fact>, default empty
```

See §10 for its current persistence status.

---

## 4. Public contracts

The V1 public surface added by this RFC:

- Kernel value/contract types: `Provenance`, `FactRef`, `Fact`, `FactSet`, `Cond`, `Guard`.
- `Actor::attribute()` (interface extension).
- `AuditEntry.decisionBasis` (additive field).
- `ActionNode.guards` (additive field, `list<Cond>`).
- `MetadataGraph.actorAttributes` (additive field).
- `DanglingFactReference` (compile-time error; see §6).
- DSL: `Dsl::actorAttributes()`, `ActionBuilder::requireThat()`.
- Runtime: `ImmutableFactSet`, `CondEvaluator`, `CondGuard`, `FactResolver`, `GuardComposer`.

Concrete runtime classes (`ImmutableFactSet`, `CondEvaluator`, `CondGuard`, `FactResolver`,
`GuardComposer`) live in `ausus/runtime-default`; consumers depend on the kernel interfaces
(`Guard`, `FactSet`), not on the concrete classes.

---

## 5. DSL

### 5.1 Declaring a guard on an action

`ActionBuilder::requireThat(Cond $cond)` attaches a data-aware Guard to an action. It is
additive to `requireRole()`: an action may carry a role requirement (RFC-005 Policy) and one
or more `requireThat()` predicates (Guards). Each `Cond` is carried into the compiled
`ActionNode.guards`.

```
'approve' => Action::transition('status', from: 'FILED', to: 'APPROVED')
    ->requireRole('claims.adjuster')
    ->requireThat(Cond::lte(Fact::subject('claim_amount'), Fact::actor('authority_limit'))),
```

### 5.2 Declaring actor attributes

`Dsl::actorAttributes(array $schema)` declares the actor-attribute schema (attribute name →
declared type) at the plugin level. The schema accumulates into the plugin descriptor and is
folded into `MetadataGraph.actorAttributes`. It is consumed by the compile-time closure check
(§6) to validate that a `Fact::actor($key)` referencing a non-reserved key names a declared
attribute.

```
$dsl->actorAttributes(['authority_limit' => Field::integer()]);
```

---

## 6. Compilation

The `Compiler` collects each plugin's declared `actorAttributes`, then performs a **static
guard-closure check** before building the graph — `Compiler::validateGuardClosure($actions,
$entities, $actorAttributes)`. There is no runtime evaluation at compile time.

For every action carrying guards, for every `FactRef` reachable from every guard `Cond`
(`Cond::factRefs()`), the reference must resolve to declared metadata:

| Provenance | Must resolve to |
|------------|-----------------|
| `SubjectField` | a declared field of the action's entity |
| `OperationInput` | one of the action's declared inputs |
| `Actor` | a reserved key `{id, roles, permissions}` **or** a declared actor attribute |
| `Context` | a reserved key `{now, tenant, actor}` |
| `SubjectIdentity` | a reserved key `{id, type, tenant}` |

A reference that resolves to none throws `DanglingFactReference(actionFqn, provenance, key)`
at compile time. This is the static half of "no guard reads an undeclared fact"; it runs
after entities and actions are collected and before the graph is canonicalised.

Actions without guards are skipped entirely. The canonical graph hash is computed over the
**keys** of the graph collections (FQNs) — adding `guards` to an `ActionNode` or declaring
`actorAttributes` does not change the graph hash of an existing plugin.

---

## 7. Runtime

All runtime classes live in `ausus/runtime-default`.

### 7.1 ImmutableFactSet

An immutable `FactSet` with O(1) `get()` / `has()`, indexed by `provenance.value . '|' . key`.
`all()` returns the captured facts (the decision basis). No mutation after construction.

### 7.2 FactResolver

`FactResolver::resolve($refs, $actor, ?$subject, $inputs, $context, $persistence): FactSet`
resolves a list of declared `FactRef`s into an `ImmutableFactSet`. It loads the subject
**entity** only when at least one `SubjectField` reference is present *and* a subject exists,
via `$persistence->repository($subject->entityFqn)->find($subject)` — a single read inside the
active tenant's persistence context. Resolution is fail-safe: an unresolvable fact yields
`null`. Per-provenance resolution:

| Provenance | Resolution |
|------------|------------|
| `Actor` | `id` → `actor.ref().id`; `roles` → comma-joined roles; `permissions` → comma-joined permissions; otherwise → `scalarize(actor.attribute(key))` |
| `SubjectIdentity` | `id` → `identityHandle`; `type` → `entityFqn`; `tenant` → `tenantId`; otherwise `null` (and `null` when no subject) |
| `SubjectField` | `scalarize(entity.field(key))` when the entity loaded, else `null` |
| `OperationInput` | `scalarize(inputs[key])` when present, else `null` |
| `Context` | `now` → `clock.toRfc3339()`; `tenant` → `tenant.value()`; `actor` → `actor.ref().id`; otherwise `null` |

`scalarize()` keeps `null / int / string / float / bool` and maps any other value to `null`
(R-1: facts are scalar). A structured value (e.g. a money `{amount, currency}`) therefore
resolves to `null`.

### 7.3 CondEvaluator

`CondEvaluator::eval(Cond, FactSet): bool` evaluates the predicate tree:

- `and` → all sub-conditions true; `or` → any true; `not` → negation.
- `in` → operand strictly in the literal list.
- `mul(operand, k)` → numeric intermediate `num(operand) * k`, usable as a comparison operand.
- `eq` / `ne` → strict equality, or numeric equality when both operands are numeric.
- `lt` / `lte` / `gt` / `gte` → numeric comparison only; if either operand is non-numeric the
  comparison is **false** (fail-closed for non-numeric ordering).

`num()` coerces `int`, `float`, and numeric strings to `float`, otherwise `null`.

### 7.4 CondGuard

`CondGuard` adapts a `Cond` to the `Guard` interface: `decide()` returns `Permit` when the
`Cond` evaluates true, `Deny` when false. A `CondGuard` never returns `Abstain`. `reads()`
returns the `Cond`'s declared `FactRef`s.

### 7.5 GuardComposer

`GuardComposer::compose($guards, $facts): Decision` is deny-overrides: any guard deciding
`Deny` yields `Deny`; otherwise `Permit`. No positive Permit is required from a guard for the
set to permit (abstain-neutral), but `CondGuard` itself only ever permits or denies.

---

## 8. Evaluation order

The Invoker chain places the data-aware Guard **after** the role Policy and **inside** the
action's transaction:

```
1. Tenant context check          (subject tenant == active tenant)
2. Policy chain                  (RFC-005, identity-only) — PRE-transaction
   └─ deny → PolicyDenied (403), no transaction opened
3. Open transaction
   ├─ RFC-018 data-aware Guard   — IN-transaction
   │    a. collect FactRefs from action.guards (Cond::factRefs())
   │    b. FactResolver.resolve(...)  → loads subject (if SubjectField) within the tenant context
   │    c. CondGuard per Cond → GuardComposer.compose → Decision
   │    d. not Permit → PolicyDenied → rollback (403)
   │    e. decisionBasis = facts.all()
   ├─ Workflow guard              (RFC-006)
   ├─ Effect                      (RFC-013) — the mutation
   └─ Audit emission              (RFC-007) — carries decisionBasis
4. Commit
```

The Guard runs inside the transaction because it must read the subject row for a consistent
snapshot, and because a denial must roll back without side effects. The role Policy stays
pre-transaction so the common role-decision path opens no transaction. An action whose
`guards === []` skips the entire Guard block and follows the exact pre-RFC-018 path.

---

## 9. Fail-closed behaviour

Every layer denies on doubt:

- **FactResolver** — an unresolvable or non-scalar fact resolves to `null`.
- **CondEvaluator** — a non-numeric operand in an ordering comparison yields `false`.
- **CondGuard** — `false` → `Deny`.
- **GuardComposer** — deny-overrides; any `Deny` denies the set.
- **Invoker** — a guard denial throws `PolicyDenied`; the `finally` block rolls the
  transaction back if it was not committed, so a denial leaves no mutation and no commit.

A guard that references data that cannot be resolved therefore denies the action rather than
permitting it.

---

## 10. Security

- **Actor attributes are server-resolved scalars (R-2).** Over HTTP, they are read from the
  `X-Actor-Attributes` request header (a flat JSON object), parsed fail-safe (a missing
  header, invalid JSON, or non-object payload yields `[]`; only scalar/null values are kept).
  The header is **trusted as set by an upstream authenticated gateway** — the same trust model
  the HTTP layer already applies to `X-Tenant-ID` and `X-Actor-Roles`. The header is not
  signed by this layer; the integrity of actor attributes depends on the trusted gateway
  (see §11).
- **Attributes are excluded from `roleHash()`** so they cannot influence the role-decision
  cache key.
- **Tenant isolation is preserved.** The Guard reads the subject through the active tenant's
  persistence context, and the Invoker asserts `subject.tenant == active tenant` before the
  guard runs; a guard cannot read another tenant's row.
- **No I/O in predicates (R-1).** A `Cond` is pure data; the only read performed during guard
  evaluation is the single subject load by the `FactResolver`.

---

## 11. Compatibility

All additions are backward-compatible by construction:

- `ActionNode.guards`, `MetadataGraph.actorAttributes`, `AuditEntry.decisionBasis`, and
  `StubActor`'s attributes parameter are all defaulted and appended — every existing
  construction site keeps compiling.
- The `Invoker` gains two defaulted constructor parameters (`FactResolver`, `GuardComposer`);
  existing construction sites are unchanged.
- `guards === []` reproduces the exact historical invocation path.
- The graph hash is computed over collection keys, so existing plugins keep their hash.
- `Actor::attribute()` is an **interface extension**: any class implementing `Actor` must now
  provide `attribute()`. Within the codebase, `StubActor` is the sole implementor and is
  updated. This is the one non-additive surface change and is a versioning event for the
  kernel package.

---

## 12. Known limitations

These are observed in the implementation and stated as limitations, not as planned work:

1. **`decisionBasis` is not persisted.** The Invoker captures the resolved facts and carries
   them on `AuditEntry.decisionBasis`, but no `AuditSink` writes the field — it is in-memory
   only. A permitted guarded action's decision basis is not currently recorded in the audit
   table.
2. **Data-aware denials are not audited.** A guard denial throws `PolicyDenied` and rolls the
   transaction back before the audit emission step, so a denied action produces no audit row.
   This matches the existing behaviour of pre-transaction role-Policy denials.
3. **Actor attributes are trusted from the HTTP gateway.** The `X-Actor-Attributes` header is
   not signed at the HTTP layer; the correctness of any attribute-based guard depends on a
   trusted upstream gateway setting it. In a deployment without such a gateway, a client may
   supply its own attribute values.
4. **Dependency on a trusted gateway.** Data-aware authorization that reads actor attributes
   is only as trustworthy as the gateway that resolves them; the trust boundary itself is
   outside this RFC.
5. **Scalar-only facts.** Non-scalar field/input values (e.g. structured money) resolve to
   `null` under the fail-safe resolver and cannot be compared directly.
6. **Fixed provenance set.** Only `Actor`, `SubjectIdentity`, `SubjectField`, `OperationInput`,
   and `Context` facts are resolved. No relation, aggregate, history, or external provenance is
   implemented.

---

## 13. Test coverage of record

- `packages/kernel/tests/phase2-actor-attributes.php` — actor-attribute value objects and
  scalar carriage (12 assertions).
- `packages/kernel/tests/phase3-guard-closure.php` — `requireThat()`, the compile-time
  closure check, and `DanglingFactReference` for undeclared subject fields, inputs, and actor
  attributes (6 assertions).
- `packages/runtime-default/tests/phase4-guard-runtime.php` — `CondEvaluator` (all eleven
  operators), `FactResolver` across provenances, `GuardComposer` deny-overrides, and an
  end-to-end claims case through the real Invoker proving
  `claim_amount <= authority_limit → Permit` and `claim_amount > authority_limit →
  PolicyDenied` (25 assertions).
