---
id: index
title: 'Tutorial: Build a Ticket System'
sidebar_label: Overview
description: A from-zero, step-by-step tutorial that builds a complete small AUSUS application.
---

# Tutorial: Build a Ticket System

This tutorial builds a complete, working application **from zero** — a minimal
support **Ticket System**. Unlike the concept pages, which explain *how AUSUS
is designed*, this tutorial teaches you *how to assemble a real application*,
one runnable step at a time.

By the end you will have:

- a domain plugin describing a `ticket` entity, its fields, actions and lifecycle;
- a SQLite database whose schema AUSUS derived for you;
- a working HTTP API;
- a React user interface that lists tickets and drives their workflow.

Everything here uses only **implemented v0.1.x capabilities**. Nothing is
mocked or aspirational — every code block runs.

## What you will build {#what-you-will-build}

A ticket moves through a fixed lifecycle:

```
OPEN  ──start──▶  IN_PROGRESS  ──resolve──▶  RESOLVED  ──close──▶  CLOSED
```

Each arrow is an **action** guarded by a **workflow**. An agent can only
`resolve` a ticket that is `IN_PROGRESS`; calling `resolve` on an `OPEN`
ticket is rejected by the runtime, before anything is written.

## How AUSUS fits together {#how-ausus-fits-together}

You will touch four layers. Keep this picture in mind — each tutorial part
fills in one piece:

```
  Your plugin (domain as data)
        │  compiled into
        ▼
  MetadataGraph  ──▶  Runtime (Invoker: policy → workflow → effect → audit)
        │                     │
        │                     ▼
        │              SQLite persistence
        ▼
  HTTP API  ──▶  ViewSchema JSON  ──▶  React renderer
```

You never write a controller, a migration, a SQL query, or a form component.
You describe the domain; AUSUS compiles and runs it.

## Prerequisites {#prerequisites}

| Tool | Version | Checked with |
|---|---|---|
| PHP | 8.3+ | `php --version` |
| PHP extensions | `pdo`, `pdo_sqlite` | `php -m` |
| Composer | 2.0+ | `composer --version` |
| Node.js | 18+ | `node --version` |
| npm | 8+ | `npm --version` |

You should be comfortable with PHP and basic command-line use. No prior AUSUS
knowledge is assumed. You do **not** need Laravel — AUSUS is Laravel-native but
does not require the framework to run.

## The learning path {#the-learning-path}

Work through the seven parts in order. Each builds directly on the previous one.

1. **[Installation](installation.md)** — create the project and install AUSUS.
2. **[The domain](domain.md)** — bootstrap the `Application`; declare the
   entity, fields, actions and workflow.
3. **[Persistence](persistence.md)** — run the app on the command line; AUSUS
   derives and applies the SQLite schema.
4. **[HTTP API](http-api.md)** — expose the domain over HTTP and exercise it
   with `curl`.
5. **[React UI](react-ui.md)** — render the tickets in the browser and drive
   the workflow.
6. **[Troubleshooting & recap](troubleshooting.md)** — common mistakes,
   debugging tips, and a final architecture recap.

Total time: roughly 30–45 minutes.

:::tip Where to go after this
Once the tutorial clicks, the [Core Concepts](../concepts/metadata-graph.md)
section explains the model in depth, and [The PHP DSL](../backend/php-dsl.md)
is the full builder reference.
:::

Ready? Start with **[Part 1 — Installation](installation.md)**.
