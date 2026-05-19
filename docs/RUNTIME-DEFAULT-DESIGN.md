# ausus/runtime-default — V0 Implementation Design

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Active design (V0 implementation phase)                |
| Authors       | architect, runtime maintainer                          |
| Date          | 2026-05-19                                             |
| Implements    | RFC-001 §A-1.4 §8.2.1 (Invoker), RFC-005 §3–§13 (Policy Engine), RFC-006 (Workflow Runtime), RFC-013 (Effect dispatch + EffectContext), RFC-007 §3–§4 (Auditor + primary sink coordination) |
| Owning package | `ausus/runtime-default` (namespace `Ausus\Runtime\`) |
| Depends on    | `ausus/kernel` only                                    |

This document is the **how** for the runtime. The accepted RFCs are the **what**. Where this document deviates, the RFCs win.

The runtime is the package that ties everything together. It does not invent contracts — it implements them. V0 builds the smallest runtime capable of supporting RFC-000's `HelloInvoice` slice through `apps/playground`'s M1 integration test.

---

## 1. Mission and scope

### 1.1 Mission

Implement the Invoker chain end-to-end. Each invocation runs five steps: Tenant check → Policy chain → Workflow guard → Action effect → Audit emission. Coordinate transactions with `ausus/persistence-sql`. Construct the `EffectContext` that Effect classes receive. Provide built-in parameterized Policies (`RoleRequired`, etc.) and built-in Effects for `Action::create` / `Action::transition` sugar.

### 1.2 V0 in scope

| Component                | V0 surface                                                                       |
|--------------------------|----------------------------------------------------------------------------------|
| `Invoker`                | Full 5-step chain; nested invocation wired but untested                          |
| `PolicyEngine`           | Chain assembly (one segment: Action base); combinator; deny-by-default            |
| `WorkflowRuntime`        | Step 3 algorithm; multi-Workflow support; transition-set indexing                 |
| `EffectDispatcher`       | Built-in Effect registry + container-based plugin Effect resolution               |
| `EffectContext`          | All 8 methods of RFC-013 §3.1                                                    |
| Built-in Policies        | `RoleRequired`, `PermissionRequired`, `RolesRequired` (RFC-011 §8.3)             |
| Built-in Effects         | `CreateEffect`, `TransitionEffect` (RFC-011 §8.2)                                |
| `Auditor`                | Transactional primary sink coordination; per-correlation sequence; entryId generation |
| Redaction                | Glob patterns from config (`audit.redact`)                                        |
| `InvocationContext`      | Per-request scoped: actor, tenant, correlationId, traceId                         |

### 1.3 V0 deferrals

Hard rule deferrals (per the brief): no async, no queues, no retries, no distributed transactions, no event sourcing, no saga orchestration.

Additional V0 deferrals consistent with prior design docs:

- **Policy Engine cache** (RFC-005 §8 two-tier). V0 recomputes every call. Chains are 1–3 policies; cost negligible.
- **Side-effect spy** (RFC-005 §10.3). V0 trusts plugin authors; static analysis at the CI level.
- **Policy timeout enforcement** (RFC-005 §9.2). V0 best-effort; relies on PHP's default execution timeout.
- **Per-Field visibility evaluation** (Amendment-01 §A-1.2). Field-level Policies require Field-attached Policy descriptors. None in V0 HelloInvoice. M2 adds.
- **Projection-attached Policies and Tenant-added Policies** (RFC-005 §4.1 chain segments 7-8 + segment 2). V0 chain has one segment.
- **Global Policies** (segment 9). V0 has none.
- **Elevation** (RFC-003 §10). Wired through `InvocationContext.elevation()` but unused in HelloInvoice. `Ausus::elevate()` raises `ElevationNotImplementedInV0` if called.
- **Audited reads** (RFC-007 §15). All Entities in HelloInvoice have `audited_reads: false`. Read audit not exercised.
- **External primary sink protocol** (RFC-007 §6.2 prepare/confirm/cancel). V0 ships only the Transactional primary; no external sinks.
- **Secondary sinks + retry queue + reconciliation worker** (RFC-007 §11, §12). V0 has zero secondaries.
- **MaintenanceAction execution** (RFC-010 §9). V0 has no MaintenanceActions; `skip_workflow_guards: true` flag path exists but is dead code.
- **Read-side audit emission via Repository** (RFC-007 §15.1). V0 Repository does not call the Auditor.

---

## 2. Runtime architecture

### 2.1 Class map

```
src/
├── RuntimeServiceProvider.php                      # Laravel SP
├── Invoker/
│   ├── DefaultInvoker.php                          # implements kernel Invoker contract
│   ├── InvocationLifecycle.php                     # orchestrates the 5 steps
│   ├── InvocationContext.php                       # per-request scoped (actor, tenant, correlationId, traceId)
│   ├── DefaultEffectContext.php                    # implements EffectContext for Effects
│   └── ElevationStub.php                           # V0: throws ElevationNotImplementedInV0
├── Policy/
│   ├── DefaultPolicyEngine.php                     # implements PolicyEngine contract
│   ├── ChainResolver.php                           # MetadataGraph → ordered Policy list per Action
│   ├── Combinator.php                              # Deny > Permit > Abstain (static method)
│   └── Builtin/
│       ├── RoleRequired.php
│       ├── PermissionRequired.php
│       └── RolesRequired.php
├── Workflow/
│   ├── DefaultWorkflowRuntime.php                  # implements WorkflowRuntime contract
│   ├── TransitionSetIndex.php                      # Action FQN → applicable transitions; built at boot
│   └── Builtin/
│       ├── CreateEffect.php
│       └── TransitionEffect.php
├── Effect/
│   ├── EffectDispatcher.php                        # resolves Effect for an ActionNode
│   ├── BuiltinEffectRegistry.php                   # parameterized built-in Effects keyed by Action FQN
│   └── BuiltinEffectMarkers.php                    # string constants ("kernel.builtin.create", etc.)
├── Audit/
│   ├── DefaultAuditor.php                          # implements Auditor contract
│   ├── EntryFactory.php                            # constructs AuditEntry from invocation
│   ├── SequenceCounter.php                         # per-correlationId monotonic counter; per-process
│   └── Redactor.php                                # glob-pattern redaction over inputs/outputs
├── Time/
│   └── PinnedClock.php                             # per-invocation Instant; immutable for the call
└── Errors/
    └── (re-exports kernel error types; runtime adds zero new types)
```

**22 classes.** Larger than `ausus/persistence-sql` (15) because the runtime composes five subsystems (Policy, Workflow, Effect, Audit, Invoker). Smaller than the kernel itself.

### 2.2 What is NOT in the runtime

- No HTTP routing, no controllers, no middleware definitions. That is L4 API Surface; out of V0 (apps/playground calls the Invoker directly via PHPUnit).
- No console commands. `ausus:doctor` extensions deferred to M2.
- No telemetry beyond standard PHP logs.
- No metrics emission.
- No queue dispatcher (jobs are L4; out of V0).

### 2.3 Layer placement

L2 (Runtime). Depends only on `ausus/kernel` (contracts + MetadataGraph + value objects).

External dependencies:
- `illuminate/contracts` — Laravel container interface.
- `illuminate/support` — service provider base.

No `illuminate/database`, no `illuminate/http`, no `illuminate/auth` (those live in L3 packages).

---

## 3. Invocation lifecycle

### 3.1 The 5-step Invoker chain (per RFC-001 §A-1.4 §8.2.1)

```
Invoker.invoke(Actor, ActionFqn, Subject | null, inputs): array

  Pre-flight (no transaction yet):
    - Resolve ActionNode from MetadataGraph by ActionFqn.
        If missing: throw UnknownAction (compiled graph shouldn't allow this; defensive).
    - Verify subject_required matches subject presence:
        if action.subject_required && subject === null: throw PolicySubjectRequired
    - Verify Actor non-null:
        if actor === null: throw ActorRequired
    - Resolve active TenantContext:
        if not bound && config.runtime.strict_tenant: throw TenantContextRequired
    - Verify subject.tenant matches active Tenant:
        if subject !== null && subject->tenantId() !== active.tenant: throw TenantBoundaryViolation
    - Pin Clock for this invocation
    - Allocate or inherit CorrelationId

  Step 1 — Tenant Context check (RFC-001 §8.2 step 1):
    - The pre-flight check above is step 1 in practice. Redundant restatement only.
    - Documented separately because nested invocations re-enter at step 1 with the SAME context.

  Step 2 — Policy chain (RFC-005):
    decision = policyEngine.evaluate(action, actor, subject, context)
    if decision !== Permit: throw PolicyDenied(action, decision)

  ━━ Transaction opens here ━━
    txn = persistenceDriver.beginTransaction(activeTenant)
    persistenceContext = persistenceDriver.context(activeTenant, txn)

  Step 3 — Workflow guard (RFC-006):
    if action.transitionSet is empty: skip
    elif action.kind === 'maintenance' && action.skip_workflow_guards: skip
    else:
        workflowRuntime.evaluate(action, subject, persistenceContext, context)
        # may throw WorkflowStateMismatch / WorkflowGuardDenied — rollback handled below

  Step 4 — Effect execution (RFC-013):
    effect = effectDispatcher.resolve(action)
    effectContext = construct DefaultEffectContext(persistenceContext, invoker(self), actor, ...)
    try:
        outputs = effect.execute(effectContext, subject, inputs)
    catch Throwable e:
        persistenceDriver.rollback(txn)
        if e instanceof EffectError: rethrow
        else: throw EffectFailed(action.fqn, cause: e)

  Step 5 — Audit emission (RFC-007):
    entry = entryFactory.build(action, actor, subject, inputs, outputs, context)
    try:
        outcome = auditor.emit(entry, txn)
    catch AuditEmissionFailed:
        persistenceDriver.rollback(txn)
        rethrow

  ━━ Transaction commits here ━━
    persistenceDriver.commit(txn)

    return outputs
```

### 3.2 Transaction boundary

Transaction opens **between step 2 and step 3**. Reason:

- Steps 1 and 2 (Tenant check, Policy chain) do not require database access. Policies are pure per RFC-005 §10; they consult the Actor and Context only.
- Step 3 (Workflow guard) MUST load the Subject via Repository — requires an open transaction so the read is part of the same MVCC snapshot as the subsequent write.
- Step 4 (Effect) writes via Repository — requires transaction.
- Step 5 (Audit) writes via the Transactional primary sink in the same transaction.

Opening the transaction earlier (e.g., before step 1) is wasted work when the Policy denies. Opening it later (e.g., before step 4 only) breaks the snapshot consistency between Workflow state read and Effect mutation.

### 3.3 Why pre-flight is split out

The "pre-flight" checks in §3.1 are conceptually part of step 1 (RFC-001 §8.2 numbering). They are split out in code because:

- They are validation, not authorization. The kernel's contract is violated if `subject_required` mismatches; this is a programming error in the caller, not a runtime policy decision.
- Pre-flight failures raise distinct error types (`UnknownAction`, `PolicySubjectRequired`, `ActorRequired`, `TenantContextRequired`, `TenantBoundaryViolation`) that callers should handle differently from Policy denials.
- Pre-flight runs without a transaction; failure has no rollback cost.

### 3.4 Invocation completes synchronously

Every Invoker call returns synchronously with either the outputs (success) or a thrown error (failure). No queueing, no deferred completion, no callback. This is the hard rule.

---

## 4. Transaction lifecycle

### 4.1 Top-level invocations

```
Invoker.invoke(...)
  ├─ pre-flight                                          (no DB)
  ├─ step 1: tenant check                                (no DB)
  ├─ step 2: policy chain                                (no DB; policies pure)
  ├─ driver.beginTransaction(tenant) → txn               (opens DB transaction)
  ├─ step 3: workflow guard                              (DB reads via Repository)
  ├─ step 4: effect.execute(...)                         (DB writes via Repository)
  ├─ step 5: auditor.emit(...)                           (DB write to audit log within txn)
  └─ on success: driver.commit(txn)                      (commits atomic data + audit)
     on failure: driver.rollback(txn)                    (rolls back both)
```

### 4.2 Nested invocations (Effect calls another Action)

Per RFC-013 §4, an Effect may call `$ctx->invoker()->invoke(...)` to invoke another Action. The nested call:

- Sees the SAME PersistenceContext (V0: same `txn`; nested invocation opens a savepoint, not a new top-level transaction).
- Inherits the parent's `InvocationContext` (actor, tenant, correlationId, traceId, elevation, clock).
- Runs the full 5-step chain again: tenant check, policy chain, workflow guard, effect, audit.

```
Invoker.invoke (nested)
  ├─ pre-flight                                          (uses inherited InvocationContext)
  ├─ step 1                                              (inherited tenant)
  ├─ step 2                                              (fresh policy chain for nested action)
  ├─ driver.beginTransaction(tenant) → nested handle    (Laravel auto-savepoint)
  ├─ step 3, step 4, step 5                              (within savepoint)
  └─ commit(nested)                                       (releases savepoint; parent still open)
```

V0 uses Laravel's `ConnectionInterface::beginTransaction()` recursive behavior: nested calls automatically generate `SAVEPOINT trans<N>` SQL. RFC-002 §7.4 requires 8 levels; Laravel handles arbitrary depth.

The Invoker does NOT explicitly track nested depth. The PersistenceDriver does (via `connection->transactionLevel()`).

### 4.3 Rollback cascade

A rollback at any nesting level reverts all writes from that level inward:

```
top.commit succeeded → both top and nested visible
nested.rollback     → nested writes reverted; top continues with original state
top.rollback        → both reverted
```

This is standard MVCC savepoint behavior. The Invoker relies on it without re-implementing.

### 4.4 No XA / two-phase commit

V0 single-driver only (RFC-002 §14). No cross-database coordination. No distributed transactions.

### 4.5 No long-lived transactions

Each Invoker call opens and closes a transaction. No transaction spans multiple Invoker calls. No "transactional scope" abstractions.

---

## 5. Nested invocation behavior

### 5.1 Context inheritance

When `$ctx->invoker()->invoke(...)` is called from inside an Effect:

```php
DefaultInvoker (top)
  ↓ calls effect.execute(EffectContext with this->invoker())
Effect
  ↓ calls $ctx->invoker()->invoke($actor, $nestedAction, $nestedSubject, $nestedInputs)
DefaultInvoker (nested call)
  ↓ accesses InvocationContext from request scope
  ↓ inherits: actor, tenant, correlationId, traceId, elevation, clock
  ↓ allocates: new sequence number (per-correlation counter)
  ↓ runs full 5-step chain for the nested action
```

The `InvocationContext` is request-scoped (one instance per HTTP request / CLI invocation / queue job). Nested calls share the same instance.

### 5.2 Tenant during nested

The active Tenant is whatever the request started with — or the elevation target if the call is within `Ausus::elevate(...)` scope (V0: deferred). Nested invocations cannot change Tenant; they inherit.

### 5.3 Correlation propagation

Per RFC-007 §9.1: nested invocations share `correlationId` with parent. Each invocation gets its own `sequence` number (incremented from the parent's counter).

```
Top invocation:    correlationId=C1, sequence=0
  Nested 1:        correlationId=C1, sequence=1
    Nested 1.1:    correlationId=C1, sequence=2
  Nested 2:        correlationId=C1, sequence=3
```

The `SequenceCounter` (V0: in-memory map of `correlationId → int`) increments atomically per process.

### 5.4 V0 wiring without test coverage

The wiring for nested invocation is complete in V0 (EffectContext exposes `invoker()`; DefaultInvoker is reentrant). No HelloInvoice scenario exercises it. M2 adds a test scenario when L4 enables cross-Entity Actions.

---

## 6. Error wrapping and rollback semantics

### 6.1 Error type origin matrix

| Origin                                          | Native error class                                                | Wrapped to               | Wrap point                          |
|-------------------------------------------------|-------------------------------------------------------------------|---------------------------|--------------------------------------|
| Pre-flight: action missing                       | `UnknownAction` (kernel error)                                    | (already kernel error)    | DefaultInvoker pre-flight            |
| Pre-flight: subject mismatch                     | `PolicySubjectRequired` (kernel error)                            | (already kernel error)    | DefaultInvoker pre-flight            |
| Step 1: tenant context missing                   | `TenantContextRequired`                                            | (already kernel error)    | DefaultInvoker step 1                |
| Step 2: policy denies                            | n/a (Permit/Deny/Abstain decision)                                 | `PolicyDenied(action, decision)` | DefaultInvoker step 2          |
| Step 2: policy throws                            | Plugin exception                                                   | `PolicyException(fqn, cause)` → contributes Deny | PolicyEngine §9.2 |
| Step 2: policy returns non-Decision              | Type error                                                          | `PolicyMalformedReturn`   | PolicyEngine                          |
| Step 3: workflow state mismatch                  | n/a                                                                | `WorkflowStateMismatch`   | WorkflowRuntime + rollback            |
| Step 3: workflow guard denies                    | `Decision::Deny` from guard Policy                                  | `WorkflowGuardDenied`     | WorkflowRuntime + rollback            |
| Step 4: Effect throws domain exception           | `\DomainException` or plugin-defined                                | `EffectFailed(fqn, cause)` | DefaultInvoker step 4 + rollback     |
| Step 4: Effect throws Persistence error          | `ConcurrencyConflict`, `ConstraintViolation`, etc.                  | propagated as-is (already closed taxonomy) | DefaultInvoker step 4 + rollback |
| Step 5: primary sink fails                       | `SinkRejected` from sink                                            | `AuditEmissionFailed`     | Auditor + rollback                    |
| Commit: connection fails                         | `PDOException`                                                      | `DriverError('commit_failed', cause)` | DefaultInvoker post-step-5    |

### 6.2 Rollback decision tree

```
Pre-flight fails           → no rollback (no txn open)
Step 1 fails               → no rollback
Step 2 fails               → no rollback
[transaction opens]
Step 3 fails               → driver.rollback(txn)
Step 4 fails               → driver.rollback(txn)
Step 5 fails               → driver.rollback(txn)
Commit fails               → already partial; surface to caller as DriverError
Success                    → driver.commit(txn)
```

A single `try/finally` in `DefaultInvoker::invokeWithinTransaction()` ensures rollback runs on any non-commit path:

```php
private function invokeWithinTransaction(...): array
{
    $txn = $this->driver->beginTransaction($tenant);
    $committed = false;
    try {
        $outputs = $this->runStepsThreeFourFive(...);
        $this->driver->commit($txn);
        $committed = true;
        return $outputs;
    } finally {
        if (!$committed) {
            try { $this->driver->rollback($txn); }
            catch (\Throwable $rollbackError) { /* log; the original error wins */ }
        }
    }
}
```

### 6.3 No partial-state observability

Per RFC-013 §5.2: the Effect sees no commit/rollback. The Invoker performs commit AFTER the Effect returns AND after audit acknowledges. The Effect's return is just data — no signal of persistence durability.

### 6.4 No retry inside the Invoker

Per RFC-013 §6.1: the Invoker does NOT retry on transient failures. A ConcurrencyConflict on update raises through to the caller, who may retry by calling `invoke()` again.

This is intentional: hidden retries hide bugs and complicate audit reasoning.

### 6.5 Audit on failed Effect: NOT emitted

Per RFC-013 §7.4: if step 4 fails, the audit for the failed attempt is NOT emitted in V0. The mutation rolled back; there is nothing to audit. Operational forensics for failed attempts is RFC-009 telemetry territory (out of V0).

---

## 7. Context propagation

### 7.1 `InvocationContext` shape

```php
final class InvocationContext
{
    public function __construct(
        public Actor $actor,
        public Tenant $tenant,
        public string $correlationId,
        public ?string $traceId,
        public ?ElevationRef $elevation,        // V0: always null
        public Instant $clock,
    ) {}

    public function withElevation(ElevationRef $elevation): self { /* V0: throws */ }
}
```

Per-request scoped. Constructed once at request entry (HTTP middleware, CLI bootstrap, queue worker) and bound to Laravel's request scope.

### 7.2 Where each field comes from

| Field           | Source (V0)                                                                       |
|-----------------|----------------------------------------------------------------------------------|
| `actor`         | `ActorResolver::resolve()` (from `ausus/auth-bridge`); typically `Auth::user()`  |
| `tenant`        | `TenantResolver` from active `ResolverContext` (CLI flag in V0; HTTP middleware M2) |
| `correlationId` | Fresh ULID at top-level invocation; inherited at nested                          |
| `traceId`       | W3C `traceparent` header in HTTP context; null in CLI / queue (V0)               |
| `elevation`     | Always null in V0                                                                 |
| `clock`         | `PinnedClock` snapshot of `microtime(true)` at top-level entry                    |

### 7.3 Construction at request entry

```php
class InvocationContextProvider
{
    public function bind(): InvocationContext
    {
        $actor    = $this->actorResolver->resolve()
                    ?? throw new ActorRequired();
        $tenant   = $this->tenantContext->current()
                    ?? throw new TenantContextRequired();
        $traceId  = $this->extractTraceId();   // null in V0 non-HTTP
        $clock    = new PinnedClock(microtime(true));
        $corrId   = $this->ulid->generate();

        return new InvocationContext(
            actor: $actor,
            tenant: $tenant,
            correlationId: $corrId,
            traceId: $traceId,
            elevation: null,
            clock: $clock,
        );
    }
}
```

### 7.4 Propagation to `EffectContext`

When the Invoker calls an Effect, it constructs `DefaultEffectContext` from the active `InvocationContext`:

```php
$effectContext = new DefaultEffectContext(
    persistence: $persistenceContext,
    invoker: $this,                              // self-reference for nested calls
    actor: $invocationContext->actor,
    tenant: $invocationContext->tenant,
    correlationId: $invocationContext->correlationId,
    traceId: $invocationContext->traceId,
    elevation: $invocationContext->elevation,
    clock: $invocationContext->clock,            // pinned; shared across nested
);
```

All `EffectContext` instances within a single top-level invocation share the same `clock`, `correlationId`, `traceId`, `elevation` per RFC-013 §3.2.

---

## 8. Elevation propagation (V0: deferred)

### 8.1 V0 behaviour

`Ausus::elevate(targetTenant, reason, scope)` is implemented as a stub that raises:

```php
class ElevationStub
{
    public function elevate(TenantId $target, string $reason, ?\Closure $scope = null): never
    {
        throw new ElevationNotImplementedInV0(
            "Ausus::elevate() is not implemented in V0. Deferred to M2."
        );
    }
}
```

`InvocationContext::elevation` is always `null` in V0.

### 8.2 Wiring readiness

Despite the stub, the runtime is wired for elevation:

- `EffectContext::elevation()` exists and returns `null`.
- `AuditEntry::elevation` slot exists (per RFC-003 §10.5 + RFC-007 §10) and serializes as `null`.
- `InvocationContext::withElevation()` method exists but throws.

M2 will implement by:

1. `Ausus::elevate()` constructs an `ElevatedContext` that binds a target Tenant.
2. Within the scope, `InvocationContext::tenant` is replaced with the target.
3. Open + close emit `kernel.tenant.elevate` / `kernel.tenant.elevate_close` audit entries.
4. Nested invocations during elevation inherit the target Tenant.

V0 does not exercise any of this; the HelloInvoice integration test runs entirely within one Tenant.

---

## 9. Audit emission timing

### 9.1 When audit happens

Step 5 of the Invoker chain. After the Effect returns successfully, before transaction commit.

Per RFC-007 §6.1 (Transactional ACK):

```
[step 4: Effect executes, writes via Repository within txn]
[step 5: Auditor.emit(entry, txn)
   ├─ Redaction applied
   ├─ entryId generated (ULID v7-style)
   ├─ sequence assigned from per-correlation counter
   └─ Transactional sink: INSERT INTO kernel_audit_log within txn]
[commit: both data and audit committed atomically]
```

For Transactional primary sinks (V0 default): the audit insert is in the same transaction. Either both commit or both roll back. No orphan possible.

### 9.2 What happens on audit failure

Per Amendment-01 §A-1.6: primary sink failure aborts the Action and rolls back the transaction.

```
auditor.emit(entry, txn)
  primarySink.writeInTransaction(entry, txn)
    INSERT INTO kernel_audit_log ...
    if SQLSTATE error (e.g., constraint violation, unique key collision on entry_id):
      throw SinkRejected
  Auditor catches SinkRejected
  Auditor throws AuditEmissionFailed

DefaultInvoker catches AuditEmissionFailed:
  driver.rollback(txn)
  rethrow AuditEmissionFailed to caller
```

The data writes from step 4 are reverted. No audit row exists either (it was in the same transaction). The caller learns the Action failed.

### 9.3 No audit for denied Actions

If step 2 (Policy) denies, step 5 never runs. No `AuditEntry` is created. The caller sees `PolicyDenied` only.

This is consistent with RFC-013 §7.4. A future RFC may add "attempt audit" for denied Actions; out of V0.

### 9.4 No audit for failed pre-flight

Same: pre-flight failures (UnknownAction, ActorRequired, etc.) emit no audit.

### 9.5 Audit entry construction

```php
class EntryFactory
{
    public function build(
        ActionNode $action,
        Actor $actor,
        ?Reference $subject,
        array $inputs,
        array $outputs,
        InvocationContext $context,
    ): AuditEntry {
        return new AuditEntry(
            entryId: $this->ulid->generate(),
            sequence: $this->sequenceCounter->next($context->correlationId),
            actor: $actor->ref(),
            tenant: $context->tenant->id()->value(),
            actionFqn: $action->fqn,
            subject: $subject !== null
                ? new SingleSubject($subject->tenantId(), $subject->entityFqn(), $subject->identityHandle())
                : new SingleSubject($context->tenant->id()->value(), $action->entityFqn, 'kernel.reporting.aggregate'),
            inputs: $inputs,
            outputs: $outputs,
            timestamp: $context->clock->toRfc3339(),
            correlationId: $context->correlationId,
            traceId: $context->traceId,
            invocationClass: $action->kind === 'maintenance' ? 'Maintenance' : 'Standard',
            elevation: $context->elevation,
            emitterVersion: \Ausus\KernelVersion::VERSION,
        );
    }
}
```

Note: for Actions with `subject_required: false` (like `create`), the Subject in the audit uses a synthetic identity handle (`kernel.reporting.aggregate` per Amendment-02 §A-1.13). Wait — that's the reporting aggregate handle. For Actions without an instance Subject, what handle to use?

Actually RFC-001 §A-1.13 §6 reserved synthetic identity handles for "operations that have no real instance Subject (e.g., a reporting query aggregating across rows)." For `Action::create`, there IS a real instance Subject after creation — the newly-created Entity. So the audit Subject should reference the new ID returned by the create Effect.

V0 implementation: for `Action::create`, the Effect's output includes `id`. The EntryFactory uses that as the identity handle for the Subject. For Actions where no instance Subject exists at all (none in V0 HelloInvoice), use the `kernel.reporting.aggregate` synthetic.

### 9.6 Redaction

```php
class Redactor
{
    public function __construct(private array $globPatterns) {}

    public function applyToEntry(AuditEntry $entry): AuditEntry
    {
        return $entry
            ->withInputs($this->redact($entry->inputs))
            ->withOutputs($this->redact($entry->outputs));
    }

    private function redact(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? $key : "{$prefix}.{$key}";
            if ($this->matchesAny($path)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = $this->redact($value, $path);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
```

V0 reads `audit.redact` config (RFC-007 §14.1 global patterns); per-Field `sensitive: true` annotation deferred to M2 (requires RFC-001 Amendment-02 §A-1.12 to be reflected in the FieldNode shape — already covered per Amendment-02; runtime just needs to consult the flag).

---

## 10. Policy Engine V0 implementation

### 10.1 V0 simplifications

| RFC-005 feature                                | V0 implementation                                                  |
|------------------------------------------------|-------------------------------------------------------------------|
| 9-segment chain (RFC-005 §4.1)                 | 1 segment (Action base) — only segment present in V0 HelloInvoice |
| Two-tier cache (§8)                            | NONE — recompute every call                                       |
| Side-effect spy (§10.3)                        | NONE — trust + PHPStan rule                                       |
| Per-Policy timeout (§9.2)                      | NONE — best-effort relying on PHP's default execution timeout     |
| Field visibility evaluation (Amendment-01 §A-1.2) | Not exercised in V0 (no Fields with `visibility`)              |
| Projection-level Policies                      | Not evaluated in V0 (no ViewSchema)                               |
| Tenant-added Policies (Amendment-01 §A-1.3)    | Not evaluated in V0 (no overrides)                                |
| Global Policies                                | Not registered in V0                                              |

What V0 implements:

- Chain resolution: read Action's policyFqn from MetadataGraph → resolve PolicyDescriptor → instantiate Policy class via container.
- Evaluation: call `policy.evaluate(actor, actionFqn, subject, context)`.
- Composition: trivial (only one policy per chain in V0 HelloInvoice; combinator handles N).
- Deny-by-default at chain top.
- Fail-closed on Policy exceptions (wrap as Deny, log).

### 10.2 `DefaultPolicyEngine`

```php
final class DefaultPolicyEngine implements PolicyEngine
{
    public function __construct(
        private MetadataGraph $graph,
        private Container $container,
        private ChainResolver $resolver,
    ) {}

    public function evaluate(
        ActionNode $action,
        Actor $actor,
        ?Reference $subject,
        InvocationContext $context,
    ): Decision {
        $chain = $this->resolver->resolveForAction($action);
        $result = Decision::Abstain;
        foreach ($chain as $policyFqn) {
            $decision = $this->evaluatePolicy($policyFqn, $actor, $action->fqn, $subject, $context);
            $result = Combinator::combine($result, $decision);
            if ($result === Decision::Deny) {
                return Decision::Deny;   // short-circuit
            }
        }
        // Deny-by-default
        return $result === Decision::Abstain ? Decision::Deny : $result;
    }

    private function evaluatePolicy(
        string $policyFqn,
        Actor $actor,
        string $actionFqn,
        ?Reference $subject,
        InvocationContext $context,
    ): Decision {
        $descriptor = $this->graph->policy($policyFqn);
        if ($descriptor === null) {
            return Decision::Deny;  // missing policy = deny (compiler should have caught)
        }

        try {
            $policyInstance = $this->container->make($descriptor->implementationClass);
            $subjectValueObject = $subject !== null ? new Subject(...$subject) : null;
            $policyContext = new Context(/* from invocation context */);
            return $policyInstance->evaluate($actor, $actionFqn, $subjectValueObject, $policyContext);
        } catch (\Throwable $e) {
            // Fail-closed
            // V0: log; M2: emit PolicyException via diagnostic channel per RFC-005 §9.3
            return Decision::Deny;
        }
    }
}
```

### 10.3 `Combinator`

```php
final class Combinator
{
    public static function combine(Decision $left, Decision $right): Decision
    {
        return match (true) {
            $left === Decision::Deny || $right === Decision::Deny => Decision::Deny,
            $left === Decision::Permit || $right === Decision::Permit => Decision::Permit,
            default => Decision::Abstain,
        };
    }
}
```

Five lines. Truth table exhaustively verified in RFC-005 Appendix C.

### 10.4 Built-in Policies (V0: 3 classes)

```php
final class RoleRequired implements Policy
{
    public function __construct(private string $requiredRole) {}

    public function evaluate(Actor $actor, string $action, ?Subject $subject, Context $context): Decision
    {
        return in_array($this->requiredRole, $actor->roles(), strict: true)
            ? Decision::Permit
            : Decision::Deny;
    }
}
```

`PermissionRequired` and `RolesRequired` are structurally identical.

These classes are instantiated by the Compiler's Normalizer at boot, parameterized with the role/permission strings declared in the DSL (e.g., `Action::make()->requireRole('invoice.issuer')` produces a `RoleRequired('invoice.issuer')` instance).

Registered with the container as singletons by the RuntimeServiceProvider.

---

## 11. Workflow Runtime V0 implementation

### 11.1 V0 simplifications

| RFC-006 feature                                              | V0 implementation                                              |
|--------------------------------------------------------------|---------------------------------------------------------------|
| Multi-Workflow support (§2.2)                                | YES — runtime iterates over Action's transition set            |
| Wildcard `source: '*'` (§4.5)                                | YES — needed for `cancel` from any state in HelloInvoice       |
| Guard Policy evaluation (§4.2 step 4)                        | YES — but HelloInvoice has no guard Policies declared          |
| Subject pre-load caching (§4.6)                              | YES — single Repository call; cached for step 4                |
| Built-in TransitionEffect handles state mutation (§5.1)      | YES — required for HelloInvoice                                |
| Custom Effects trust pattern (§5.2)                          | YES — runtime does not post-verify                             |
| Initial state on create (§5.4)                               | YES — CreateEffect sets stateField to Workflow.initial if not in inputs |
| MaintenanceAction bypass (§8)                                | Wired but unused in V0                                          |
| Workflow inference from enum + transitions (RFC-011 §6.4)    | YES — handled by Compiler; runtime reads inferred WorkflowNode |

### 11.2 `TransitionSetIndex`

Built at boot from the MetadataGraph. Maps each Action FQN to the set of `(WorkflowNode, TransitionNode)` pairs that fire for it.

```php
final class TransitionSetIndex
{
    /** @var array<string, list<array{0: WorkflowNode, 1: TransitionNode}>> keyed by Action FQN */
    private array $index;

    public function __construct(MetadataGraph $graph)
    {
        foreach ($graph->workflows as $workflow) {
            foreach ($workflow->transitions as $transition) {
                $this->index[$transition->viaActionFqn][] = [$workflow, $transition];
            }
        }
    }

    public function forAction(string $actionFqn): array
    {
        return $this->index[$actionFqn] ?? [];
    }
}
```

O(workflows × transitions) at boot; O(1) per invocation lookup.

### 11.3 `DefaultWorkflowRuntime`

```php
final class DefaultWorkflowRuntime implements WorkflowRuntime
{
    public function __construct(
        private TransitionSetIndex $index,
        private MetadataGraph $graph,
    ) {}

    public function evaluate(
        ActionNode $action,
        ?Reference $subject,
        PersistenceContext $persistence,
        InvocationContext $context,
    ): WorkflowEvaluationResult {
        $transitions = $this->index->forAction($action->fqn);
        if (empty($transitions)) {
            return WorkflowEvaluationResult::skip();  // Action not Workflow-attached
        }
        if ($action->kind === 'maintenance' && $action->skipWorkflowGuards) {
            return WorkflowEvaluationResult::bypassed();
        }
        if ($subject === null) {
            throw new WorkflowSubjectRequired($action->fqn);
        }

        // Load Subject once
        $entity = $persistence->repository($subject->entityFqn())->find($subject);
        if ($entity === null) {
            throw new WorkflowSubjectNotFound($subject, /* first workflow */);
        }

        foreach ($transitions as [$workflow, $transition]) {
            $current = $entity->field($workflow->stateField);
            if ($current === null || !in_array($current, $workflow->states, true)) {
                throw new WorkflowStateInvalid($subject, $workflow->fqn, $current);
            }
            if ($transition->source !== '*' && $current !== $transition->source) {
                throw new WorkflowStateMismatch($subject, $workflow->fqn, $transition->source, $current);
            }
            if ($transition->guardPolicyFqn !== null) {
                $decision = $this->policyEngine->evaluatePolicy(
                    $transition->guardPolicyFqn, /* ... */
                );
                if ($decision !== Decision::Permit) {
                    throw new WorkflowGuardDenied($subject, $workflow->fqn, $transition, $decision);
                }
            }
        }

        return WorkflowEvaluationResult::passed(cachedEntity: $entity);
    }
}
```

The cached entity is passed to the Effect via the EffectContext (V0 doesn't expose it explicitly; the Effect re-loads via Repository, which sees the same transaction-local snapshot).

### 11.4 Built-in Effects

```php
final class CreateEffect implements Effect
{
    public function __construct(
        private string $entityFqn,
        private array $declaredInputs,
        private ?string $workflowStateField,   // for setting initial state if applicable
        private ?string $workflowInitial,
    ) {}

    public function execute(EffectContext $ctx, ?Reference $subject, array $inputs): array
    {
        $repo = $ctx->persistence()->repository($this->entityFqn);
        $payload = $inputs;
        if ($this->workflowStateField !== null && !isset($payload[$this->workflowStateField])) {
            $payload[$this->workflowStateField] = $this->workflowInitial;
        }
        $entity = $repo->create($payload);
        return ['id' => $entity->reference()->identityHandle(), ...$entity->fields()];
    }
}

final class TransitionEffect implements Effect
{
    public function __construct(
        private string $entityFqn,
        private string $stateField,
        private string $target,
        private array $stamps,                  // ['issued_at', ...]
    ) {}

    public function execute(EffectContext $ctx, ?Reference $subject, array $inputs): array
    {
        $repo = $ctx->persistence()->repository($this->entityFqn);
        $entity = $repo->find($subject);
        if ($entity === null) {
            throw new \DomainException("Subject not found during transition");
        }
        $patch = [$this->stateField => $this->target];
        foreach ($this->stamps as $field) {
            $patch[$field] = $ctx->clock()->toRfc3339();
        }
        foreach ($inputs as $key => $value) {
            // Accept declared inputs (e.g., 'reason' for cancel)
            $patch[$key] = $value;
        }
        $repo->update($subject, $patch, $entity->version());
        return $patch;
    }
}
```

Both are instantiated parameterized per-Action at boot by the RuntimeServiceProvider, registered in BuiltinEffectRegistry.

---

## 12. Effect dispatch V0

### 12.1 Resolution algorithm

```php
final class EffectDispatcher
{
    public function __construct(
        private BuiltinEffectRegistry $builtins,
        private Container $container,
    ) {}

    public function resolve(ActionNode $action): Effect
    {
        if ($this->builtins->has($action->fqn)) {
            return $this->builtins->get($action->fqn);
        }
        // Plugin-defined Effect: resolve via container
        return $this->container->make($action->effectClass);
    }
}
```

The `BuiltinEffectRegistry` is populated at boot from the MetadataGraph: for every ActionNode whose `effectClass` is a built-in marker (e.g., `"kernel.builtin.create"` or `"kernel.builtin.transition"`), the RuntimeServiceProvider constructs the appropriate parameterized Effect and registers it.

```php
final class BuiltinEffectRegistry
{
    /** @var array<string, Effect> keyed by Action FQN */
    private array $effects = [];

    public function register(string $actionFqn, Effect $effect): void { $this->effects[$actionFqn] = $effect; }
    public function has(string $actionFqn): bool                     { return isset($this->effects[$actionFqn]); }
    public function get(string $actionFqn): Effect                    { return $this->effects[$actionFqn]; }
}
```

### 12.2 Boot-time registration

```php
class RuntimeServiceProvider
{
    public function boot(): void
    {
        $graph    = $this->app->make(MetadataGraph::class);
        $registry = new BuiltinEffectRegistry();

        foreach ($graph->actions as $action) {
            if ($action->effectClass === BuiltinEffectMarkers::CREATE) {
                $registry->register($action->fqn, new CreateEffect(
                    entityFqn: $action->entityFqn,
                    declaredInputs: array_map(fn($i) => $i->name, $action->inputs),
                    workflowStateField: $this->stateFieldFor($graph, $action),
                    workflowInitial: $this->workflowInitialFor($graph, $action),
                ));
            } elseif ($action->effectClass === BuiltinEffectMarkers::TRANSITION) {
                $params = $this->transitionParamsFor($graph, $action);
                $registry->register($action->fqn, new TransitionEffect(
                    entityFqn: $action->entityFqn,
                    stateField: $params['stateField'],
                    target: $params['target'],
                    stamps: $params['stamps'],
                ));
            }
        }

        $this->app->instance(BuiltinEffectRegistry::class, $registry);
    }
}
```

For plugin-defined Effect classes: not pre-instantiated. Resolved on demand via `container->make()` per invocation. (PHP's autoloader is fast; container resolution is microseconds.)

### 12.3 Why two paths

Built-in Effects need parameterization (e.g., TransitionEffect needs to know which target state). Plugin Effects know their own state (encapsulated). The registry pattern lets built-ins be pre-configured at boot; plugin Effects are stateless classes per RFC-013 §3.7, fine to construct per call.

---

## 13. Auditor V0 implementation

### 13.1 `DefaultAuditor`

```php
final class DefaultAuditor implements Auditor
{
    public function __construct(
        private AuditSink $primarySink,           // V0: DatabaseTransactionalSink from ausus/audit-database
        private EntryFactory $factory,
        private SequenceCounter $counter,
        private Redactor $redactor,
    ) {}

    public function emit(AuditEntry $entry, ?TransactionHandle $txn): EmissionOutcome
    {
        $entry = $this->redactor->applyToEntry($entry);

        if ($txn === null) {
            // V0: should never happen; Invoker always passes a txn
            throw new AuditError\Internal("Auditor.emit requires a transaction in V0");
        }

        try {
            assert($this->primarySink instanceof TransactionalSink);
            $this->primarySink->writeInTransaction($entry, $txn);
        } catch (SinkRejected $e) {
            throw new AuditEmissionFailed("primary sink rejected", $e);
        }

        // V0: no secondary sinks; nothing to dispatch
        return new EmissionOutcome(primaryAcked: true, secondaryDispatched: []);
    }
}
```

### 13.2 `SequenceCounter`

```php
final class SequenceCounter
{
    /** @var array<string, int> */
    private array $perCorrelation = [];

    public function next(string $correlationId): int
    {
        $current = $this->perCorrelation[$correlationId] ?? -1;
        $next    = $current + 1;
        $this->perCorrelation[$correlationId] = $next;
        return $next;
    }
}
```

In-memory, per-process. Sufficient because correlationIds are process-scoped per RFC-007 §9.3. Maximum memory: O(active correlations × 32 bytes); negligible.

Stale entries (correlations from completed invocations) are not garbage-collected in V0. M2 adds LRU.

---

## 14. Service graph + boot process

### 14.1 Service binding graph

```
KernelServiceProvider::boot()
    ↓ Compiler runs
    ↓ MetadataGraph singleton bound
    ↓ UlidGenerator bound

SqlPersistenceServiceProvider::boot()
    ↓ SqlPersistenceDriver bound as PersistenceDriver

TenancyRowServiceProvider::boot()
    ↓ RowTenantIsolationStrategy bound
    ↓ TenantResolvers (HTTP/CLI/QUEUE/SCHEDULED) bound
    ↓ TenantCatalog bound

DatabaseAuditServiceProvider::boot()
    ↓ DatabaseTransactionalSink bound as primary AuditSink

AuthBridgeServiceProvider::boot()
    ↓ StubActorResolver bound as ActorResolver

RuntimeServiceProvider::boot()
    ↓ TransitionSetIndex built from MetadataGraph
    ↓ BuiltinEffectRegistry populated (V0: 1 CreateEffect + N TransitionEffects)
    ↓ Built-in Policy classes (RoleRequired, etc.) registered (transient via container.bind)
    ↓ DefaultPolicyEngine bound as PolicyEngine
    ↓ DefaultWorkflowRuntime bound as WorkflowRuntime
    ↓ EffectDispatcher bound
    ↓ EntryFactory bound
    ↓ SequenceCounter bound (singleton for process)
    ↓ Redactor bound (constructed with audit.redact config)
    ↓ DefaultAuditor bound as Auditor (composes primarySink, factory, counter, redactor)
    ↓ DefaultInvoker bound as Invoker
    ↓ InvocationContextProvider bound (request-scoped)
```

### 14.2 Container resolution graph (request time)

```
Invoker (singleton)
  ├─ PolicyEngine (singleton)
  │   ├─ MetadataGraph
  │   ├─ Container (for resolving Policy classes per call)
  │   └─ ChainResolver
  ├─ WorkflowRuntime (singleton)
  │   ├─ TransitionSetIndex
  │   └─ MetadataGraph
  ├─ EffectDispatcher (singleton)
  │   ├─ BuiltinEffectRegistry
  │   └─ Container (for resolving plugin Effects per call)
  ├─ Auditor (singleton)
  │   ├─ AuditSink (primary; singleton)
  │   ├─ EntryFactory
  │   ├─ SequenceCounter (singleton)
  │   └─ Redactor (singleton)
  ├─ PersistenceDriver (singleton)
  ├─ InvocationContextProvider (request-scoped)
  │   ├─ ActorResolver
  │   ├─ TenantContext
  │   └─ UlidGenerator
  └─ MetadataGraph (singleton; for action lookups)
```

### 14.3 Boot order strictness

The order in `RuntimeServiceProvider::boot()` matters:

1. Read MetadataGraph (must be bound by KernelServiceProvider first — Laravel runs `register()` for all providers before any `boot()`).
2. Build TransitionSetIndex (needs the graph).
3. Build BuiltinEffectRegistry (needs the graph + parameterization logic).
4. Bind the engines (they only need the graph + their own helpers).
5. Bind the Invoker (composes everything).

If any L3 driver's binding fails (e.g., audit-database can't find the audit table), the runtime fails to compose. `ausus:doctor` catches at boot.

---

## 15. Cache strategy V0

### 15.1 What is cached

| What                                           | Lifetime           | Implementation                          |
|------------------------------------------------|--------------------|------------------------------------------|
| `MetadataGraph`                                | Process lifetime   | Singleton in container                   |
| `TransitionSetIndex`                           | Process lifetime   | Built once at boot; immutable            |
| `BuiltinEffectRegistry`                        | Process lifetime   | Built once at boot; immutable            |
| Built-in Policy class instances (`RoleRequired`)| Process lifetime  | Singletons in container                  |
| `SequenceCounter` state                        | Process lifetime   | In-memory map (`array<correlationId, int>`) |
| Pinned `Clock` per invocation                  | Invocation lifetime| Created in InvocationContextProvider     |

### 15.2 What is NOT cached

| What                                           | Why                                                          |
|------------------------------------------------|-------------------------------------------------------------|
| Policy evaluation results                      | RFC-005 §8 two-tier cache deferred to M2; per-Tenant overlays complicate |
| Workflow guard evaluation results              | Subject-dependent; no useful cache key                        |
| Audit entries                                  | Not cached; write-once-immediately                            |
| Repository reads                               | Per-transaction MVCC; driver handles                          |
| Effect output                                  | One-shot per invocation                                       |

### 15.3 Cache invalidation V0

Trivial: process restart. The MetadataGraph and derived caches are immutable per process. Plugin changes require recompile (M2 adds disk-cached compiled graph; V0 recompiles every boot).

---

## 16. Sequence diagrams

### 16.1 `test.invoice.create` (no Workflow attached)

```
PHPUnit test
  invoker.invoke(actor, "test.invoice.create", null, {number, customer, amount})
    │
    ├─ DefaultInvoker.invoke
    │   │
    │   ├─ Pre-flight
    │   │   ├─ graph.action("test.invoice.create") → ActionNode (kind: standard, subject_required: false)
    │   │   ├─ actor != null ✓
    │   │   ├─ subject_required=false; subject is null ✓
    │   │   ├─ invocationContext = InvocationContextProvider.bind()
    │   │   │     ↳ actor, tenant, correlationId=C1, clock=PinnedClock(t1)
    │   │
    │   ├─ Step 1: tenant context (already in InvocationContext)
    │   │
    │   ├─ Step 2: policy chain
    │   │   ├─ policyEngine.evaluate(action, actor, null, ctx)
    │   │   │   ├─ chainResolver → ["test.invoice.policy.create"]
    │   │   │   ├─ RoleRequired('invoice.creator').evaluate(actor, ...) → Permit
    │   │   │   ├─ combinator → Permit
    │   │   │   └─ return Permit
    │   │
    │   ├─ persistenceDriver.beginTransaction(tenant) → txn1
    │   │
    │   ├─ Step 3: workflow guard
    │   │   ├─ transitionSetIndex.forAction("test.invoice.create") → []
    │   │   └─ skip
    │   │
    │   ├─ Step 4: effect
    │   │   ├─ effectDispatcher.resolve(action) → builtins.get("test.invoice.create") → CreateEffect
    │   │   ├─ effectContext = DefaultEffectContext(persistence, this, actor, tenant, C1, null, null, clock)
    │   │   ├─ CreateEffect.execute(effectContext, null, inputs)
    │   │   │   ├─ repo = persistence.repository("test.invoice")
    │   │   │   ├─ entity = repo.create({number, customer, amount, status: 'DRAFT'})
    │   │   │   │   ↳ SQL: INSERT INTO test_invoice ... RETURNING * (within txn1)
    │   │   │   ├─ return {id: 'inv_01J', number, customer, amount, status: 'DRAFT'}
    │   │   └─ outputs = {id: 'inv_01J', ...}
    │   │
    │   ├─ Step 5: audit
    │   │   ├─ entry = entryFactory.build(action, actor, null→synthetic, inputs, outputs, ctx)
    │   │   │     ↳ entryId=E1, sequence=0, correlationId=C1, invocationClass='Standard'
    │   │   │     ↳ subject = SingleSubject(tenant, 'test.invoice', 'inv_01J')   // from outputs.id
    │   │   ├─ redactor.applyToEntry(entry) → no patterns match
    │   │   ├─ primarySink.writeInTransaction(entry, txn1)
    │   │   │     ↳ SQL: INSERT INTO kernel_audit_log ... (within txn1)
    │   │   └─ EmissionOutcome(acked=true)
    │   │
    │   ├─ persistenceDriver.commit(txn1)
    │   │     ↳ both INSERTs committed atomically
    │   │
    │   └─ return outputs
```

### 16.2 `test.invoice.issue` (Workflow transition)

```
PHPUnit test
  invoker.invoke(actor, "test.invoice.issue", invoiceRef, {})
    │
    ├─ DefaultInvoker.invoke
    │   │
    │   ├─ Pre-flight ✓
    │   │   ├─ action.kind=standard, subject_required=true
    │   │   ├─ invoiceRef.tenant == active ✓
    │   │   └─ invocationContext: correlationId=C2 (fresh)
    │   │
    │   ├─ Step 1 ✓
    │   │
    │   ├─ Step 2: policyEngine → Permit (RoleRequired('invoice.issuer'))
    │   │
    │   ├─ driver.beginTransaction → txn2
    │   │
    │   ├─ Step 3: workflow
    │   │   ├─ transitionSetIndex.forAction("test.invoice.issue") →
    │   │   │     [(lifecycle, {source:DRAFT, target:ISSUED, via:test.invoice.issue})]
    │   │   ├─ workflowRuntime.evaluate(action, invoiceRef, persistence, ctx)
    │   │   │   ├─ entity = repo.find(invoiceRef)
    │   │   │   │     ↳ SQL: SELECT * FROM test_invoice WHERE id=? AND tenant_id=? (within txn2)
    │   │   │   │     ↳ entity={status:'DRAFT', _version:v1, ...}
    │   │   │   ├─ current = 'DRAFT' ∈ {DRAFT,ISSUED,CANCELLED} ✓
    │   │   │   ├─ source='DRAFT' == current ✓
    │   │   │   ├─ guard=null skip
    │   │   │   └─ pass
    │   │
    │   ├─ Step 4: effect
    │   │   ├─ effectDispatcher → TransitionEffect(state=status, target=ISSUED, stamps=[issued_at])
    │   │   ├─ TransitionEffect.execute(ctx, invoiceRef, {})
    │   │   │   ├─ entity = repo.find(invoiceRef)  // re-read within same txn; sees same v1
    │   │   │   ├─ patch = {status: ISSUED, issued_at: clock.toRfc3339()}
    │   │   │   ├─ repo.update(invoiceRef, patch, expected: v1)
    │   │   │   │     ↳ SQL: UPDATE test_invoice SET ..., _version=v2 WHERE id=? AND tenant_id=? AND _version=v1
    │   │   │   │     ↳ rowsAffected = 1 ✓
    │   │   │   └─ return {status: ISSUED, issued_at: '2026-05-19T...'}
    │   │
    │   ├─ Step 5: audit
    │   │   ├─ entry: {entryId=E2, sequence=0, correlationId=C2, action=test.invoice.issue,
    │   │   │          subject=SingleSubject(tenant, test.invoice, inv_01J),
    │   │   │          outputs={status, issued_at}}
    │   │   └─ primarySink.writeInTransaction(entry, txn2) ✓
    │   │
    │   ├─ commit(txn2)
    │   │
    │   └─ return outputs
```

### 16.3 Concurrency conflict on second issue attempt

```
[Two concurrent processes both call invoker.invoke("test.invoice.issue", invoiceRef, {})]

Process A:                                Process B:
─────────────────────                     ─────────────────────
  ... up through Step 3 ...                 ... up through Step 3 ...
  entity v1                                 entity v1
  ↓                                         ↓
  Step 4: repo.update(ref, ..., v1)         Step 4: repo.update(ref, ..., v1)
       ↳ rowsAffected=1; v2 assigned             ↳ rowsAffected=0; row exists; v=v2≠v1
       ↳ returns ok                              ↳ follow-up SELECT _version
                                                 ↳ throw ConcurrencyConflict
       ↓                                         ↓
  Step 5: audit emit ✓                      Step 5: NEVER REACHED
       ↓                                         ↓
  commit ✓                                  driver.rollback (in Invoker's finally)
       ↓                                         ↓
  returns outputs                           throws ConcurrencyConflict to caller
```

Postgres MVCC handles the contention; Repository's optimistic-lock check surfaces the conflict cleanly.

---

## 17. Failure matrix

| Step / Phase                | Failure                                | Error type                       | Rollback? |
|------------------------------|-----------------------------------------|----------------------------------|-----------|
| Pre-flight: action lookup    | Action FQN not in graph                 | `UnknownAction`                  | No txn    |
| Pre-flight: subject mismatch | `subject_required: true` but null       | `PolicySubjectRequired`          | No txn    |
| Pre-flight: actor missing    | `ActorResolver` returned null            | `ActorRequired`                  | No txn    |
| Step 1: tenant missing       | No active Tenant Context                 | `TenantContextRequired`          | No txn    |
| Step 1: tenant mismatch      | `subject.tenant != active`              | `TenantBoundaryViolation`        | No txn    |
| Step 2: policy denies        | Combined chain → `Deny`                 | `PolicyDenied(action, decision)` | No txn    |
| Step 2: policy throws        | Plugin exception in `evaluate()`         | wrapped `PolicyException` → contributes `Deny` to chain | No txn    |
| Step 2: malformed return     | Policy returns non-`Decision`            | `PolicyMalformedReturn` → `Deny` | No txn    |
| (txn opens)                  |                                          |                                  |           |
| Step 3: subject not loaded   | Repository.find returned null            | `WorkflowSubjectNotFound`        | Yes       |
| Step 3: state invalid        | Subject's state not in workflow.states  | `WorkflowStateInvalid`           | Yes       |
| Step 3: state mismatch       | current ≠ transition.source              | `WorkflowStateMismatch`          | Yes       |
| Step 3: guard denies         | Guard Policy → `Deny`                    | `WorkflowGuardDenied`            | Yes       |
| Step 4: domain exception     | Effect throws `\DomainException` or plugin error | `EffectFailed(action, cause)` | Yes |
| Step 4: persistence error    | Repository raises `ConcurrencyConflict`, `ConstraintViolation`, etc. | propagated as-is (already closed taxonomy) | Yes |
| Step 4: forbidden side effect | Effect calls `app()` (detected by spy — M2) | `EffectForbiddenSideEffect`  | Yes       |
| Step 5: primary sink fails   | `SinkRejected`                           | `AuditEmissionFailed`            | Yes       |
| Commit: connection error     | `PDOException` on commit                 | `DriverError('commit', cause)`   | partial; surfaced |

All errors propagate to the caller. The Invoker does NOT retry. Caller decides what to do.

---

## 18. V0 executable path

For the M1 integration test:

```
[1] Boot Laravel with all 6 packages installed
[2] KernelServiceProvider boots:
    ├─ Compile graph
    ├─ MetadataGraph singleton bound
[3] SqlPersistenceServiceProvider boots (driver bound)
[4] TenancyRowServiceProvider boots (catalog + resolvers bound)
[5] DatabaseAuditServiceProvider boots (primary sink bound)
[6] AuthBridgeServiceProvider boots (stub actor resolver bound)
[7] RuntimeServiceProvider boots:
    ├─ TransitionSetIndex built (1 entry: test.invoice.issue → 1 transition)
    ├─ BuiltinEffectRegistry built (CreateEffect for create; TransitionEffect for issue + cancel)
    ├─ Engines bound
    ├─ Invoker bound

[8] Test setup:
    ├─ Run `ausus:migrate` → test_invoice + kernel_audit_log tables exist
    ├─ Bootstrap Tenant: tenant_id="test"
    ├─ TenantContext::bind(test)
    ├─ Create stub actor: user="user42", roles=["invoice.creator","invoice.issuer","invoice.viewer"]

[9] Test invocations:
    invoker = app(Invoker::class)

    # Create
    outputs1 = invoker.invoke(actor, "test.invoice.create", null, {
        number: "INV-001",
        customer_name: "Test",
        amount: 1500.00
    })
    assert outputs1["status"] == "DRAFT"
    assert outputs1["id"] is 26-char ULID

    # Issue
    invoiceRef = Reference(test, test.invoice, outputs1["id"])
    outputs2 = invoker.invoke(actor, "test.invoice.issue", invoiceRef, {})
    assert outputs2["status"] == "ISSUED"
    assert outputs2["issued_at"] starts with "2026-"

    # Verify state via Repository directly (read-only verification)
    ctx = persistenceDriver.context(test, persistenceDriver.beginTransaction(test))
    repo = ctx.repository("test.invoice")
    final = repo.find(invoiceRef)
    assert final.field("status") == "ISSUED"
    persistenceDriver.rollback(ctx.transaction())  # read-only, just close

    # Verify audit
    auditRows = DB.select("SELECT * FROM kernel_audit_log ORDER BY timestamp")
    assert count(auditRows) == 2
    assert auditRows[0].action_fqn == "test.invoice.create"
    assert auditRows[1].action_fqn == "test.invoice.issue"
    assert auditRows[0].correlation_id != auditRows[1].correlation_id  # separate top-level calls

    # Concurrency: stale write
    try {
        # Reuse a stale version
        repo3.update(invoiceRef, {status: "CANCELLED"}, originalVersionV1)
        fail("Expected ConcurrencyConflict")
    } catch (ConcurrencyConflict) { /* pass */ }

[10] Test teardown: rollback any open transactions; drop test DB
```

This is M1's acceptance for `ausus/runtime-default` + cooperation with all sibling packages.

---

## 19. Synchronous-only assumptions and non-goals

### 19.1 V0 synchronous assumptions

- Every Invoker call returns synchronously with outputs or throws.
- No invocation is queued, scheduled, retried, delayed.
- No call returns a Promise / Future / deferred handle.
- Failed invocations are caller's responsibility to retry (if appropriate).

### 19.2 Non-goals (explicit)

- **Async invocation.** Not in V0; not in M1; not in M2. A future RFC may add via a separate `AsyncInvoker` contract. The current `Invoker` contract is synchronous by signature.
- **Queue dispatch from inside Effects.** Plugin authors who want background work dispatch at L4 (after the synchronous Invoker call returns). RFC-013 §4.4 documents.
- **Retry policies.** Not even configurable in V0. Caller retries via fresh `invoke()` call.
- **Distributed transactions / sagas.** Single driver per RFC-002 §14; no coordination across stores.
- **Event sourcing.** The audit log IS the event log. Replaying audit to reconstruct state is a downstream concern (out of V0; out of M1, M2, M3).
- **Webhooks / external event emission.** Plugin authors emit via Effects (if they MUST), via Laravel's queue at L4.

### 19.3 Temporary V0 simplifications

These are explicit V0 shortcuts that will be removed when downstream RFCs exercise the surface:

| Simplification                                       | Removed in | Why temporary                                              |
|------------------------------------------------------|------------|-----------------------------------------------------------|
| Policy Engine: no cache                              | M2         | RFC-005 §8 specifies two-tier cache; defer until performance matters |
| Policy Engine: no side-effect spy                    | M2         | Static analysis catches most violations; spy is belt+braces |
| Policy Engine: no timeout enforcement                | M2         | PHP default execution timeout sufficient for HelloInvoice  |
| Audit: no secondary sinks                            | M2 or M3   | Only Transactional primary in V0                            |
| Audit: no retry queue                                | M2 or M3   | No secondaries; no retries needed                          |
| Audit: no orphan reconciliation                      | post-V1    | Transactional primary cannot orphan                         |
| Effect: no field visibility evaluation               | M2         | No Fields with `visibility` in HelloInvoice                |
| Workflow: no MaintenanceAction bypass exercised      | M3         | No MaintenanceActions in HelloInvoice                       |
| Elevation: stub raises                                | M2         | HelloInvoice single-Tenant                                  |
| Audit: simple in-memory SequenceCounter               | post-V1    | Per-process scoped; no cross-process sequencing needed     |

Each simplification is removable without changing public contracts. The RFC-defined interfaces are stable; V0 implementations are abbreviated.

### 19.4 Future extension points (no new abstractions needed now)

These are places where V0's structure already accommodates future features without redesign:

- **Multi-segment Policy chains.** `ChainResolver` returns a list; V0 returns 1-element lists; M2 returns longer lists by walking more segments. No interface change.
- **Multi-Workflow Entities.** `TransitionSetIndex.forAction()` returns N pairs; V0 typically has 0 or 1; future has more. No code change.
- **Per-Field visibility evaluation.** Add a method on PolicyEngine: `evaluateFieldVisibility(field, actor, subject, ctx)`. Already invocable via the same chain machinery.
- **Tenant-added Policies.** ChainResolver consults override store; the Invoker passes through unchanged.
- **External primary sink.** Switch the binding of `AuditSink` from `DatabaseTransactionalSink` to an `ExternalSink` implementation. Auditor's `emit()` detects the kind and uses 3-phase protocol. Plumbing is in place at the kernel contract level.
- **Elevation.** Replace `ElevationStub` with a real implementation. `InvocationContext::withElevation()` already exists.
- **Per-Tenant graph overlays.** `MetadataGraph` consumers go through a `ResolvedGraph` adapter that applies overlays. Adapter is added; consumers don't know.

None of these require new contracts. The V0 runtime is forward-compatible with every RFC-defined extension.

---

## 20. Implementation order

### 20.1 Within M1 sprint (days 7–10)

After the kernel ships M1-complete (days 1–6) and persistence-sql + tenancy-row + audit-database + auth-bridge are at least in-progress (days 5–6), the runtime is built in this order:

| Day | Focus                                                                                              |
|-----|----------------------------------------------------------------------------------------------------|
| 7   | `InvocationContext`, `DefaultEffectContext`, `PinnedClock`, `SequenceCounter`, `EntryFactory`, `Redactor` — value-object and helper layer; unit tests |
| 7   | `Combinator`, built-in Policy classes (`RoleRequired`, etc.) — unit tests for combinator + each Policy |
| 8   | `ChainResolver`, `DefaultPolicyEngine` — unit tests using stub graph + stub container               |
| 8   | `TransitionSetIndex`, `DefaultWorkflowRuntime` — unit tests using stub graph + stub Repository      |
| 9   | `BuiltinEffectRegistry`, built-in `CreateEffect` + `TransitionEffect`, `EffectDispatcher` — unit tests |
| 9   | `DefaultAuditor` — unit tests using stub sink                                                      |
| 10  | `DefaultInvoker` + `InvocationLifecycle` — wires all 5 steps; unit tests using all stubs           |
| 10  | `RuntimeServiceProvider` — boot wiring; integration test from `apps/playground`                    |

Approximately 1,800 LOC including tests. Built across 4 working days assuming the kernel + L3 packages are at least partially shipped.

### 20.2 Parallelization

Within the runtime package, days 7-9 are parallelizable:

- Maintainer A: Policy machinery (days 7-8)
- Maintainer B: Workflow + Effect machinery (days 7-9)
- Maintainer C: Audit machinery (day 7-9)
- All converge on day 10 for Invoker integration

With one maintainer: serial, ~7 working days.

---

## 21. Summary

- **22 classes** in `ausus/runtime-default` for V0.
- **5-step Invoker chain** per RFC-001 §A-1.4 §8.2.1; transaction opens between step 2 and step 3.
- **Single-segment Policy chain** (Action base only); no cache; no spy; no timeout.
- **Workflow runtime** supports multi-Workflow Entities + wildcards + Subject pre-load caching.
- **Two built-in Effects** parameterized at boot from MetadataGraph.
- **Three built-in parameterized Policies** registered at boot.
- **Transactional primary audit sink** only; no secondaries, no retry queue, no reconciliation.
- **Per-correlationId sequence counter**; entryId via ULID.
- **Glob-pattern redaction** from `audit.redact` config.
- **InvocationContext** request-scoped: actor, tenant, correlationId, traceId, elevation (null in V0), pinned clock.
- **Nested invocation wired** but untested in HelloInvoice; uses savepoints via Laravel auto-behavior.
- **Elevation stubbed** (throws); wiring complete for M2.
- **No async, no queues, no retries, no distributed transactions, no event sourcing, no sagas.** Hard rule.
- **13-row failure matrix** maps every error path to closed taxonomy + rollback decision.
- **3 sequence diagrams** worked end-to-end against HelloInvoice.
- **Implementation: ~1,800 LOC** in 4 working days within M1 days 7–10 of the sprint plan.
- **Every V0 simplification is removable without contract change.** Extension points listed for M2 and beyond.

The runtime is the package that completes M1. When it ships, `apps/playground`'s integration test runs end-to-end and RFC-000 V0 Real Pass moves from BLOCKED toward GO.
