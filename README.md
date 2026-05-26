# AUSUS

> **The Laravel-native enterprise application platform.**
> Metadata-first. Plugin-first. Tenant-first. API-first.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.3-777BB4.svg)](https://www.php.net/)
[![Node](https://img.shields.io/badge/Node-%E2%89%A5%2018-339933.svg)](https://nodejs.org/)
[![React](https://img.shields.io/badge/React-18%20%7C%2019-61DAFB.svg)](https://react.dev/)
[![Version](https://img.shields.io/badge/version-0.2.0--alpha.1-blue.svg)](RELEASE-NOTES-v0.2.0-alpha.1.md)

AUSUS is a PHP framework for building enterprise apps — CRUD platforms,
ERP workflows, SaaS multi-tenant products, internal tools — from
**metadata graphs** instead of hand-rolled controllers/views. It targets
the design space currently held by **Filament**, **Laravel Nova**,
**Retool**, **Odoo**, and **Salesforce**, with a fundamentally different
substrate: a deterministic, layered, plugin-composable kernel.

---

## What ships today (v0.2.0-alpha.1)

| Package | Role | Status |
|---|---|---|
| [`ausus/kernel`](packages/kernel)              | L0 — contracts, value objects, DSL facade        | implemented |
| [`ausus/persistence-sql`](packages/persistence-sql) | L3 — SQL `PersistenceDriver` (PDO)            | implemented |
| [`ausus/runtime-default`](packages/runtime-default) | L2 — Invoker, Policy Engine, Workflow runtime | implemented |
| [`ausus/api-http`](packages/api-http)          | L4 — PSR-7/15 HTTP API surface (ViewSchema + Actions) | implemented |
| [`ausus/starter`](packages/starter)            | project template — `composer create-project`     | implemented |
| [`ausus/standard-stack`](packages/standard-stack) | metapackage pinning the V0 set                | implemented |
| [`@ausus/renderer-react`](renderer/react)      | React 18+ renderer for the RFC-004 ViewSchema    | implemented |
| `ausus/tenancy-row`, `ausus/audit-database`, `ausus/auth-bridge`, `ausus/presentation-default` | dedicated drivers / plugins | name-reserved, ship in v0.2.0 |

Current alpha: [`RELEASE-NOTES-v0.2.0-alpha.1.md`](RELEASE-NOTES-v0.2.0-alpha.1.md). Last stable: [`RELEASE-NOTES-v0.1.1.md`](RELEASE-NOTES-v0.1.1.md). Consolidated history: [`CHANGELOG.md`](CHANGELOG.md). The v0.1.0 release-candidate notes remain available at [`RELEASE-NOTES-v0.1.0.md`](RELEASE-NOTES-v0.1.0.md).

---

## Runtime hardening (v0.2 alpha)

`v0.2.0-alpha.1` is a **stabilization line**, not yet stable. It is purely
additive on top of `v0.1.1` — no public API rename, no wire-format change,
no `schemaVersion` bump. The recommended line for production remains
`v0.1.1`.

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
| [`RELEASE-NOTES-v0.2.0-alpha.1.md`](RELEASE-NOTES-v0.2.0-alpha.1.md) | current alpha — typed runtime error markers, marker-first HTTP classification |
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
