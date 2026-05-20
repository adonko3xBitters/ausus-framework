---
id: implemented
title: Implemented RFCs
sidebar_label: Implemented in v0.1.0
description: RFCs realised by the AUSUS v0.1.0 code.
---

# Implemented RFCs

These RFCs are realised by v0.1.0 — in most cases as a deliberate subset. Each
entry notes what shipped and links to the documentation for it.

## RFC-001 — Kernel

The contracts-and-value-objects layer. v0.1.0 ships the
[metadata graph](../concepts/metadata-graph.md) node types, the `Plugin`
contract, the `Compiler`, the value objects (`Tenant`, `Reference`, `Actor`,
`Context`, …), `Ulid`, and the [exception taxonomy](../reference/errors.md).

**Subset note:** the v0.1.0 kernel uses a flat `Ausus\` namespace for its
public surface. The package README describes a planned reorganisation into an
`Ausus\Kernel\Contracts\…` namespace tree — that reorganisation is **not** in
v0.1.0.

→ [ausus/kernel](../packages/index.md#aususkernel)

## RFC-002 — Persistence driver

The `PersistenceDriver` / `Repository` contracts and a concrete driver.
v0.1.0 ships the **SQLite** driver with `find` / `create` / `update`,
schema derivation, optimistic concurrency, and tenant scoping.

**Subset note:** SQLite only; no `findMany`, query API, or `delete`.

→ [SQL Persistence](../backend/sql-persistence.md)

## RFC-004 — ViewSchema

The JSON wire format between backend and renderer. v0.1.0 ships
`schemaVersion 1.0.0`, the `react.web.v1` profile, and list/detail data shapes.

**Subset note:** empty `filters`, no real pagination, fixed locale.

→ [ViewSchema](../frontend/viewschema.md)

## RFC-005 — Policy engine

Action authorization. v0.1.0 ships the `PolicyEngine` with deny-by-default and
fail-closed semantics, and the `RoleRequired` policy.

**Subset note:** `RoleRequired` is the only policy implementation; no
attribute-based or combined policies.

→ [Policies](../concepts/policies.md)

## RFC-006 — Workflow runtime

State machines on entities. v0.1.0 ships workflow inference from an enum field,
transition guards, wildcard sources, and multi-source transitions.

**Subset note:** per-transition guard policies are not evaluated in v0.1.0.

→ [Workflows](../concepts/workflows.md)

## RFC-007 — Audit

A transactional audit trail. v0.1.0 ships `DefaultAuditor` and
`DatabaseAuditSink`, writing one audit entry per action inside the action's
transaction, to a `kernel_audit_log` table.

**Subset note:** the audit sink is part of `persistence-sql`; the dedicated
`ausus/audit-database` package is reserved and ships no code. The per-process
sequence counter is not durable across restarts.

→ [The Runtime](../backend/runtime.md) · [SQL Persistence](../backend/sql-persistence.md#the-audit-log)

## RFC-011 — DSL

The fluent domain-declaration API. v0.1.0 ships the **minimal** RFC-011 subset:
`DslPlugin`, `Dsl`, `Field`, `Action`, and the entity/field/action/workflow/
projection builders.

**Subset note:** convention-resolved policy/effect classes, field-level
visibility, and DSL diagnostics are deferred.

→ [The PHP DSL](../backend/php-dsl.md)

## RFC-012 — Standard stack

The curated package set. v0.1.0 ships `ausus/standard-stack` as a metapackage
pinning `kernel`, `persistence-sql`, `runtime-default`, and `api-http`.

→ [Packages](../packages/index.md)

## RFC-013 — Action / Effect

The action-effect contract. v0.1.0 ships the `Effect` / `EffectContext`
contracts and two built-in effects — `kernel.builtin.create` and
`kernel.builtin.transition` — dispatched by `EffectDispatcher`.

**Subset note:** custom `Effect` classes are dispatchable but not exercised by
the v0.1.0 sample domain.

→ [The Runtime](../backend/runtime.md) · [Entities, Fields & Actions](../concepts/entities-fields-actions.md)

## Related

- [Planned RFCs](planned.md) — what is not yet implemented.
- [Release Notes v0.1.0](../releases/v0.1.0.md)
