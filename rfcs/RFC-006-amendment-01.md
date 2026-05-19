# RFC-006-amendment-01

| Field    | Value                                                       |
|----------|-------------------------------------------------------------|
| Status   | Proposed                                                    |
| Authors  | architect, kernel, challenger                               |
| Date     | 2026-05-19                                                  |
| Amends   | RFC-006 Workflow Runtime                                    |
| Scope    | C-V0R-01 (single finding from RFC-000 First Real Implementation Pass) |
| Origin   | Real bug surfaced in `WorkflowRuntime::evaluate()` on first execution against the HelloInvoice slice; one fix iteration; second run all 23 assertions passed |

Strictly scoped to one finding. No other RFC-006 clauses are touched. No new primitives. All existing semantics and guarantees preserved.

---

## A-6.1 C-V0R-01 — Step 3 algorithm under-specified for multi-transition Actions

### Restatement

RFC-006 §4.2 specifies the step 3 algorithm with the pseudocode:

> "For each `(Workflow W, Transition T)` in the Action's transition set:
>   1. Load the Subject ...
>   2. Read the current state ...
>   3. Match the transition's source: If `T.source != '*' AND current != T.source`: raise `WorkflowStateMismatch`."

This reads as a `For each` loop with early-exit on mismatch. A first-time implementer (RFC-000 First Real Implementation Pass) wrote exactly that and the runtime rejected legitimate transitions.

**Reproducible failure case (HelloInvoice).** The `billing.invoice.cancel` Action has two transitions in the `billing.invoice.lifecycle` Workflow:

- `(source: 'DRAFT',  target: 'CANCELLED', via: 'billing.invoice.cancel')`
- `(source: 'ISSUED', target: 'CANCELLED', via: 'billing.invoice.cancel')`

Invoking `cancel` on an `ISSUED` invoice with the literal `For each` reading:

1. Iteration 1: `(W=lifecycle, T=DRAFT→CANCELLED)`. Read current=`ISSUED`. Compare to `T.source='DRAFT'`. Mismatch. Raise `WorkflowStateMismatch`. **Wrong outcome** — the ISSUED→CANCELLED transition exists and should fire.

RFC-006 §4.4 forbids ambiguous `(source, via)` tuples per Workflow, which implies the correct algorithm is **per-Workflow SELECT** the applicable transition based on current state. But the §4.2 pseudocode does not make this explicit.

The contradiction is between §4.2 (iterate-and-mismatch) and §4.4 (SELECT-the-unique).

### Resolution

Replace §4.2's `For each (Workflow W, Transition T)` algorithm with a two-level structure:

1. **Outer**: iterate Workflows attached to the Action (one or more per RFC-006 §2.2).
2. **Inner (per Workflow)**: SELECT the unique transition whose source matches the current state (explicit match) OR whose source is `'*'` (wildcard). Raise `WorkflowStateMismatch` if zero match. Raise `WorkflowAmbiguousTransition` if more than one match.

Existing semantics preserved:

- One transition per `(source, via)` per Workflow remains required (§4.4 unchanged).
- Wildcard semantics unchanged (§4.5 unchanged).
- Multi-Workflow Entity semantics unchanged (§2.2, §4.3 unchanged).
- Guard evaluation semantics unchanged (still evaluated on the matched transition).
- Subject pre-load caching unchanged (§4.6 unchanged).

The amendment promotes `WorkflowAmbiguousTransition` from compile-time-only (per §12 / §13.1) to a closed runtime error type as well. The Compiler still catches the static case (`('DRAFT', 'cancel')` + `('*', 'cancel')` declared in source); the runtime check is defense-in-depth against corrupted graphs or future Compiler bugs.

### Sections amended

- §4.2 (Step 3 algorithm per invocation) — full replacement.
- §12 (Error taxonomy) — reclassify `WorkflowAmbiguousTransition` from compile-time-only to compile-time **and** runtime.
- §13.1 (Compiler validation, cross-Workflow Action consistency) — clarify that runtime defense-in-depth complements compile-time detection.
- §18.2 (Challenger review — runtime lifecycle) — update one row.

### Replacement normative text

**Replace §4.2 in full with:**

