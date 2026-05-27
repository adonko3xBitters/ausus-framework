# AUSUS

> **The Laravel-native enterprise application platform.**
> Metadata-first. Plugin-first. Tenant-first. API-first.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.3-777BB4.svg)](https://www.php.net/)
[![Node](https://img.shields.io/badge/Node-%E2%89%A5%2018-339933.svg)](https://nodejs.org/)
[![React](https://img.shields.io/badge/React-18%20%7C%2019-61DAFB.svg)](https://react.dev/)
[![Version](https://img.shields.io/badge/version-0.2.0--alpha.4-blue.svg)](docs-site/docs/releases/v0.2.0-alpha.4.md)

AUSUS is a PHP framework for building enterprise apps — CRUD platforms,
ERP workflows, SaaS multi-tenant products, internal tools — from
**metadata graphs** instead of hand-rolled controllers/views. It targets
the design space currently held by **Filament**, **Laravel Nova**,
**Retool**, **Odoo**, and **Salesforce**, with a fundamentally different
substrate: a deterministic, layered, plugin-composable kernel.

---

## What ships today (v0.2.0-alpha.4)

| Package | Role | Status |
|---|---|---|
| [`ausus/kernel`](packages/kernel)              | L0 — contracts, value objects, DSL facade        | implemented |
| [`ausus/persistence-sql`](packages/persistence-sql) | L3 — SQL `PersistenceDriver` (PDO)            | implemented |
| [`ausus/runtime-default`](packages/runtime-default) | L2 — Invoker, Policy Engine, Workflow runtime | implemented |
| [`ausus/api-http`](packages/api-http)          | L4 — PSR-7/15 HTTP API surface (ViewSchema + Actions) | implemented |
| [`ausus/starter`](packages/starter)            | project template — `composer create-project`     | implemented |
| [`ausus/standard-stack`](packages/standard-stack) | metapackage pinning the V0 set                | implemented |
| [`@ausus/renderer-react`](renderer/react)      | React 18+ renderer for the RFC-004 ViewSchema    | implemented |
| `ausus/tenancy-row`, `ausus/audit-database`, `ausus/auth-bridge`, `ausus/presentation-default` | dedicated drivers / plugins | name-reserved, tagged at v0.2.0-alpha.4 (no code yet) |

Current alpha: [`RELEASE-NOTES-v0.2.0-alpha.4.md`](RELEASE-NOTES-v0.2.0-alpha.4.md). Last stable: [`RELEASE-NOTES-v0.1.1.md`](RELEASE-NOTES-v0.1.1.md). Consolidated history: [`CHANGELOG.md`](CHANGELOG.md). The v0.1.0 release-candidate notes remain available at [`RELEASE-NOTES-v0.1.0.md`](RELEASE-NOTES-v0.1.0.md).

---

## Current status (v0.2.0-alpha.4)

- **Public packaging.** The historical Packagist packaging defect that
  shipped the entire monorepo inside each `vendor/ausus/<package>`
  tarball is **fixed**. Each `ausus/*` package is now distributed from
  its own dedicated subtree-split GitHub repository under
  [`github.com/adonko3xBitters/<package>`](https://github.com/adonko3xBitters);
  Packagist pulls from those dedicated repos, not from the monorepo.
- **Install works without workaround.** `composer require
  ausus/standard-stack:^0.2@alpha` resolves cleanly to `v0.2.0-alpha.4`
  for the entire chain (`kernel`, `runtime-default`, `persistence-sql`,
  `api-http`, `standard-stack`). No manual autoload, no custom
  classmap, no monorepo extraction required. PSR-15 transitive deps
  (`psr/http-server-handler`, `psr/http-server-middleware`, …) are now
  correctly propagated.
- **Runtime hardening Phase A + B + C is distributed publicly.** The
  five typed marker interfaces in `Ausus\Errors\*` —
  `BadRequestError`, `ForbiddenError`, `NotFoundError`,
  `ConflictError`, `InternalError` — ship inside the public
  `ausus/kernel` tarball. The marker-first `ErrorMapper::classify()`
  dispatch ships inside the public `ausus/api-http` tarball. Plugin
  authors can opt their custom exceptions into the correct HTTP status
  (`400` / `403` / `404` / `409` / `500`) by implementing one of the
  five markers — no edit to `ErrorMapper` required.

---

## Runtime hardening (v0.2 alpha)

`v0.2.0-alpha.4` (current alpha) is a **stabilization line**, not yet
stable. It is purely additive on top of `v0.1.1` — no public API
rename, no wire-format change, no `schemaVersion` bump. The recommended
line for production remains `v0.1.1`.

What it adds:

- **Typed runtime exception markers.** Five marker interfaces in
  `Ausus\Errors\*` (`BadRequestError`, `ForbiddenError`, `NotFoundError`,
  `ConflictError`, `InternalError`) tag the intended HTTP status of every
  kernel exception. No methods, no abstract base classes — pure type
  metadata.
- **Marker-first HTTP error classification.** `ErrorMapper::classify()`
  dispatches on the marker interface first; the legacy short-name table
  is preserved verbatim as a back-compat fallback for out-of-tree
  consumers.
- **Plugin exception opt-in.** A custom plugin exception implementing one
  of the five markers routes to its HTTP status (`400` / `403` / `404` /
  `409` / `500`) automatically — no edit to `ErrorMapper` required.
- **Additive, back-compatible runtime hardening.** `catch (AususError $e)`
  and `catch (PolicyDenied $e)` keep matching the exact same instances as
  `v0.1.1`; the 14 existing kernel exception status codes are
  bit-identical. Pinned by `apps/playground/error-taxonomy-test.php`
  (70 assertions, CI step `4j`).

---

## 30-second quickstart

> **IMPORTANT.** AUSUS v0.2.x is currently in **alpha**. Consumers must
> declare the alpha stability in their `composer.json` until the first
> beta/stable release:
>
> ```json
> "minimum-stability": "alpha",
> "prefer-stable": true
> ```
>
> Without this flag, Composer refuses to resolve the alpha chain
> (because the inter-package `^0.2@alpha` constraints expose alpha
> dependencies transitively) and falls back to the v0.1.x stable line.

```bash
composer create-project ausus/starter myapp
cd myapp && composer boot
# → OK — ausus/starter boots cleanly.
```

```bash
mkdir consumer && cd consumer
npm init -y
npm install @ausus/renderer-react react@18 react-dom@18
```

---

## Alpha installation requirements

`ausus/*` `v0.2.x` packages are tagged with **alpha** stability on
Packagist. Consumers must opt into alpha stability at the **root** of
their `composer.json` for the install to resolve.

### Why this is necessary

Composer does **not** propagate the `@alpha` per-package flag to a
required package's own transitive dependencies. When
`ausus/standard-stack ^0.2@alpha` declares `ausus/kernel ^0.2@alpha`,
that inner constraint is evaluated against the **root**'s
`minimum-stability` — which defaults to `stable`. Without opting in,
Composer rejects the alpha chain and falls back to the `v0.1.x` stable
line.

> This is **standard Composer behaviour**, not specific to AUSUS. It
> applies identically to every pre-release in the PHP ecosystem
> (Symfony betas, Laravel previews, Doctrine RCs, …). Documenting it
> here so AUSUS first-time consumers don't lose the half-hour we lost
> finding it.

### Two equivalent setups

**Option A — declare in `composer.json` directly:**

```json
{
    "minimum-stability": "alpha",
    "prefer-stable": true
}
```

`prefer-stable: true` keeps stable versions preferred when both stable
and alpha are available for the same package. Without it, Composer
might pick alpha versions of unrelated non-`ausus/*` dependencies.

**Option B — initialize the project with the stability flag:**

```bash
composer init -n --type=project --stability=alpha
```

`composer init --stability=alpha` writes `minimum-stability: alpha`
into the generated `composer.json`. Then add `"prefer-stable": true`
manually (or via `composer config prefer-stable true`).

### Validated install procedure

```bash
composer init -n --type=project --stability=alpha
composer require ausus/standard-stack:^0.2@alpha
```

This exact sequence is exercised end-to-end by
[`scripts/public-install.sh`](scripts/public-install.sh) (CI step
`11`), which fails the build if anything in the chain — Packagist
indexing, tarball structure, autoload, or smoke functionality —
regresses.

---

## Verified public install

Reproduces the canonical clean-room install of `v0.2.0-alpha.4` end to
end. No local monorepo, no path repositories, no symlinks — exactly
what an external consumer sees from Packagist.

```bash
composer init -n
composer require ausus/standard-stack:^0.2@alpha
```

Sanity smoke (creates a working `Application` against SQLite):

```php
<?php
require 'vendor/autoload.php';

use Ausus\Application;
use Ausus\ApplicationConfig;

$app = Application::create(
    ApplicationConfig::make()
        ->tenant('acme')
        ->actor('demo')
        ->sqlite('/tmp/ausus.sqlite')
);

var_dump($app instanceof Application);
// → bool(true)
```

The same procedure pulls every kernel, runtime, persistence and HTTP
class at `v0.2.0-alpha.4`, including the five `Ausus\Errors\*` marker
interfaces and the marker-first `Ausus\Api\Http\ErrorMapper`.

---

## What changed since v0.1.x

- **Standard Stack facade.** `Ausus\Application` +
  `Ausus\ApplicationConfig` collapse manual `Invoker` wiring into a
  four-call lifecycle (`create → register → boot → invoke`), with a
  one-call PSR-7 entry point via `Application::http()`.
- **HTTP API package.** `ausus/api-http` ships a PSR-7/15 `Router`
  with three real routes (`GET /_health`, `GET /projections/{fqn}`,
  `POST /actions/{fqn}`), a typed error envelope, and `ErrorMapper`
  with marker-first HTTP classification (Phase C).
- **Workflow explicit-state deprecation warning.** Implicit workflow
  inference ("first enum with default wins") now emits
  `E_USER_DEPRECATED`; the supported form is explicit
  `EntityBuilder::workflow(field:, initial:)`.
- **Public Packagist packaging fix.** Each `ausus/*` package is now
  distributed from its own dedicated subtree-split repository
  (`github.com/adonko3xBitters/<package>`); the previous
  monorepo-in-tarball defect is resolved end-to-end.
- **Runtime error taxonomy hardening.** Five marker interfaces in
  `Ausus\Errors\*` tag the intended HTTP status of every kernel
  exception; `ErrorMapper` dispatches on the marker first, with the
  legacy short-name table preserved verbatim as a back-compat
  fallback.

---

## Compatibility matrix

| Version | Status | Packaging | Runtime hardening |
|---|---|---|---|
| `v0.1.0` | legacy | broken historical tarballs (monorepo-in-tarball defect) | none |
| `v0.1.1` | legacy | broken historical tarballs (same defect) | partial |
| `v0.2.0-alpha.1` | obsolete | broken (same defect) | Phase A + B (declared, undelivered) |
| `v0.2.0-alpha.2` | fixed packaging | dedicated subtree-split repos active; internal `^0.2@alpha` constraints not yet bumped | Phase A + B + C (server-side) |
| `v0.2.0-alpha.4` | **current alpha** | fully fixed (subtree-split + Packagist source + internal constraint propagation) | Phase A + B + C (fully distributed) |

`v0.1.x` remains the **recommended line for production** until v0.2.0
stable ships. Consumers wanting the runtime hardening should install
the `v0.2.0-alpha.4` line per [Alpha installation
requirements](#alpha-installation-requirements). Versions marked
*legacy* / *obsolete* are kept in Packagist's index for historical
traceability — do not pull them for a new install.

---

## Architecture in one diagram

```
L7  Plugins              ← user-authored domain logic
L6  Renderer (React)     ← @ausus/renderer-react
L5  Presentation         ← ProjectionRenderer → ViewSchema (RFC-004)
L4  API Surface
L3  Drivers              ← persistence-sql, tenancy, audit, …
L2  Runtime              ← Invoker chain (Tenant → Policy → Workflow → Effect → Audit)
L1  Compiler             ← MetadataGraph synthesis from Plugins
L0  Kernel               ← contracts, value objects, DSL facade
```

Each layer has a stable contract. Layers below never depend on layers
above. Plugins compose by declaration; the Compiler produces a
deterministic, content-addressable `MetadataGraph`.

---

## Documentation

| Document | What it covers |
|---|---|
| [`RELEASE-NOTES-v0.2.0-alpha.4.md`](RELEASE-NOTES-v0.2.0-alpha.4.md) | current alpha — public Packagist packaging fix, internal `^0.2@alpha` constraint bumps, full Phase A+B+C runtime hardening distributed |
| [`RELEASE-NOTES-v0.1.1.md`](RELEASE-NOTES-v0.1.1.md) | last stable — v0.1.x stabilisation, breaking changes, migration |
| [`CHANGELOG.md`](CHANGELOG.md) | consolidated changelog (Keep a Changelog) |
| [`RELEASE-NOTES-v0.1.0.md`](RELEASE-NOTES-v0.1.0.md) | initial release-candidate notes (v0.1.0) — packages, compatibility, publish order, rollback |
| [`docs/PUBLICATION-READINESS.md`](docs/PUBLICATION-READINESS.md) | publication audit |
| [`docs/L4-API-DESIGN.md`](docs/L4-API-DESIGN.md) | L4 HTTP API design + integration evidence |
| [`docs/RFC-000-v0r2-remediation.md`](docs/RFC-000-v0r2-remediation.md) | Node-ESM + clean-room remediation pass |
| [`docs/RENDERER-REACT-V0-REAL-PASS.md`](docs/RENDERER-REACT-V0-REAL-PASS.md) | React renderer V0 evidence |
| [`docs/COMPILER-DESIGN.md`](docs/COMPILER-DESIGN.md), [`docs/PERSISTENCE-SQL-DESIGN.md`](docs/PERSISTENCE-SQL-DESIGN.md), [`docs/RUNTIME-DEFAULT-DESIGN.md`](docs/RUNTIME-DEFAULT-DESIGN.md), [`docs/RENDERER-REACT-DESIGN.md`](docs/RENDERER-REACT-DESIGN.md) | per-package design docs |
| [`rfcs/`](rfcs/) | 14 architectural RFCs (kernel, persistence, tenancy, ViewSchema, policy engine, workflow, audit, reporting, DSL, standard stack, action effect, authorization) |

---

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md) — covers branching strategy,
conventional commits, release process, and the local validation gates.

Quickstart for contributors:

```bash
git clone https://github.com/adonko3xBitters/ausus-framework.git
cd ausus-framework
composer install     # workspace install via path repositories
npm install          # workspace install
bash scripts/ci.sh   # 9-step validation gate
```

---

## Security

For security disclosures, see [`SECURITY.md`](SECURITY.md). Do not
report vulnerabilities via public issues.

---

## License

[MIT](LICENSE) © 2026 AUSUS Framework Contributors
