---
id: planned
title: Planned RFCs
sidebar_label: Planned / Deferred
description: RFCs not yet realised, or realised only in part, by v1.1.0.
---

# Planned / Deferred RFCs

These RFCs are **not** realised by v1.1.0, or are realised only in part. They
describe the intended direction of the framework.

:::caution Design, not commitment
The items below are documented design and architectural direction. They are
**not** roadmap guarantees, dates, or promises. Treat them as "where the
architecture points", not "what will ship when".
:::

## RFC-003 — Tenancy {#rfc-003--tenancy}

Designs a dedicated **row-level tenancy** driver and tenant-resolution model.

**Current state — partial.** Tenant scoping *exists* — every entity is
tenant-scoped and the SQLite driver enforces tenant boundaries (see
[SQL Persistence](../backend/sql-persistence.md)). What is **not** in v0.1.0 is
the dedicated `ausus/tenancy-row` driver package; that name is reserved and
ships no code. There is no multi-tenant *runtime* — an `Invoker` is bound to a
single tenant.

## RFC-007 (dedicated audit package) — Audit database {#rfc-007-dedicated-audit-package--audit-database}

RFC-007 itself is [implemented in subset](implemented.md#rfc-007--audit) — the
audit trail works. What is deferred is the **dedicated `ausus/audit-database`
package**: a standalone audit driver separate from `persistence-sql`. That name
is reserved and ships no code in v0.1.0.

## RFC-010 — Reporting & maintenance {#rfc-010--reporting--maintenance}

Designs a reporting/query subsystem and maintenance-class operations
(`ReportingDriver`, maintenance-class invocations).

**Current state — not implemented.** There is no reporting driver. The kernel
distinguishes a `Maintenance` invocation class in the audit record, but no
reporting or maintenance subsystem ships.

## RFC-014 — Authorization {#rfc-014--authorization}

Designs the full authorization model — actor resolution, an authentication
bridge, and richer policy composition.

**Current state — partial.** The `Actor` / `ActorRef` contracts exist in the
kernel, and `StubActor` provides a fixed in-memory actor. What is **not** in
v0.1.0: any authentication, actor resolution from credentials, or the
`ausus/auth-bridge` package (reserved, no code). Anything exposing the runtime
must supply its own authentication — see
[The HTTP API](../backend/http-api.md).

## Reserved packages summary {#reserved-packages-summary}

Four package names are reserved and tied to the RFCs above:

| Package | RFC | Status |
|---|---|---|
| `ausus/tenancy-row` | RFC-003 | reserved name, no code |
| `ausus/audit-database` | RFC-007 | reserved name, no code |
| `ausus/auth-bridge` | RFC-014 | reserved name, no code |
| `ausus/presentation-default` | presentation (L5) | reserved name, no code |

See [Packages](../packages/index.md) for the full catalogue.

## Other deferred items {#other-deferred-items}

Not tied to a single RFC, but documented as deferred:

- **MySQL persistence driver** — the SQL design allows for it; SQLite and
  PostgreSQL are both implemented today.
- **Supply-chain attestation** — npm provenance, GPG-signed tags, and an SBOM
  are deferred to v0.2.0 (see [Package Integrity](../operations/package-integrity.md)).
- **DSL enrichments** — convention-resolved policy/effect classes, field-level
  visibility, DSL diagnostics (RFC-011 deferred surface).

## Related {#related}

- [Implemented RFCs](implemented.md)
- [Release Notes v1.1.0](../releases/v1.1.0.md) — the current release.
