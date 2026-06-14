---
id: intro
title: AUSUS
sidebar_label: Overview
slug: /
description: Metadata-first, plugin-first, tenant-first Laravel-native platform for enterprise applications — CRUD, ERP workflows, multi-tenant SaaS, and internal tools.
---

# AUSUS

**Ship enterprise applications in days, not quarters.** AUSUS is a Laravel-native
platform for building CRUD apps, ERP workflows, multi-tenant SaaS, and internal
tools — where multi-tenancy, authorization, audit trails, and approval workflows
are built in, not bolted on.

You describe **what** the application is — its records, actions, workflows, and
permissions — and AUSUS compiles it into a working system: database schema,
business rules, HTTP API, and React UI, all from one source of truth.

## What can you build? {#what-can-you-build}

| You need to ship | AUSUS gives you, out of the box |
|---|---|
| **A back-office / admin app** | Records, forms, lists, and detail views generated from your data model |
| **An ERP / approval workflow** | First-class state machines (`draft → review → approved → paid`) with role-and-state gated transitions and a full audit trail |
| **A multi-tenant SaaS** | Tenant isolation enforced on every read and write, so one customer can never see another's data |
| **A governance / claims / KYC tool** | **Authorization that reads the data** — "an adjuster may approve a claim only up to their limit" is a rule, not custom code |
| **An internal tool** | A typed domain plus a ready HTTP API and a React UI, assembled from reusable plugins |

## Why AUSUS is different {#why-different}

- **Metadata-first.** Declare your domain as metadata; AUSUS turns it into a
  working application instead of thousands of lines of glue code.
- **Plugin-first.** Every capability is a swappable plugin — change the database
  (SQLite ↔ PostgreSQL) or add a domain module without touching the engine.
- **Tenant-first.** Multi-tenancy is structural: every record carries its tenant
  and the platform refuses to cross that boundary.
- **Safe by construction.** Four guarantees can never be bypassed by any API —
  **tenant isolation, authorization, audit, and workflow**. Compliance becomes a
  property of the platform, not a code-review checklist.

## What ships today (v1.1.0) {#what-ships-today}

A complete, production-installable stack on Packagist:

- **Core engine** — `ausus/kernel`, `ausus/runtime-default` — compiles your
  application and runs every action through a single guarded, audited transaction.
- **Databases** — `ausus/persistence-sql` (SQLite, zero-config for dev) and
  **`ausus/persistence-postgres` (PostgreSQL, for production)** — interchangeable
  behind one contract.
- **HTTP API** — `ausus/api-http` — a ready PSR-7/15 API surface generated from
  your model.
- **Frontend** — `@ausus/renderer-react` — a React 18 / 19 renderer that turns
  your app into a UI.
- **Get started fast** — `ausus/standard-stack` (the curated bundle) and
  `ausus/starter` (`composer create-project`).

## Capabilities delivered in v1.1 {#capabilities-v11}

- **PostgreSQL, production-ready.** `ausus/persistence-postgres` brings AUSUS onto
  the database real enterprise systems run on — concurrent writes, durability,
  scale — behind the same contract as the SQLite driver. Moving from development
  to production requires **no change to your application code**.
- **Relations & data integrity (RFC-015).** Link records together (a claim
  belongs to a policy, evidence belongs to a claim). Bad links are caught when you
  build the app, and broken references are rejected when you write.
- **Data-dependent authorization (RFC-018).** Permissions can read the record and
  the user — *approve only if `amount ≤ the approver's authority limit`*. These
  rules are checked before anything changes and fail closed, expressed as
  configuration rather than smuggled into business logic.

## Install {#install}

Create a new project from the starter template:

```bash
composer create-project ausus/starter myapp
cd myapp
composer boot      # builds the app, creates the schema, runs a demo end to end
composer serve     # live HTTP API at http://127.0.0.1:8080
```

It uses SQLite by default. Going to production on PostgreSQL is a one-line
dependency, with no rewrite of your domain:

```bash
composer require ausus/persistence-postgres:^1.1
```

Add the React renderer to a frontend project:

```bash
npm install @ausus/renderer-react react@18 react-dom@18
```

See [Installation](getting-started/installation.md) for the from-source path and
version requirements.

## A minimal domain {#a-minimal-domain}

This is a complete domain plugin — an `invoice` entity with three actions and a
status workflow — written in the AUSUS DSL:

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
            ->workflow(field: 'status', initial: 'DRAFT')
            ->projection('summary',
                fields:  ['id', 'number', 'customer_name', 'status', 'amount'],
                actions: ['create', 'cancel'],
                role:    'invoice.viewer');
    }
}
```

Walk through this end-to-end in the [HelloInvoice tutorial](getting-started/hello-invoice.md).

## How it fits together {#how-it-fits-together}

AUSUS keeps a clean separation between the domain, the engine, and the
presentation:

- **The domain** describes the business — entities, actions, workflows,
  permissions — and never knows about HTTP or the UI.
- **The engine** compiles that description and runs every action through one
  transactional path that enforces tenancy, permissions, workflow, and audit.
- **The presentation** — the HTTP API and the React renderer — is generated from
  that same description. React is purely a rendering layer.

![AUSUS architecture: user-authored plugins are compiled into one metadata graph; the runtime drives interchangeable persistence drivers; the HTTP API and presentation layer expose data to the React renderer.](/img/diagrams/architecture.svg)

Because the database, the API, and the UI are all derived from one source of
truth, they can't drift apart — and swapping any part (for example, SQLite for
PostgreSQL) leaves the rest untouched. The runtime's invocation pipeline is
unpacked in [The Runtime](backend/runtime.md); the model's lifecycle is in
[The metadata graph](concepts/metadata-graph.md).

## Ecosystem links {#ecosystem-links}

- **GitHub** — [adonko3xBitters/ausus-framework](https://github.com/adonko3xBitters/ausus-framework)
- **Packagist** — [`ausus/*` packages](https://packagist.org/search/?query=ausus)
- **npm** — [`@ausus/renderer-react`](https://www.npmjs.com/package/@ausus/renderer-react)

## Where to go next {#where-to-go-next}

| If you want to… | Start here |
|---|---|
| Install AUSUS | [Getting Started → Installation](getting-started/installation.md) |
| Understand the model | [Core Concepts → The Metadata Graph](concepts/metadata-graph.md) |
| Build something | [HelloInvoice tutorial](getting-started/hello-invoice.md) |
| See the packages | [Packages](packages/index.md) |
| Read the release notes | [Releases on GitHub](https://github.com/adonko3xBitters/ausus-framework/releases) |
