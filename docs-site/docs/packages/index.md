---
id: index
title: Packages
sidebar_label: Package Catalog
slug: /packages/
description: Every AUSUS v0.1.0 package — implemented and reserved.
---

# Packages

AUSUS v0.1.0 publishes **10 Composer packages** and **1 npm package**. This
page is the authoritative list of what each one is — and which ones are
**reserved names with no code yet**.

:::warning Implemented vs reserved
Four Composer packages in v0.1.0 are **name reservations only**. They contain a
`composer.json` and a `README.md` but **no source code**. They are listed here
so the namespace is documented and claimed; they are planned to ship as working
code in v0.2.0. Do not depend on them as functional packages.
:::

## Implemented — Composer

| Package | Layer | Role |
|---|---|---|
| [`ausus/kernel`](#aususkernel) | L0 | contracts, value objects, DSL, Compiler |
| [`ausus/runtime-default`](#aususruntime-default) | L2 | the Invoker chain |
| [`ausus/persistence-sql`](#aususpersistence-sql) | L3 | SQLite persistence driver |
| [`ausus/api-http`](#aususapi-http) | L4 | PSR-7/15 HTTP API |

### ausus/kernel

Layer L0. Contracts and value objects only — no runtime side effects. Defines
the [metadata graph](../concepts/metadata-graph.md) node types, the `Plugin`
contract, the `Compiler`, the [DSL](../backend/php-dsl.md) (`DslPlugin`, `Dsl`,
`Field`, `Action`), the `Policy` / `Effect` / `Repository` / `Auditor`
contracts, the value objects (`Tenant`, `Reference`, `Actor`, …), `Ulid`, and
the [exception taxonomy](../reference/errors.md). Every other package depends
on it.

### ausus/runtime-default

Layer L2. The execution engine: `Invoker`, `PolicyEngine`, `WorkflowRuntime`,
`EffectDispatcher` and the built-in effects, `DefaultAuditor`, and
`ProjectionRenderer`. Depends on `kernel`. See [The Runtime](../backend/runtime.md).

### ausus/persistence-sql

Layer L3. A SQLite-backed `PersistenceDriver`: `SqlitePersistenceDriver`,
`SqliteRepository`, `SchemaDeriver`, and `DatabaseAuditSink`. Depends on
`kernel`. See [SQL Persistence](../backend/sql-persistence.md).

### ausus/api-http

Layer L4. A PSR-15 `Router` exposing projections and actions over HTTP, plus
`ErrorMapper` and a minimal `Emitter`. Depends on `kernel` and
`runtime-default`. See [The HTTP API](../backend/http-api.md).

## Implemented — template and metapackage

| Package | Type | Role |
|---|---|---|
| `ausus/starter` | project | `composer create-project` template — wires the stack and ships the HelloInvoice sample |
| `ausus/standard-stack` | metapackage | pins the validated v0.1.0 package set; depends on `kernel`, `persistence-sql`, `runtime-default`, `api-http` |

## Implemented — npm

| Package | Role |
|---|---|
| `@ausus/renderer-react` | React 18/19 renderer for the [ViewSchema](../frontend/viewschema.md) wire format |

ESM-only; `react`/`react-dom` are peer dependencies. See
[The React renderer](../frontend/react-renderer.md).

## Reserved — name only, no code in v0.1.0

These four packages are **reserved names**. They ship in v0.1.0 with metadata
but **no implementation**, and are planned for v0.2.0.

| Package | Reserved for | Planned layer |
|---|---|---|
| `ausus/tenancy-row` | a row-level multi-tenancy driver | L3 |
| `ausus/audit-database` | a dedicated database audit sink/driver | L3 |
| `ausus/auth-bridge` | the authentication / actor-resolution bridge | L2–L4 |
| `ausus/presentation-default` | the L5 presentation layer beyond the kernel renderer | L5 |

:::note What this means for you
- **Multi-tenancy** in v0.1.0 is the tenant scoping built into
  [`persistence-sql`](#aususpersistence-sql) — not a separate `tenancy-row`
  driver.
- **Auditing** in v0.1.0 is `DatabaseAuditSink` in `persistence-sql` — not the
  `audit-database` package.
- **Authentication** is not provided — `auth-bridge` is unwritten. See the
  security note in [The HTTP API](../backend/http-api.md).
:::

## Dependency order

When installing packages by hand, follow the dependency order:

```
kernel
 ├─ runtime-default      (-> kernel)
 ├─ persistence-sql      (-> kernel)
 └─ api-http             (-> kernel, runtime-default)
standard-stack           (-> kernel, persistence-sql, runtime-default, api-http)
starter                  (-> kernel, persistence-sql, runtime-default)
```

## Related

- [Installation](../getting-started/installation.md) — how to install them.
- [Release Notes v0.1.0](../releases/v0.1.0.md) — the compatibility matrix.
- [Package Integrity](../operations/package-integrity.md) — artifact verification.
