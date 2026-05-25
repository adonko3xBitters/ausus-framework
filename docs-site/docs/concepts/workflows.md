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

A workflow is declared **explicitly** with `->workflow()`. You name the `enum`
field that holds the state, and the state a new record starts in:

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
    ->workflow(field: 'status', initial: 'DRAFT');
```

`->workflow()` takes two arguments:

| Argument | Required | Meaning |
|---|---|---|
| `field` | yes | The `enum` field whose options are the workflow states. |
| `initial` | recommended | The state a freshly created record starts in. |

Passing `initial` explicitly is recommended. If you omit it
(`->workflow('status')`), AUSUS infers it from the field default and emits a
**deprecation notice** — see [Migrating from implicit workflows](#migrating-from-implicit-workflows).

From this the compiler builds a `WorkflowNode`:

- **stateField** — `status`.
- **states** — `DRAFT`, `ISSUED`, `CANCELLED` (the enum options).
- **initial** — `DRAFT` (the value you passed).
- **transitions** — one `TransitionNode` per `(from, to)` declared by a
  transition action, each tagged with the action that performs it.

The workflow FQN is `{entity}.lifecycle`, e.g. `billing.invoice.lifecycle`.

## Validation {#validation}

The DSL validates the workflow declaration when the plugin compiles. Each error
is a clear, named exception:

| Error | Cause |
|---|---|
| `WorkflowFieldNotFound` | `field` names a field the entity does not declare. |
| `WorkflowFieldNotEnum` | `field` exists but is not an `enum`. |
| `WorkflowFieldNoStates` | The enum field declares no options. |
| `WorkflowInitialInvalid` | `initial` is not one of the enum's states. |
| `AmbiguousWorkflowField` | No `->workflow()` call, and the entity has **more than one** `enum` field with a default — the legacy inference cannot choose. |

The compiler additionally rejects a `WorkflowNode` whose `initial` is not among
its `states` (`WorkflowCoherence`), which also covers hand-written plugins.

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

## Migrating from implicit workflows {#migrating-from-implicit-workflows}

Before the explicit API, the workflow state field and initial state were
**inferred**: the runtime scanned the entity for the first `enum` field that had
a default value. This was order-dependent and silent — adding another defaulted
enum field could change which field drove the workflow.

The inference is still supported as a **fallback** so existing apps keep
working, but every implicit path now emits an `E_USER_DEPRECATED` notice.

**Migrate in one step** — add the explicit arguments to `->workflow()`:

```diff
- ->workflow('status')
+ ->workflow(field: 'status', initial: 'DRAFT')
```

For an entity that has a defaulted `enum` field but **no** `->workflow()` call
at all, decide what you intend:

- If the enum *is* a workflow state — add a `->workflow(field:, initial:)` call.
- If it is just a defaulted field (not a state machine) — the field default
  still applies on create; the deprecation simply flags that the old inference
  would have treated it as workflow state.

Two or more defaulted `enum` fields with no `->workflow()` call is now a hard
`AmbiguousWorkflowField` error rather than a silent first-match guess. Resolve
it by declaring the workflow explicitly.

The deprecation notices are advisory in v0.1.x and will become errors in a
future release. The explicit form is forward-compatible — migrate now and the
notices disappear.

## Current v0.1.0 limitations {#current-v010-limitations}

- A workflow's `states` are always the full set of the enum field's options;
  there is no way to use a subset of the enum as states.
- **Transition guard policies are not evaluated in v0.1.0.** Authorization is
  enforced by the action's [policy](policies.md); a per-transition guard policy
  is part of the design but is not run by the v0.1.0 workflow runtime.
- An entity has at most one `->workflow()` declaration; multi-workflow entities
  are not expressible through the DSL.
- Implicit inference still exists as a deprecated fallback — see
  [the migration section](#migrating-from-implicit-workflows).

## Related {#related}

- [Entities, Fields & Actions](entities-fields-actions.md) — transition actions.
- [The Runtime](../backend/runtime.md) — where the workflow guard runs.
- [Policies](policies.md) — action authorization.
