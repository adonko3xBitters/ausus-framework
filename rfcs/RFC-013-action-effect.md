# RFC-013 — Action Effect Contract

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Draft                                                  |
| Authors       | architect, kernel, challenger                          |
| Date          | 2026-05-18                                             |
| Depends on    | RFC-001 Draft-04 (incl. Amendments-01 / -02), RFC-002, RFC-003, RFC-005, RFC-007 Draft-02 (incl. Amendment-01), RFC-010, RFC-011, RFC-012 |
| Mission       | Formalize the `Effect` contract that the Invoker dispatches to per RFC-001 §A-1.4 §8.2.1 step 4. Unblock RFC-000 F-V0-02. |
| Hard rule     | No new kernel primitives. Effect is the formal name for the de facto interface used by `ausus/runtime-default` (RFC-012 §6.2) and resolved by convention per RFC-011 §5.2. |

---

## 0. Problem statement

RFC-001 Amendment-01 §A-1.4 §8.2.1 specifies the Invoker chain:

```
1. Tenant Context check
2. Policy chain (RFC-005)
3. Workflow guard (RFC-001 §8.2 / RFC-006 deferred)
4. Action effect (delegated to the registered Action implementation)
5. Audit emission (RFC-007)
```

Step 4 — "delegated to the registered Action implementation" — is the only step the kernel does not specify. RFC-011 §5.2 commits the DSL to convention-resolve effect classes as `<plugin PHP namespace>\Effects\<ActionName>Effect`. RFC-012 §6.2 ships a "de facto" interface inside `ausus/runtime-default` to make the runtime work. RFC-000 F-V0-02 lists this gap as a BLOCKER.

This RFC formalizes the `Effect` contract. It does **not** introduce a new kernel primitive: Actions, ActionDescriptors, the Invoker, transactions, Audit, and Tenant binding are all unchanged. What this RFC adds is the contract that `Effect` classes (plugin code) must satisfy and the `EffectContext` that the Invoker passes them. Both live alongside `ausus/runtime-default` as plugin-facing surface; both are SemVer-bound under the V1 kernel.

Ten challenger constraints frame the design space:

1. No bypass of the Invoker.
2. No direct Driver access.
3. No Unit of Work.
4. No service locator from inside an Effect.
5. No async Effects in V1.
6. Secondary mutations route through the Invoker.
7. Compatible with RFC-002 transactions.
8. Compatible with RFC-003 elevation.
9. Compatible with RFC-007 audit.
10. Compatible with RFC-011 convention-based resolution.

Every section below satisfies all ten.

---

## 1. Scope and inherited constraints

### 1.1 Inherited (non-negotiable)

1. Actions are the **only** way state is mutated (RFC-001 §2.4).
2. The Invoker is the sole authorized caller of Effects (RFC-001 §A-1.4 §8.2.1).
3. Mutations execute inside an Invoker-managed transaction; rollback is at the Invoker's discretion (RFC-002 §7.3).
4. Audit emission happens after the Effect returns, before transaction commit, via the Auditor (RFC-007 §3.3).
5. Primary audit failure aborts the Action and rolls back the transaction (Amendment-01 §A-1.6).
6. Plugins must not import driver internals (RFC-002 §3.2.5).
7. No closures in descriptor payloads (RFC-001 §5.8.6).
8. Convention class resolution per RFC-011 §5.2 unless overridden via `->effect(...)`.
9. Effect classes are stateless across calls (consistent with the Policy stateless rule, RFC-005 §2.4).
10. Effect classes never receive runtime-resolved service container handles.

### 1.2 Out of scope

- Asynchronous Effects, queued background work as Effect kind. Background work is plugin-author choice using their own Laravel jobs invoking Actions through the Invoker; it is not a new Effect kind.
- Effect inheritance trees (rejected symmetrically with RFC-005 §14.2).
- Multi-method Effect classes (one Action → one Effect class → one method).
- Effect-level audit emission (Effects do not call the Auditor; the Invoker does).
- Effect-level Workflow state mutation. The simple Workflow runtime in RFC-012 §6.3 handles state column writes; custom Effects MAY write state explicitly when not using `Action::transition` sugar.
- Idempotency keys at the API layer (RFC-005 deferred; out of this RFC).
- Distributed transactions, sagas, compensation (post-V1 per RFC-002 §14).

---

## 2. Interface canonical

### 2.1 Decision

```php
interface Effect
{
    public function execute(
        EffectContext $context,
        ?Reference $subject,
        array $inputs
    ): array;
}
```

**Method name: `execute`.** Three arguments. Returns `array<string,mixed>` representing the Action's outputs.

### 2.2 Why `execute` (not `__invoke`, not `handle`)

- `__invoke` couples Effect to PHP's callable magic. It permits `array_map($effect, ...)`-style usage and obscures the dispatch boundary the Invoker enforces. **Rejected.**
- `handle` is Laravel-flavoured (Job::handle, Notification::handle, Mailable::handle). It matches RFC-012 §6.2's de facto, but the symmetry with `Policy::evaluate` (RFC-005 §2.1) — a verb-named single-method interface — is broken. **Rejected.**
- `execute` is verb-named, framework-neutral, symmetric with `Policy::evaluate`, explicit, IDE-discoverable. **Adopted.**

This RFC's `execute` supersedes RFC-012 §6.2's de facto `handle`. RFC-012 §16.5 lists ActionEffect as provisional; on acceptance of this RFC, `ausus/runtime-default` releases a major bump renaming `handle` → `execute`.

### 2.3 Why three arguments (not four)

RFC-012 §6.2's de facto used four arguments: `(PersistenceContext $ctx, ?Reference $subject, array $inputs, Context $context)`. The fourth (`Context`) is the Policy Engine's `Context` (RFC-005 §7.1), which collides nominally with the call's surrounding "context" semantics.

This RFC consolidates everything an Effect needs into a single `EffectContext` value object (§3). The signature becomes:

```php
execute(EffectContext $context, ?Reference $subject, array $inputs): array
```

Three arguments, no naming collisions. `EffectContext` exposes the Policy `Context`, the `PersistenceContext`, the `Invoker`, the `Actor`, the `Tenant`, the `CorrelationId`, the `TraceId`, the `Elevation`, and the `Clock` — everything in one bundle.

