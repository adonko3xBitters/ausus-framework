---
id: implemented
title: Implemented RFCs
sidebar_label: Implemented
description: RFCs realised by the AUSUS v1.1.0 code.
---

# Implemented RFCs

These RFCs are realised by v1.1.0 — in most cases as a deliberate subset. Each
entry notes what shipped and links to the documentation for it.

## RFC-001 — Kernel {#rfc-001--kernel}

The contracts-and-value-objects layer. v0.1.0 ships the
[metadata graph](../concepts/metadata-graph.md) node types, the `Plugin`
contract, the `Compiler`, the value objects (`Tenant`, `Reference`, `Actor`,
`Context`, …), `Ulid`, and the [exception taxonomy](../reference/errors.md).

**Subset note:** the v0.1.0 kernel uses a flat `Ausus\` namespace for its
public surface. The package README describes a planned reorganisation into an
`Ausus\Kernel\Contracts\…` namespace tree — that reorganisation is **not** in
v0.1.0.

→ [ausus/kernel](../packages/index.md#aususkernel)

## RFC-002 — Persistence driver {#rfc-002--persistence-driver}

The `PersistenceDriver` / `Repository` contracts and a concrete driver.
AUSUS ships **two** drivers behind the contract — `persistence-sql` (SQLite,
reference) and `persistence-postgres` (PostgreSQL, production) — with `find` /
`create` / `update` / `findAll` / `findPaged`, schema derivation, optimistic
concurrency, tenant scoping, and referential integrity.

**Subset note:** no `delete`. Behavioural parity between the two drivers is
enforced by a cross-driver compatibility gate.

→ [SQL Persistence](../backend/sql-persistence.md)

## RFC-004 — ViewSchema {#rfc-004--viewschema}

The JSON wire format between backend and renderer. AUSUS ships
`schemaVersion 1.2.0`, the `react.web.v1` profile, and list/detail data shapes
with pagination, filtering, and sorting.

**Subset note:** fixed locale.

→ [ViewSchema](../frontend/viewschema.md)

## RFC-005 — Policy engine {#rfc-005--policy-engine}

Action authorization. AUSUS ships the `PolicyEngine` with deny-by-default and
fail-closed semantics, the `RoleRequired` policy, and — via RFC-018 —
data-dependent guards.

**Subset note:** no separate permission-based policy class.

→ [Policies](../concepts/policies.md)

## RFC-006 — Workflow runtime {#rfc-006--workflow-runtime}

State machines on entities. v0.1.0 ships workflow inference from an enum field,
transition guards, wildcard sources, and multi-source transitions.

**Subset note:** per-transition guard policies are not evaluated in v0.1.0.

→ [Workflows](../concepts/workflows.md)

## RFC-007 — Audit {#rfc-007--audit}

A transactional audit trail. v0.1.0 ships `DefaultAuditor` and
`DatabaseAuditSink`, writing one audit entry per action inside the action's
transaction, to a `kernel_audit_log` table.

**Subset note:** the audit sink is part of `persistence-sql`; the dedicated
`ausus/audit-database` package is reserved and ships no code. The per-process
sequence counter is not durable across restarts.

→ [The Runtime](../backend/runtime.md) · [SQL Persistence](../backend/sql-persistence.md#the-audit-log)

## RFC-011 — DSL {#rfc-011--dsl}

The fluent domain-declaration API. v0.1.0 ships the **minimal** RFC-011 subset:
`DslPlugin`, `Dsl`, `Field`, `Action`, and the entity/field/action/workflow/
projection builders.

**Subset note:** convention-resolved policy/effect classes, field-level
visibility, and DSL diagnostics are deferred.

→ [The PHP DSL](../backend/php-dsl.md)

## RFC-012 — Standard stack {#rfc-012--standard-stack}

The curated package set. v0.1.0 ships `ausus/standard-stack` as a metapackage
pinning `kernel`, `persistence-sql`, `runtime-default`, and `api-http`.

→ [Packages](../packages/index.md)

## RFC-013 — Action / Effect {#rfc-013--action--effect}

The action-effect contract. v0.1.0 ships the `Effect` / `EffectContext`
contracts and two built-in effects — `kernel.builtin.create` and
`kernel.builtin.transition` — dispatched by `EffectDispatcher`.

**Subset note:** custom `Effect` classes are dispatchable but not exercised by
the v0.1.0 sample domain.

→ [The Runtime](../backend/runtime.md) · [Entities, Fields & Actions](../concepts/entities-fields-actions.md)

## RFC-015 — Relations & referential integrity {#rfc-015--relations--referential-integrity}

Typed foreign references between entities. AUSUS ships `Field::reference(...)`,
compile-time rejection of dangling references (`DanglingRelation`), write-time
enforcement (`ReferentialIntegrityViolation`), and projection `expand` to fold a
referenced row's display field. The `Subject` identity value object is unified
into `Reference`.

→ [SQL Persistence](../backend/sql-persistence.md) · [Glossary: Field reference](../glossary.md#field-reference)

## RFC-018 — Data-dependent authorization {#rfc-018--data-dependent-authorization}

Authorization that reads subject field values and structured actor attributes.
AUSUS ships `Action::…->requireThat(Cond)`, the `Fact` / `Cond` surface,
plugin-level `actorAttributes(...)`, compile-time closure
(`DanglingFactReference`), and in-transaction, fail-closed evaluation
(`PolicyDenied`).

→ [Policies](../concepts/policies.md#data-dependent-authorization)

## Related {#related}

- [Planned RFCs](planned.md) — what is not yet implemented.
- [Release Notes v1.1.0](../releases/v1.1.0.md)
