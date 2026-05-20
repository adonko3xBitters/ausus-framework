---
id: installation
title: Installation
sidebar_label: Installation
description: Requirements and install paths for AUSUS v0.1.0.
---

# Installation

AUSUS v0.1.0 has two halves you can install independently: the **PHP backend**
packages and the **React renderer** npm package.

## Requirements {#requirements}

| Layer | Tool | Minimum | Tested with |
|---|---|---|---|
| Runtime (PHP) | `php` | 8.3 | 8.4.18 |
| Runtime (PHP) | `ext-pdo`, `ext-pdo_sqlite` | bundled | bundled |
| Tooling (PHP) | `composer` | 2.0 | 2.9.5 |
| Runtime (JS) | `node` | 18 | 22.x |
| Tooling (JS) | `npm` | 8 | 10.x |
| Runtime (JS) | `react`, `react-dom` | ^18 \|\| ^19 | 18.3.1 |

AUSUS does **not** require the Laravel framework, Eloquent, Filament,
Tailwind, a bundler, or any UI component library. Persistence in v0.1.0 uses
the bundled SQLite PDO driver.

## Option A — start from the project template {#option-a--start-from-the-project-template}

The fastest path. `ausus/starter` is a ready-to-run project that already wires
the kernel, persistence, runtime, and a sample domain together.

```bash
composer create-project ausus/starter myapp
cd myapp
composer boot
```

Expected output:

```
ausus/starter boot
  ✓ compiled graph (hash …)
  ✓ schema applied
  ✓ created invoice id=…
  ✓ issued invoice (DRAFT → ISSUED)
  ✓ rendered summary projection (items=1)
OK — ausus/starter boots cleanly.
```

If you used `--no-install`, finish with `composer install && composer boot`.

## Option B — add packages to an existing project {#option-b--add-packages-to-an-existing-project}

Install only the packages you need. The dependency order is bottom-up:

```bash
composer require ausus/kernel
composer require ausus/runtime-default
composer require ausus/persistence-sql
composer require ausus/api-http        # optional — HTTP API surface
```

Or pin the whole validated v0.1.0 set with the metapackage:

```bash
composer require ausus/standard-stack
```

## Option C — build from source (monorepo) {#option-c--build-from-source-monorepo}

Use this to read the code, run the validation gates, or contribute.

```bash
git clone https://github.com/adonko3xBitters/ausus-framework.git
cd ausus-framework
composer install     # workspace install via path repositories
npm install           # workspace install
bash scripts/ci.sh    # full validation gate
```

`scripts/ci.sh` runs a 10-step gate and ends with:

```
[ci] DONE — all 10 steps passed
```

## The React renderer {#the-react-renderer}

The renderer is a separate npm package. `react` and `react-dom` are **peer
dependencies** — you install them yourself.

```bash
npm install @ausus/renderer-react react@18 react-dom@18
# React 19 also works:
# npm install @ausus/renderer-react react@^19 react-dom@^19
```

The package is **ESM-only** (`"type": "module"`, NodeNext resolution). It ships
no bundled dependencies and no CSS file — see
[The React renderer](../frontend/react-renderer.md).

## Current v0.1.0 limitations {#current-v010-limitations}

- **Persistence is validated on SQLite only.** MySQL/PostgreSQL drivers are a
  design goal, not a tested v0.1.0 capability.
- `composer create-project ausus/starter` is a 2-command flow
  (`create-project` then `composer boot`); with `--no-install` it becomes a
  3-command flow.
- The reserved packages (`tenancy-row`, `audit-database`, `auth-bridge`,
  `presentation-default`) are **not installable as working code** — they are
  name reservations. See [Packages](../packages/index.md).

## Next {#next}

- [Your first app](first-app.md) — wire the layers together by hand.
- [HelloInvoice tutorial](hello-invoice.md) — a full domain, end to end.