### 2.4 Input shape

`$inputs` is `array<string, mixed>`. Keys are the input names declared in the Action's `inputs` schema (RFC-001 §2.4 + RFC-011 §8.2). Values are typed per RFC-004 §4 value types (string, integer, decimal, boolean, date, datetime, time, enum, money, json, reference, null).

The kernel validates inputs against the Action's declared schema BEFORE calling `execute`. The Effect receives a validated map. Any missing required input or any type mismatch raises `InputValidationFailed` (§7.1) at the Invoker boundary; `execute` is never called.

The Effect MAY read keys; it MUST NOT mutate `$inputs`. PHP's pass-by-value protects in practice; semantically, `$inputs` is frozen for the call.

### 2.5 Output shape

The return value is `array<string, mixed>`. Keys are arbitrary; values are JSON-serializable.

The Invoker uses the return value:

1. As the AuditEntry's `outputs` field (RFC-007 §2.1). Subject to RFC-007 §14 redaction.
2. As the response payload returned to the L4 API Surface.

Effects returning non-array values raise `EffectMalformedReturn` (§7.1). Effects returning `null` are interpreted as `[]` (empty outputs).

### 2.6 Subject argument

`?Reference $subject` is the canonical reference tuple (RFC-001 §2.1.1.4 — `(tenant_id, entity_fqn, identity_handle)`). Nullability mirrors RFC-005 §6.1's Subject=null cases:

- Subject is `null` for `subject_required: false` Actions (pre-create, MaintenanceAction).
- Subject is non-null for `subject_required: true` Actions.

The kernel enforces presence/absence at the Invoker boundary; mismatch raises `PolicySubjectRequired` (RFC-005 §13) before reaching `execute`.

When non-null, `Reference::tenantId()` always equals the active Tenant (RFC-002 §13.1). Effects MAY assume this without re-checking.

---

## 3. EffectContext

### 3.1 Contract

```php
interface EffectContext
{
    function persistence(): PersistenceContext;     // RFC-002 §4
    function invoker(): Invoker;                     // RFC-001 §A-1.4
    function actor(): Actor;                         // RFC-005 §1.3
    function tenant(): Tenant;                       // RFC-003 §2.2
    function correlationId(): string;                // RFC-007 §2.1
    function traceId(): ?string;                     // RFC-007 §2.1
    function elevation(): ?ElevationRef;             // RFC-003 §10.5
    function clock(): Instant;                       // pinned per Invoker call
}
```

### 3.2 Invariants

1. `EffectContext` is **constructed by the Invoker** and passed to `execute`. Plugin code never instantiates it.
2. The instance is **immutable** for the lifetime of the `execute` call. The same instance is observed by the Effect and by any nested Action invocations spawned through `invoker()`.
3. `tenant()` is the **active** Tenant. Inside an elevated scope (RFC-003 §10), this is the **target** Tenant, not the origin. `elevation()` returns the elevation reference; `null` outside elevation.
4. `clock()` is pinned at the start of the outer Invoker call. Every Effect in a single Action invocation (the outer plus any nested) observes the same Clock. Cross-Invoker-call invocations see different Clocks.
5. `correlationId()` is the active correlation. Nested Invoker calls inherit. `traceId()` is propagated from the entry point.

### 3.3 `persistence()` access

The `PersistenceContext` returned is the **same** instance the Invoker constructed (RFC-002 §4.1). Effects acquire Repositories via `persistence()->repository($entityFqn)`. All Repository operations execute inside the active transaction (RFC-002 §7.3).

Effects MAY:

- Call `Repository::find`, `findMany`, `iterate`, `exists`, `count`, `fetchRelated` — reads.
- Call `Repository::create`, `update`, `delete`, `updateMany`, `deleteMany` — writes (subject to MaintenanceAction acknowledgement for bulk, RFC-002 §11.4).

Effects MUST NOT:

- Call `PersistenceContext::transaction()` and pass the handle to `PersistenceDriver::commit` or `::rollback`. The Invoker owns the lifecycle (RFC-002 §7.2). Attempting this raises `UnauthorizedTransactionControl` (RFC-002 §4.2).
- Construct a `PersistenceContext` by another mechanism (e.g., resolving from the container) — the container binding is private to L2.

### 3.4 `invoker()` access — nested Actions

`invoker()` returns an `Invoker` reference for spawning nested Action invocations:

```php
$ctx->invoker()->invoke(
    $ctx->actor(),
    'billing.notification.send',
    null,
    ['invoice_id' => $subject->identityHandle(), 'event' => 'issued']
);
```

Nested invocations run the full §8.2.1 chain (Tenant check → Policy chain → Workflow guard → Effect → Audit). Each nested Effect receives its own `EffectContext`, but the inner Context shares `tenant()`, `actor()`, `correlationId()`, `traceId()`, `clock()`, and `elevation()` with the parent. The inner `persistence()` returns a `PersistenceContext` bound to a savepoint inside the parent's transaction (RFC-002 §7.4).

Per RFC-002 §7.4, drivers MUST support at least 8 levels of nesting.

### 3.5 `actor()`, `tenant()` — read-only

`actor()` returns the current Actor satisfying RFC-005 §1.3 minimum. Effects MAY call `actor()->roles()`, `actor()->permissions()`, etc., for Authorization-plugin-defined extensions. **They MUST NOT mutate.** The Actor value object is immutable.

`tenant()` is similarly read-only. To operate against a different Tenant, the Effect uses `invoker()` with an elevation (`Ausus::elevate(targetTenant, reason: ...)`), which establishes a new Invoker context. Per RFC-003 §10.3, nested elevation is forbidden; an Effect already inside an elevated scope cannot re-elevate.

### 3.6 What `EffectContext` does NOT expose

- `PersistenceDriver` — only `PersistenceContext`. Driver access is L3-internal.
- `ReportingDriver` — Effects do not consume reports; they mutate. ReportingDriver access from inside Effects is forbidden by symmetry with RFC-005 §10's Policy prohibitions.
- `Auditor` — audit is post-Effect (RFC-007 §3.3); Effects do not emit.
- `AuditSink` — never directly.
- Laravel `app()`, `resolve()`, facades — service-locator access from inside Effects is forbidden (challenger constraint 4).
- HTTP request, response objects — L4 transport is invisible to Effects.
- File system, network, queue dispatch facades — see §10.

