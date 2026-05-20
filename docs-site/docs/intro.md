---
id: intro
title: AUSUS
sidebar_label: Overview
slug: /
description: A metadata-first, plugin-first PHP framework for enterprise applications.
---

# AUSUS

AUSUS is a PHP framework for building enterprise applications — CRUD platforms,
workflow tools, internal tools — from **metadata graphs** instead of
hand-written controllers and views.

You describe your domain (entities, fields, actions, workflows, policies,
projections) as a **plugin**. A compiler turns that description into a
deterministic, content-addressable `MetadataGraph`. A layered runtime executes
actions against it; an HTTP API exposes it; a React renderer draws it.

## Architecture first

AUSUS is organised as a stack of layers with stable, one-directional
contracts. A layer never depends on a layer above it.

```
L7  Plugins              user-authored domain logic
L6  Renderer (React)     @ausus/renderer-react
L5  Presentation         ProjectionRenderer -> ViewSchema (RFC-004)
L4  API Surface          ausus/api-http (PSR-7/15)
L3  Drivers              ausus/persistence-sql (and reserved drivers)
L2  Runtime              ausus/runtime-default (Invoker chain)
L1  Compiler             MetadataGraph synthesis
L0  Kernel               ausus/kernel (contracts, value objects, DSL)
```

This is the central idea: the **metadata graph is the application**. Backends,
APIs, and UIs are renderings of the same graph rather than independently
maintained code.

## Install

Create a new project from the starter template:

```bash
composer create-project ausus/starter myapp
cd myapp && composer boot
# -> OK — ausus/starter boots cleanly.
```

Add the React renderer to a frontend project:

```bash
npm install @ausus/renderer-react react@18 react-dom@18
```

See [Installation](getting-started/installation.md) for the from-source path
and version requirements.

## A minimal domain

This is a complete domain plugin — an `invoice` entity with three actions and
a status workflow — written in the AUSUS DSL:

```php
use Ausus\{DslPlugin, Dsl, Field, Action};

final class HelloInvoiceDsl extends DslPlugin
{
    public function name(): string        { return 'billing'; }
    public function phpNamespace(): string { return 'Acme\\Billing'; }

    public function dsl(Dsl $dsl): void
    {
        $dsl->entity('invoice')
            ->fields([
                'number'        => Field::string()->unique()->max(32),
                'customer_name' => Field::string()->max(200),
                'amount'        => Field::money()->currency('USD'),
                'status'        => Field::enum('DRAFT', 'ISSUED', 'CANCELLED')->default('DRAFT'),
                'issued_at'     => Field::datetime()->nullable(),
            ])
            ->actions([
                'create' => Action::create('number', 'customer_name', 'amount')
                              ->requireRole('invoice.creator'),
                'issue'  => Action::transition('status', from: 'DRAFT', to: 'ISSUED')
                              ->stamp('issued_at')
                              ->requireRole('invoice.issuer'),
                'cancel' => Action::transition('status', from: 'DRAFT',  to: 'CANCELLED')
                              ->andTransition('status', from: 'ISSUED', to: 'CANCELLED')
                              ->requireRole('invoice.canceler'),
            ])
            ->workflow('status')
            ->projection('summary',
                fields:  ['id', 'number', 'customer_name', 'status', 'amount'],
                actions: ['create', 'cancel'],
                role:    'invoice.viewer');
    }
}
```

Walk through this end-to-end in the [HelloInvoice tutorial](getting-started/hello-invoice.md).

## Current status

:::info v0.1.0 — initial public release

This is an **early public release**. It is suitable for evaluation, prototypes,
and learning the model. Public contracts are still **stabilizing** and may
change before v1.0.

**What ships and works today:**

- **4 implemented PHP libraries** — `kernel`, `persistence-sql`,
  `runtime-default`, `api-http`.
- A **starter template** (`ausus/starter`) and a **metapackage**
  (`ausus/standard-stack`).
- A **React renderer** (`@ausus/renderer-react`) for the ViewSchema wire format.

**What does _not_ ship yet:**

- **4 package names are reserved only** — `tenancy-row`, `audit-database`,
  `auth-bridge`, `presentation-default` carry **no source code** in v0.1.0.
  They are planned for v0.2.0.
- Persistence is validated on **SQLite**; MySQL and PostgreSQL are designed-for
  but not validated.
- The runtime is **single-process, single-tenant, single-actor** per invocation.
  There is no distributed runtime and no multi-tenant runtime.
- Authentication is a **stub actor** only; there is no auth bridge.

See the [v0.1.0 Release Notes](releases/v0.1.0.md) for the full compatibility
matrix and known limitations.

:::

## Ecosystem links

- **GitHub** — [adonko3xBitters/ausus-framework](https://github.com/adonko3xBitters/ausus-framework)
- **Packagist** — [`ausus/*` packages](https://packagist.org/search/?query=ausus)
- **npm** — [`@ausus/renderer-react`](https://www.npmjs.com/package/@ausus/renderer-react)

## Where to go next

| If you want to… | Start here |
|---|---|
| Install AUSUS | [Getting Started → Installation](getting-started/installation.md) |
| Understand the model | [Core Concepts → The Metadata Graph](concepts/metadata-graph.md) |
| Build something | [HelloInvoice tutorial](getting-started/hello-invoice.md) |
| See what is real vs reserved | [Packages](packages/index.md) |
| Judge release maturity | [Release Notes v0.1.0](releases/v0.1.0.md) |
