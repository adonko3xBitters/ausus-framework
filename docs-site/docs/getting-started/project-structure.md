---
id: project-structure
title: Project Structure
sidebar_label: Project Structure
description: How the AUSUS monorepo and a generated app are laid out.
---

# Project Structure

There are two layouts worth knowing: the **monorepo** (where AUSUS itself is
developed) and a **consuming application** (what you build).

## The monorepo {#the-monorepo}

The framework is developed as a single repository with one publishable package
per directory.

```
ausus-framework/
├── packages/                 one Composer package per directory
│   ├── kernel/               L0 — contracts, value objects, DSL, Compiler
│   ├── persistence-sql/      L3 — SQLite PersistenceDriver
│   ├── runtime-default/      L2 — Invoker chain
│   ├── api-http/             L4 — PSR-7/15 HTTP API
│   ├── standard-stack/       metapackage — pins the v0.1.0 set
│   ├── starter/              project template
│   ├── tenancy-row/          reserved name — no code in v0.1.0
│   ├── audit-database/       reserved name — no code in v0.1.0
│   ├── auth-bridge/          reserved name — no code in v0.1.0
│   └── presentation-default/ reserved name — no code in v0.1.0
├── renderer/
│   └── react/                @ausus/renderer-react (npm package)
├── apps/
│   └── playground/           end-to-end runner + live HTTP trace
├── docs/                     design docs + operational runbooks
├── docs-site/                this documentation site (Docusaurus)
├── rfcs/                     architectural RFCs
└── scripts/                  ci.sh, clean-room.sh, integration-http.sh
```

Each package directory holds `composer.json`, `src/`, `README.md`,
`CHANGELOG.md`, and `LICENSE`. The four reserved packages contain a
`composer.json` and `README.md` but **no `src/` PHP code**.

### Validation gates {#validation-gates}

| Script | What it runs |
|---|---|
| `scripts/ci.sh` | 10-step gate: validate, install, playground, boot, build, trace, pack, HTTP integration |
| `scripts/clean-room.sh` | 8-step isolated rebuild in a temp directory |
| `scripts/integration-http.sh` | 12 live-HTTP assertions against `php -S` + the renderer |
| `apps/playground/run.php` | 36 end-to-end assertions |

## A consuming application {#a-consuming-application}

A project created from `ausus/starter` is a normal Composer project:

```
myapp/
├── composer.json             requires ausus/kernel, persistence-sql, runtime-default
├── bin/
│   └── boot.php              the `composer boot` end-to-end smoke script
├── src/
│   ├── HelloInvoice.php      sample plugin — hand-written descriptor form
│   └── HelloInvoiceDsl.php   sample plugin — DSL form
└── vendor/
```

Your own application replaces the sample plugins with your domain plugins and
replaces `bin/boot.php` with your real entry point (a CLI command, an HTTP
front controller using [ausus/api-http](../backend/http-api.md), etc.).

## Where domain code lives {#where-domain-code-lives}

AUSUS does not impose a directory convention on your domain code. A plugin is
an ordinary PHP class that extends `DslPlugin` (or implements `Plugin`). Put it
wherever your autoloader can find it. The only hard rule is that a plugin
declares a `phpNamespace()` and a `name()` — see [Plugins](../concepts/plugins.md).

## Naming conventions {#naming-conventions}

- **Entity FQN** — `{plugin name}.{entity local name}`, e.g. `billing.invoice`.
- **Action FQN** — `{entity FQN}.{action local name}`, e.g. `billing.invoice.issue`.
- **Projection FQN** — `{entity FQN}.{projection local name}`, e.g.
  `billing.invoice.summary`.
- **SQL table name** — the entity FQN with dots replaced by underscores, e.g.
  `billing_invoice`.

## Next {#next}

- [Plugins](../concepts/plugins.md) — the unit of domain code.
- [Packages](../packages/index.md) — what each package contains.