### 3.7 No service locator

The `Effect` class's constructor receives **only static configuration** passed at boot via the container (consistent with RFC-005 §2.4 for Policies). Typical Effect class:

```php
final class IssueEffect implements Effect
{
    public function __construct()
    {
        // no service dependencies
    }

    public function execute(EffectContext $ctx, ?Reference $subject, array $inputs): array
    {
        // EffectContext is the only runtime surface
    }
}
```

If the Effect needs static config (e.g., a per-deployment threshold), it accepts via constructor:

```php
public function __construct(private int $approvalThreshold) {}
```

Container resolves the constructor at boot using a deployment-provided binding. Runtime `app()` calls from inside `execute` are detected by the runtime spy (symmetric with RFC-005 §10.3 Policy side-effect detection) and raise `EffectForbiddenSideEffect` (§7.1).

---

## 4. Nested Actions

### 4.1 The only authorized path

Effects that mutate state on Entities **outside their own Action's domain** SHOULD route through `$ctx->invoker()->invoke(...)`. This:

1. Re-runs the full Invoker chain on the secondary mutation (Tenant check, Policy chain, Workflow guard, Effect, Audit emit).
2. Produces a separate audit entry for the secondary mutation, traceable via shared `correlationId`.
3. Honours the secondary Entity's own Policies, Workflow, and audit settings.
4. Operates inside a savepoint of the parent transaction; rollback at any level cascades correctly.

### 4.2 What is NOT enforced

The kernel **cannot mechanically prevent** an Effect from calling `$ctx->persistence()->repository('billing.customer')->update(...)` directly. The Repository contract permits any Entity write within the active Tenant.

The normative guidance is: **use the Invoker for cross-Entity / cross-domain mutations.** Direct cross-Entity Repository writes from an Effect are permitted by the contract but bypass the secondary Entity's Policies and Workflow. Plugin authors are responsible for the choice; `ausus:doctor` (§9 below) detects the pattern via static analysis where possible.

### 4.3 Nesting depth bound

Per RFC-002 §7.4, drivers MUST support 8 levels of nested savepoints. Effects MAY invoke nested Actions up to 7 levels deep (the outer Action is level 0); the 8th level fails with `NestedInvokerDepthExceeded`. In practice, designs reaching this depth are pathological.

### 4.4 Async or queued nesting

V1 does not permit asynchronous nested invocation. An Effect that wants to dispatch background work uses Laravel's queue facade (which is a service-locator violation per §10) — **forbidden inside `execute`**.

The pattern: the Effect itself completes synchronously. Background work is dispatched by L4 (the controller that received the request) AFTER the Action returns successfully. The L4 layer is outside `execute`; it can call `Queue::push(...)`.

Alternatively, a future RFC may introduce an explicit `Action::dispatch_async(...)` primitive. Out of V1.

### 4.5 Recursive invocation of the same Action

An Effect that calls `$ctx->invoker()->invoke($actor, <same ActionFqn>, ...)` is permitted by the kernel; the Invoker re-runs the chain. The Policy Engine detects deep recursion via RFC-005 §9.6's depth counter. Recursion is the plugin author's responsibility to terminate.

---

## 5. Transaction semantics

### 5.1 The Effect runs entirely inside one transaction

When the Invoker calls `execute`:

