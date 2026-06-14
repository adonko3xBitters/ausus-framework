# AUSUS

[![Version](https://img.shields.io/badge/version-1.1.0-brightgreen.svg)](https://github.com/adonko3xBitters/ausus-framework/releases)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-8.3%2B-777bb4.svg)](https://www.php.net/)
[![Packagist](https://img.shields.io/badge/packagist-ausus%2Fstandard--stack-orange.svg)](https://packagist.org/packages/ausus/standard-stack)

**Ship enterprise applications in days, not quarters.** AUSUS is a Laravel-native platform for building CRUD apps, ERP workflows, multi-tenant SaaS, and internal tools — where multi-tenancy, authorization, audit trails, and approval workflows are built in, not bolted on.

---

## What can I build?

| You need to ship | AUSUS gives you, out of the box |
|---|---|
| **A back-office / admin app** | Records, forms, lists, and detail views generated from your data model — no controllers or templates to hand-write |
| **An ERP / approval workflow** | First-class state machines (`draft → review → approved → paid`) with role-and-state gated transitions and a full audit trail |
| **A multi-tenant SaaS** | Tenant isolation enforced on every read and write, so one customer can never see another's data |
| **A governance / claims / KYC tool** | **Authorization that reads the data** — "an adjuster may approve a claim only up to their limit" is a rule, not custom code |
| **An internal tool** | A typed domain plus a ready HTTP API and a React UI, assembled from reusable plugins |

You describe **what** the application is. AUSUS compiles it and runs it — with the guarantees an enterprise app needs already in place.

---

## Why AUSUS is different

- **Metadata-first.** You declare entities, fields, actions, workflows, and permissions as metadata. AUSUS turns that into a working application — database schema, business rules, HTTP API, and UI — instead of thousands of lines of glue code.
- **Plugin-first.** Every capability is a swappable plugin. Swap the database (SQLite ↔ PostgreSQL) or add a domain module without touching the engine.
- **Tenant-first.** Multi-tenancy is structural. Every record carries its tenant and the platform refuses to cross that boundary — you can't forget a `where tenant_id = ?`.
- **Safe by construction.** Four guarantees can never be bypassed by any API: **tenant isolation, authorization, audit, and workflow**. Compliance stops being a code-review checklist and becomes a property of the platform.

The result: less code to write, far less code to get wrong, and the parts that usually leak across an enterprise app — security, tenancy, audit — handled once, centrally.

---

## What ships today (v1.1.0)

A complete, production-installable stack on Packagist:

- **Core engine** — `ausus/kernel`, `ausus/runtime-default` — compiles your application and runs every action through a single guarded, audited transaction.
- **Databases** — `ausus/persistence-sql` (SQLite, zero-config for dev) and **`ausus/persistence-postgres` (PostgreSQL, for production)** — interchangeable behind one contract.
- **HTTP API** — `ausus/api-http` — a ready PSR-7/15 API surface generated from your model.
- **Frontend** — `@ausus/renderer-react` — a React 18 / 19 renderer that turns your app into a UI.
- **Get started fast** — `ausus/standard-stack` (the curated bundle) and `ausus/starter` (`composer create-project`).

---

## Capabilities delivered in v1.1

- **PostgreSQL, production-ready.** `ausus/persistence-postgres` brings AUSUS onto the database real enterprise systems run on — concurrent writes, durability, scale — behind the exact same contract as the SQLite driver. Moving from development to production requires **no change to your application code**.
- **Relations & data integrity (RFC-015).** Link records together (a claim belongs to a policy, evidence belongs to a claim). Bad links are caught when you build the app, and broken references are rejected when you write — so your data can't silently rot.
- **Data-dependent authorization (RFC-018).** Permissions can now read the record and the user: *approve only if `amount ≤ the approver's authority limit`*. These rules are checked before anything changes and fail closed — the kind of control that governance, claims, vendor-risk, and finance domains genuinely require, expressed as configuration rather than smuggled into business logic.

---

## How do I start?

```bash
composer create-project ausus/starter myapp
cd myapp

composer boot      # builds the app, creates the schema, runs a demo end to end
composer serve     # live HTTP API at http://127.0.0.1:8080
```

Three commands to a running application. It uses SQLite by default — perfect for development.

**Going to production on PostgreSQL** is a one-line dependency, with no rewrite of your domain:

```bash
composer require ausus/persistence-postgres:^1.1
```

---

## Architecture, briefly

AUSUS keeps a clean separation:

- **The domain** describes the business — entities, actions, workflows, permissions — and never knows about HTTP or the UI.
- **The engine** compiles that description, runs every action through one transactional path that enforces tenancy, permissions, workflow, and audit, then exposes the result as data.
- **The presentation** (the HTTP API and the React renderer) is generated from that same description — React is purely a rendering layer.

Because the database, the API, and the UI are all derived from one source of truth, they can't drift apart — and swapping any layer (for example, SQLite for PostgreSQL) leaves the rest untouched.

> Deeper dives — the compiled model, the runtime, the driver contract, and the wire format — live in the [documentation](docs-site/docs/intro.md).

---

## Competitive positioning

| You currently reach for | AUSUS covers it with |
|---|---|
| **Filament** / **Laravel Nova** | Admin and CRUD apps generated from your model, not from UI builders |
| **Retool** | Internal tools over a typed, audited, multi-tenant domain |
| **Odoo** | ERP workflows on **PostgreSQL**, with a native approval-state engine |
| **Salesforce** | Multi-tenant SaaS with **data-aware authorization** and a built-in audit trail |

---

## Documentation & links

- **Documentation** — [`docs-site/docs/intro.md`](docs-site/docs/intro.md)
- **Getting started** — [`docs-site/docs/getting-started/installation.md`](docs-site/docs/getting-started/installation.md)
- **Packages** — [`docs-site/docs/packages/index.md`](docs-site/docs/packages/index.md)
- **Current release** — [Releases on GitHub](https://github.com/adonko3xBitters/ausus-framework/releases)
- **Changelog** — [`CHANGELOG.md`](CHANGELOG.md)
- **License** — [MIT](LICENSE)
