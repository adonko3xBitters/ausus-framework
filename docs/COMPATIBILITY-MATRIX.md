# Compatibility Matrix — AUSUS v0.1

**Status:** ratified for v0.1.x · last validated 2026-05-19
**Companion documents:** [`UPGRADE-POLICY.md`](UPGRADE-POLICY.md),
[`SEMVER-CONTRACT.md`](SEMVER-CONTRACT.md), [`API-GOVERNANCE.md`](API-GOVERNANCE.md)
**Evidence script:** [`scripts/upgrade-sim.sh`](../scripts/upgrade-sim.sh) — 7 real sandbox simulations, all green.

---

## 1. Supported runtime matrix

| Stack | Minimum | Validated up to | Notes |
|---|---|---|---|
| **PHP**          | 8.3       | 8.4.18       | strict types, readonly classes, `final` by default — no v8.5/v9 features anywhere |
| `ext-pdo`        | bundled   | bundled      | required by `ausus/persistence-sql` |
| `ext-pdo_sqlite` | bundled   | bundled      | required by `ausus/starter` |
| **Composer**     | 2.0       | 2.9.5        | path repositories, artifact repositories, `--no-install` |
| **Node**         | 18        | 22.22.0      | strict ESM (`type: module`, NodeNext resolution) |
| **npm**          | 8         | 10.9.4       | workspaces |
| **React**        | 18.0.0 OR 19.0.0 | 18.3.1 + 19.2.6 | declared `peerDependency: "^18 || ^19"` |
| **react-dom**    | matched to React major | same | declared `peerDependency: "^18 || ^19"` |
| **TypeScript**   | 5.4 (dev) | 5.4–6.x      | only required for renderer build; consumers can ignore |

Tested on **macOS arm64**. CI matrix exercises Linux x86_64 (Ubuntu) on
every PR (PHP 8.3/8.4 × Node 18/20/22, plus a `react-compat` job that
re-runs against React 18 and 19 independently).

### 1.1 Explicit non-dependencies

The framework neither imports nor pulls in any of:

Laravel framework, Eloquent, Symfony components, Doctrine, Filament,
Tailwind, Vite, Webpack, Babel, Jest, PHPUnit, `react-router`, any
UI-component library.

