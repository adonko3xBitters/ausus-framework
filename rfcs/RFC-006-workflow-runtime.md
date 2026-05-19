# RFC-006 — Workflow Runtime

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Draft                                                  |
| Authors       | architect, kernel, challenger                          |
| Date          | 2026-05-18                                             |
| Depends on    | RFC-001 Draft-04 (incl. Amendments-01 / -02), RFC-002, RFC-003, RFC-005, RFC-007 Draft-02 (incl. Amendment-01), RFC-010, RFC-011, RFC-012, RFC-013 |
| Mission       | Formalize the Workflow runtime — the implementation of Invoker step 3 — without adding new kernel primitives. Unblock RFC-000 F-V0-03. |
| Hard rule     | No state outside `PersistenceDriver`. No Policy bypass. No Tenant bypass. Actions remain the only mutation path. |

---

## 0. Problem statement

RFC-001 Amendment-01 §A-1.4 §8.2.1 defines step 3 of the Invoker chain as:

> "Workflow guard, if the Action triggers a Workflow transition. (Skipped only for `MaintenanceAction` per §2.4.1.)"

The Workflow primitive itself is defined by RFC-001 §2.6 (states, transitions, guards, effects). RFC-011 §6.4 commits the DSL to **inferring** Workflows from enum fields. RFC-012 §6.3 ships a "simple Workflow runtime" inside `ausus/runtime-default` as provisional. RFC-013 §1.2 explicitly defers "Effect-level Workflow state mutation" to this RFC.

What none of those specifies: how step 3 actually executes, where the state lives, how concurrent transitions interact with RFC-002 optimistic locking, how the runtime interacts with custom Effects vs `Action::transition` built-ins, and what the closed error taxonomy is.

This RFC formalizes the Workflow runtime for V1. It introduces **zero new kernel primitives** — Workflow, Transition, and TransitionDescriptor already exist in RFC-001 §2.6. What this RFC adds is the **execution contract** that the Invoker invokes during step 3 and the cooperation rules with RFC-013 Effects in step 4.

Eight challenger constraints frame the design:

1. No state outside `PersistenceDriver`.
2. No Policy bypass.
3. No Tenant bypass.
4. Actions remain the only mutation path.
5. MaintenanceAction can bypass guards only via the existing `skip_workflow_guards: true` flag (RFC-010 §8.1).
6. Compatible with RFC-002 optimistic locking.
7. Compatible with RFC-007 audit.
8. Compatible with RFC-011 inferred Workflows.

Every section satisfies all eight.

---

## 1. Scope and inherited constraints

### 1.1 Inherited (non-negotiable)

1. Workflow is a kernel primitive (RFC-001 §2.6). Descriptor shape (states, initial, transitions with source/target/via/guard) is unchanged.
2. The Invoker is the sole dispatcher of step 3 and step 4 (RFC-001 §A-1.4 §8.2.1).
3. Workflow guards are Policies (RFC-005). They evaluate via the Policy Engine through the same chain machinery used elsewhere.
4. Workflow state is read and written via the `Repository` contract (RFC-002 §5). No separate "state store" is introduced.
5. State mutations are subject to optimistic locking (RFC-002 §8). No exceptions.
6. Tenant scope applies to all reads and writes (RFC-002 §13). No exceptions.
7. MaintenanceActions MAY skip Workflow guards iff their manifest declares `skip_workflow_guards: true` (RFC-001 §2.4.1, RFC-010 §8.1).
8. Mutations during step 4 (the Effect) are governed by RFC-013.
9. The audit entry produced in step 5 (RFC-007) reflects the post-Effect, post-state-mutation outcome. Failed guards in step 3 produce no audit entry (consistent with RFC-013 §7.4).
10. The DSL infers single-field Workflows from enum Fields (RFC-011 §6.4); explicit Workflow declarations remain available for multi-field or non-enum cases.

### 1.2 Out of scope

- Declarative transition-level "effect Actions" (RFC-001 §2.6 mentions "Effects (other Actions triggered by a transition)"). V1 does **not** support these as a runtime concept. Plugin authors needing chained Actions invoke them from custom Effects via `EffectContext::invoker()->invoke(...)` per RFC-013 §4.
- Multi-Entity transitions (a single Action triggering transitions across multiple Subject instances of different Entities). V1 supports one Subject per Invoker call; cross-Entity orchestration uses nested invocation.
- Asynchronous transitions, delayed transitions, scheduled state changes. V1 transitions are synchronous within the active Invoker transaction.
- State-machine concepts beyond states + transitions (hierarchical states, parallel regions, history states, BPMN). The kernel commits only to flat state machines.
- Workflow versioning / migrating in-flight instances when the Workflow descriptor changes. The Workflow is part of the Metadata Graph; graph hash changes invalidate caches but do not migrate stored state. Plugin authors handling Workflow evolution write their own data migrations.
- Workflow visualization / runtime introspection beyond `ausus:doctor` checks. Out of V1; tooling concern.

---

## 2. State source

### 2.1 Where Workflow state lives

The Workflow state for a given Subject is the value of a **single Field on the Subject's Entity**. The Field MUST be of type `enum` (RFC-001 §2.2, RFC-012 §7) and MUST be declared on the Entity. The Workflow's `stateField` attribute names this Field.

There is **no separate state store**. The state is a column / attribute on the Entity's row, managed by `PersistenceDriver` like any other Field. Reads use `Repository::find` (RFC-002 §5); writes use `Repository::update` (subject to optimistic locking).

This satisfies challenger constraint 1 (no state outside PersistenceDriver) by construction.

### 2.2 Multiple Workflows on one Entity

RFC-001 §2.6 permits multiple Workflows attached to the same Entity. Each Workflow has its **own** `stateField` — distinct enum Field. For example, `billing.invoice` may carry `status` (lifecycle Workflow) and `approval_status` (approval Workflow). The two Workflows are independent at the runtime level; their transitions are evaluated separately in step 3.

If an Action triggers transitions on more than one Workflow (e.g., `cancel` transitions both `status` and `approval_status`), the runtime evaluates each Workflow's transition in declaration order. Failure of any one rejects the entire Action (no partial transitions).

