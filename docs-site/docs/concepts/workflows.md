---
id: workflows
title: Workflows
sidebar_label: Workflows
description: State machines attached to an entity in AUSUS.
---

# Workflows

A **workflow** is a state machine attached to an entity. It declares the legal
states of a record and which [transition actions](entities-fields-actions.md#transition-actions)
move it between them.

## How a workflow is declared {#how-a-workflow-is-declared}

A workflow is **inferred**, not written out by hand. You point the entity at an
`enum` field, and that field's options become the workflow states:

```php
$dsl->entity('invoice')
    ->fields([
        'status' => Field::enum('DRAFT', 'ISSUED', 'CANCELLED')->default('DRAFT'),
    ])
    ->actions([
        'issue'  => Action::transition('status', from: 'DRAFT', to: 'ISSUED'),
        'cancel' => Action::transition('status', from: 'DRAFT',  to: 'CANCELLED')
                      ->andTransition('status', from: 'ISSUED', to: 'CANCELLED'),
    ])
    ->workflow('status');     // <- the status enum drives the workflow
```

From this the compiler builds a `WorkflowNode`:

- **states** — `DRAFT`, `ISSUED`, `CANCELLED` (the enum options).
- **initial** — `DRAFT` (the enum default).
- **stateField** — `status`.
- **transitions** — one `TransitionNode` per `(from, to)` declared by a
  transition action, each tagged with the action that performs it.

The workflow FQN is `{entity}.lifecycle`, e.g. `billing.invoice.lifecycle`.

## How a transition is enforced {#how-a-transition-is-enforced}

When you invoke a transition action, the [runtime](../backend/runtime.md) runs a
**workflow guard** before the effect:

1. The current record is loaded.
2. Its current state (the `status` value) is read.
3. The runtime finds the transition for the invoked action whose `source`
   matches the current state — either an exact match or a wildcard (`*`).
4. If no transition matches, it throws `WorkflowStateMismatch` and the whole
   invocation rolls back.

So `issue` works only on a `DRAFT` invoice; calling it on an `ISSUED` invoice
is rejected. `cancel`, declared from both `DRAFT` and `ISSUED`, works on either.

### Wildcard transitions {#wildcard-transitions}

A transition source may be `*`, meaning "from any state". The runtime treats
a wildcard as matching the current state when no exact source matches.

:::warning One transition per state
For a given workflow and current state, **exactly one** transition may match an
action. If two declared transitions both match (for example an exact source and
a wildcard that overlap), the runtime throws an ambiguous-transition error.
Declare transitions so that at most one applies per state.
:::

## Multiple workflows {#multiple-workflows}

An action can drive transitions on more than one workflow. When that happens,
each attached workflow must have exactly one matching transition for the
current state. In the v0.1.0 sample domain each entity has a single workflow.

## Current v0.1.0 limitations {#current-v010-limitations}

- Workflows are inferred from a single enum field — there is no standalone
  workflow declaration syntax.
- **Transition guard policies are not evaluated in v0.1.0.** Authorization is
  enforced by the action's [policy](policies.md); a per-transition guard policy
  is part of the design but is not run by the v0.1.0 workflow runtime.
- The initial state is the enum `default`, or the first option if no default
  is set.

## Related {#related}

- [Entities, Fields & Actions](entities-fields-actions.md) — transition actions.
- [The Runtime](../backend/runtime.md) — where the workflow guard runs.
- [Policies](policies.md) — action authorization.