> **§4.2 Step 3 algorithm (per invocation).**
>
> For each `Workflow W` whose `transitions` contain at least one entry with `via == currentAction.fqn`:
>
> 1. **Load the Subject** (or use the cached load from a prior workflow in the same step 3):
>    ```
>    entity = persistenceContext.repository(W.ownerEntity).find(subject)
>    If entity == null:
>        raise WorkflowSubjectNotFound(subject, W.fqn)
>    ```
>
> 2. **Read the current state:**
>    ```
>    current = entity.field(W.stateField)
>    If current == null OR current ∉ W.states:
>        raise WorkflowStateInvalid(subject, W.fqn, current)
>    ```
>
> 3. **Select the applicable transition.** Among the transitions in `W.transitions` whose `via == currentAction.fqn`, find every transition `T` where `T.source == current` (explicit match) OR `T.source == '*'` (wildcard match):
>    ```
>    candidates = [ T ∈ W.transitions
>                  : T.via == currentAction.fqn
>                  AND (T.source == current OR T.source == '*') ]
>
>    If |candidates| == 0:
>        raise WorkflowStateMismatch(
>            subject, W.fqn,
>            actual: current,
>            offered_sources: [ T.source for T ∈ W.transitions where T.via == currentAction.fqn ])
>
>    If |candidates| > 1:
>        raise WorkflowAmbiguousTransition(
>            subject, W.fqn, currentAction.fqn, candidates)
>
>    matched = candidates[0]
>    ```
>
>    The Compiler already enforces `|candidates| <= 1` at compile time per §4.4 + §13.1. The runtime check is defense-in-depth.
>
> 4. **Evaluate the guard, if any:**
>    ```
>    If matched.guard != null:
>        decision = policyEngine.evaluateChain(
>            attachmentKey: "workflow-guard:" + matched.guard,
>            actor:    invocationActor,
>            actionFqn: invocationActionFqn,
>            subject:  invocationSubject,
>            context:  invocationContext)
>        If decision != Permit:
>            raise WorkflowGuardDenied(subject, W.fqn, matched, decision)
>    ```
>
> If any Workflow's evaluation raises, the Invoker rejects the Action: rollback the transaction (no mutations have occurred yet — step 4 hasn't run), no audit emission, return the error to the caller.
>
> If all Workflows pass, step 3 succeeds and the Invoker proceeds to step 4 (the Effect). The cached entity (loaded in step 1) is shared with step 4 per §4.6.

**Replace the relevant row in §12 error taxonomy with:**

> ```
> WorkflowAmbiguousTransition(workflowFqn, source, viaAction, count)
> ```
>
> Raised at **compile time** when a single Workflow declares more than one transition per `(source, via)` tuple — including the wildcard-plus-explicit case (§4.4).
>
> Raised at **runtime** as defense-in-depth when step 3's transition-selection algorithm (§4.2 clause 3) observes more than one matching candidate. Under a correctly compiled graph this is unreachable; the runtime check guards against graph corruption and future Compiler regression.

**Replace the relevant clause in §13.1 with:**

> **§13.1 Cross-Workflow Action consistency.** For each Action FQN, the Compiler computes its transition set (§4.1). It then verifies, per Workflow:
>
> - No Workflow contains two transitions whose tuples `(source, via)` are equal. Wildcards are treated as covering every concrete source value: `('DRAFT', 'cancel')` and `('*', 'cancel')` are considered conflicting and rejected as `WorkflowAmbiguousTransition`.
> - Multiple transitions with the same `via` and **different explicit sources** are permitted (e.g., `('DRAFT', 'cancel')` and `('ISSUED', 'cancel')`). The runtime selects the applicable one per current state per §4.2 clause 3.
>
> The runtime defense-in-depth check (§4.2 clause 3) catches any case the compile-time check missed.

**Add to §18.2 challenger review (replace the "Policy bypass" row):**

| Attack | Defence |
|---|---|
| Policy bypass: guard skipped because runtime iteration short-circuited on the first non-matching transition. | §4.2 clause 3 now SELECTS the applicable transition before evaluating the guard. The guard runs on the matched transition, not on the first iterated one. The bug surfaced in C-V0R-01 (real implementation pass) is structurally impossible under the amended algorithm. |

### Downstream RFCs impacted

- **`ausus/runtime-default`** — `WorkflowRuntime::evaluate()` implementation must follow the amended algorithm. The First Real Implementation Pass already applied the fix in ~30 LOC (`packages/runtime-default/src/runtime.php`); the change is upstream of any other consumer.
- **RFC-001** — no change.
- **RFC-005** — no change.
- **RFC-007** — no change.
- **RFC-011** — no change. The DSL's `Action::transition()` syntax produces correct descriptors regardless of runtime selection algorithm.
- **`docs/RUNTIME-DEFAULT-DESIGN.md` §11.3** — should be updated to reference the amended algorithm.

### Challenger attack

- **Layer violations.** None. Algorithm change is L2 runtime-internal; no contract surface added.
- **Policy bypass.** Strengthened: the guard now runs on the correctly-matched transition. Previously, with the buggy iterate-and-mismatch, the guard ran on an arbitrary first transition then immediately raised — guard semantics were effectively undefined for multi-transition actions.
- **Tenant bypass.** None. Tenant scope checks unchanged (load and read happen via Repository, which enforces tenancy).
- **Audit bypass.** None. Audit emission is step 5; step 3 changes do not affect it.
- **State-source escape.** None. The state column is still read from the Entity row; mutation still happens in step 4 via Effect or built-in TransitionEffect.
- **SemVer expansion.** Zero new public surface. The error type `WorkflowAmbiguousTransition` already exists in §12; the amendment broadens when it fires. No new error type, no new method, no new contract.

