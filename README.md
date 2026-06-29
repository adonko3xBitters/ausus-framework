<p align="center">
  <img src="docs-site/static/img/logo.svg" alt="AUSUS" width="120" />
</p>

<h1 align="center">AUSUS</h1>

<p align="center"><strong>Compile immutable metadata into running applications.</strong></p>

<p align="center">
  <a href="https://github.com/adonko3xBitters/ausus-framework/releases"><img src="https://img.shields.io/badge/version-2.0.0-brightgreen.svg" alt="Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="License"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/php-8.3%2B-777bb4.svg" alt="PHP"></a>
  <a href="https://nodejs.org/"><img src="https://img.shields.io/badge/node-18%2B-339933.svg" alt="Node"></a>
</p>

---

## Introduction

AUSUS is a **metadata-first** PHP framework. You declare an application as
data — entities, fields, actions, projections, and authorization rules — and the
**Entity Engine** compiles that declaration into a frozen, content-addressed
schema and runs it: persistence, data-aware authorization, an HTTP API, and a
React UI.

You describe *what* the application is. The engine provides the *how*, once,
centrally — so the database, the API, and the UI are all derived from one source
of truth and cannot drift apart.

## Why AUSUS

Business applications keep re-implementing the same machinery — storage mapping,
per-record authorization, read shapes, state transitions, multi-tenancy, and a
UI — once per domain, by hand, each team getting a different subset wrong.

AUSUS replaces that with a **declared domain** and one engine that compiles and
runs it:

- **Immutable Entity Definitions** — a definition compiles to an `EntitySchema`
  keyed by a hash of its canonical form. Same semantics ⇒ same hash; the runtime
  never recompiles.
- **Data-aware, fail-closed authorization** — rules read `actor` / `tenant` /
  `subject` / `input`; an unresolved fact denies.
- **Tenant-first, driver-agnostic** — every entity is tenant-scoped; the runtime
  depends only on a persistence-driver contract, never on a concrete store.
- **One contract, three surfaces** — the same compiled domain drives persistence,
  a framework-agnostic HTTP API, and an HTTP-only React renderer.

## Installation

PHP 8.3+ (and Node 18+ for the React renderer):

```bash
composer require ausus/cli:^2.0 ausus/api-runtime:^2.0 ausus/persistence-memory:^2.0
npm install @ausus/react-renderer react react-dom
```

`ausus/cli` pulls `ausus/kernel`, `ausus/authoring`, and `ausus/entity-engine`.

## First example

Declare an entity with the **Authoring DSL** (`entities/Customer.php`):

```php
<?php
use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\Enum\ActionKind;

return Definition::make('customer', true)
    ->field('name', FieldType::String)
    ->field('status', FieldType::Enum, ['default' => 'inactive',
        'typeOptions' => ['values' => ['active', 'inactive']]])
    ->action('create', ActionKind::Create, ['inputs' => ['name'],
        'guard' => Expr::eq(Expr::actor('type'), 'user')])
    ->action('activate', ActionKind::Transition,
        ['transition' => ['field' => 'status', 'from' => 'inactive', 'to' => 'active']])
    ->projection('board', ['fields' => [['field' => 'name'], ['field' => 'status']]])
    ->build();
```

Compile it (content-addressed), bind it to a driver, and run it — the full,
runnable walkthrough is the **[Quick Start](docs/v2/QUICKSTART.md)** (under five
minutes, from `composer require` to a rendered UI).

## Architecture

A declared domain flows through one pipeline:

```
Definition      closed PHP DSL → one EntityDefinition
   ↓
Compiler        canonical normal form + SHA-256 content hash (atomic)
   ↓
EntitySchema    frozen, content-addressed (.ausus/schemas/<hash>.json)
   ↓
Runtime         EntityEngine::bind(schema, driver) → RuntimeEntity (invoke / read)
   ↓
API Runtime     resolve → bind → invoke/read, returning { status, body }
   ↓
React Renderer  discovers entities/projections/actions from the HTTP contract only
```

Layers depend only on layers below them; the kernel has zero dependencies. See
**[Architecture](docs/v2/02-architecture.md)**.

## Packages

| Layer | Package | Role |
|---|---|---|
| L0 | [`ausus/kernel`](packages/kernel) | Entity model, runtime contracts, compiled form (Definition / Contracts / Compiled) |
| L1 | [`ausus/entity-engine`](packages/entity-engine) | Content-addressed Compiler + bind/runtime |
| L1 | [`ausus/authoring`](packages/authoring) | Closed PHP DSL producing an `EntityDefinition` |
| L3 | [`ausus/persistence-memory`](packages/persistence-memory) | Reference in-memory `PersistenceDriver` |
| L4 | [`ausus/api-runtime`](packages/api-runtime) | HTTP API runtime |
| L5 | [`ausus/view-system`](packages/view-system) | `ViewDefinition` presentation metadata |
| L5 | [`@ausus/react-renderer`](packages/react-renderer) | React renderer over the HTTP contract |
| L6 | [`ausus/cli`](packages/cli) | DSL frontend and the `ausus compile` command |

## Documentation

The canonical reference is **[`docs/v2/`](docs/v2/README.md)**:

- [Quick Start](docs/v2/QUICKSTART.md) · [Introduction](docs/v2/01-introduction.md) · [Architecture](docs/v2/02-architecture.md) · [Pipeline](docs/v2/03-pipeline.md)
- [Capabilities](docs/v2/06-capabilities.md) · [Known limits](docs/v2/07-known-limits.md)
- [Product vision](PRODUCT-VISION-AUSUS-2.0.md) · [Release notes](RELEASE-NOTES-v2.0.0.md) · [Changelog](CHANGELOG.md)

## Examples

Three reference applications, each built only from the DSL and view metadata,
with no change to the framework:

- **[CRM](apps/crm)** — 5 entities.
- **[Teranga PMS](apps/teranga-pms)** — a hotel property-management system, 10 entities.
- **[SGH](apps/sgh)** — a hospital system, 12 entities, data-aware authorization across actor/tenant/subject/input.

## Roadmap

AUSUS 2.0 is a stable, frozen kernel: the seven concepts and the L0 contracts do
not change within the major version. The current model's boundaries are
documented openly in **[Known limits](docs/v2/07-known-limits.md)** (single-hop
expand, single-field transitions, no cross-entity invariants, …) — stated as
boundaries, not as promises. Contributions that respect the frozen contracts are
welcome.

## Contributing

See **[CONTRIBUTING.md](CONTRIBUTING.md)** and the
[Code of Conduct](CODE_OF_CONDUCT.md). Issues and pull requests are welcome.

## License

[MIT](LICENSE).
