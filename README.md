# AUSUS

[![Version](https://img.shields.io/badge/version-2.0.0-brightgreen.svg)](https://github.com/adonko3xBitters/ausus-framework/releases)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-8.3%2B-777bb4.svg)](https://www.php.net/)
[![Node](https://img.shields.io/badge/node-18%2B-339933.svg)](https://nodejs.org/)

**AUSUS is a metadata-first platform for building business applications.** You
declare an application as data — entities, fields, actions, projections, and
authorization rules — and the **Entity Engine** compiles that declaration to a
frozen, content-addressed schema and runs it: persistence, data-aware
authorization, an HTTP API, and a React UI.

You describe *what* the application is. The engine provides the *how*, once,
centrally.

- **Metadata-first** — the application is data (`EntityDefinition`), not glue code.
- **Content-addressed compilation** — a definition compiles to an `EntitySchema`
  keyed by a hash of its canonical form. Same semantics ⇒ same hash; the runtime
  never recompiles.
- **Data-aware, fail-closed authorization** — rules read `actor` / `tenant` /
  `subject` / `input`; an unresolved fact denies.
- **Tenant-first, driver-agnostic** — every entity is tenant-scoped; the runtime
  depends only on a persistence-driver contract, never on a concrete store.

Validated by three reference applications under [`apps/`](apps/) — a CRM, a hotel
PMS (Teranga), and a hospital system (SGH) — each built only from the DSL and
view metadata, with no change to the framework.

---

## Quick Start

The fastest path from `composer require` to a rendered UI, outside the monorepo:

**→ [`docs/v2/QUICKSTART.md`](docs/v2/QUICKSTART.md)**

It walks through authoring a first `EntityDefinition`, compiling it, running it,
exposing the HTTP API, and rendering it with React.

---

## Why AUSUS

Business applications keep re-implementing the same machinery — storage mapping,
per-record authorization, read shapes, state transitions, multi-tenancy, and a
UI — once per domain, by hand, each team getting a different subset wrong.

AUSUS replaces that with a declared domain and one engine that compiles and runs
it. Instead of coding controllers, ORM models, and templates, you declare the
entity once; the same compiled domain drives persistence, the HTTP API, and the
UI, so they cannot drift apart. Authorization is a declared predicate over the
data, not custom code; multi-tenancy is structural, not a `where tenant_id = ?`
you can forget.

That is what *metadata-first* means here: the application is a value the engine
executes, not a program you maintain.

---

## Installation

PHP 8.3+ (and Node 18+ for the React renderer):

```bash
composer require ausus/cli:^2.0 ausus/api-runtime:^2.0 ausus/persistence-memory:^2.0
```

`ausus/cli` pulls `ausus/kernel`, `ausus/authoring`, and `ausus/entity-engine`;
`ausus/api-runtime` and `ausus/persistence-memory` add the HTTP runtime and the
reference driver. Add `ausus/view-system:^2.0` if you assemble views.

For the React UI:

```bash
npm install @ausus/react-renderer react react-dom
```

The full, runnable walkthrough is in the [Quick Start](docs/v2/QUICKSTART.md).

---

## Architecture

A declared domain flows through one pipeline, from authored DSL to a rendered UI:

```
Definition      closed PHP DSL → one EntityDefinition
   ↓
Compiler        canonical normal form + SHA-256 content hash (atomic)
   ↓
EntitySchema    frozen, content-addressed (.ausus/schemas/<hash>.json)
   ↓
Runtime         EntityEngine::bind(schema, driver) → RuntimeEntity (invoke / read)
   ↓
HTTP API        resolve → bind → invoke/read, returning { status, body }
   ↓
React Renderer  discovers entities/projections/actions from the HTTP contract only
```

The View System (`ViewDefinition`) assembles presentation metadata for the
renderer. Layers depend only on layers below them; the kernel has zero
dependencies. See [`docs/v2/02-architecture.md`](docs/v2/02-architecture.md).

---

## Packages

The AUSUS 2.0 (Entity Engine) line:

| Layer | Package | Role |
|---|---|---|
| L0 | [`ausus/kernel`](packages/kernel) | Field/Entity/Action/Projection model, runtime contracts, compiled form (Definition / Contracts / Compiled) |
| L1 | [`ausus/entity-engine`](packages/entity-engine) | Content-addressed compile pipeline + bind/runtime |
| L1 | [`ausus/authoring`](packages/authoring) | Closed PHP DSL producing an `EntityDefinition` |
| L3 | [`ausus/persistence-memory`](packages/persistence-memory) | Reference in-memory `PersistenceDriver` |
| L4 | [`ausus/api-runtime`](packages/api-runtime) | HTTP API runtime (resolve → bind → invoke/read) |
| L5 | [`ausus/view-system`](packages/view-system) | `ViewDefinition` presentation metadata |
| L5 | [`@ausus/react-renderer`](packages/react-renderer) | Generic React renderer over the HTTP contract |
| L6 | [`ausus/cli`](packages/cli) | DSL frontend and the `ausus compile` command |

---

## Documentation

The canonical reference for AUSUS 2.0 is **[`docs/v2/`](docs/v2/README.md)**:

- [Quick Start](docs/v2/QUICKSTART.md) — first project, outside the monorepo.
- [Architecture](docs/v2/02-architecture.md) — the L0 → L6 layering.
- [Capabilities](docs/v2/06-capabilities.md) — actions, guards, expand, views, API, React.
- [Known limits](docs/v2/07-known-limits.md) — documented honestly.
- [Product vision](PRODUCT-VISION-AUSUS-2.0.md) · [Release notes](RELEASE-NOTES-v2.0.0.md) · [Changelog](CHANGELOG.md)

---

## The 1.x line

AUSUS 1.x — the `standard-stack` lineage (`ausus/standard-stack`, `ausus/api-http`,
`ausus/runtime-default`, `@ausus/renderer-react`) — remains in this repository and
is maintained. It uses a distinct package set and namespaces. **AUSUS 2.0, the
Entity Engine, is the primary line going forward.** For how the two generations
relate, see [`docs/history/`](docs/history/README.md).

---

License: [MIT](LICENSE).
