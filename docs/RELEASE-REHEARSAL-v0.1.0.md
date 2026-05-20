# AUSUS v0.1.0 — Dry-Run Release Operator Rehearsal

**Latest run:** 2026-05-20 — **Re-run #3** (post infrastructure setup)
**Operator role:** release operator (rehearsal only)
**Normative procedure:** [`docs/PUBLICATION-RUNBOOK.md`](PUBLICATION-RUNBOOK.md)
**Setup plan:** [`docs/RELEASE-INFRA-SETUP-v0.1.0.md`](RELEASE-INFRA-SETUP-v0.1.0.md)
**Release commit under test:** `0773c4a301d39953e02f2c99c36e728b5910099a`
**Determination:** **READY TO PUBLISH** — see §6.

> This rehearsal executed every **safe** pre-flight check from runbook §2 and
> verified prerequisites only for unsafe steps. **Nothing was published,
> pushed, tagged, or submitted.** Registry-mutating commands were replaced with
> read-only / dry-run equivalents.

### Run history

| Run | Date | Release commit | Determination | P0 blockers open |
|---|---|---|---|---|
| #1 | 2026-05-20 | `a0307cb` (on `chore/publication-runbook`) | HOLD | 4 |
| #2 | 2026-05-20 | `bde3fde` (on `main`) | HOLD | 3 |
| **#3** | **2026-05-20** | **`0773c4a` (on `main`)** | **READY TO PUBLISH** | **0** |

**Change since Re-run #2:** the operator executed the
`docs/RELEASE-INFRA-SETUP-v0.1.0.md` infrastructure steps. `gh` is now
authenticated (`adonko3xBitters`); GitHub Actions CI is `success` on the exact
HEAD commit; all 10 per-package repos exist, are empty, and are public; the
`@ausus` npm org exists with the operator as owner and 2FA at
`auth-and-writes`; the working tree is now literally clean (Warning W1
resolved). **All 4 blockers are cleared.**

---

## 1. Gate results — PASS / FAIL (Re-run #3)

| # | Gate (runbook §) | Run #2 | Run #3 | Evidence |
|---|---|---|---|---|
| 1 | §2.1 Toolchain | PASS | **PASS** | PHP 8.4.18, Composer 2.9.5, Node 22.22.0, npm 10.9.4, gh 2.87.3 |
| 2 | §2.2 P0-A — clean tree / on `main` / synced | PASS¹ | **PASS** | `git status --porcelain` **empty**; branch `main`; `0 0` vs `origin/main` |
| 3 | §2.3 P0-B — CI green on exact commit | FAIL | **PASS** | `gh auth status` → logged in (`adonko3xBitters`); `gh run list --commit 0773c4a` → `completed success` |
| 4 | §2.4 P0-C — 10 release repos exist + EMPTY | FAIL | **PASS** | **10/10** exist, 0 branches, 0 tags, visibility `PUBLIC` |
| 5 | §2.5 P0-D — no `v0.1.0` tag on remotes | PASS(partial) | **PASS** | **10/10** per-package repos: no `v0.1.0` tag. Monorepo `origin`: no `v0.1.0` tag |
| 6 | §2.6 — npm identity + org membership | FAIL | **PASS** | `npm whoami` → `adonko3xbitters`; `npm org ls @ausus` → `adonko3xbitters - owner` |
| 7 | §2.6 — npm 2FA / TOTP readiness | BLOCKED | **PASS** | `npm profile get` → `two-factor auth: auth-and-writes` |
| 8 | §2.7 — npm artifact pre-inspection | PASS | **PASS** | `npm pack --dry-run`: `@ausus/renderer-react@0.1.0`, **11.0 kB**, **21 files** |
| 9 | §2.8 — registry reachability | PASS | **PASS** | Packagist `HTTP 200`, npm registry `HTTP 200` |
| 10 | Composer manifest validation | PASS | **PASS** | `composer validate` — **11/11** (root + 10 packages) |
| 11 | Composer artifact build | PASS | **PASS** | `composer archive` — **10/10**, no failures |
| 12 | `bash scripts/ci.sh` | PASS | **PASS** | `[ci] DONE — all 10 steps passed` |
| 13 | `bash scripts/clean-room.sh` | PASS | **PASS** | `[clean-room] ALL STEPS PASSED` |
| 14 | `bash scripts/integration-http.sh` | PASS | **PASS** | `RESULT: passed=12 failed=0`, exit 0 |

¹Run #2 P0-A passed with caveat W1 (untracked rehearsal docs). W1 is now
resolved — the working tree is literally clean.

**Re-run #3 summary: 14 PASS · 0 FAIL.** Every P0 gate (A, B, C, D) and every
P1 npm control is green.

---

## 2. Command outputs (summarized)

### §2.1 Toolchain
```
PHP 8.4.18   Composer 2.9.5   node v22.22.0   npm 10.9.4   gh 2.87.3
```

### §2.2 P0-A — working tree / branch / sync
```
git branch --show-current                            → main          ✓
git status --porcelain                               → (empty)       ✓
git rev-list --left-right --count origin/main...HEAD  → 0  0          ✓
git rev-parse HEAD                                    → 0773c4a301d39953e02f2c99c36e728b5910099a
```

### §2.3 P0-B — CI green on exact commit
```
gh auth status  → ✓ Logged in to github.com account adonko3xBitters (keyring), active
RELEASE_COMMIT  → 0773c4a301d39953e02f2c99c36e728b5910099a
gh run list --commit 0773c4a… --jq '.[0]|"\(.status) \(.conclusion)"'
                → completed success
local ci.sh     → all 10 steps passed
```

