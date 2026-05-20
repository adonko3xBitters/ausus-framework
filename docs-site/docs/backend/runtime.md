---
id: runtime
title: The Runtime
sidebar_label: The Runtime
description: The Invoker chain that executes actions.
---

# The Runtime

The runtime (`ausus/runtime-default`, layer L2) executes actions against a
[metadata graph](../concepts/metadata-graph.md). Its centre is the `Invoker`.

## The Invoker chain {#the-invoker-chain}

Every call to `Invoker::invoke()` runs the same ordered chain. The chain is the
core guarantee of AUSUS: an action either completes all of these steps or
changes nothing.

```
invoke(actionFqn, subject?, inputs)
  │
  1. Pre-flight   action exists? subject required & present?
  │               subject tenant == active tenant?
  2. Policy       PolicyEngine evaluates the action's policy
  │               not Permit  -> throw PolicyDenied  (nothing written)
  ── open transaction ──────────────────────────────────────
  3. Workflow     WorkflowRuntime guard — current state allows this transition?
  4. Effect       EffectDispatcher runs the built-in effect
  5. Audit        an AuditEntry is written in the same transaction
  ── commit ────────────────────────────────────────────────
  return outputs
```

If any step throws, the transaction is **rolled back** — the effect and the
audit entry are atomic together.

```php
$outputs = $invoker->invoke('billing.invoice.create', null, [
    'number'        => 'INV-2026-001',
    'customer_name' => 'ACME Corporation',
    'amount'        => ['amount' => '1500.00', 'currency' => 'USD'],
]);
```

## Constructing an Invoker {#constructing-an-invoker}

```php
use Ausus\Runtime\{
    PolicyEngine, WorkflowRuntime, TransitionSetIndex,
    EffectDispatcher, DefaultAuditor, SequenceCounter, Invoker,
};

$invoker = new Invoker(
    $graph,                                              // MetadataGraph
    $driver,                                             // PersistenceDriver
    new PolicyEngine($graph),
    new WorkflowRuntime(new TransitionSetIndex($graph)),
    new EffectDispatcher(),
    new DefaultAuditor(new DatabaseAuditSink($pdo)),
    new SequenceCounter(),
    $tenant,                                             // active Tenant
    $actor,                                              // acting Actor
);
```

:::note One tenant, one actor per Invoker
An `Invoker` is bound to a single `Tenant` and a single `Actor` at construction.
To act as a different tenant or actor, construct another `Invoker`. v0.1.0 is
deliberately single-process and single-tenant per invocation — there is no
distributed runtime and no multi-tenant runtime.
:::

## The pieces {#the-pieces}

### `PolicyEngine` {#policyengine}

Resolves and evaluates an action's [policy](../concepts/policies.md).
Deny-by-default and fail-closed: `Abstain` and thrown exceptions both become
`Deny`.

### `WorkflowRuntime` {#workflowruntime}

Runs the [workflow](../concepts/workflows.md) guard. It loads the subject,
reads its current state, and confirms the invoked action declares a transition
from that state. No match → `WorkflowStateMismatch`.

### `EffectDispatcher` and built-in effects {#effectdispatcher-and-built-in-effects}

Maps an action's `effectClass` to an `Effect` instance:

| `effectClass` | Effect | Behaviour |
|---|---|---|
| `kernel.builtin.create` | `CreateEffect` | inserts a row; applies the workflow initial state |
| `kernel.builtin.transition` | `TransitionEffect` | updates the state field, stamps, and patches |

The dispatcher can also instantiate a custom `Effect` class by FQN, though the
v0.1.0 sample domain only uses the two built-ins.

### `DefaultAuditor` and `SequenceCounter` {#defaultauditor-and-sequencecounter}

Every successful action writes an `AuditEntry` through the `Auditor` into the
audit sink — **inside the action's transaction**. The `SequenceCounter` assigns
a monotonic sequence number per correlation id. See
[SQL Persistence](sql-persistence.md#the-audit-log) for the audit table.

## Transaction semantics {#transaction-semantics}

- The transaction opens **after** policy evaluation — a denied action never
  opens one.
- The workflow guard, the effect, and the audit write all run inside it.
- Commit happens only if all three succeed. Any failure rolls back everything.
- If the effect throws a non-AUSUS exception, it is wrapped as `EffectFailed`.

## `ProjectionRenderer` {#projectionrenderer}

`runtime-default` also provides `ProjectionRenderer`, which renders a
[projection](../concepts/projections.md) to a
[ViewSchema](../frontend/viewschema.md). It opens its own read transaction.

## Current v0.1.0 limitations {#current-v010-limitations}

- Single-process, single-tenant, single-actor per `Invoker` (see the note above).
- The audit `SequenceCounter` is **per process** — sequence numbers are not
  durable across process restarts.
- Per-transition guard policies are not evaluated (see [Workflows](../concepts/workflows.md)).
- There is no retry, queue, or async execution — `invoke()` is synchronous.

## Related {#related}

- [Policies](../concepts/policies.md) · [Workflows](../concepts/workflows.md)
- [SQL Persistence](sql-persistence.md) — the driver the runtime writes through.
- [Error Reference](../reference/errors.md) — the exception taxonomy.