### 2.3 Initial state

The Workflow's `initial` attribute names a state the runtime uses when a Subject is first created with no value in the `stateField`. The DSL infers this from the enum Field's `default()` value if declared; otherwise the explicit `workflow(..., initial: 'X')` form is required.

When a Subject is created via `Action::create` or a custom Effect, the runtime sets the `stateField` to the initial state if the create operation did not explicitly set it. Explicit set overrides initial.

### 2.4 What is NOT in the state

- Process variables, working memory, history of past transitions, "current Actor of this state": all out of scope. The state is the single enum value. Plugins needing process variables store them in additional Fields on the Entity.
- Pending events, deferred transitions: V1 has no event queue. Transitions are synchronous.
- Sub-states, hierarchical structure: V1 has flat states only.

### 2.5 Reading state

The runtime reads the Subject's state in step 3 via `Repository::find(subject)`. The read returns the full Entity instance; the runtime extracts `entity->field(stateField)`. The read happens inside the active transaction; the value reflects any prior writes within the same transaction (transaction-local consistency).

For Subjects loaded by the Action's caller (e.g., L4 passing a `Reference`), the runtime ignores any caller-supplied state hint. The runtime always loads fresh.

### 2.6 Writing state

For Actions declared via `Action::transition(...)` (RFC-011 §8.2 built-in), the runtime's default Effect writes the target state to the `stateField` using `Repository::update(subject, [stateField => target], version)`. The version used is the version read in step 3.

For Actions with custom Effects (RFC-013), the Effect is responsible for writing the state. The runtime does NOT verify the post-Effect state value. (See §5.3 for the verification trade-off.)

---

## 3. Workflow descriptor (restated)

```php
final class WorkflowDescriptor
{
    function fqn(): string;                  // e.g., 'billing.invoice.lifecycle'
    function ownerEntity(): string;          // e.g., 'billing.invoice'
    function stateField(): string;           // e.g., 'status'
    function states(): array;                // string[]
    function initial(): string;              // one of states()
    function transitions(): array;           // TransitionDescriptor[]
}

final class TransitionDescriptor
{
    function source(): string;               // one of states(), or '*' wildcard
    function target(): string;               // one of states()
    function via(): string;                  // Action FQN
    function guard(): ?string;               // Policy FQN; null if no explicit guard
}
```

This shape is what the Compiler produces from explicit DSL declarations OR from RFC-011 §6.4 inferred Workflows. The runtime consumes it.

No new fields versus RFC-001 §2.6. The `effects` slot RFC-001 §2.6 mentions ("Effects (other Actions triggered by a transition) are declared") is not populated in V1 — declarative effect Actions are out of scope (§1.2). The slot remains in the descriptor type for forward compatibility; runtime ignores it in V1.

---

## 4. Runtime lifecycle (Invoker step 3)

### 4.1 Trigger detection

At compile time, the Compiler computes for every Action the set of Workflow transitions that name it as their `via`. This is the Action's **transition set**: a list of `(WorkflowDescriptor, TransitionDescriptor)` pairs.

An Action with an empty transition set is **not Workflow-attached**; Invoker step 3 is a no-op for it.

An Action with a non-empty transition set is Workflow-attached. Step 3 evaluates every entry in the transition set.

### 4.2 Step 3 algorithm (per invocation)

For each `(Workflow W, Transition T)` in the Action's transition set:

```
1. Load the Subject (or pre-load once, sharing across iterations):
     entity = persistenceContext.repository(W.ownerEntity).find(subject)
     If entity == null:
       raise WorkflowSubjectNotFound(subject, W.fqn)

2. Read the current state:
     current = entity.field(W.stateField)
     If current == null OR current ∉ W.states:
       raise WorkflowStateInvalid(subject, W.fqn, current)

3. Match the transition's source:
     If T.source != '*' AND current != T.source:
       raise WorkflowStateMismatch(subject, W.fqn, expected: T.source, actual: current)

4. Evaluate the guard, if any:
     If T.guard != null:
       decision = policyEngine.evaluateChain(
         attachmentKey: "workflow-guard:" + T.guard,
         actor:    invocationActor,
         actionFqn: invocationActionFqn,
         subject:  invocationSubject,
         context:  invocationContext
       )
       If decision != Permit:
         raise WorkflowGuardDenied(subject, W.fqn, T, decision)
```