---

## Conformance examples

The three example scenarios cover the algorithm's branches: explicit match (the common case), wildcard match (the wildcard pattern), and ambiguity (defense-in-depth).

### Example 1 — `cancel` from `DRAFT` (explicit match, one candidate)

**Setup.** Invoice in state `DRAFT`. Workflow `billing.invoice.lifecycle` has transitions:

```
('DRAFT',  'CANCELLED', via 'billing.invoice.cancel')
('ISSUED', 'CANCELLED', via 'billing.invoice.cancel')
```

**Invocation.** `invoker.invoke(actor, 'billing.invoice.cancel', invoiceRef, {})`.

**Step 3 trace.**

```
Workflow: billing.invoice.lifecycle
  Load subject → entity{status: 'DRAFT', _version: v1}
  current = 'DRAFT' ∈ {DRAFT, ISSUED, CANCELLED} ✓
  candidates = [
    T1 = (DRAFT  → CANCELLED via cancel)   ← T1.source == current ✓
    T2 = (ISSUED → CANCELLED via cancel)   ← T2.source ≠ current; not a candidate
  ]
  |candidates| == 1 → matched = T1
  matched.guard == null → skip
  step 3 passes
```

**Result.** Pass. Effect runs; sets `status = CANCELLED`.

### Example 2 — `cancel` from `ISSUED` (explicit match, different candidate)

**Setup.** Same Workflow; invoice in state `ISSUED`.

**Invocation.** `invoker.invoke(actor, 'billing.invoice.cancel', invoiceRef, {})`.

**Step 3 trace.**

```
Workflow: billing.invoice.lifecycle
  Load subject → entity{status: 'ISSUED', _version: v2}
  current = 'ISSUED' ∈ {DRAFT, ISSUED, CANCELLED} ✓
  candidates = [
    T1 = (DRAFT  → CANCELLED via cancel)   ← T1.source ≠ current; not a candidate
    T2 = (ISSUED → CANCELLED via cancel)   ← T2.source == current ✓
  ]
  |candidates| == 1 → matched = T2
  matched.guard == null → skip
  step 3 passes
```

**Result.** Pass. Effect runs; sets `status = CANCELLED`. This is the scenario that failed under the pre-amendment algorithm; it succeeds under the amended algorithm.

This case is **directly verified** by `apps/playground/run.php` test 6 in the First Real Implementation Pass (assertion `outputs.status == CANCELLED`).

### Example 3 — Ambiguous wildcard (defense-in-depth)

**Setup.** A corrupted-or-untested Workflow declares both an explicit-source and a wildcard transition for the same `via` (the Compiler should reject this at compile time per §13.1; this example illustrates the runtime defense if the compile check is missed or bypassed):

```
('DRAFT', 'CANCELLED', via 'billing.invoice.cancel')
('*',     'CANCELLED', via 'billing.invoice.cancel')
```

**Invocation.** `invoker.invoke(actor, 'billing.invoice.cancel', invoiceRef, {})` where invoice is in `DRAFT`.

**Step 3 trace.**

```
Workflow: billing.invoice.lifecycle
  Load subject → entity{status: 'DRAFT'}
  current = 'DRAFT' ✓
  candidates = [
    T1 = (DRAFT → CANCELLED via cancel)   ← explicit match ✓
    T2 = (*    → CANCELLED via cancel)   ← wildcard match ✓
  ]
  |candidates| == 2 → raise WorkflowAmbiguousTransition(
      workflow:  billing.invoice.lifecycle,
      action:    billing.invoice.cancel,
      candidates: [T1, T2])
```

**Result.** Runtime error. Invoker rolls back, no audit emitted, error returns to caller. The defense-in-depth catch confirms the algorithm is robust against missing compile-time validation.

A correctly compiled graph never reaches this branch; the Compiler raises `WorkflowAmbiguousTransition` at compile time (§13.1, §12) and the deployment fails to boot.

---

## Determination

**ACCEPT.**

The amendment:

- Resolves the single contradiction surfaced by the First Real Implementation Pass.
- Adds zero new primitives.
- Preserves every existing semantic and guarantee (state field source, Tenant scope, optimistic locking, Subject pre-load caching, multi-Workflow ordering, MaintenanceAction bypass, guard evaluation contract).
- Adds zero new error types — broadens when `WorkflowAmbiguousTransition` may fire (compile-time → compile-time AND runtime defense-in-depth).
- Strengthens the algorithm against the bug class surfaced in C-V0R-01: structurally impossible under the amended pseudocode.
- Is directly verified by the existing implementation: `packages/runtime-default/src/runtime.php`'s `WorkflowRuntime::evaluate()` was fixed in place during the First Real Implementation Pass and the 23-assertion test suite passes against it.

Folding the §4.2 replacement, the §12 reclassification, the §13.1 clarification, and the §18.2 challenger-row update into RFC-006 produces RFC-006 Draft-02.