### §2.4 P0-C + §2.5 P0-D — per-package release repos
```
adonko3xBitters/{kernel, persistence-sql, runtime-default, api-http,
  tenancy-row, audit-database, auth-bridge, presentation-default,
  standard-stack, starter}
  → 10/10 READY: exists, 0 branches, 0 tags, no v0.1.0 tag, visibility PUBLIC
git ls-remote --tags origin refs/tags/v0.1.0  → (empty)  ✓ no v0.1.0 on monorepo
```
Note: an empty GitHub repo's `commits` API returns HTTP 409 "Git Repository is
empty" (documented in runbook §2.4). Emptiness was therefore verified via
ref enumeration (`git ls-remote --heads/--tags` → no refs), which is the
reliable signal for a zero-commit repo.

### §2.6 — npm identity + 2FA
```
npm whoami                          → adonko3xbitters
npm org ls @ausus                   → adonko3xbitters - owner
npm profile get → two-factor auth   → auth-and-writes
```

### §2.7 / §2.8 — artifact + registry
```
npm pack --dry-run  → @ausus/renderer-react@0.1.0  package size 11.0 kB  total files 21
Packagist           → HTTP 200
npm registry        → HTTP 200
```

### Composer artifact readiness
```
composer validate  → 11/11 manifests OK
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

| Artifact | Run #3 measured | Documented expectation | Verdict |
|---|---|---|---|
| `@ausus/renderer-react` tarball | 11.0 kB packed, 21 files | unchanged across runs #1–#3; RELEASE-NOTES asserts no hard size | ✓ consistent — no drift |
| Composer package versions | all `0.1.0` | RELEASE-NOTES §1 | ✓ consistent |
| CI step count | `ci.sh` → "all 10 steps passed" | RELEASE-NOTES §5 / runbook | ✓ consistent |

---

## 4. Blocker status (vs `RELEASE-INFRA-SETUP-v0.1.0.md`)

| # | Blocker | Run #2 | Run #3 | Evidence |
|---|---|---|---|---|
| 1 | Release commit not on `main` | ✅ CLEARED | **✅ CLEARED** | `main` == `origin/main` == `0773c4a`; synced `0 0` |
| 2 | `gh` unauthenticated; CI unverifiable | ❌ OPEN | **✅ CLEARED** | `gh` logged in; CI `completed success` on `0773c4a` |
| 3 | 10 per-package GitHub repos missing | ❌ OPEN | **✅ CLEARED** | 10/10 exist, empty, public |
| 4 | npm not logged in; `@ausus` org absent | ❌ OPEN | **✅ CLEARED** | `npm whoami` ok; `@ausus` org owner; 2FA `auth-and-writes` |

**All 4 blockers cleared.**

---

## 5. Warnings & residual notes

- **W3 — stale header comment in `scripts/ci.sh`** — the comment block says
  "9 manifests" / "Steps 1..9" while the script validates 11 manifests and runs
  10 steps. Cosmetic; the final line correctly prints "all 10 steps passed".
  Non-blocking.
- **W4 — `integration-http.sh` prints `Terminated: 15`** on some runs — the
  script's own teardown of its `php -S` server; exit code `0`. Not an error.
- **Deferred supply-chain controls** (`npm --provenance`, GPG-signed tags, SBOM,
  reproducible-build container) remain **accepted deferred risk** for v0.2.0,
  per runbook §7. Not release-blocking for v0.1.0.
- **`RELEASE-NOTES-v0.1.0.md` still reads `PUBLICATION HOLD`** — and correctly
  so: it flips to "published" only **after** runbook §3 completes (runbook §8).
  "READY TO PUBLISH" below means the §2 pre-flight is satisfied, not that
  publication has occurred.
- **Re-verification rule:** this rehearsal verified §2 against release commit
  `0773c4a`. If any new commit lands on `main` before publication, runbook §2
  (especially P0-A and P0-B) must be re-run against the new HEAD.

---

## 6. Final determination

# READY TO PUBLISH

All **14 rehearsal gates pass** and **all 4 P0 blockers are cleared**:

- **P0-A** — clean working tree, on `main`, synced with `origin` ✓
- **P0-B** — GitHub Actions CI `success` on the exact release commit `0773c4a` ✓
- **P0-C** — all 10 per-package release repos exist, empty, and public ✓
- **P0-D** — no `v0.1.0` tag on any per-package repo or on the monorepo ✓
- npm identity, `@ausus` org ownership, and 2FA (`auth-and-writes`) ✓
- registries reachable; 11/11 manifests valid; 10/10 archives; `ci.sh` 10/10,
  `clean-room.sh` 8/8, `integration-http.sh` 12/12 ✓

The runbook §2 pre-flight is fully satisfied for release commit `0773c4a`. The
operator may proceed to **`docs/PUBLICATION-RUNBOOK.md` §2.9** (create the local
`v0.1.0` tag) and then **§3 Phase 1** of the phased publication.

> **This rehearsal published nothing.** "READY TO PUBLISH" authorizes the
> operator to begin the real, irreversible §3 procedure — `npm publish` and
> Packagist submission are permanent. Each §3 phase still has its own STOP-gate
> (runbook §4); honor them. If any commit lands on `main` before §3 begins,
> re-run §2 against the new HEAD first.

**Re-run #3 determination: READY TO PUBLISH.**
