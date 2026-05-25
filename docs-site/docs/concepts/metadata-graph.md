---
id: metadata-graph
title: The Metadata Graph
sidebar_label: The Metadata Graph
description: The compiled, immutable description of an AUSUS application.
---

# The Metadata Graph

The `MetadataGraph` is the central object in AUSUS. It is the **compiled,
immutable description of an application** — every entity, action, policy,
workflow, and projection, validated and frozen into one value.

Everything downstream — persistence schema, the runtime, the HTTP API, the
rendered UI — is derived from this single graph.

## What is in the graph {#what-is-in-the-graph}

A `MetadataGraph` (in `ausus/kernel`) holds:

| Field | Contents |
|---|---|
| `hash` | a SHA-256 content hash of the canonical graph |
| `kernelVersion` | the kernel contract version the graph was compiled against |
| `entities` | map of FQN → `EntityNode` |
| `actions` | map of FQN → `ActionNode` |
| `policies` | map of FQN → `PolicyNode` |
| `workflows` | map of FQN → `WorkflowNode` |
| `projections` | map of FQN → `ProjectionNode` |

Each node is a `final readonly` value object. The graph has no methods that
mutate it — once compiled, it does not change.

## Compilation {#compilation}

The `Compiler` takes a list of [plugins](plugins.md) and produces the graph:

```php
use Ausus\Compiler;

$graph = (new Compiler())->compile([new HelloInvoiceDsl()], kernelVersion: '1.0.0');
```

Compilation does three things:

1. **Collect** — each plugin's `describe()` output contributes entity, action,
   policy, workflow, and projection nodes.
2. **Validate** — see below.
3. **Canonicalize and hash** — node maps are key-sorted, serialized to a
   canonical JSON form, and hashed with SHA-256.

The graph is the **only** runtime configuration AUSUS reads. Persistence,
the HTTP API, and the renderer all consume it directly — they never re-parse
the source code:

![Metadata graph lifecycle: Plugin A and Plugin B both feed Compiler::compile(), which produces one immutable MetadataGraph; that graph is independently consumed by the Runtime / Invoker, the SchemaDeriver, and the ProjectionRenderer.](/img/diagrams/metadata-graph-lifecycle.svg)

## Validation {#validation}

The compiler rejects an incoherent graph at compile time, not at runtime:

- **DuplicateRegistration** — two plugins declare the same action FQN.
- **DanglingReference** — an action points at an entity or policy that is not
  registered; a workflow points at a missing entity; a transition points at a
  missing action.
- **WorkflowCoherence** — a workflow's state field is not on its owner entity,
  or a transition's `source`/`target` is not one of the workflow's declared
  states.

If validation fails, `compile()` throws — you cannot build an invalid graph.

## Content-addressable hashing {#content-addressable-hashing}

The graph `hash` is deterministic: the **same plugins always compile to the
same hash**. This is used as an identity and integrity check.

A concrete consequence, verified in the playground: the `HelloInvoice` domain
written with the [DSL](../backend/php-dsl.md) and the same domain written as
hand-built descriptor arrays compile to a **byte-identical hash**. The DSL is
pure sugar over the descriptor form — it adds no semantics.

:::note What the v0.1.0 hash covers
The v0.1.0 canonical form hashes the **set of node FQNs** (entity, action,
policy, workflow, projection names) plus the kernel version. It is a strong
identity for graph *shape*. A future revision may extend the canonical form to
cover full node contents.
:::

## Why a graph {#why-a-graph}

Because the application is a single declarative value, the same domain
description drives every layer without re-statement:

- `SchemaDeriver` reads `entities` to emit `CREATE TABLE` statements.
- The `Invoker` reads `actions`, `policies`, and `workflows` to execute calls.
- `ProjectionRenderer` reads `projections` to produce ViewSchemas.

No layer re-describes the domain. They all read the same graph.

## Current v0.1.0 limitations {#current-v010-limitations}

- The graph is compiled **in-process at boot**. There is no on-disk compiled
  artifact or graph cache in v0.1.0.
- The canonical hash covers node FQNs and kernel version (see the note above),
  not full node bodies.
- There is no graph diffing or migration tooling.

## Related {#related}

- [Plugins](plugins.md) — the input to the compiler.
- [Entities, Fields & Actions](entities-fields-actions.md) — the node types.
- [SQL Persistence](../backend/sql-persistence.md) — schema derived from the graph.