1. The active transaction (opened in §8.2.1 step 4's preamble per RFC-002 §3.1) is bound to `EffectContext::persistence()`.
2. Every Repository call inside `execute` runs inside this transaction.
3. Nested Invoker calls open savepoints within the same transaction (RFC-002 §7.4).

### 5.2 Effect has no commit/rollback visibility

After `execute` returns:

- The Invoker proceeds to audit emission.
- If audit emission succeeds → Invoker calls `driver.commit(txn)`. All Repository writes (parent + nested) become visible.
- If audit emission fails → Invoker calls `driver.rollback(txn)`. All Repository writes (parent + nested) are reverted.

If `execute` throws:

- The Invoker catches.
- The Invoker calls `driver.rollback(txn)`. All Repository writes are reverted.
- No audit emission for the failed attempt (V1; RFC-007 §15 read-audit semantics are unrelated; mutation-attempt audit is post-V1 per RFC-005 §17.2 and out of V1 scope).
- The Invoker wraps the exception and returns to the caller (§6).

### 5.3 No partial visibility

Reads issued by the Effect after a write within `execute` see the write (transaction-local view). Reads after a nested-Action invocation see the nested writes (within the same transaction). No partial-visibility ambiguity.

Reads issued by other concurrent Invoker calls (in other transactions) do NOT see the in-flight writes until commit (standard transactional isolation).

### 5.4 Effect MUST NOT call commit/rollback

`EffectContext::persistence()->transaction()` is exposed by RFC-002 §4.1 for introspection only. Effects passing the handle to `PersistenceDriver::commit` or `::rollback` violate RFC-002 §7.2. Detection raises `UnauthorizedTransactionControl` (RFC-002 §4.2). The Effect is reported as failed.

### 5.5 No transaction-spanning Effects

An Effect cannot span multiple transactions. Each Invoker call is one transaction. Effects requiring "stage 1 commit, then stage 2 work" pattern decompose into two Actions invoked sequentially by L4 (with whatever business-level idempotency the use case needs).

---

## 6. Idempotency

### 6.1 The Invoker does not retry Effects

If `execute` throws or audit emission fails, the Invoker returns an error. **The Invoker does not retry the Action.** The transaction is rolled back; no state changed. The error surfaces to L4 which decides what to do.

### 6.2 Caller-driven retry

If L4 (or a queue worker, or an HTTP retry policy at the client) retries the Action, the Invoker runs from scratch:

- New transaction.
- Same Policy chain.
- Same Workflow guard.
- Same Effect class invoked with possibly the same inputs.
- New audit entry (new `entryId` per RFC-007 §2.2.1).

The Effect MUST NOT assume "this is a retry." The Effect's responsibility is to be deterministic over `(Actor, Subject, inputs)`.

### 6.3 Idempotency by inputs

Effects that perform identity-creating operations (e.g., `create`) and want to be safe under retry require an idempotency key supplied by the caller in `$inputs`. The Effect uses the key to detect a prior successful creation and return the existing identity. This is a caller-coordinated pattern, not a kernel-provided one in V1.

A future RFC may add `Action::idempotent($keyField)` sugar that automatically dedups based on a declared input field. Out of V1.

### 6.4 No automatic at-least-once

V1 is **at-most-once** at the Invoker level: the Effect runs zero or one times per `Invoker::invoke` call. There is no "at-least-once" guarantee — if the network drops the response, the caller doesn't know whether the Effect ran.

The Audit Spine (RFC-007) provides ground truth: if an entry exists with the corresponding `(correlationId, actionFqn, subject)`, the Effect ran. Callers consulting the audit log can disambiguate.

### 6.5 Duplicate invocation within the same Invoker call

The Invoker invokes each Effect exactly once per call (per §8.2.1). The kernel does not call `execute` twice for the same `invoke` invocation. Duplicate calls would violate the audit shape (one AuditEntry per invocation). The Invoker enforces.

---

## 7. Error semantics

### 7.1 Closed error taxonomy

```
EffectError                                  (abstract base)
├── EffectClassNotFoundByConvention(actionFqn, expectedClass)  (RFC-011 §10; restated)
├── EffectContractViolation(class, reason)                     (signature mismatch at registration)
├── EffectInstantiationFailure(class, cause)                   (constructor failed at boot)
├── EffectRuntimeRegistration(class)                           (post-boot register attempt)
├── InputValidationFailed(actionFqn, errors)                   (kernel-side input validation)
├── EffectMalformedReturn(actionFqn, returnedValue)            (return value not array<string,mixed>)
├── EffectForbiddenSideEffect(actionFqn, operation)            (runtime spy detection: container access, etc.)
├── NestedInvokerDepthExceeded(actionFqn, depth)               (>7 levels of nested invocation)
├── EffectFailed(actionFqn, cause)                             (wraps any uncaught Throwable from execute)
```

All errors extend `EffectError`. Plugin-thrown exceptions from inside `execute` are wrapped in `EffectFailed(actionFqn, cause: $throwable)`.

### 7.2 Exception handling at the Invoker

When `execute` throws:

1. The Invoker catches at the call boundary.
2. The Invoker calls `driver.rollback($txn)`. All Repository writes from this Effect + any nested invocations are reverted.
3. If the thrown exception is already an `EffectError`, it is returned as-is.
4. If the thrown exception is a `PersistenceError` (RFC-002 §12.1), it is returned as-is (driver errors propagate transparently — they are already closed taxonomy).
5. If the thrown exception is a `PolicyEngineError` from a nested Invoker call, it is returned as-is.
6. Any other `Throwable` is wrapped in `EffectFailed(actionFqn, cause: $throwable)`.

### 7.3 Plugin exceptions are not magic

Plugin-defined exceptions (e.g., `\DomainException`, `\Acme\Billing\InvoiceCannotBeIssued`) are caught and wrapped in `EffectFailed`. The original exception is preserved in `cause`. L4 unwraps to extract the original for HTTP status mapping.

This means **all** Effect failures look the same to the Invoker. No special "domain exception" path; no special-case rollback skipping. The Invoker rolls back unconditionally on any throw.

### 7.4 No audit for failed Effects in V1

If `execute` throws, no AuditEntry is emitted (audit emission is step 5; the Effect failure occurred at step 4). The mutation never happened (rollback); there is nothing to audit.

Operational forensics for failed attempts is RFC-009 telemetry's concern (out of this RFC). A future RFC may introduce "attempt audit" as a distinct AuditEntry kind. Out of V1.

### 7.5 Workflow guard failures vs Effect failures

A Workflow guard failure (step 3) means `execute` is never called. The Invoker returns an error before opening the Effect's transaction. No rollback needed; no audit. The caller sees a Workflow-rejection error.

An Effect failure (step 4) means `execute` was called and threw. Rollback applies. Caller sees an `EffectFailed` error.

L4 distinguishes the two for HTTP status purposes (Workflow rejection → 409 Conflict; Effect failure → 422 Unprocessable Entity or 500 Internal Server Error, depending on cause).

---

## 8. Compiler validation

The Compiler (RFC-001 §4.2) validates Effect classes at compile time. Failures abort `ausus:compile` with diagnostics.

### 8.1 Class existence

For every Action declared with a custom Effect (`->effect(\Foo\Bar::class)`) OR convention-resolved (RFC-011 §5.2), the Compiler verifies:

- The class FQN is resolvable via PSR-4 autoloader.
- The class exists.

Failure: `EffectClassNotFoundByConvention(actionFqn, expectedClass)` (when convention resolution failed) or `EffectClassNotFound(actionFqn, declaredClass)` (when explicit override failed).

### 8.2 Signature validation

The Compiler verifies via reflection:

- The class implements `Ausus\Effect`.
- The `execute` method exists with exactly three parameters.
- Parameter 1 is type-hinted `EffectContext` (or untyped — type hints are optional per RFC-011 §4.3).
- Parameter 2 is `?Reference` (or untyped).
- Parameter 3 is `array` (or untyped).
- Return type is `array` (or untyped).
- The method is `public`.

Failure: `EffectContractViolation(class, reason)`.

### 8.3 Instantiability

The Compiler does NOT instantiate every Effect class at compile time (would require running constructors with potentially live configuration). Instead, it verifies:

- The class's constructor is callable from the Laravel container (no circular dependencies, no missing required scalar parameters that have no binding).
- The class is not `abstract`.
- The class is `final` (recommended) or at minimum not in a known Effect inheritance chain. (Effect classes SHOULD be final to discourage subclassing; warning issued if not final, per §9.)

Failure: `EffectInstantiationFailure(class, cause)` at boot when the container cannot instantiate.

### 8.4 Built-in Effects do not require classes

Actions declared via RFC-011 §8.2 built-ins (`Action::create(...)`, `Action::transition(...)`) use default Effects bundled in `ausus/runtime-default`. The Compiler verifies the built-in is recognized; no plugin-side class is required. The convention-resolution is skipped for these Actions.

### 8.5 Convention vs explicit precedence

When both convention-resolved AND explicit `->effect()` apply (the convention class exists and the author also called `->effect(SomeClass::class)`), the **explicit override wins**. The Compiler emits a `EffectConventionOverridden(actionFqn, conventionClass, explicitClass)` notice for clarity, not an error.

### 8.6 Convention class with wrong contract

If the convention path resolves to a class that exists but does NOT implement `Effect`, the Compiler raises `EffectContractViolation(class, "convention-resolved class does not implement Ausus\\Effect")`. The author either fixes the class or specifies an explicit `->effect()`.

---

## 9. Doctor checks

`ausus:doctor` (RFC-001 §5.5, extended by RFC-012 §10) adds the following Effect-specific checks:

| # | Check                                                                                    | Severity |
|---|------------------------------------------------------------------------------------------|----------|
| 1 | Every Action's Effect class is instantiable from the container.                          | error    |
| 2 | Convention-resolved Effect classes have unique paths (no two Actions resolve to the same class). | error    |
| 3 | Effect classes are `final` (recommended pattern).                                        | warning  |
| 4 | Effect classes declared but unreferenced (no Action points at them) — orphan Effect.     | notice   |
| 5 | Effect classes whose constructors require unresolvable scalar parameters.                | error    |
| 6 | Effect classes that import `Illuminate\Support\Facades\*` or call `app()` / `resolve()` (static analysis). | warning |
| 7 | Effects that call `PersistenceContext::transaction()->commit(...)` or similar — detected by AST scan if available. | warning |
| 8 | Effect-class-to-Action-name convention deviation count. If >50% of Actions use explicit `->effect()`, surfaces as a notice (suggests plugin organization mismatch with convention). | notice |

Items 6, 7, 8 are static-analysis-best-effort; PHP's lack of sandboxing limits enforcement, consistent with RFC-005 §10.3.

---

## 10. Rejected alternatives

The following patterns are explicitly rejected for V1. Each rejection is normative — plugin code attempting any is non-conforming.

### 10.1 Closures as Effects

```php
'issue' => Action::transition(...)->effect(function ($ctx, $subject, $inputs) { ... })
```

**Rejected** by RFC-001 §5.8.6 (no closures in descriptor payloads). Restated.

### 10.2 Static Effect methods

```php
class InvoiceEffects {
  public static function issue($ctx, $subject, $inputs): array { ... }
}
```

**Rejected.** Effect is an instantiable class with one instance per Action binding. Static-method dispatch obscures construction-time static configuration (§3.7) and breaks the symmetry with `Policy`.

### 10.3 Effect inheritance trees

```php
abstract class BaseEffect implements Effect { ... }
class IssueEffect extends BaseEffect { ... }
```

**Rejected** symmetrically with RFC-005 §14.2. Shared logic factored into pure helper functions or trait composition is acceptable; inheritance hierarchies with overridden `execute` are not.

### 10.4 Service locator from inside Effects

```php
public function execute(...) {
  $service = app(MyService::class);
}
```

**Rejected.** Effect classes receive runtime data through `EffectContext` only. Static configuration is constructor-injected at boot. The runtime spy raises `EffectForbiddenSideEffect` on detection.

### 10.5 Direct Driver access

```php
public function execute(EffectContext $ctx, ...) {
  $driver = $ctx->persistence()->driver();  // does not exist
}
```

**Rejected.** `PersistenceContext` does not expose `driver()`. The kernel binding to `PersistenceDriver` is private to L2 / L3 boundary.

### 10.6 Out-of-band transaction control

```php
public function execute(EffectContext $ctx, ...) {
  $txn = $ctx->persistence()->transaction();
  PersistenceDriver::commit($txn);  // forbidden
}
```

**Rejected** by RFC-002 §7.2 + RFC-002 §4.2's `UnauthorizedTransactionControl`. Restated.

### 10.7 Async / queued Effects in V1

```php
class IssueEffect implements AsyncEffect { ... }   // no such interface
```

**Rejected** for V1 (§4.4). Background dispatch happens at L4, not from inside `execute`. The pattern of "fire and forget" inside an Effect breaks audit correlation, transaction boundaries, and the Invoker's atomic chain.

### 10.8 Unit of Work pattern

**Rejected** by RFC-002 §15.1. Restated. Every Repository call is immediate within the active transaction; no queue-and-flush.

### 10.9 `__invoke` magic method as Effect signature

**Rejected** (§2.2). Effects use explicit `execute()`.

### 10.10 Return types other than `array<string, mixed>`

```php
public function execute(...): InvoiceDto { ... }
```

**Rejected.** Return value is the AuditEntry's `outputs` field; the audit subsystem consumes JSON-serializable arrays per RFC-007 §2.1. Custom return types break audit serialization. L4 builds its DTO from the returned array.

### 10.11 Stateful Effect classes (cross-call instance state)

```php
class CounterEffect implements Effect {
  private int $counter = 0;
  public function execute(...) { $this->counter++; ... }
}
```

**Rejected** (§3.7). Effects are stateless across calls. The runtime spy MAY detect mutation of `$this->*` properties post-construction.

### 10.12 Multi-method Effect classes

```php
class InvoiceEffects implements Effect {
  public function execute(...) { /* main */ }
  public function executeAlternate(...) { /* secondary */ }
}
```

**Rejected.** One Effect class per Action. The Invoker dispatches via `execute` only. Multi-method classes obscure the Action-to-Effect binding and break convention resolution.

---

## 11. Trade-offs

1. **Explicit `EffectContext` wrapper** adds one layer over what a "just take all the dependencies" signature would. Accepted: the symmetry with `Policy`'s Context and the immutability guarantee are worth it.
2. **No async in V1** forces all background work to L4. Plugin authors accustomed to dispatching jobs from inside business methods face friction. Mitigated by Laravel's clean job dispatch at the controller layer; documented.
3. **No automatic retry** means transient failures (network blips, deadlocks) surface to L4 without kernel-level recovery. L4 layers (or callers) implement retry with idempotency keys. The Invoker is dumb-and-correct rather than smart-and-confusing.
4. **Cross-Entity writes through direct Repository are permitted but discouraged.** The kernel cannot mechanically prevent Repository writes outside the Action's "natural" domain. Doctor surfaces patterns; documentation warns; ultimate responsibility is plugin author's.
5. **No audit for failed attempts in V1.** Failed mutations are invisible in the audit log. Forensics requires telemetry (RFC-009). Acknowledged; a future RFC may add attempt-audit as a distinct AuditEntry kind.
6. **Effect class proliferation.** One class per non-built-in Action; plugins with many custom Actions accumulate Effect classes. Mitigated by RFC-011's built-in `Action::create`/`Action::transition` covering the majority of CRUD-style Actions; custom Effects are the exception.
7. **`execute` rename from RFC-012 §6.2's `handle`** requires `ausus/runtime-default` major bump. Documented; consistent with RFC-012 §16.5's provisional commitment.

---

## 12. Open questions

1. **RFC for attempt-audit.** A separate AuditEntry kind for failed mutation attempts, with the failure reason. Useful for compliance and operational forensics. Out of V1.
2. **RFC for async Effects.** If a use case for async dispatch from inside `execute` proves dominant (e.g., heavy ERP workflows), a future RFC may introduce `Action::dispatch_async(...)` or a separate `AsyncEffect` kind. Currently no use case justifies V1 inclusion.
3. **RFC for idempotency primitives.** `Action::idempotent($keyField)` sugar with kernel-side dedup. Useful for any Action with at-least-once delivery semantics from upstream callers. Out of V1.
4. **Effect-level telemetry.** Per-Effect execution latency, throw rate, nesting depth distributions. RFC-009 territory.
5. **Effect-side caching of Repository reads.** Within a single `execute` call, the Effect may read the same Reference multiple times. The Repository contract makes no caching promise. A future RFC may introduce per-EffectContext read cache. Out of V1; plugins memoize locally if needed.
6. **Workflow runtime replacement.** When RFC-006 lands, the simple Workflow runtime in `ausus/runtime-default` (RFC-012 §6.3) is replaced. Effect classes invoking `Action::transition` built-ins inherit the new runtime; custom Effects manually mutating state columns may need to delegate to the new runtime. Documented per RFC-012 §16.5.

---

## 13. Challenger review — attack matrix

Each load-bearing section attacked against: **layer violations**, **Invoker bypass**, **transaction bypass**, **audit bypass**, **tenancy bypass**, **service-locator escape**, **SemVer traps**.

### 13.1 `Effect` interface (§2)

| Attack | Defence |
|---|---|
| Layer violation: Effect implements a different interface to bypass the Invoker. | The Invoker dispatches only to classes implementing `Effect`. Compiler validates (§8.2). Non-conforming classes raise `EffectContractViolation` at compile time. |
| Invoker bypass: caller calls `$effect->execute(...)` directly without going through Invoker. | Direct call works in PHP but produces no audit, no Tenant binding, no Policy chain, no transaction. The result is a non-functional ghost effect — no observable mutation. Detection: `EffectContext` cannot be constructed by plugin code; calling `execute` with a hand-crafted Context fails at the first Repository call because the transaction handle is invalid. |
| Transaction bypass: Effect calls a non-Repository write path. | Plugin code cannot import `Illuminate\Database\*` (RFC-002 §3.2.5). Static analysis catches; the kernel-contracts package boundary prevents. Detection if attempted via reflection: raises `PolicyForbiddenSideEffect`-style violation (detection symmetric with RFC-005 §10.3). |
| Audit bypass: Effect manages to return without throwing but the audit never emits. | The Invoker always emits audit on successful return. Skipping audit requires bypassing the Invoker (which the above defences prevent) or a kernel bug. |
| Tenancy bypass: Effect operates on a different Tenant than `EffectContext::tenant()`. | Repository writes enforce Tenant scope per RFC-002 §5.3.1. Cross-Tenant writes raise `TenantBoundaryViolation`. |
| Service-locator escape: Effect resolves a service via `app()`. | Detected by the runtime spy (§3.7 + §9 doctor check 6). Raises `EffectForbiddenSideEffect`. |
| SemVer trap: adding a fourth parameter to `execute`. | Signature is V1-frozen. Adding a parameter is a major bump. New optional capabilities are accessed via methods on `EffectContext` (additive). |

### 13.2 `EffectContext` (§3)

| Attack | Defence |
|---|---|
| Layer violation: a plugin defines its own `EffectContext` subclass and instantiates one. | `EffectContext` is a sealed interface; the implementation is constructed by the Invoker only. Plugin-constructed instances cannot be passed to a real Invoker. |
| Invoker bypass: `EffectContext::invoker()` returns a real Invoker; plugin calls Invoker with bogus arguments. | The Invoker re-validates every input. Bogus calls fail at the Tenant check or Policy chain. |
| Transaction bypass: `EffectContext::persistence()->transaction()->commit(...)`. | RFC-002 §4.2 raises `UnauthorizedTransactionControl`. |
| Audit bypass: `EffectContext::correlationId()` rewritten. | All EffectContext methods are read-only; no setters. |
| Tenancy bypass: Plugin calls `Ausus::elevate(...)` from inside Effect to operate as a different Tenant. | Elevation is permitted from inside an Effect (RFC-003 §10). It is audited per RFC-003 §10.2. The elevated invocation runs through the Invoker; no bypass — the elevation is fully traceable. |
| Service-locator escape: `EffectContext` exposes a way to reach `app()`. | It does not. Eight methods listed in §3.1, all returning kernel value objects or kernel contracts. No service-container handle exposed. |
| SemVer trap: adding a ninth method. | New methods on `EffectContext` are minor (additive). Removing methods is major. The eight V1 methods are frozen. |

### 13.3 Nested Actions (§4)

| Attack | Defence |
|---|---|
| Layer violation: Effect calls Invoker with a different Actor identity. | The Invoker re-validates: the supplied Actor is used as-is. The Audit Entry records the supplied Actor. Plugin authors faking Actor identity inside an Effect produce a misleading audit entry — but this is a forgery the kernel cannot prevent (the Invoker takes the Actor argument at face value). Documented; mitigated by passing `$ctx->actor()` unchanged in standard usage. |
| Invoker bypass: nested Action invoked outside the parent's transaction. | The Invoker uses the parent's PersistenceContext for nested invocations (RFC-002 §7.4 savepoints). No way to escape the transaction. |
| Transaction bypass: nested Action commits independently. | Impossible: savepoints commit nested mutations to the parent transaction; outer commit/rollback applies. |
| Audit bypass: nested invocation skips audit. | Each nested Invoker call emits its own AuditEntry. Tracing via shared `correlationId`. |
| Tenancy bypass: nested Action targets a different Tenant. | Cross-Tenant invocations require elevation. Without elevation, RFC-003 §10 forbids and the Invoker rejects. |
| Service-locator escape: nested Effect uses the outer Effect's class to acquire services. | Each Effect is independently constructed at boot; runtime cross-Effect resolution is impossible. |
| SemVer trap: changing nesting depth bound. | Bound is RFC-002 §7.4 (8 levels). Change requires RFC-002 amendment. |

### 13.4 Transaction semantics (§5)

| Attack | Defence |
|---|---|
| Layer violation: Effect spans two transactions. | Single transaction per Invoker call; Effect cannot open a second. |
| Invoker bypass: Effect commits the transaction itself. | Forbidden (§5.4); detected via `UnauthorizedTransactionControl`. |
| Transaction bypass: Effect throws after irreversible side effect (e.g., external HTTP call). | External HTTP is forbidden inside `execute` per §3.7 + §10.4. If it nonetheless happened, the Invoker rolls back the database transaction but cannot reverse the external call. Plugin-author responsibility. The kernel commits to data-side rollback only. |
| Audit bypass: Effect completes; audit fails; rollback occurs but external systems already saw the data. | Same as above. The data-layer atomicity is guaranteed; external-system atomicity requires plugin-author coordination (e.g., outbox pattern, which is out of V1). |
| Tenancy bypass | Repository enforcement (RFC-002 §5.3.1). |
| Service-locator escape | Same defences as §13.1. |
| SemVer trap: changing rollback semantics. | RFC-002 §7.3 + RFC-001 Amendment-01 §A-1.6. Stable contract. |

### 13.5 Idempotency (§6)

| Attack | Defence |
|---|---|
| Layer violation: Invoker auto-retries Effects. | V1 commits to no automatic retry. Adding retries is a major behavior change. |
| Invoker bypass: caller assumes at-least-once. | Documented: V1 is at-most-once at the Invoker level. The audit log is the ground-truth ledger. |
| Transaction bypass | n/a. |
| Audit bypass: caller retries; second run creates duplicate audit entries. | Each Invoker call has a fresh `entryId`. Sinks that dedup by entryId (RFC-007 §7.2) preserve uniqueness; sinks that don't show duplicates. Duplicates are the caller's responsibility to recognize via the audit log. |
| Tenancy bypass | n/a. |
| Service-locator escape | n/a. |
| SemVer trap: introducing automatic retry. | Major bump; would change observable Effect-execution count per call. |

### 13.6 Error semantics (§7)

| Attack | Defence |
|---|---|
| Layer violation: Effect bypasses Invoker's exception handling. | All exceptions from `execute` propagate up; the Invoker catches at one boundary (§7.2). No way for an Effect to "exit cleanly" without returning or throwing. |
| Invoker bypass: Effect catches its own exception and returns success-looking outputs to fool the audit. | Permitted by the contract — Effects MAY catch internal exceptions and return outputs reflecting the recovered state. This is plugin-author choice. The audit reflects what the Effect reported. Forgery of "all good" outputs when the operation actually failed is a plugin-author responsibility, not a kernel concern. |
| Transaction bypass: Effect throws after committing. | Effect cannot commit (§5.4). |
| Audit bypass: failed Effects don't emit attempt-audit. | Acknowledged V1 design (§7.4). Future RFC for attempt-audit. |
| Tenancy bypass: exception leaks data from another Tenant. | Per RFC-002 §18.10, drivers MUST scrub error details. Plugin-thrown exceptions are caller responsibility. |
| Service-locator escape | n/a. |
| SemVer trap: changing the error taxonomy. | §7.1 taxonomy is V1 frozen; additions are minor (new types for new operations), removals are major. |

### 13.7 Compiler validation (§8)

| Attack | Defence |
|---|---|
| Layer violation: Effect class declared with wrong interface but Compiler misses it. | §8.2 reflection-based check verifies interface. Conformance test catches. |
| Invoker bypass: Effect class registered without convention resolution AND without explicit `->effect()`. | The Compiler refuses to compile the graph. No runtime path; boot fails. |
| Transaction bypass | n/a at compile time. |
| Audit bypass | n/a at compile time. |
| Tenancy bypass | n/a at compile time. |
| Service-locator escape: Compiler doesn't detect `app()` calls. | Static analysis (§9 doctor item 6) is best-effort. Runtime spy is the second layer. |
| SemVer trap: adding compile-time constraints in V1.x. | New constraints are breaking for plugins that violated previously-tolerated rules. Treated as major bumps if they affect previously-conforming code. |

---

## 14. Acceptance criteria

This RFC is accepted when:

1. The three role signatories (architect, kernel, challenger) sign off on §2 (interface), §3 (EffectContext), §5 (transactions), §7 (errors), §10 (rejected alternatives).
2. RFC-012 §6.2 is updated to adopt `execute` as the canonical method name and to register the consequent `ausus/runtime-default` major bump.
3. RFC-012 §16.5 is updated to remove "ActionEffect interface" from the provisional list, replacing it with "fixed by RFC-013."
4. RFC-011 §5.2's convention resolution targets the `Effect` interface defined by this RFC.
5. The Invoker implementation in `ausus/runtime-default` satisfies the chain of §5 (rollback on throw, rollback on audit failure, no retry).
6. The `EffectContext` implementation exposes exactly the eight methods of §3.1 and nothing more.
7. The conformance test suite for `Effect` is scoped: at minimum, one test per "MUST" clause in §2, §3, §4, §5.
8. The conformance test suite for the Compiler-side §8 checks is scoped.

Once accepted, this RFC is the source of truth for the Effect contract. Any contradiction in a future RFC requires an amendment to this document or an explicit "supersedes."

---

## 15. Determination

**ACCEPT.**

Justification:

- **No new kernel primitives.** §0 + §1.1 enumerate the constraints; every clause in this RFC composes existing primitives (Action, Invoker, PersistenceContext, Tenant, Actor, CorrelationId, TraceId, Clock, Elevation, Reference). Effect itself is the formalization of a plugin-facing contract — not a new kernel concept.
- **All ten challenger constraints satisfied.** Mapped explicitly:
  1. No Invoker bypass (§2, §13.1).
  2. No direct Driver access (§3.6, §10.5).
  3. No Unit of Work (§10.8).
  4. No service locator (§3.7, §10.4).
  5. No async (§4.4, §10.7).
  6. Secondary mutations route through Invoker (§4.1).
  7. RFC-002 transaction compatibility (§5).
  8. RFC-003 elevation compatibility (§3.5, §4.4).
  9. RFC-007 audit compatibility (§2.5 outputs feed audit; §7.4 failure semantics).
  10. RFC-011 convention compatibility (§8.4, §8.5).
- **Closed contract** (§2) + **closed error taxonomy** (§7.1).
- **Symmetric with Policy contract** (RFC-005 §2.1): verb-named single-method interface, stateless instance, EffectContext bundle, constructor-only static configuration, runtime spy enforcement.
- **Unblocks RFC-000 F-V0-02.** Per §14 acceptance criterion #3, RFC-012 §16.5 is updated to mark "ActionEffect interface" no longer provisional.

Conditional notes:

- Acceptance is **specification-level**. Runtime verification requires `ausus/runtime-default` to be built per RFC-012 §19. RFC-000 V0 Real Pass demonstrated the package does not exist; this RFC's contract is verifiable only when the runtime ships.
- Predicted UX-3 delta vs UX-2 attributable to this RFC alone: zero direct delta on the seven UX measurements (Effect contract is downstream of DSL; LOC, FQNs, imports are RFC-011 territory). Indirect benefit: the F-V0-02 BLOCKER is removed, which is a prerequisite for any UX measurement to become observable.

---

## Appendix A — V1 public surface enumeration

```
Ausus\
  Effect                              (interface; this RFC §2)

Ausus\Effect\
  EffectContext                       (interface; this RFC §3)
  Reference                           (re-exported from Ausus\Kernel\Contracts\Persistence)
  Instant                             (re-exported clock type)

Ausus\Effect\Errors\
  EffectError                         (abstract base)
  EffectClassNotFoundByConvention,
  EffectClassNotFound,
  EffectContractViolation,
  EffectInstantiationFailure,
  EffectRuntimeRegistration,
  InputValidationFailed,
  EffectMalformedReturn,
  EffectForbiddenSideEffect,
  NestedInvokerDepthExceeded,
  EffectFailed,
  EffectConventionOverridden          (notice, not error; sometimes useful as warning)
```

11 error / notice types. Closed for V1.

The `Effect` interface, the `EffectContext` interface, and the closed error taxonomy form the entire V1 public surface of the Effect contract. Anything not enumerated is internal.

---

## Appendix B — Error taxonomy summary

| Error                              | Phase       | Caller-visible? | Recovery                                     |
|------------------------------------|-------------|-----------------|----------------------------------------------|
| EffectClassNotFoundByConvention    | compile     | boot failure    | Fix DSL or class location                    |
| EffectClassNotFound                | compile     | boot failure    | Fix DSL `->effect()` reference               |
| EffectContractViolation            | compile     | boot failure    | Implement `Effect` correctly                 |
| EffectInstantiationFailure         | boot        | boot failure    | Fix constructor / container binding          |
| EffectRuntimeRegistration          | runtime     | exception       | Register at boot, not at runtime             |
| InputValidationFailed              | runtime     | 422-style       | Caller fixes inputs                          |
| EffectMalformedReturn              | runtime     | 500-style       | Fix Effect return type                       |
| EffectForbiddenSideEffect          | runtime     | 500-style       | Remove forbidden operation from Effect       |
| NestedInvokerDepthExceeded         | runtime     | 500-style       | Reduce Effect nesting                        |
| EffectFailed                       | runtime     | 422/500-style   | Inspect `cause`; caller decides              |
| EffectConventionOverridden         | compile     | notice only     | Informational; no action required            |

---

## Appendix C — Worked example: `IssueEffect`

A custom Effect for the Invoice slice (RFC-011 §2). The slice's default `Action::transition` built-in suffices for most cases; this example shows what a hand-authored Effect looks like when business logic needs to exceed the built-in.

`src/Effects/IssueEffect.php`:

```php
<?php

namespace Acme\Billing\Effects;

use Ausus\{Effect, EffectContext, Reference};

final class IssueEffect implements Effect
{
    public function execute(
        EffectContext $ctx,
        ?Reference $subject,
        array $inputs
    ): array {
        $repo = $ctx->persistence()->repository('billing.invoice');

        $invoice = $repo->find($subject);
        if ($invoice === null || $invoice->field('status') !== 'DRAFT') {
            throw new \DomainException('Invoice must exist and be DRAFT');
        }

        $issuedAt = $ctx->clock()->toRfc3339();

        $repo->update(
            $subject,
            ['status' => 'ISSUED', 'issued_at' => $issuedAt],
            $invoice->version()
        );

        if (! empty($inputs['notify'])) {
            $ctx->invoker()->invoke(
                $ctx->actor(),
                'billing.notification.send',
                null,
                [
                    'invoice_id' => $subject->identityHandle(),
                    'event'      => 'issued',
                ]
            );
        }

        return [
            'status'    => 'ISSUED',
            'issued_at' => $issuedAt,
        ];
    }
}
```

23 lines including imports and braces. Demonstrates:

- Single `execute` method per §2.1.
- Three-argument signature per §2.3.
- `EffectContext`-mediated Repository access per §3.3.
- Domain validation throwing per §7.2.
- Clock pinned per §3.2.4.
- Optimistic locking with `version()` per RFC-002 §8.
- Nested invocation through `invoker()` per §4.1.
- Outputs returned per §2.5 (feeds audit per RFC-007 §2.1).
- No service-locator access (§3.7).
- No transaction control (§5.4).
- Stateless class (no instance properties post-construction).

The class is `final` per §9 recommendation. The constructor is implicit (no static configuration needed).
