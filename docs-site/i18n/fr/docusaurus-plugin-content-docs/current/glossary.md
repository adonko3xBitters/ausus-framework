---
id: glossary
title: Glossary
sidebar_label: Glossary
description: Definitions of the AUSUS v0.1.x terms that recur across the documentation, with links to deeper pages.
---

# Glossary

Short, page-anchored definitions for the recurring AUSUS terms. Skim it
once when you start; come back when a concept page assumes a word you
have not seen yet.

## Action {#action}

A named operation an actor can invoke against an entity. v0.1.x ships
three kinds — `create`, `transition`, `update` (see
[Action kinds](reference/dsl.md#action) and the
[php-dsl reference](backend/php-dsl.md)). Every action runs through
the same Invoker chain regardless of kind.

## ActionDescriptor {#action-descriptor}

The JSON shape an action takes on the wire as part of a ViewSchema.
Carries the FQN, the localised label, `subjectRequired`, the input
field list, and (for update actions) `initialValues` for prefill. Full
field-by-field reference in
[ViewSchema wire](reference/view-schema-wire.md#action-descriptor).

## Actor {#actor}

The acting principal for an invocation — a `Tenant`-scoped identity
plus a role/permission set. v0.1.x ships one implementation (`StubActor`)
and resolves it per-request from `X-Actor-Id` / `X-Actor-Roles` over
HTTP. There is no authentication layer; an authenticated gateway is
expected to set the headers.

## Application {#application}

The bootstrap facade in `ausus/standard-stack`. Composes the compiler,
the SQLite driver and the default runtime; exposes `invoke`, `run`,
`http`, projection rendering, and direct accessors to every runtime
service. Full reference at
[Application & ApplicationConfig](reference/application.md).

## ApplicationConfig {#application-config}

The typed, immutable fluent builder for `Application::create()`. Every
setter returns a new instance. The companion to `Application`; full
reference at [Application & ApplicationConfig](reference/application.md#applicationconfig).

## Audit log {#audit-log}

The kernel-managed table `kernel_audit_log` to which the runtime
appends one row per successful action invocation, in the **same
database transaction** as the row it changed. Schema is fixed by the
runtime and not user-extensible in v0.1.x.

## BuiltinEffect {#builtin-effect}

The enum (`Create` / `Transition` / `Update`) the kernel uses to mark
an `ActionNode` as backed by a runtime-shipped effect rather than a
custom `Effect` class. The string values
(`'kernel.builtin.create'`, …) are stable wire metadata.

## Compiler {#compiler}

The kernel service that takes a list of `Plugin` instances and
produces a single `MetadataGraph`. Validation (FQN uniqueness,
dangling references, workflow coherence) runs here; the produced graph
is content-hashed and immutable. See
[Concepts · Metadata Graph](concepts/metadata-graph.md).

## Effect {#effect}

The runtime side of an action. Three built-ins ship in v0.1.x —
`CreateEffect`, `TransitionEffect`, `UpdateEffect`. Custom effects
implement `Ausus\Effect` and are addressed by FQN in
`ActionNode::effectClass`. The effect is the only layer that mutates
state; it runs inside the Invoker's database transaction.

## Entity {#entity}

A kind of record the application stores. Declared on the DSL via
`$dsl->entity('name')->fields([...])`. The Compiler maps each entity
to one SQL table whose name is the FQN with dots replaced by
underscores (`billing.invoice` → `billing_invoice`). The runtime adds
five system fields per entity automatically.

## Field {#field}

A column on an entity. Declared with `Field::string()` / `::integer()`
/ `::datetime()` / `::money()` / `::enum(...)`, optionally tagged
`nullable`, `default`, `unique`, `max`, `currency`, `options`, `label`.
Type list in [DSL reference](reference/dsl.md).

## FQN — Fully-Qualified Name {#fqn}

The dotted, plugin-scoped identifier the framework uses to address
graph nodes. Conventions:

| Node | FQN shape |
|---|---|
| Entity | `{plugin.name}.{entityLocal}` — e.g. `billing.invoice` |
| Action | `{entity.fqn}.{actionLocal}` — e.g. `billing.invoice.create` |
| Policy | `{entity.fqn}.policy.{actionLocal}` — e.g. `billing.invoice.policy.create` |
| Workflow | `{entity.fqn}.lifecycle` — e.g. `billing.invoice.lifecycle` |
| Projection | `{entity.fqn}.{projectionLocal}` — e.g. `billing.invoice.summary` |

## Invoker {#invoker}

The runtime service that executes an action through the five-step
chain: **preflight → policy → workflow guard → effect → audit**, all
inside one database transaction. See
[Backend · Runtime](backend/runtime.md). `Application::invoke()`
and `Application::run()` are thin wrappers over it.

## MetadataGraph {#metadata-graph}

The compiled, immutable, content-addressed value the framework runs
on. Holds entities, actions, policies, workflows, projections plus a
SHA-256 hash that is identical for identical plugins. See
[Concepts · Metadata Graph](concepts/metadata-graph.md).

## Plugin {#plugin}

A class describing a piece of domain. Implements `Ausus\Plugin` or
extends `Ausus\DslPlugin`. Returns its description from `describe()`
(or composes one via `dsl()`). The Compiler folds every registered
plugin into one MetadataGraph. See [Concepts · Plugins](concepts/plugins.md).

## Policy {#policy}

The authorisation contract evaluated before an action's effect. v0.1.x
ships one implementation, `RoleRequired`, applied via
`->requireRole('member')` on the DSL. Custom policies implement
`Ausus\Policy`. See [Concepts · Policies](concepts/policies.md).

## Projection {#projection}

A named read-shaped view of an entity declared on the DSL via
`->projection('summary', fields:, actions:, role:)`. Compiles to a
`ProjectionNode`; the `ProjectionRenderer` turns one into a ViewSchema
JSON document. Two shapes — list (`data.items`) and detail
(`data.item`).

## Reference {#reference}

The value-object identity of an entity instance:
`{ tenantId, entityFqn, identityHandle }`. The HTTP wire uses
`Reference` as the `subject` field on action POSTs. The
`Subject` value object has the same shape but a different semantic
role (Policy evaluation); both currently coexist.

## Renderer {#renderer}

`@ausus/renderer-react` — the React 18/19 package that consumes
ViewSchema JSON and emits UI. Ships no CSS; ESM-only; React is a peer
dep. See [Frontend · React renderer](frontend/react-renderer.md).

## Runtime {#runtime}

`ausus/runtime-default` — the L2 package that contains the Invoker
chain (`Invoker`, `PolicyEngine`, `WorkflowRuntime`, `EffectDispatcher`,
`DefaultAuditor`) plus the `ProjectionRenderer`. The Application wires
it for you; you rarely instantiate it by hand.

## Stub actor {#stub-actor}

The v0.1.x default `Actor` implementation — fixed identity + role list,
no authentication. Used by `ApplicationConfig` for CLI invocations and
by the Router for HTTP requests (resolved from `X-Actor-*` headers).

## Subject {#subject}

The entity-instance identity passed to `Policy::evaluate()` during the
Invoker chain. Same data shape as `Reference`; the distinction is
semantic (policies receive `Subject`, everything else uses `Reference`).

## System field {#system-field}

A field every entity gets automatically — `id`, `tenant_id`, `_version`,
`created_at`, `updated_at`. Runtime-managed; not writable from action
inputs. `id` is a 26-character Crockford-base32 ULID; `_version` is
the optimistic-lock token.

## Tenant {#tenant}

The isolation key every entity row carries. v0.1.x is single-tenant
per `Application` instance — to act as another tenant, build another
Application. Cross-tenant references (a `Reference.tenantId` not
matching the active tenant) raise `TenantBoundaryViolation`.

## Transition {#transition}

The second action kind. Moves the workflow state field from one value
to another along a declared arrow (`from: '…', to: '…'`). May be
multi-source via `->addTransition()` (or the deprecated alias
`->andTransition()`). Subject required. See
[Concepts · Workflows](concepts/workflows.md).

## Update {#update}

The third action kind, added in v0.2-foundation. Partial-PATCH on a
fixed closed list of fields. **Cannot** patch the workflow state field
or any system field. Subject required. See
[Backend · php-dsl · Action builders](backend/php-dsl.md#action--action-builders).

## ViewSchema {#view-schema}

The JSON wire document `/projections/{fqn}` returns. Fixed
`schemaVersion: '1.0.0'` and `targetProfile: 'react.web.v1'` in v0.1.x.
Full reference at
[ViewSchema wire reference](reference/view-schema-wire.md).

## Workflow {#workflow}

The state machine attached to an entity by declaring an enum state
field and listing transitions over it. Compiled to one `WorkflowNode`;
the runtime enforces "exactly one matching transition per action per
current state." See [Concepts · Workflows](concepts/workflows.md).

## Workflow guard {#workflow-guard}

Step 3 of the Invoker chain. For every action that participates in a
workflow, the guard verifies the entity's current state matches a
declared source for that action. Failure raises
`WorkflowStateMismatch`; no row is written.

## Related {#related}

- [Concepts](concepts/metadata-graph.md) — full conceptual model.
- [Reference](reference/dsl.md) — public API surface.
- [Application & ApplicationConfig](reference/application.md) — the bootstrap centerpiece.
