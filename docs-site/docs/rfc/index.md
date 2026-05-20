---
id: index
title: RFCs
sidebar_label: RFC Overview
slug: /rfc/
description: The architectural RFCs behind AUSUS.
---

# RFCs

AUSUS is designed through **RFCs** — architectural design documents, one per
subsystem. The RFCs are the reasoning behind the framework; the code is one
implementation of them.

:::info Where the RFCs live
The full RFC texts are in the `rfcs/` directory of the
[repository](https://github.com/adonko3xBitters/ausus-framework/tree/main/rfcs).
This section is a map of them and a record of **which RFCs v0.1.0 actually
implements** — in most cases as a deliberate subset.
:::

## How to read this section {#how-to-read-this-section}

- **[Implemented in v0.1.0](implemented.md)** — RFCs the v0.1.0 code realises,
  with a note on the subset that shipped.
- **[Planned / deferred](planned.md)** — RFCs not yet realised, or realised
  only in part, and what is missing.

A subsystem appearing in an RFC does **not** mean it exists in v0.1.0. Always
check the implemented/planned split before relying on a capability.

## RFC catalogue {#rfc-catalogue}

| RFC | Topic | v0.1.0 |
|---|---|---|
| RFC-000 | First real implementation passes / vertical slice | basis of v0.1.0 |
| RFC-001 | Kernel — contracts and value objects | implemented (subset) |
| RFC-002 | Persistence driver | implemented (SQLite subset) |
| RFC-003 | Tenancy | partial — see below |
| RFC-004 | ViewSchema | implemented (subset) |
| RFC-005 | Policy engine | implemented (subset) |
| RFC-006 | Workflow runtime | implemented (subset) |
| RFC-007 | Audit | implemented (subset) |
| RFC-010 | Reporting & maintenance | planned |
| RFC-011 | DSL | implemented (minimal subset) |
| RFC-012 | Standard stack | implemented |
| RFC-013 | Action / Effect | implemented (built-in effects) |
| RFC-014 | Authorization | partial — contracts only |

Several RFCs carry amendments and reviews (`RFC-001-amendment-01`,
`RFC-006-amendment-01`, `RFC-007-amendment-01`, …); those refine the parent RFC
and are read alongside it.

## Why subsets {#why-subsets}

v0.1.0 implements **subsets** of most RFCs on purpose. The RFCs describe the
intended end state; v0.1.0 is a vertical slice that proves the architecture
end-to-end with the smallest surface that works. The pages in this section make
the gap between "designed" and "shipped" explicit.

## Related {#related}

- [Implemented RFCs](implemented.md) · [Planned RFCs](planned.md)
- [Core Concepts](../concepts/metadata-graph.md) — the implemented model.
- [Release Notes v0.1.0](../releases/v0.1.0.md) — the shipped surface.