If any iteration raises, the Invoker rejects the Action: rollback the transaction (no mutations have occurred yet — step 4 hasn't run), no audit emission, return the error to the caller.

If all iterations pass, step 3 succeeds and the Invoker proceeds to step 4 (the Effect).

### 4.3 Multi-Workflow ordering

When multiple `(W, T)` pairs apply (an Action triggers transitions on more than one Workflow), they are evaluated in **declaration order**: the order in which Workflows are attached to the Entity in the DSL. This is deterministic per the Compiler's chain-assembly rule (RFC-005 §4.3).

Per-Workflow ordering within a single Workflow's transitions: at most one transition per Workflow per Action (see §4.4 ambiguity rule), so there is at most one transition per Workflow to evaluate.

### 4.4 Ambiguity

A single Workflow MUST declare at most ONE transition per `(source, via)` tuple. This includes wildcards: `(source='*', via='X')` counts as covering every source for action X. Declaring both `('DRAFT', 'cancel')` and `('*', 'cancel')` is ambiguous; the Compiler rejects with `WorkflowAmbiguousTransition` (§8).

For multi-Workflow Entities, each Workflow is independent: `('DRAFT', 'cancel')` in `lifecycle` and `('PENDING', 'cancel')` in `approval` are both valid (different Workflows).

### 4.5 Wildcard semantics

`T.source == '*'` matches any current state value declared in `W.states`. It does NOT match `null` or invalid state values; those raise `WorkflowStateInvalid` before the wildcard check.

Wildcards are useful for "cancel from anywhere" patterns. They MUST NOT be combined with explicit-source transitions for the same `via` (per §4.4).

### 4.6 Subject pre-load

The Subject is loaded at most once per step 3 invocation, even when multiple Workflows are evaluated. The runtime caches the loaded Entity for the duration of step 3 + step 4. The Effect can re-read via Repository if it wants a fresh load (e.g., after invoking a nested Action that mutated the Subject); otherwise the cached load is shared.

The cached load's version is the version used for state mutation in step 4 (for built-in `Action::transition` Effects). Custom Effects that re-read get a fresh version; their writes use whatever version they observed.

---

## 5. State mutation (step 4 cooperation)

### 5.1 Built-in `Action::transition`

For an Action declared via `Action::transition('status', from: 'X', to: 'Y')` (RFC-011 §8.2), the runtime ships a default Effect that:

1. Receives the `EffectContext` (RFC-013 §3).
2. Updates the `stateField` to the transition's `target`, using the version cached from step 3:
   ```
   ctx.persistence().repository(ownerEntity).update(
     subject,
     [stateField => target] + any `stamp` directives,
     cachedVersion
   )
   ```
3. Returns outputs including the new state and any stamped fields:
   ```
   return [stateField => target, stampedField => clock.toRfc3339(), ...]
   ```

For multi-Workflow Actions using multiple `Action::transition` declarations on the same Action key (DSL syntax to be specified by an extension to RFC-011), the default Effect applies all state mutations in a single `Repository::update` call (atomic within the transaction).

### 5.2 Custom Effects

For an Action with a custom Effect (RFC-013), the Effect is responsible for writing the state. Typical pattern:

```php
public function execute(EffectContext $ctx, ?Reference $subject, array $inputs): array
{
    $repo = $ctx->persistence()->repository('billing.invoice');
    $invoice = $repo->find($subject);
    $repo->update(
        $subject,
        ['status' => 'ISSUED', 'issued_at' => $ctx->clock()->toRfc3339()],
        $invoice->version()
    );
    return ['status' => 'ISSUED'];
}
```

The runtime does NOT post-verify that the state was actually mutated to the transition's target. This is the deliberate trust trade-off (§5.3).

### 5.3 Trust trade-off

The runtime trusts custom Effects. If a custom Effect for an Action declared to transition `DRAFT → ISSUED` actually leaves the state at `DRAFT`, the runtime does not detect it. Reasons:

- Post-verification requires a second Repository read after the Effect, doubling read load on every Workflow-attached Action.
- Custom Effects may legitimately do more complex things (e.g., skip the transition under a runtime business condition and return a no-op outcome). Forced post-verification would prevent this pattern.
- Plugin authors choosing custom Effects accept responsibility per RFC-013 §10.6.

`ausus:doctor` (§9) MAY surface a notice when an Action is declared as a Workflow transition AND uses a custom Effect (suggesting the author verify the Effect mutates state correctly). Static analysis cannot reliably confirm; the notice is informational.

### 5.4 Initial state on create

For `Action::create` (RFC-011 §8.2) on a Workflow-attached Entity, the default create Effect sets the `stateField` to the Workflow's `initial` state if the inputs did not explicitly set it. If the inputs DID set it (rare; usually create doesn't accept the state field as input), the inputs win.

For custom create Effects, the runtime checks the post-Effect state: if the `stateField` is `null` after the create, the runtime raises `WorkflowInitialStateNotSet(subject, W.fqn)`. This is the **one** post-Effect verification the runtime performs — only at create time, only for state-field initialization. Justified because an Entity with `null` Workflow state is fundamentally broken.

### 5.5 Stamp directives

`Action::transition(...)->stamp('issued_at')` (RFC-011 §8.2) declares that the named datetime Field is set to `Context::clock()` at execution time. The default Effect applies the stamp alongside the state mutation in step 4. Custom Effects that want stamp behavior call `$ctx->clock()->toRfc3339()` and set the field explicitly.

---

## 6. Concurrent transitions

### 6.1 Optimistic locking is the only mechanism

RFC-002 §8 optimistic locking is the sole concurrency primitive for state mutations. No Workflow-level pessimistic locking, no advisory locks, no row locks beyond what the Persistence Driver naturally provides during the transaction.

Two concurrent Invoker calls attempting the same transition on the same Subject:

1. Both pass step 3 (both read the same state; both pass the guard with version v1).
2. Both call Effect in step 4.
3. Both Effects attempt `Repository::update(subject, ..., version: v1)`.
4. First to commit wins. Subject's version becomes v2.
5. Second's update raises `ConcurrencyConflict` (RFC-002 §12.1).
6. Second's Invoker call rolls back; returns error to caller.

This is the same mechanism RFC-002 uses for any concurrent write. Workflow state is not special.

### 6.2 Concurrent different transitions

Two concurrent invocations on the same Subject for different transitions (e.g., `issue` and `cancel` racing on a DRAFT invoice):

1. Both read state DRAFT, version v1.
2. Both pass their respective guards (different Policies).
3. Both Effects attempt update.
4. First commits (state becomes ISSUED, version v2).
5. Second's update raises `ConcurrencyConflict`.

The losing transition's caller can retry; on retry, the current state is ISSUED, which may no longer be a valid source for the desired transition; retry rejects with `WorkflowStateMismatch`.

### 6.3 Read-then-act windows

Between step 3 (read state) and step 4 (mutate state), the Subject's version may change due to a different invocation that touches the Subject without going through this Workflow (e.g., updating a non-state field). The cached version becomes stale.

For built-in `Action::transition` Effects: the Effect uses the cached version. If stale, `ConcurrencyConflict` raised. Caller retries.

For custom Effects: the Effect re-reads if it needs fresh data. Fresh read yields fresh version; the Effect's write uses the fresh version.

In either case, optimistic locking surfaces the conflict and the Invoker rolls back cleanly.

### 6.4 No "transition is in progress" lock

V1 does not lock the Subject for the duration of step 3 + step 4. Other invocations may proceed; conflicts surface at write time. This is the standard MVCC pattern.

---

## 7. Terminal states

### 7.1 Definition

