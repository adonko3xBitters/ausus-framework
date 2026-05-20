# AUSUS v0.1.0 — Dry-Run Release Operator Rehearsal

**Latest run:** 2026-05-20 — **Re-run #2** (post runbook-merge)
**Operator role:** release operator (rehearsal only)
**Normative procedure:** [`docs/PUBLICATION-RUNBOOK.md`](PUBLICATION-RUNBOOK.md)
**Setup plan:** [`docs/RELEASE-INFRA-SETUP-v0.1.0.md`](RELEASE-INFRA-SETUP-v0.1.0.md)
**Release commit under test:** `bde3fded293d1cc18c35fb74dbd3f6b0eb1fe153`
**Determination:** **HOLD** — see §6.

> This rehearsal executed every **safe** pre-flight check from runbook §2 and
> verified prerequisites only for unsafe steps. **Nothing was published,
> pushed, tagged, or submitted.** Registry-mutating commands were replaced with
> read-only / dry-run equivalents.

### Run history

| Run | Date | Commit | Determination | Blockers open |
|---|---|---|---|---|
| #1 | 2026-05-20 | `a0307cb` (on `chore/publication-runbook`) | HOLD | 4 |
| **#2** | **2026-05-20** | **`bde3fde` (on `main`)** | **HOLD** | **3** |

**Change since Re-run #1:** PR #10 (`chore/publication-runbook` → `main`) was
merged. The publication runbook and concise release notes are now on `main`;
`main` is synced with `origin`. **Blocker 1 (P0-A branch state) is CLEARED.**
Blockers 2–4 (GitHub auth/CI, per-package repos, npm identity) remain open —
the `docs/RELEASE-INFRA-SETUP-v0.1.0.md` infrastructure steps have not yet been
executed.

---

## 1. Gate results — PASS / FAIL

| # | Gate (runbook §) | Run #1 | Run #2 | Notes |
|---|---|---|---|---|
| 1 | §2.1 Toolchain | PASS | **PASS** | PHP 8.4.18, Composer 2.9.5, Node 22.22.0, npm 10.9.4, gh 2.87.3 |
| 2 | §2.2 P0-A clean tree / on `main` / synced | FAIL | **PASS¹** | On `main` ✓, synced `0 0` vs `origin/main` ✓. ¹`git status --porcelain` lists 2 **untracked operator rehearsal docs** in `docs/` — see Warning W1 |
| 3 | §2.3 P0-B CI green on exact commit | FAIL | **FAIL** | `gh` still **not authenticated** — cannot query the GitHub Actions run for `bde3fde` |
| 4 | §2.4 P0-C 10 release repos exist + EMPTY | FAIL | **FAIL** | **All 10** per-package repos under `adonko3xBitters` still **MISSING** |
| 5 | §2.5 P0-D no `v0.1.0` tag on remotes | PASS(partial) | **PASS(partial)** | Monorepo `origin`: no `v0.1.0` tag ✓. Per-package: not checkable (repos absent) |
| 6 | §2.6 npm identity + org membership | FAIL | **FAIL** | `npm whoami` → `ENEEDAUTH`. `npm org ls @ausus` → `404 Scope not found` — `@ausus` org still does not exist |
| 7 | §2.6 npm 2FA / TOTP readiness | WARN | **BLOCKED** | Not human-confirmed and not verifiable — operator not logged in; no 2FA confirmation on record |
| 8 | §2.7 npm artifact pre-inspection | PASS | **PASS** | `npm pack --dry-run`: `@ausus/renderer-react@0.1.0`, **11.0 kB**, **21 files** |
| 9 | §2.8 registry reachability | PASS | **PASS** | Packagist `HTTP 200`, npm registry `HTTP 200` |
| 10 | Composer manifest validation | PASS | **PASS** | `composer validate` — **11/11** (root + 10 packages) |
| 11 | Composer artifact build | PASS | **PASS** | `composer archive` — **10/10** packages archived, no failures |
| 12 | `bash scripts/ci.sh` | PASS | **PASS** | `[ci] DONE — all 10 steps passed` |
| 13 | `bash scripts/clean-room.sh` | PASS | **PASS** | `[clean-room] ALL STEPS PASSED` |
| 14 | `bash scripts/integration-http.sh` | PASS | **PASS** | `RESULT: passed=12 failed=0`, exit 0 |

