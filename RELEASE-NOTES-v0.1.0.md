# AUSUS v0.1.0 — Release Notes

**Release date:** 2026-05-19
**Release type:** Release Candidate / initial public release
**Status:** **PUBLICATION HOLD** — see §6.

> **Code and artifacts are release-candidate ready.** Public publication
> to Packagist and npm is **on HOLD** until every **P0 pre-flight control**
> in [`docs/PUBLICATION-RUNBOOK.md`](docs/PUBLICATION-RUNBOOK.md) §2 passes.
>
> **Do not run ad-hoc publish commands from this document.** The full,
> normative publication procedure lives in `docs/PUBLICATION-RUNBOOK.md` —
> that runbook is authoritative.

---

## 1. Summary

First public release of the AUSUS framework — a metadata-first,
plugin-first, Laravel-native enterprise application platform. This
release ships:

- **4 implemented PHP libraries** — `kernel`, `persistence-sql`, `runtime-default`, `api-http`
- **1 PHP project template** — `starter`
- **1 PHP metapackage** — `standard-stack` (pins the V0 set)
- **4 reserved PHP names** — `tenancy-row`, `audit-database`, `auth-bridge`, `presentation-default` (name reservations only — no source code)
- **1 npm package** — `@ausus/renderer-react` (React 18 / 19 renderer for the RFC-004 ViewSchema)

All **11 publishable packages are at version 0.1.0** (10 Composer + 1 npm).

## 2. Packages in this release

### Composer (10)

| # | Package | Type | Implementation | `ausus/*` deps |
|---|---|---|---|---|
| 1  | `ausus/kernel`               | library     | full | — |
| 2  | `ausus/persistence-sql`      | library     | full | kernel |
| 3  | `ausus/runtime-default`      | library     | full | kernel |
| 4  | `ausus/api-http`             | library     | full (L4 PSR-7/15) | kernel, runtime-default |
| 5  | `ausus/tenancy-row`          | library     | name reservation | — |
| 6  | `ausus/audit-database`       | library     | name reservation | — |
| 7  | `ausus/auth-bridge`          | library     | name reservation | — |
| 8  | `ausus/presentation-default` | library     | name reservation | — |
| 9  | `ausus/standard-stack`       | metapackage | meta | kernel, persistence-sql, runtime-default, api-http |
| 10 | `ausus/starter`              | project     | full + boot script | kernel, persistence-sql, runtime-default |

### npm (1)

| Package | Type | Notes |
|---|---|---|
| `@ausus/renderer-react` | React 18 / 19 library | ESM-only; no bundled dependencies; React + react-dom are peer dependencies |

The publication order, per-package commands, and dependency-safety
analysis are in [`docs/PUBLICATION-RUNBOOK.md`](docs/PUBLICATION-RUNBOOK.md) §1 + §3.

## 3. Compatibility matrix

| Layer | Tool | Minimum | Tested with | Notes |
|---|---|---|---|---|
| **Runtime: PHP** | `php`              | 8.3      | 8.4.18  | strict types, readonly classes, `final` by default |
| **Runtime: PHP** | `ext-pdo`          | bundled  | bundled | required by `ausus/persistence-sql` |
| **Runtime: PHP** | `ext-pdo_sqlite`   | bundled  | bundled | required by `ausus/starter` |
| **Tooling: PHP** | `composer`         | 2.0      | 2.9.5   | path repositories, artifact repositories |
| **Runtime: JS**  | `node`             | 18       | 22.22.0 | strict ESM (`type: module`, NodeNext resolution) |
| **Runtime: JS**  | `react`            | ^18 or ^19 | 18.3.1 | declared as `peerDependency` |
| **Runtime: JS**  | `react-dom`        | ^18 or ^19 | 18.3.1 | declared as `peerDependency` |
| **Tooling: JS**  | `npm`              | 8        | 10.9.4  | workspaces |
| **Tooling: JS**  | `typescript` (dev) | 5.4      | 5.x     | `moduleResolution: NodeNext` |

**Explicit non-dependencies (none of these are required):**
Laravel framework, Eloquent, Filament, Tailwind, any UI component
library, Vite, Webpack, Babel, Jest, PHPUnit, Doctrine, Symfony
components.

## 4. Known limitations (deferred to v0.2.0)

| Limitation |
|---|
| Skeleton packages (`tenancy-row`, `audit-database`, `auth-bridge`, `presentation-default`) ship no code — names reserved only. |
| `composer create-project ausus/starter myapp` is a 2-command flow post-Packagist; a 3-command clean-room flow uses `--no-install`. |
| Persistence verified on SQLite; MySQL / Postgres are designed-for but not validated under V0. |
| The renderer has no built-in router, theme tokens, optimistic UI, or default CSS file. |
| No PHPUnit test suite yet — the playground's 36 assertions cover the same surface. |
| Supply-chain attestation (`npm --provenance`, GPG-signed tags, SBOM) is deferred to v0.2.0 — see `docs/PUBLICATION-RUNBOOK.md` §7. |

## 5. Reproducibility summary

The release candidate was validated with the in-repo gates. Each is
re-runnable from a clean checkout:

| Gate | Result |
|---|---|
| `composer validate` — 10 package manifests + workspace root (11 total) | PASS |
| `bash scripts/ci.sh`              | PASS — ends with `[ci] DONE — all 10 steps passed` |
| `bash scripts/clean-room.sh`      | PASS — 8/8 isolated-`mktemp` steps |
| `bash scripts/integration-http.sh`| PASS — 12/12 live-HTTP assertions |
| `php apps/playground/run.php`     | PASS — 36/36 assertions |
| `npm run build && npm run trace`  | PASS — 12/12 React render assertions |

Background on the clean-room + Node-ESM remediation is in
[`docs/RFC-000-v0r2-remediation.md`](docs/RFC-000-v0r2-remediation.md).
The publication-readiness audit is in
[`docs/PUBLICATION-READINESS.md`](docs/PUBLICATION-READINESS.md).

## 6. Final status

**PUBLICATION HOLD.**

The code and the build artifacts are release-candidate ready. Public
publication does **not** proceed from this document. It proceeds **only**
through [`docs/PUBLICATION-RUNBOOK.md`](docs/PUBLICATION-RUNBOOK.md),
and **only after every P0 pre-flight control in that runbook's §2
passes**.

The P0 controls that gate the HOLD:

1. Clean working tree, on `main`, synced with `origin`.
2. CI green on the **exact commit** being tagged.
3. All 10 per-package GitHub release repos exist **and are empty**.
4. No `v0.1.0` tag pre-exists on any release repo or the monorepo.
5. Packagist propagation is polled before each dependent publish and
   before the smoke test.

When all P0 controls pass and the runbook's phased procedure (§3)
completes with every STOP-matrix gate (§4) green, the status changes to
**published**. Until then it remains **PUBLICATION HOLD**.

---

**Authoritative procedure:** [`docs/PUBLICATION-RUNBOOK.md`](docs/PUBLICATION-RUNBOOK.md)
— if this document and the runbook ever disagree, the runbook wins.