A state `S` in Workflow `W` is **terminal** iff no transition `T` in `W.transitions` has `T.source == S` (and no wildcard transition covers `S`'s position in the state set).

Terminal states are not flagged in the descriptor; they are computed from the transition set. The Compiler annotates the in-memory graph but does not require explicit terminal markers.

### 7.2 Reaching terminal

The runtime does not prevent reaching terminal states. The transition that lands the Subject in a terminal state proceeds normally; the state field is set; the audit emits.

### 7.3 Leaving terminal

An Action whose transition declares `source == terminal_state` is impossible to satisfy (no transitions out of terminal). Step 3 raises `WorkflowStateMismatch` (current state is terminal; transition's source matches; but the wildcard rule or explicit source declaration would have to exist).

More precisely: if the Action is Workflow-attached at all on a terminal-state Subject, the only way step 3 can pass is via wildcard `source: '*'`. A wildcard transition into a terminal state would be unusual; designers typically don't grant wildcard transitions to or from terminal states. If they do, the runtime allows it.

### 7.4 No "auto-archive" on terminal

Reaching terminal does not trigger automatic side effects (no implicit archive, no implicit notification). If the plugin wants such, they implement it in the Effect or via a downstream Action.

### 7.5 Doctor flags

`ausus:doctor` reports:

- Terminal states with no incoming transitions: unreachable; likely dead-code. Severity: notice.
- States that are NOT terminal but have no outgoing transitions and are NOT the initial state: same. Severity: notice.

---

## 8. MaintenanceAction bypass

### 8.1 Existing flag

Per RFC-001 §2.4.1 and RFC-010 §8.1, a MaintenanceAction's manifest declares `skip_workflow_guards: true | false`. When `true`, Invoker step 3 is **entirely skipped** for that Action. No state check, no guard evaluation, no Workflow-related rejection.

This is the only sanctioned bypass of step 3 in V1 (challenger constraint 5).

### 8.2 What `skip_workflow_guards: true` does NOT bypass

- Tenant Context check (step 1). Always runs.
- Policy chain (step 2). Always runs. The MaintenanceAction's manifest `policy` still gates invocation.
- Audit emission (step 5). Always runs.

The Workflow descriptor's state field is not implicitly mutated by the bypass. If the MaintenanceAction Effect wants to mutate state on bulk-affected Subjects, the Effect does so explicitly via `Repository::updateMany`. The runtime does not auto-mutate state for guard-skipped MaintenanceActions.

### 8.3 No state validation on bulk

`Repository::updateMany(filter, patch)` may include the state field in `patch`. The runtime does NOT validate that the resulting state values are valid Workflow states; it does NOT verify the transitions are valid; it does NOT enforce optimistic locking per-Subject (RFC-002 §11.4 last-write-wins bulk semantics).

This is the documented operational cost of MaintenanceActions affecting state. Plugin authors using `skip_workflow_guards: true` accept the responsibility of producing valid state values.

### 8.4 Audit reflects the bypass

The AuditEntry for a MaintenanceAction has `invocationClass: "Maintenance"` (RFC-007 §2.1). Auditors and operators reading the log can distinguish bulk state mutations from per-instance transitions.

---

## 9. Retries

### 9.1 No automatic retry by the runtime

The Workflow runtime does not retry transitions on failure. Failure modes:

- `WorkflowStateMismatch` → caller may retry (after re-reading Subject and adjusting expected source).
- `WorkflowGuardDenied` → caller cannot retry usefully; the Policy denied.
- `ConcurrencyConflict` from step 4 → caller may retry; runtime re-runs step 3 from scratch.

Per RFC-013 §6: retry is the caller's concern. Each retry is a fresh Invoker call with a fresh transaction, fresh step 3 evaluation, fresh state read, fresh guard.

### 9.2 No idempotency tracking

The runtime does not track "this Subject has already transitioned via this Action in this caller's intent." Each invocation is independent.

If a caller retries a `cancel` Action on a Subject that is already in `CANCELLED` state, step 3 raises `WorkflowStateMismatch` (current=CANCELLED, source=ISSUED or wildcard). The caller's retry observation: "already cancelled" — which is exactly the desired idempotent outcome at the caller's level.

For idempotency at the caller's API layer, the caller provides idempotency keys; the L4 layer deduplicates. RFC-013 §6.3 documents this pattern.

---

## 10. Nested transitions (rejected for V1)

### 10.1 Declarative transition-level effect Actions

RFC-001 §2.6 mentions "Effects (other Actions triggered by a transition)." V1 does NOT support these as a declarative runtime feature. Reasons:

- Adds Workflow-level complexity (chained Invoker calls embedded in transition descriptors).
- Duplicates `EffectContext::invoker()` capability for cases plugin authors can express in custom Effects.
- Increases the surface for failure semantics (what happens if a transition-effect Action fails after the primary transition succeeded? Rollback? Retry? Partial commit?).

### 10.2 Plugin-author workaround

Plugin authors wanting chained mutations write a custom Effect that calls nested Actions via `EffectContext::invoker()->invoke(...)`. RFC-013 §4 specifies this path. The chain runs in the parent's transaction; rollback cascades correctly.

### 10.3 Future RFC

A future RFC may add declarative transition effects with explicit failure semantics. V1 does not commit.

---

## 11. Multi-Entity transitions (rejected for V1)

### 11.1 Out of scope

A single Action triggering Workflow transitions on Subjects of more than one Entity is not supported in V1. The Invoker handles one Subject per invocation; the Workflow runtime evaluates transitions for that one Subject's Entity (across multiple Workflows on that Entity, per §2.2).

### 11.2 Plugin-author workaround

Cross-Entity orchestration uses nested Invoker calls (RFC-013 §4). Each Entity's transition runs in its own Invoker call; the parent's transaction wraps all.

### 11.3 No saga, no compensation

V1 has no saga primitive, no compensation chain, no two-phase commit across Entities. Cross-Entity workflows are sequential per nested invocation; failure of a nested call rolls back the parent. Compensation is the plugin author's concern (typically: design so that the first-committed Entities' state is recoverable if downstream invocations fail).

---

## 12. Error taxonomy (closed for V1)

```
WorkflowError                                       (abstract base)
├── WorkflowSubjectNotFound(subject, workflowFqn)
├── WorkflowStateInvalid(subject, workflowFqn, observedState)
├── WorkflowStateMismatch(subject, workflowFqn, expected, actual)
├── WorkflowNoTransition(workflowFqn, currentState, viaAction)
├── WorkflowAmbiguousTransition(workflowFqn, source, viaAction, count)    (compile-time)
├── WorkflowGuardDenied(subject, workflowFqn, transition, policyFqn)
├── WorkflowInitialStateNotSet(subject, workflowFqn)
├── WorkflowSourceUnknownState(workflowFqn, transitionId, source)         (compile-time)
├── WorkflowTargetUnknownState(workflowFqn, transitionId, target)         (compile-time)
├── WorkflowOwnerNotEntity(workflowFqn, ownerCandidate)                   (compile-time)
├── WorkflowStateFieldNotEnum(workflowFqn, fieldName)                     (compile-time)
├── WorkflowMultipleWildcards(workflowFqn, viaAction)                     (compile-time)
└── WorkflowError.Internal(message)                                       (runtime invariant violation)
```

All runtime errors propagate to the Invoker, which rolls back the transaction (no Effect ran), emits no audit (consistent with RFC-013 §7.4), and returns to the caller.

Compile-time errors (those marked) prevent boot. Caught by `ausus:compile` and surfaced by `ausus:doctor`.

Cross-references:

- `ConcurrencyConflict` (RFC-002 §12.1) is raised by the Repository on state-mutation conflicts, not by the Workflow runtime itself.
- `PolicyEngineError` types (RFC-005 §13) may be raised during guard evaluation; they propagate unchanged.
- `EffectError` types (RFC-013 §7.1) may be raised during step 4; they propagate unchanged.

---

## 13. Compiler validation

Beyond the validations RFC-011 §10 already lists (`WorkflowFieldNotFound`, `WorkflowFieldNotEnum`, `TransitionStateInvalid`, `WildcardOnNonEnum`, `StampFieldNotDatetime`), the Workflow runtime adds:

### 13.1 Cross-Workflow Action consistency

For each Action FQN, the Compiler computes its transition set (§4.1). It then verifies:

- No Workflow contains two transitions with the same `(source, via)` tuple (per-Workflow ambiguity; §4.4).
- Wildcard and explicit-source transitions for the same `via` do not coexist within a single Workflow (`WorkflowMultipleWildcards` or `WorkflowAmbiguousTransition`).
- Across multiple Workflows on the same Entity, transitions are independent. No cross-Workflow conflict detection in V1 (transitions on different `stateField`s are inherently independent).

### 13.2 State validity

- Every transition's `source` (if not `'*'`) is in the Workflow's declared `states`. Else `WorkflowSourceUnknownState`.
- Every transition's `target` is in the Workflow's declared `states`. Else `WorkflowTargetUnknownState`.
- The Workflow's `initial` is in `states`. Else `WorkflowInitialStateUnknown`.
- The Workflow's `stateField` exists on the owner Entity. Else `WorkflowFieldNotFound`.
- The `stateField` is of type `enum`. Else `WorkflowStateFieldNotEnum`.

### 13.3 Owner consistency

- The Workflow's `ownerEntity` is a registered Entity FQN. Else `WorkflowOwnerNotEntity`.
- Every transition's `via` is an Action registered on the owner Entity. Else `WorkflowViaActionNotOnOwner`.

### 13.4 Guard FQN resolution

- Every transition's `guard` (if non-null) resolves to a registered Policy. Else `WorkflowGuardNotFound`.

### 13.5 No verification of Policy contract

The guard's Policy is evaluated by the Policy Engine; the Engine's compile-time validation (RFC-005 §13) handles signature checks. The Workflow Compiler does not re-validate.

---

## 14. `ausus:doctor` checks

The Workflow runtime adds the following checks beyond what `ausus:compile` already enforces:

| # | Check                                                                                       | Severity |
|---|---------------------------------------------------------------------------------------------|----------|
| 1 | Workflow has no transitions at all (degenerate; only initial state reachable).              | warning  |
| 2 | Workflow has states that are unreachable from initial (no transition path reaches them).    | notice   |
| 3 | Workflow has terminal states with no incoming transitions (dead code).                      | notice   |
| 4 | Workflow has a cycle (transition path returns to a previously-visited state). Informational; cycles are valid. | notice |
| 5 | Action declared as a Workflow transition AND has a custom Effect — possible state-write inconsistency. | warning |
| 6 | MaintenanceAction with `skip_workflow_guards: true` on a Workflow-attached Action.          | warning  |
| 7 | Workflow's `stateField` is not declared with a `default()` (initial state derivation may be ambiguous). | notice |
| 8 | Two Workflows on the same Entity with overlapping `stateField` names (impossible per §2.2; defensive check). | error |

Severity warnings continue boot; notices are informational; errors abort.

---

## 15. Rejected alternatives

The following are explicitly rejected for V1. Each rejection is normative.

### 15.1 Separate workflow-state table

```sql
CREATE TABLE workflow_states (subject_id, workflow_fqn, state, version);
```

**Rejected.** Violates challenger constraint 1. State on the Entity row is simpler, cheaper to query, atomic with Entity writes, and uses the existing PersistenceDriver primitive.

### 15.2 Pessimistic Subject locking during transitions

**Rejected.** RFC-002 §8 optimistic locking is sufficient. Pessimistic locking would deadlock under high concurrency without operational benefit.

### 15.3 Auto-rollback on `WorkflowStateMismatch` with caller-blind retry

**Rejected.** Hides legitimate concurrency conflicts under a "the runtime will eventually succeed" abstraction. Surfacing the conflict to the caller is more honest.

### 15.4 Pre-step-3 state pinning (row lock until step 4 commits)

**Rejected.** §6.4. Standard MVCC. Conflicts surface at write time.

### 15.5 Declarative transition-level effect Actions in V1

**Rejected.** §10.

### 15.6 Multi-Entity declarative transitions

**Rejected.** §11.

### 15.7 Asynchronous transitions

**Rejected.** V1 is synchronous. Async transitions would require an event queue, decoupled state mutation, and complex audit correlation. Out of V1; plugin authors who want async dispatch from inside a transition use custom Effects + L4-level queue dispatch per RFC-013 §4.4.

### 15.8 Hierarchical / parallel / history states

**Rejected.** V1 commits to flat state machines only. BPMN-style features are out of scope.

### 15.9 Post-Effect state verification (verify state matches transition target after Effect)

**Rejected.** §5.3 trust trade-off. Doubles Repository read load for marginal benefit; custom Effects may have legitimate reasons to skip the transition.

### 15.10 Workflow versioning / instance migration

**Rejected.** V1. The Workflow descriptor is graph-resident; changing it changes the graph hash and invalidates caches. In-flight Subjects retain their state values; plugin authors handle Workflow evolution via data migrations if needed.

### 15.11 Workflow-level "transition history" log

**Rejected.** The audit log (RFC-007) IS the transition history. Each successful Workflow-attached Action invocation emits an AuditEntry with the Subject, the Action FQN, the inputs/outputs (which include the new state). Querying the audit log yields the full transition history for any Subject. A separate "transition log" would duplicate.

### 15.12 Runtime introspection API (list current state of all Subjects)

**Rejected for the Workflow contract.** Subjects' states are queryable via the standard Repository / ReportingDriver. No special Workflow introspection API; it would duplicate.

---

## 16. Trade-offs

1. **State as enum field couples Workflows to Field structure.** Adding a state requires altering the enum on the Field, which is a base-graph change (not a Tenant override per Amendment-01 §A-1.3). Plugin authors evolving Workflows commit graph-level changes through normal kernel deployment. Accepted as honest cost; the alternative (separate state store) is worse.
2. **Trust custom Effects to mutate state correctly.** §5.3. Plugin authors choosing custom Effects accept responsibility; doctor surfaces a warning. The runtime cost of post-verification is too high to justify.
3. **No transition history beyond audit.** §15.11. The audit log is the source of truth; reconstructing per-Subject transition timelines requires audit-log queries. Acceptable; no duplication of state.
4. **No declarative transition effects.** §10. Plugin authors using nested invocation pay a small ergonomic cost; the kernel surface stays small.
5. **No multi-Entity transitions.** §11. Cross-Entity orchestration via nested invocation. Accepted.
6. **Wildcard ambiguity prevention.** §4.4. Disallows useful patterns (e.g., "always allow cancel, plus a special-case transition from DRAFT with extra guard"). Acknowledged; alternative is per-source explicit transitions.
7. **MaintenanceAction bypass produces no automatic state validity check.** §8.3. Operational cost; the audit's `invocationClass: "Maintenance"` makes the bulk-vs-per-Subject distinction visible.
8. **Optimistic locking conflicts are caller-visible.** §6.2. Callers must handle `ConcurrencyConflict` and decide whether to retry. The alternative (transparent retry by runtime) hides conflicts that may indicate genuine business-rule violations.

---

## 17. Open questions

1. **Future RFC for transition-level declarative effect Actions.** Add a `->effects(['notify:invoice.notify'])` slot on `Action::transition`. Needs failure-semantics design (per-effect rollback, retry, ordering).
2. **Future RFC for Workflow versioning and in-flight Subject migration.** A real concern for long-lived Workflows. Out of V1.
3. **RFC-009 (Telemetry).** Per-Workflow metrics: transition counts, guard rejection rate, state distribution, time-in-state. Out of this RFC.
4. **Future RFC for hierarchical / parallel states.** Only if a use case proves dominant; otherwise BPMN-style features stay out.
5. **Future RFC for cross-Entity orchestration primitives.** If nested-invocation patterns become common, a saga or workflow-orchestration primitive may be justified.
6. **DSL extension for multi-Workflow `Action::transition`.** Currently `Action::transition('status', ...)` handles one Workflow. Multi-Workflow Actions need an extended syntax. To be specified in an RFC-011 follow-up.
7. **Workflow visualization / debugging tools.** Generating state diagrams from descriptors. Tooling RFC.

---

## 18. Challenger review — attack matrix

Each load-bearing section attacked against: **layer violations**, **Policy bypass**, **Tenant bypass**, **mutation outside Action**, **audit bypass**, **state-source escape**, **SemVer traps**.

### 18.1 State source (§2)

| Attack | Defence |
|---|---|
| Layer violation: state stored in a non-Persistence sink (Redis, memory). | §2.1: state lives on the Entity row. The Workflow runtime reads via Repository only; no other access path. |
| Policy bypass: state mutation skips Policy. | Mutations are Action effects (§5); Actions run through the Invoker; the Invoker runs Policy (step 2). No path to mutation outside the Invoker chain. |
| Tenant bypass: state read or written for another Tenant. | Repository enforces Tenant scope (RFC-002 §5.3.1). Cross-Tenant access raises `TenantBoundaryViolation`. |
| Mutation outside Action: state changed by something other than an Action. | The state field is just an Entity Field; the only mutation path is the Action's Effect via Repository (per RFC-001 §2.4 / RFC-013). |
| Audit bypass: state mutation produces no audit. | Audit emits in step 5 for every successful Action including state-mutating ones. |
| State-source escape: kernel has a special API to read state. | None. State is read via Repository like any other field. |
| SemVer trap: changing state-source location. | V1 freezes "state lives on the Entity row in the enum stateField." Change is a major bump. |

### 18.2 Runtime lifecycle (§4)

| Attack | Defence |
|---|---|
| Layer violation: step 3 calls something other than Repository (for state read) or Policy Engine (for guard). | Algorithm in §4.2 is exhaustive. No other calls. |
| Policy bypass: guard skipped. | §4.2 step 4 always evaluates if `T.guard != null`. The only sanctioned skip is MaintenanceAction `skip_workflow_guards: true` (§8). |
| Tenant bypass | Repository reads in step 3 are Tenant-scoped. |
| Mutation outside Action: step 3 mutates anything. | §4.2 algorithm is read-only; raises on failure, returns on success. No writes. |
| Audit bypass: step 3 failure produces no audit. | Correct by design (RFC-013 §7.4); failed step 3 means no mutation, so no audit. |
| State-source escape: step 3 reads from a stale cache. | Step 3 reads via Repository inside the active transaction. Reads see committed state plus any in-transaction writes. No external cache involved. |
| SemVer trap: changing the step 3 algorithm. | V1 algorithm is fixed. Adding steps is breaking; new check kinds require a new RFC. |

### 18.3 State mutation (§5)

| Attack | Defence |
|---|---|
| Layer violation: state mutation outside Repository. | Default Effect uses Repository::update; custom Effects use Repository::update; no other path. |
| Policy bypass: state mutated without Policy chain. | State mutations are Repository writes inside Effects; Effects run only after Policy chain passed (Invoker step 2 before step 4). |
| Tenant bypass | Repository writes are Tenant-scoped. |
| Mutation outside Action: state changed by a process other than an Action's Effect. | Only Actions mutate; per RFC-001 §2.4. State is just a field. |
| Audit bypass: state change not reflected in audit `outputs`. | Default Effect returns the new state in outputs; custom Effects responsibility (§5.3 trust). Doctor warns (§14 #5). |
| State-source escape: Effect writes state to a different table. | Repository is bound to the Entity FQN; writes go to that Entity's storage only. No "different table" path. |
| SemVer trap: changing default Effect's state-mutation pattern. | V1 default Effect updates state column to transition's target. Change is a kernel runtime change. |

### 18.4 Concurrent transitions (§6)

| Attack | Defence |
|---|---|
| Layer violation: introduce a custom locking mechanism. | §15.2 rejected. Standard optimistic locking only. |
| Policy bypass: race condition allows a second invocation to slip past Policy after the first's evaluation. | Policy evaluation runs per Invoker call; the second invocation gets its own Policy evaluation. No race. |
| Tenant bypass | Optimistic locking is Tenant-bound (writes are Tenant-scoped). |
| Mutation outside Action: concurrent transitions both succeed via non-Action path. | Only Actions mutate; concurrent Actions race per §6.1. |
| Audit bypass: concurrent Action that loses to ConcurrencyConflict emits no audit. | Correct: lost transaction rolled back; no mutation; no audit. The winning transaction emits audit. |
| State-source escape | Optimistic locking is on Subject row; the state field is part of the row. |
| SemVer trap: introducing pessimistic locking. | Major bump; would change observable concurrent-transition behavior. |

### 18.5 MaintenanceAction bypass (§8)

| Attack | Defence |
|---|---|
| Layer violation: bypass triggered without the manifest flag. | The flag is part of the Action's descriptor; the Compiler reads it; the runtime checks it. No runtime bypass possible without descriptor-level declaration. |
| Policy bypass: `skip_workflow_guards: true` skips Policy. | §8.2 explicit: only step 3 skipped; step 2 Policy chain still runs. |
| Tenant bypass: bulk operations cross Tenants. | RFC-002 §13.1 enforces Tenant scope on all Repository operations including bulk. |
| Mutation outside Action: MaintenanceAction Effect bulk-mutates. | Still mutating through a MaintenanceAction (which is an Action per RFC-001 §2.4.1). Inside the Invoker chain. |
| Audit bypass: MaintenanceActions produce no audit. | RFC-007 §13: BulkSubject audit per invocation. |
| State-source escape: bulk-write to an arbitrary table. | Bulk-write through Repository to the Action's declared Entities. |
| SemVer trap: adding additional bypass categories. | V1 has one (`skip_workflow_guards`). New ones require RFC. |

### 18.6 Compiler validation (§13)

| Attack | Defence |
|---|---|
| Layer violation: runtime accepts a descriptor the Compiler rejected. | Compile-failed graphs are not deployable; runtime loads only compiled graphs. |
| Policy bypass: undeclared guard at runtime. | All guards are declared at compile time; runtime evaluates declared set. |
| Tenant bypass | n/a at compile time. |
| Mutation outside Action | Compiler verifies every `via` is a registered Action. |
| Audit bypass | n/a at compile time. |
| State-source escape: state field declared as non-enum. | `WorkflowStateFieldNotEnum` rejected at compile time. |
| SemVer trap: adding compile checks in V1.x. | New checks for previously-tolerated patterns require careful versioning; treated as major where previously-conforming plugins break. |

---

## 19. Acceptance criteria

This RFC is accepted when:

1. The three role signatories (architect, kernel, challenger) sign off on §2 (state source), §4 (runtime lifecycle), §5 (state mutation), §6 (concurrent transitions), §8 (MaintenanceAction bypass), §12 (error taxonomy), §15 (rejected alternatives).
2. RFC-012 §6.3's "simple Workflow runtime" is replaced with this RFC's runtime; `ausus/runtime-default` releases a major bump.
3. RFC-012 §16.5 is updated to remove "Workflow runtime" from the provisional list, replacing it with "fixed by RFC-006."
4. RFC-011 §6.4's Workflow inference produces descriptors conforming to §3.
5. The Compiler-side §13 checks are implemented in `ausus/runtime-default`'s Compiler integration.
6. The conformance test suite for the Workflow runtime is scoped: at minimum, one test per "MUST" / "MUST NOT" clause in §2, §4, §5, §6, §8.
7. RFC-013 §1.2's out-of-scope "Effect-level Workflow state mutation" is updated to reference this RFC's specification.

Once accepted, this RFC is the source of truth for V1 Workflow execution.

---

## 20. Determination

**ACCEPT.**

Justification:

- **No new kernel primitives.** Workflow, Transition, TransitionDescriptor exist in RFC-001 §2.6. This RFC formalizes execution; no new types introduced.
- **All eight challenger constraints satisfied:**
  1. State lives in PersistenceDriver only (§2.1). ✓
  2. Policy bypass impossible — step 3 evaluates guards via Policy Engine; step 2 always runs (§4.2, §8.2). ✓
  3. Tenant bypass impossible — Repository enforces (§2.5). ✓
  4. Actions remain only mutation path (§5, §15.1). ✓
  5. MaintenanceAction bypass is the existing flag, no new mechanism (§8). ✓
  6. Optimistic locking via Repository's existing mechanism (§6). ✓
  7. Audit emission in step 5 unchanged (§4.2, §5). ✓
  8. RFC-011 inferred Workflows produce conforming descriptors (§3, §13). ✓
- **Closed error taxonomy** (§12). 13 error types including 6 compile-time variants.
- **Compiler-side validation** comprehensive (§13).
- **Doctor checks** surface design issues (§14).
- **12 explicit rejections** (§15) bound scope. Each normative.

Conditional notes:

- Acceptance is **specification-level**. Runtime verification requires `ausus/runtime-default` to be built per RFC-012 §19. RFC-000 V0 Real Pass demonstrated the package does not exist.
- This RFC unblocks **RFC-000 F-V0-03**. RFC-012 §16.5 should be updated to remove "Workflow runtime" from the provisional list.
- Three of six RFC-000 BLOCKERs are now addressed at specification level: F-V0-01 (RFC-011 DSL), F-V0-02 (RFC-013 Effect), F-V0-03 (this RFC Workflow). Remaining: F-V0-04 (full Authorization plugin contract), F-V0-05 / F-V0-14 (reference packages built).

---

## Appendix A — V1 public surface enumeration

```
Ausus\Kernel\Contracts\Workflow\
  WorkflowDescriptor               (final value object; restated from RFC-001 §2.6)
  TransitionDescriptor             (final value object)

Ausus\Kernel\Contracts\Workflow\Errors\
  WorkflowError                    (abstract base)
  WorkflowSubjectNotFound,
  WorkflowStateInvalid,
  WorkflowStateMismatch,
  WorkflowNoTransition,
  WorkflowAmbiguousTransition,         (compile-time)
  WorkflowGuardDenied,
  WorkflowInitialStateNotSet,
  WorkflowSourceUnknownState,          (compile-time)
  WorkflowTargetUnknownState,          (compile-time)
  WorkflowOwnerNotEntity,              (compile-time)
  WorkflowStateFieldNotEnum,           (compile-time)
  WorkflowMultipleWildcards,           (compile-time)
  WorkflowError.Internal
```

13 error types. Closed for V1. Six are compile-time; seven are runtime.

The runtime itself is implemented in `ausus/runtime-default` and exposes no plugin-facing API beyond the Workflow primitive Effects receive through `EffectContext` (RFC-013 §3).

---

## Appendix B — Worked invocation: `billing.invoice.issue`

Inputs:
- Active Tenant: `acme`
- Actor: `user42` with roles `[invoice.creator, invoice.issuer, invoice.viewer]`
- Subject: `Reference(acme, billing.invoice, inv_01J)` currently in state `DRAFT`, version `v1`
- Action: `billing.invoice.issue` (declared as `Action::transition('status', from: 'DRAFT', to: 'ISSUED')->stamp('issued_at')->requireRole('invoice.issuer')`)

Trace:

```
Invoker.invoke(actor=user42, action=billing.invoice.issue, subject=Reference, inputs={})

Step 1 — Tenant Context check:
  active TenantContext = acme ✓
  Subject.tenant_id = acme ✓

Step 2 — Policy chain:
  Chain assembled per RFC-005 §4.1:
    Action-attached base: [RoleRequired("invoice.issuer")]
  Evaluation:
    RoleRequired.evaluate(user42, action, subject, context):
      'invoice.issuer' in user42.roles() → Permit
  Combined: Permit ✓

Step 3 — Workflow guard (THIS RFC):
  Action transition set computed by Compiler:
    [(billing.invoice.lifecycle, TransitionDescriptor{source: 'DRAFT', target: 'ISSUED', via: 'billing.invoice.issue'})]
  For each (W, T):
    Load Subject: repo.find(Reference) → Entity{status: 'DRAFT', _version: v1, ...} ✓
    Read state: current = 'DRAFT' ✓
    Match source: T.source = 'DRAFT', current = 'DRAFT' → match ✓
    Guard: T.guard = null → no Policy evaluation
  ✓ step 3 passes

Step 4 — Action effect:
  Built-in default Effect for Action::transition:
    issuedAt = context.clock().toRfc3339() = '2026-05-18T14:32:00.123Z'
    repo.update(Reference, {status: 'ISSUED', issued_at: '2026-05-18T14:32:00.123Z'}, expected: v1)
      → SQL UPDATE billing_invoices SET status=?, issued_at=?, _version=? WHERE tenant_id=? AND id=? AND _version=?
      → 1 row affected; new _version = v2 ✓
    return {status: 'ISSUED', issued_at: '2026-05-18T14:32:00.123Z'}
  ✓ step 4 succeeds

Step 5 — Audit emission (RFC-007):
  AuditEntry constructed:
    actionFqn:     'billing.invoice.issue'
    subject:       SingleSubject(acme, billing.invoice, inv_01J)
    outputs:       {status: 'ISSUED', issued_at: '2026-05-18T14:32:00.123Z'}
    invocationClass: 'Standard'
  auditor.emit(entry, txn) → primary sink writes in transaction → ack ✓

Step 6 — driver.commit(txn):
  Both data and audit committed atomically.

Return: outputs = {status: 'ISSUED', issued_at: '2026-05-18T14:32:00.123Z'}
```

Concurrent invocation case: a second `billing.invoice.issue` arriving during the first's execution reads the same DRAFT state, passes step 3, but in step 4's `repo.update(...)` raises `ConcurrencyConflict(expected: v1, actual: v2)`. The second Invoker's transaction rolls back; no audit emits; caller sees the error and may retry. On retry, the state is now `ISSUED`, and step 3 raises `WorkflowStateMismatch(expected: 'DRAFT', actual: 'ISSUED')`. The caller observes "already issued" — idempotent at the business level.