**Run #2 summary:** 11 PASS · 3 FAIL · 1 BLOCKED. All 3 FAILs + the BLOCKED row
are **publication-infrastructure** gates. **Zero code or artifact defects.**

---

## 2. Command outputs (summarized)

### §2.1 Toolchain
```
PHP 8.4.18   Composer 2.9.5   node v22.22.0   npm 10.9.4   gh 2.87.3
```
All meet runbook minimums.

### §2.2 P0-A — working tree / branch / sync
```
git branch --show-current                          → main                     ✓
git rev-list --left-right --count origin/main...HEAD → 0  0                     ✓
git rev-parse HEAD                                  → bde3fded293d1cc18c35...   (release commit)
git status --porcelain →
  ?? docs/RELEASE-INFRA-SETUP-v0.1.0.md
  ?? docs/RELEASE-REHEARSAL-v0.1.0.md
```
Branch and sync state are now correct (Blocker 1 cleared by the PR #10 merge).
The two porcelain entries are **operator-generated rehearsal documents** in
`docs/` — not source changes, and outside `packages/` so a `git subtree split`
cannot capture them. See Warning W1.

### §2.3 P0-B — CI green on exact commit
```
RELEASE_COMMIT  bde3fded293d1cc18c35fb74dbd3f6b0eb1fe153
gh auth status  → "You are not logged into any GitHub hosts."
gh run list --commit bde3fde…  → "To get started with GitHub CLI, please run: gh auth login"
local ci.sh     → all 10 steps passed
```
Local CI is green, but the exact-commit **remote** CI gate cannot be evaluated:
`gh` is unauthenticated. (Per `RELEASE-INFRA-SETUP-v0.1.0.md` Blocker 2, the
operator must run `gh auth login`.)

### §2.4 P0-C — per-package release repos
```
adonko3xBitters/{kernel, persistence-sql, runtime-default, api-http,
  tenancy-row, audit-database, auth-bridge, presentation-default,
  standard-stack, starter}  →  all 10  MISSING
```
Checked via anonymous `git ls-remote https://github.com/adonko3xBitters/<pkg>.git`
— every repo returns "not found". None of the 10 split-target repos has been
created (`RELEASE-INFRA-SETUP-v0.1.0.md` Blocker 3 not yet executed).

### §2.5 P0-D — remote tag absence
```
git ls-remote --tags origin refs/tags/v0.1.0  → (empty)   ✓ no v0.1.0 on monorepo
per-package repos                             → N/A (repos absent)
```

### §2.6 npm identity
```
npm whoami         → npm error code ENEEDAUTH
npm org ls @ausus  → npm error 404 Not Found — "Scope not found"
```
Operator not logged in; the `@ausus` npm org/scope still does not exist
(`RELEASE-INFRA-SETUP-v0.1.0.md` Blocker 4 not yet executed). 2FA/TOTP
readiness is therefore unverifiable and has **no human confirmation on record**.

### §2.7 / §2.8 — artifact + registry
```
npm pack --dry-run  → @ausus/renderer-react@0.1.0  package size 11.0 kB  total files 21
Packagist           → HTTP 200
npm registry        → HTTP 200
```

### Composer artifact readiness
```
composer validate  → 11/11 manifests OK (composer.json + packages/*/composer.json)
composer archive   → 10/10 packages → /tmp/ausus-rehearsal-archives
```

### Build / test gates
```
scripts/ci.sh               → [ci] DONE — all 10 steps passed
scripts/clean-room.sh       → [clean-room] ALL STEPS PASSED  (8/8)
scripts/integration-http.sh → RESULT: passed=12 failed=0  (exit 0)
```

---

## 3. Artifact expectation comparison

| Artifact | Run #2 measured | Documented expectation | Verdict |
|---|---|---|---|
| `@ausus/renderer-react` tarball | 11.0 kB packed, 21 files | unchanged from Run #1 (11.0 kB / 21); RELEASE-NOTES asserts no hard size | ✓ consistent — no drift |
| Composer package versions | all `0.1.0` | RELEASE-NOTES §1 | ✓ consistent |
| CI step count | `ci.sh` → "all 10 steps passed" | RELEASE-NOTES §5 / runbook | ✓ consistent |

No artifact figure contradicts the release notes or runbook.

---

## 4. Blocker status (vs `RELEASE-INFRA-SETUP-v0.1.0.md`)

| # | Blocker | Run #1 | Run #2 | Evidence |
|---|---|---|---|---|
| 1 | Release commit not on `main` | OPEN | **✅ CLEARED** | PR #10 merged; `main` == `origin/main` == `bde3fde`; synced `0 0` |
| 2 | `gh` unauthenticated; exact-commit CI unverifiable | OPEN | **❌ OPEN** | `gh auth status` → not logged in |
| 3 | 10 per-package GitHub repos missing | OPEN | **❌ OPEN** | all 10 `git ls-remote` → not found |
| 4 | npm not logged in; `@ausus` org absent | OPEN | **❌ OPEN** | `npm whoami` ENEEDAUTH; `npm org ls @ausus` 404 |

**1 of 4 blockers cleared.** Blockers 2–4 require the operator to execute the
CLI / web-UI steps in `docs/RELEASE-INFRA-SETUP-v0.1.0.md`.

---

## 5. Blockers & warnings

### Blockers (P0 — must clear before publication)

1. **P0-B — exact-commit CI unverifiable.** `gh` is not authenticated, so the
   GitHub Actions run for `bde3fde` cannot be confirmed `success`. Resolve via
   `RELEASE-INFRA-SETUP-v0.1.0.md` Blocker 2 (`gh auth login`, then
   `gh run list --commit <HEAD>`).
2. **P0-C — 10 release repos missing.** All `adonko3xBitters/{kernel … starter}`
   repos must be created **empty**. Resolve via setup-plan Blocker 3, then
   re-check §2.4 (EMPTY) and §2.5 (per-package `v0.1.0` absence).
3. **npm publish identity not established.** Operator not logged in **and** the
   `@ausus` npm org does not exist. Resolve via setup-plan Blocker 4
   (`npm login`, create the `@ausus` org in the npm web UI, enable 2FA).

### Warnings (non-blocking)

- **W1 — untracked rehearsal docs in the working tree.** `git status
  --porcelain` lists `docs/RELEASE-REHEARSAL-v0.1.0.md` and
  `docs/RELEASE-INFRA-SETUP-v0.1.0.md`. These are operator artifacts, outside
  `packages/`, so they cannot be baked into a subtree split — P0-A's
  substantive risk is absent. **However**, runbook §2.2 requires
  `git status --porcelain` to print *nothing*. Before the **real** publication
  run, the operator must either commit these docs or remove them so the tree is
  literally clean.
- **W2 — npm 2FA/TOTP readiness unconfirmed.** Cannot be checked until the
  operator is logged in; no human confirmation is on record. Confirm during
  setup-plan Blocker 4.
- **W3 — stale header comment in `scripts/ci.sh`** — the comment block says
  "9 manifests" / "Steps 1..9" while the script validates 11 manifests and runs
  10 steps. Cosmetic; the final line correctly prints "all 10 steps passed".
- **W4 — `integration-http.sh` prints `Terminated: 15`** on some runs — the
  script's own teardown of its `php -S` server; exit code is `0`. Not an error.

---

## 6. Final determination

# HOLD

The **code and artifacts remain fully green**: 11/11 Composer manifests valid,
10/10 packages archive cleanly, `ci.sh` 10/10, `clean-room.sh` 8/8,
`integration-http.sh` 12/12, npm tarball builds clean (11.0 kB / 21 files),
both registries reachable.

**Progress since Re-run #1:** Blocker 1 is cleared — the runbook merged to
`main`, and P0-A's branch/sync conditions now pass. **3 P0 blockers remain
open**, all publication-infrastructure, none code defects:

1. GitHub CLI unauthenticated → exact-commit CI (P0-B) unverifiable;
2. all 10 per-package GitHub repos (P0-C) still missing;
3. npm identity unestablished and the `@ausus` org does not exist.

`RELEASE-NOTES-v0.1.0.md` correctly remains **PUBLICATION HOLD**. The status
becomes **READY TO PUBLISH** only after Blockers 2–4 are cleared (per
`docs/RELEASE-INFRA-SETUP-v0.1.0.md`), Warning W1 is resolved (clean working
tree), and runbook §2 is re-run with **every P0 gate green**.

**Re-run #2 determination: HOLD.**