Total transitive composer install (workspace): **3 ausus/* packages + 4 PSR interfaces + nyholm/psr7 + nyholm/psr7-server**.
Total transitive npm install (consumer): **6 packages** (`@ausus/renderer-react` + React + react-dom + 3 React internals).

---

## 2. Upgrade compatibility — measured outcomes

Each row was reproduced inside a `mktemp -d` sandbox by
`scripts/upgrade-sim.sh`. Every PASS / REJECT below is real composer or
npm output captured during the run.

| # | Scenario | Expected | Actual | Evidence |
|---|---|---|---|---|
| 1 | **Patch bump 0.1.0 → 0.1.1 lockstep** (every package) | PASS — boot works | ✅ PASS | `composer install` + `composer boot` complete; "OK — ausus/starter boots cleanly." |
| 2 | **Partial bump** (kernel→0.1.1, deps stay 0.1.0) | PASS — `0.1.*` constraint allows | ✅ PASS | Mixed-version install succeeds; boot OK |
| 3 | **Major drift** (kernel→0.2.0, runtime requires 0.1.*) | REJECT — semver violation | ✅ REJECT (composer) | see §2.1 |
| 4 | **React 17** (below peer `^18 || ^19`) | REJECT — peer-range violation | ✅ REJECT (npm) | see §2.2 |
| 5 | **React 19** (within peer range) | PASS — within range; trace renders | ✅ PASS | green WorkflowBadge renders on React 19.2.6 |
| 6 | **PHP `>=9.9.9` synthetic floor** | REJECT — platform requirement unsatisfied | ✅ REJECT (composer) | see §2.3 |
| 7 | **Subtree split** of `packages/kernel` | PASS — publish-shaped tree | ✅ PASS | `git subtree split` produces a branch containing `composer.json` + `src/` at the root |

**0 fail · 0 silent breakage across all 7 scenarios.**

### 2.1 Verbatim — SIM-3 composer rejection (major drift)

```
Your requirements could not be resolved to an installable set of packages.

  Problem 1
    - Root composer.json requires ausus/kernel 0.1.*,
      found ausus/kernel[0.2.0] but it does not match the constraint.
  Problem 2
    - Root composer.json requires ausus/persistence-sql 0.1.*
      -> satisfiable by ausus/persistence-sql[0.1.0].
    - ausus/persistence-sql 0.1.0 requires ausus/kernel 0.1.*
      -> found ausus/kernel[0.2.0] but it does not match the constraint.
  Problem 3
    - Root composer.json requires ausus/runtime-default 0.1.*
      -> satisfiable by ausus/runtime-default[0.1.0].
    - ausus/runtime-default 0.1.0 requires ausus/kernel 0.1.*
      -> found ausus/kernel[0.2.0] but it does not match the constraint.
```

Composer surfaces every constraint independently and points at the
specific package whose constraint failed. This is the explicit-error
behavior we rely on.

### 2.2 Verbatim — SIM-4 npm rejection (React 17 below peer range)

```
npm error code ERESOLVE
npm error ERESOLVE unable to resolve dependency tree
npm error peer react@"^18.0.0 || ^19.0.0" from @ausus/renderer-react@0.1.0
```

npm produces a hard ERESOLVE error rather than a warning, so the
install fails by default. A consumer who *intentionally* downgrades
must pass `--force` or `--legacy-peer-deps` — that's a documented
opt-out, not silent breakage.

### 2.3 Verbatim — SIM-6 composer rejection (PHP platform)

```
- ausus/kernel 0.1.0 requires php >=9.9.9
  -> your php version (8.4.18) does not satisfy that requirement.
```

Composer's platform check fires before any package is extracted.
Bumping a required platform version always surfaces this exact format.

---

## 3. Per-package compatibility table

Cross-version compatibility within v0.1.x is **bidirectional** — any
0.1.x ausus/* package interoperates with any other 0.1.x ausus/*
package because every cross-reference uses the `0.1.*` constraint.

| Package | Cross-deps on `ausus/*` | Constraint emitted |
|---|---|---|
| `ausus/kernel`              | — | (no ausus deps) |
| `ausus/persistence-sql`     | `ausus/kernel` | `0.1.*` |
| `ausus/runtime-default`     | `ausus/kernel` | `0.1.*` |
| `ausus/api-http`            | `ausus/kernel`, `ausus/runtime-default` | `0.1.*` each |
| `ausus/standard-stack`      | `ausus/kernel`, `ausus/persistence-sql`, `ausus/runtime-default`, `ausus/api-http` | `0.1.*` each |
| `ausus/starter`             | `ausus/kernel`, `ausus/persistence-sql`, `ausus/runtime-default` | `0.1.*` each |
| `ausus/tenancy-row`         | — (skeleton) | n/a |
| `ausus/audit-database`      | — (skeleton) | n/a |
| `ausus/auth-bridge`         | — (skeleton) | n/a |
| `ausus/presentation-default`| — (skeleton) | n/a |
| `@ausus/renderer-react`     | npm peer: `react`, `react-dom` | `^18.0.0 || ^19.0.0` |

**Consumer's recommended constraint:** `^0.1.0` (caret) — picks up any
0.1.x patch automatically and rejects 0.2 / 1.0 without manual review.

A pinned constraint (`= 0.1.0`) works but freezes the consumer to a
specific patch level and won't pick up security fixes; we recommend
caret.

---

## 4. Lockfile behaviour

### 4.1 Composer

| Action | Lockfile behaviour |
|---|---|
| `composer install` (lockfile present) | reproduces exact versions; no drift permitted |
| `composer update` | re-resolves against constraints; rewrites `composer.lock` |
| `composer update ausus/kernel` | re-resolves *only* kernel; minimal lockfile churn |
| Mismatched lockfile (manifests edited without `composer update`) | warning issued; install proceeds against lockfile until update |
| Missing lockfile | `composer install` behaves like `composer update`; lockfile written |

`composer.lock` is committed at the workspace root (it's a `type: project`,
not a library).

### 4.2 npm

| Action | Lockfile behaviour |
|---|---|
| `npm install` (lockfile present) | resolves against constraints + lockfile preferences; minor churn possible |
| `npm ci` | reproduces exact lockfile versions; refuses if lockfile is stale |
| Workspace consumer install via `npm pack` tarball | doesn't touch consumer's lockfile other than adding the tarball entry |
| Peer-range violation | `ERESOLVE` error (see §2.2); never silent |

`package-lock.json` is committed at the workspace root.

---

## 5. Subtree split correctness

Each PHP package ships independently to Packagist via `git subtree split`.
SIM-7 verifies the split branch contains:

- `composer.json` at the root of the new branch
- `src/` at the root
- `README.md` and `LICENSE` (when present)
- no `.git/` carryover from the monorepo

The 9 publishable packages (4 implemented + 4 skeletons + 1 metapackage)
all live under `packages/*` and pass identical structural checks. The
operator's subtree-split procedure is documented in
`RELEASE-NOTES-v0.1.0.md §5.1`.

---

## 6. Mixed-version envelope

Within v0.1.x, **every combination is supported**. The grid below
demonstrates that the `0.1.*` constraint is symmetric and transitive
across all implemented packages.

|                                     | kernel 0.1.0 | kernel 0.1.1 | kernel 0.1.x | kernel 0.2.0 |
|---|---|---|---|---|
| persistence-sql 0.1.0               | ✅           | ✅            | ✅            | ❌ rejected   |
| persistence-sql 0.1.1               | ✅           | ✅            | ✅            | ❌ rejected   |
| runtime-default 0.1.0               | ✅           | ✅            | ✅            | ❌ rejected   |
| api-http 0.1.0                      | ✅           | ✅            | ✅            | ❌ rejected   |
| starter 0.1.0                       | ✅           | ✅            | ✅            | ❌ rejected   |

✅ = composer install succeeds. ❌ = composer's resolver surfaces the
verbatim §2.1 error and exits non-zero before extraction.

Reading the table: as long as you stay inside `0.1.x`, you can mix and
match patch levels freely. Once one package crosses into `0.2.x`, every
other package's `0.1.*` constraint rejects it — that's the
"explicit-error" invariant we promise.

---

## 7. Downgrade compatibility

Consumers may pin to a lower patch within v0.1.x without
incident — every patch in the series is backward-compatible by
contract (`SEMVER-CONTRACT.md §1.2`).

| Consumer constraint | Behaviour |
|---|---|
| `^0.1.0` (caret, recommended)         | picks the latest 0.1.x; rejects 0.2 / 1.0 |
| `~0.1.0` (tilde, equivalent in 0.x)   | same as caret in 0.x |
| `0.1.*`                               | same — picks latest 0.1.x |
| `0.1.0` (exact)                       | freezes to one patch level |
| `>=0.1.0 <0.1.5`                      | composer enforces the upper bound |
| `^0.0.5`                              | rejected — no 0.0.x ever shipped |
| `^0.2.0`                              | rejected — 0.2.x doesn't exist yet |

The framework does not ship any pre-0.1 release tags. Consumers must
not attempt to pin below `0.1.0`.

---

## 8. Determination

**GO** — the v0.1.x compatibility envelope is

- *symmetric* within `0.1.*` (any patch mix works)
- *bounded* by composer constraint enforcement (0.2+ rejected)
- *peer-validated* by npm's `ERESOLVE` (React 17 / 20 rejected)
- *platform-validated* by composer's platform-requirement check
  (PHP < 8.3 rejected)
- *publication-ready* via `git subtree split` (proven for `ausus/kernel`)

No silent-breakage case was discovered across 7 real simulations.

The corresponding upgrade procedures, deprecation policy, and rollback
runbook live in [`docs/UPGRADE-POLICY.md`](UPGRADE-POLICY.md).
