---
id: sample-apps
title: Sample apps
sidebar_label: Sample apps
description: Production-style and demo applications that ship in the AUSUS monorepo — what each exercises and where to read further.
---

# Sample apps

Three runnable applications live in the AUSUS monorepo. Each exercises
a different slice of the framework; all three are part of the CI gate
(`scripts/ci.sh`).

## `apps/issue-tracker` — production-style sample {#issue-tracker}

A small but realistic ticket-tracking app built **only with implemented
v0.1.x capabilities**. Three entities, two workflows, eight built-in
actions plus four `Action::update(...)` actions, five projections, a
React UI built on the renderer.

- **Read first:** [`apps/issue-tracker/README.md`](https://github.com/adonko3xBitters/ausus-framework/blob/main/apps/issue-tracker/README.md) for the layout and quickstart.
- **Architecture:** [`apps/issue-tracker/ARCHITECTURE.md`](https://github.com/adonko3xBitters/ausus-framework/blob/main/apps/issue-tracker/ARCHITECTURE.md) for the layered view and request lifecycle.
- **Findings report:** [`apps/issue-tracker/FRAMEWORK-FINDINGS.md`](https://github.com/adonko3xBitters/ausus-framework/blob/main/apps/issue-tracker/FRAMEWORK-FINDINGS.md) for the honest catalogue of v0.1.x friction discovered while building it (most items now marked **FIXED** as the stabilisation tasks landed).
- **Smoke test:** `apps/issue-tracker/tests/smoke.php` — 37 assertions wired into CI step `4g`. Covers create, transitions, the three update actions, workflow gating, policy denial, and the ViewSchema-shape contract.

### What it exercises

| Surface | Used by the issue tracker |
|---|---|
| [`Ausus\Application`](../reference/application.md) + [`ApplicationConfig`](../reference/application.md#applicationconfig) | `bin/seed.php`, `public/server.php`, `tests/smoke.php` |
| [`Application::http()`](../reference/application.md#http) | `public/server.php` is 22 lines including the autoload shim |
| [`Action::update(...)`](../backend/php-dsl.md#action-kinds) | `tracker.issue.{rename,reassign,edit}` and `tracker.project.edit` |
| [`Field::*()->label(...)`](../backend/php-dsl.md) | `project_id → "Project"`, `issue_id → "Issue"`, `resolved_at → "Resolved"` |
| [Workflow](../concepts/workflows.md) | `tracker.issue.lifecycle` (TODO → DOING → REVIEW → DONE; `*` → WONTFIX) |
| [Policies](../concepts/policies.md) | `tracker.member` / `tracker.admin` / `tracker.viewer` |
| [@ausus/renderer-react](../frontend/react-renderer.md) | Vite + React 18/19 UI under `ui/` |
| [HTTP API](../reference/http-routes.md) | `php -S` front controller; custom fetcher injects `X-Actor-Roles` |

### Run it (monorepo)

```bash
php apps/issue-tracker/tests/smoke.php           # → RESULT: passed=37 failed=0
php apps/issue-tracker/bin/seed.php              # writes tracker.sqlite
php -S 127.0.0.1:8787 -t apps/issue-tracker/public apps/issue-tracker/public/server.php
( cd apps/issue-tracker/ui && npm install && npm run dev )
```

The UI runs at `http://localhost:5173`; the API at
`http://localhost:8787/api`.

## `packages/starter` + HelloInvoice — minimal template {#starter}

The `composer create-project ausus/starter` template — the recommended
starting point for a brand-new consumer. Ships:

- The same `HelloInvoice` domain the [tutorial](../tutorial/index.md) walks through, in both manual-plugin (`HelloInvoice.php`) and DSL (`HelloInvoiceDsl.php`) forms (compiled-graph hash is byte-identical between the two — asserted by playground test 10).
- A 50-line `bin/boot.php` that uses `Application::create()->register()->boot()` end-to-end.
- CI step `5` runs `composer boot` against this template on every push.

Useful when learning the framework: the manual plugin shows what the
DSL macros expand to on the kernel.

## `apps/playground` — internal test harness {#playground}

The monorepo's own kitchen sink. **Not** a consumer-facing sample — it
exists so the CI gate can prove every public API against a single
domain in PHP and JavaScript:

| File | What it proves | CI step |
|---|---|---|
| `apps/playground/run.php` | End-to-end persistence + workflow + audit + DSL-vs-manual hash parity | `4` (36 assertions) |
| `apps/playground/application-smoke.php` | `Application` lifecycle, lazy boot, manual `Invoker` parity | `4b` (23) |
| `apps/playground/workflow-test.php` | Explicit workflow declaration + deprecation warnings | `4c` (17) |
| `apps/playground/api-consistency-test.php` | Public-API consistency pass, label propagation | `4d` (50) |
| `apps/playground/config-builder-test.php` | `ApplicationConfig` fluent immutable contract | `4e` (44) |
| `apps/playground/application-http-test.php` | `Application::http()` + fail-closed actor resolution | `4f` (31) |
| `apps/playground/null-roundtrip-test.php` | Nullable column round-trip (write → SQL → read → JSON) | `4h` (30) |
| `apps/playground/update-action-test.php` | `Action::update(...)` + `UpdateEffect` (ADR-0002 implementation) | `4i` (36) |
| `apps/playground/web/render-trace.tsx` | Server-side rendering of every renderer component + helper unit tests | `8` (32) |
| `apps/playground/web/live-trace.tsx` | Live PSR-7 round-trip from the React renderer to the PHP runtime | `10` (14) |

Read these when you want a focused, copy-pastable example of one
specific public API.

## CI alignment {#ci}

`scripts/ci.sh` runs every sample app's smoke as a numbered step. A
green `[ci] DONE` is the framework's release-readiness signal:

```
[ci] step 4   playground         36/36
[ci] step 4b  application-smoke  23/23
[ci] step 4c  workflow-test      17/17
[ci] step 4d  api-consistency    50/50
[ci] step 4e  config-builder     44/44
[ci] step 4f  application-http   31/31
[ci] step 4g  issue-tracker      37/37
[ci] step 4h  null-roundtrip     30/30
[ci] step 4i  update-action      36/36
[ci] step 5   composer boot      OK
[ci] step 7   renderer build     OK
[ci] step 8   render-trace       32/32
[ci] step 10  integration-http   14/14
```

## Next {#next}

- [Tutorial — Build a Ticket System](../tutorial/index.md) — the 7-part guided build that culminates in the `apps/issue-tracker`-shaped app.
- [Your first app](first-app.md) — the smallest possible `Application` bootstrap.
- [HelloInvoice tutorial](hello-invoice.md) — the same flow with the starter's reference domain.
