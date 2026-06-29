---
id: intro
title: AUSUS 2.0
sidebar_label: Overview
slug: /
description: A metadata-first PHP framework — declare entities, fields, actions and authorization as data, and the Entity Engine compiles them into a backend, an HTTP API and a React UI.
---

# AUSUS 2.0

**Compile immutable metadata into running applications.**

AUSUS is a **metadata-first** PHP framework. You declare an application as data —
entities, fields, actions, projections, and authorization rules — and the
**Entity Engine** compiles that declaration into a frozen, content-addressed
schema and runs it: persistence, data-aware authorization, an HTTP API, and a
React UI. You describe *what* the application is; the engine provides the *how*,
once, centrally.

## Start here

- **[Quick Start](gen2/QUICKSTART.md)** — from `composer require` to a rendered UI, in under five minutes.
- **[Introduction](gen2/01-introduction.md)** — what AUSUS is, why it exists, its principles.
- **[Architecture](gen2/02-architecture.md)** — the L0 → L6 layering and the compile→run pipeline.

## Explore

- [Pipeline](gen2/03-pipeline.md) — DSL → Compiler → EntitySchema → Runtime → API → React, step by step.
- [Capabilities](gen2/06-capabilities.md) — actions, guards, expand, views, API, React.
- [Reference applications](gen2/05-reference-apps.md) — CRM, Teranga PMS, SGH.
- [Known limits](gen2/07-known-limits.md) — the boundaries of the model, documented openly.

:::note Looking for AUSUS 1.x?
The earlier `standard-stack` lineage is preserved under **AUSUS 1.x (Legacy)** in
the sidebar. AUSUS 2.0 (the Entity Engine) is the current line.
:::
