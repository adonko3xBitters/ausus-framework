# AUSUS v0.1.0 — Release Infrastructure Setup Plan

**Plan date:** 2026-05-20
**Audience:** release operator (human)
**Authoritative inputs:** [`docs/RELEASE-REHEARSAL-v0.1.0.md`](RELEASE-REHEARSAL-v0.1.0.md), [`docs/PUBLICATION-RUNBOOK.md`](PUBLICATION-RUNBOOK.md)
**Purpose:** clear the **4 HOLD blockers** so runbook §2 can be re-run all-green.

> **This plan publishes nothing.** It does not submit to Packagist, does not run
> `npm publish`, does not push package split branches, and does not create
> release tags. It only stands up the infrastructure those steps depend on.
>
> **Mutating steps are explicitly marked `⚠ MUTATES`** and are limited to what
> blocker resolution strictly requires: one merge-push to `origin/main`, the
> creation of 10 empty GitHub repos, and npm account/org setup. Everything else
> is read-only.

## Legend

| Tag | Meaning |
|---|---|
| `✅ READ-ONLY` | Inspects state; changes nothing. Safe to run anytime. |
| `⚠ MUTATES` | Changes GitHub / npm / remote state. Required for setup — run deliberately. |
| `🖐 WEB UI` | Cannot be done from the CLI — must be done in a browser. |
| `STOP` | Hard gate. If the condition is met, do not continue — resolve first. |

---

## Blocker 1 — Merge `chore/publication-runbook` into `main`

**Why it blocks:** the runbook + concise release notes live on local branch
`chore/publication-runbook` (`a0307cb`, 1 commit ahead of `origin/main`,
**not pushed**). Runbook §2.2 P0-A requires the release to be cut from a clean
`main` synced with `origin`.

**Confirmed state (already verified):** `main` (`9a5e03a`) is an ancestor of
`chore/publication-runbook` → a **clean fast-forward**, no merge conflict, no
merge commit needed. `a0307cb` is exactly one commit on top of `main`.

### Step 1.1 — Pre-merge inspection `✅ READ-ONLY`

```bash
git fetch origin
git status --porcelain                              # expect: empty
git branch --show-current                           # expect: chore/publication-runbook
git rev-list --left-right --count origin/main...chore/publication-runbook
#   expect: 0  1   (branch is 1 ahead, 0 behind)
git merge-base --is-ancestor origin/main chore/publication-runbook && echo "FF-OK"
#   expect: FF-OK
git diff --stat origin/main..chore/publication-runbook
#   expect: only docs/PUBLICATION-RUNBOOK.md (+) and RELEASE-NOTES-v0.1.0.md (M)
```

> **STOP** if `git status --porcelain` is non-empty (uncommitted work), or if
> the left-right count is not `0  1`, or if `FF-OK` does not print, or if the
> diff touches files other than the two expected docs. Investigate before merging.

### Step 1.2 — Fast-forward `main` `⚠ MUTATES` (local only)

```bash
git switch main
git fetch origin
git merge --ff-only chore/publication-runbook
#   --ff-only REFUSES to create a merge commit; if it cannot fast-forward
#   it fails safely and changes nothing.
git log --oneline -1                                # expect HEAD = a0307cb
```

> **STOP** if `git merge --ff-only` fails — it means `main` diverged from the
> branch since this plan was written. Do **not** retry with a plain `git merge`;
> re-run Step 1.1 and reassess.

### Step 1.3 — Push `main` to GitHub `⚠ MUTATES` (origin)

```bash
git push origin main
```

This is the **one required push to a public remote** in this whole plan. It is
explicitly mandated by Blocker 1. It pushes commit `a0307cb` to `origin/main`,
which also triggers the GitHub Actions CI run needed for Blocker 2.

### Step 1.4 — Post-merge cleanup `⚠ MUTATES` (local only)

```bash
git rev-list --left-right --count origin/main...main   # expect: 0  0  AFTER push
git branch -d chore/publication-runbook                # safe delete (now merged)
```

> Use `-d` (lowercase), never `-D`. `-d` refuses to delete an unmerged branch —
> a built-in safety check.

**Blocker 1 cleared when:** `git rev-parse origin/main` == `git rev-parse main`
== `a0307cb`, working tree clean, on `main`.

---

## Blocker 2 — Authenticate `gh` and verify exact-commit CI

**Why it blocks:** runbook §2.3 P0-B requires the GitHub Actions run for the
**exact release commit** to be `success`. `gh` is unauthenticated, so that run
cannot be queried. (`.github/workflows/ci.yml` already exists in the repo, so a
run **will** be produced once `a0307cb` lands on `origin/main` in Step 1.3.)

### Step 2.1 — Authenticate the GitHub CLI `⚠ MUTATES` (local credential store) / `🖐 WEB UI` (browser device-code)

```bash
gh auth login --hostname github.com --git-protocol ssh
```

`gh auth login` is **interactive**. Choose:

- **Account:** the account that owns / can administer `adonko3xBitters`.
- **Protocol:** **SSH** — the runbook §2.5 and §3.1 use `git@github.com:` SSH
  URLs (`git ls-remote`, `git push rel-<pkg>`). gh will offer to **generate and
  upload an SSH key** — accept this so SSH operations work.
- **Authentication:** browser device-code flow (`🖐 WEB UI` — paste the one-time
  code at the URL gh prints).

> **STOP** if you cannot log in as an account with admin rights on
> `adonko3xBitters`. Repo creation in Blocker 3 requires it.

### Step 2.2 — Verify GitHub login `✅ READ-ONLY`

```bash
gh auth status
#   expect: "Logged in to github.com account <name>"
gh api user --jq .login
#   expect: an account that can administer adonko3xBitters
ssh -T git@github.com 2>&1 | head -1
#   expect: "Hi <user>! You've successfully authenticated..."
```

### Step 2.3 — Verify exact-commit CI `✅ READ-ONLY` (run AFTER Step 1.3)

```bash
RELEASE_COMMIT="$(git rev-parse main)"            # expect a0307cb...
echo "Release commit: ${RELEASE_COMMIT}"

# Wait for the GitHub Actions run on this exact commit to finish, then:
gh run list --commit "${RELEASE_COMMIT}" --limit 1 --json status,conclusion \
  --jq '.[0] | "\(.status) \(.conclusion)"'
#   expect: "completed success"
```

> **STOP** if the run is `completed failure`/`cancelled`, or if **no run** is
> listed for `${RELEASE_COMMIT}` (the push in Step 1.3 may not have triggered
> CI — check the Actions tab). A green run for any **other** commit does **not**
> satisfy P0-B. If CI is red: fix → new commit → new green run → the release
> commit changes accordingly.

**Blocker 2 cleared when:** `gh auth status` shows a logged-in account **and**
`gh run list --commit <release commit>` reports `completed success`.

---

## Blocker 3 — Create the 10 empty per-package GitHub repos

**Why it blocks:** runbook §2.4 P0-C requires all 10 split-target repos under
`adonko3xBitters` to **exist and contain zero commits**. The rehearsal found all
10 missing.

> **Critical:** the repos must be created **with no README, no LICENSE, no
> .gitignore**. `gh repo create <owner>/<name> --public` with none of
> `--add-readme` / `--license` / `--gitignore` produces a **truly empty** repo.
> A repo with even one auto-generated file is **not empty** and will reject the
> runbook's first `git push` as a non-fast-forward.

### Step 3.1 — Create the 10 repos `⚠ MUTATES` (GitHub) — CLI

```bash
for PKG in kernel persistence-sql runtime-default api-http \
           tenancy-row audit-database auth-bridge presentation-default \
           standard-stack starter; do
  gh repo create "adonko3xBitters/${PKG}" \
    --public \
    --description "AUSUS — ausus/${PKG} (read-only split mirror of ausus-framework)"
  #  NO --add-readme   NO --license   NO --gitignore   NO --clone
done
```

`gh repo create` works whether `adonko3xBitters` is a personal account (you must
be logged in as it) or an organization (you must have repo-create rights). No
`🖐 WEB UI` step is needed for creation — but see Step 3.3.

> **STOP** if any `gh repo create` reports `Name already exists` — that repo may
> be non-empty from a prior attempt. Do not assume it is safe; verify it with
> Step 3.2, and if it is non-empty, **delete and recreate it empty**
> (`gh repo delete adonko3xBitters/<pkg> --yes` then re-create). Deleting a repo
> is irreversible — confirm the repo is genuinely a stray empty/aborted one
> before deleting.

### Step 3.2 — Verify all 10 repos exist, are EMPTY, and have no `v0.1.0` tag `✅ READ-ONLY`

This is the combined runbook §2.4 + §2.5 verification — proves each repo
**exists**, has **zero commits**, and carries **no `v0.1.0` tag**:

```bash
for PKG in kernel persistence-sql runtime-default api-http \
           tenancy-row audit-database auth-bridge presentation-default \
           standard-stack starter; do
  # (a) exists?
  if ! gh repo view "adonko3xBitters/${PKG}" >/dev/null 2>&1; then
    echo "✗ MISSING  ${PKG}"; continue
  fi
  # (b) zero commits?  empty repo => the commits API returns HTTP 409
  COMMITS="$(gh api "repos/adonko3xBitters/${PKG}/commits" --jq 'length' 2>/dev/null || echo 0)"
  # (c) no v0.1.0 tag?
  TAG="$(git ls-remote --tags "git@github.com:adonko3xBitters/${PKG}.git" \
         refs/tags/v0.1.0 2>/dev/null)"
  if [ "${COMMITS}" = "0" ] && [ -z "${TAG}" ]; then
    echo "✓ READY  ${PKG}  (exists, 0 commits, no v0.1.0 tag)"
  else
    echo "✗ NOT-READY  ${PKG}  (commits=${COMMITS} tag='${TAG}')"
  fi
done
```

> **STOP** unless all 10 lines read `✓ READY`. Any `✗` is a P0-C / P0-D failure.

**Blocker 3 cleared when:** the loop above prints `✓ READY` for all 10 packages.

---

## Blocker 4 — npm identity and the `@ausus` org

**Why it blocks:** runbook §2.6 requires `npm whoami` to succeed and
`npm org ls @ausus` to list the publishing account. The rehearsal found the
operator not logged in **and** `@ausus` returning `404 Scope not found` — the
org does not exist.

> **The `@ausus` org cannot be created from the npm CLI.** `npm org` only
> *manages* an existing org. Org creation is a `🖐 WEB UI` action.

### Step 4.1 — Log in to npm `⚠ MUTATES` (local npm credential) / `🖐 WEB UI`

```bash
npm login
#   interactive; opens a browser for authentication
```

### Step 4.2 — Create the `@ausus` org `🖐 WEB UI` (REQUIRED — no CLI equivalent)

1. Go to **https://www.npmjs.com/org/create**.
2. Org name: **`ausus`** (this backs the `@ausus` scope).
3. Choose the **free** plan — free npm orgs allow **unlimited public** scoped
   packages, which is all v0.1.0 needs.
4. Ensure the account from Step 4.1 is an **owner** (or has **publish** rights)
   on the new org.

> **STOP** if the org name `ausus` is already taken by someone else — the
> package name `@ausus/renderer-react` would be unpublishable. Escalate before
> proceeding; do not rename the package ad hoc.

### Step 4.3 — Enable 2FA for auth + writes `⚠ MUTATES` (npm account) / `🖐 WEB UI`

npm enforces 2FA on public scoped publishes. Enroll a TOTP authenticator:

```bash
npm profile enable-2fa auth-and-writes
#   interactive: shows a QR / secret to add to your authenticator app,
#   then asks for a TOTP code to confirm enrollment.
```

(2FA can alternatively be enabled at **https://www.npmjs.com/settings/~/profile**
under *Two-Factor Authentication* — `🖐 WEB UI`.)

> Have the authenticator device in hand. Phase 5 of the runbook (`npm publish`)
> will prompt for a fresh TOTP code.

### Step 4.4 — Verify npm identity `✅ READ-ONLY`

```bash
npm whoami
#   expect: your npm account name (no ENEEDAUTH)
npm org ls @ausus
#   expect: your account listed, with "owner" or "developer" role
npm profile get "two-factor auth"
#   expect: shows auth-and-writes (or "enabled")
```

> **STOP** if `npm whoami` errors `ENEEDAUTH`, if `npm org ls @ausus` still
> returns `404 Scope not found` (org not created / not yours), or if 2FA is not
> `auth-and-writes`.

**Blocker 4 cleared when:** `npm whoami` prints the account, `npm org ls @ausus`
lists it with publish rights, and 2FA is `auth-and-writes`.

---

## CLI vs Web UI — summary

| Action | CLI | Web UI |
|---|---|---|
| Merge + push `main` (Blocker 1) | ✅ all CLI | — |
| `gh auth login` | ✅ CLI command… | …with a `🖐` browser device-code paste |
| Verify CI run (Blocker 2) | ✅ all CLI | — |
| Create 10 GitHub repos (Blocker 3) | ✅ all CLI (`gh repo create`) | — |
| `npm login` | ✅ CLI command… | …with a `🖐` browser auth step |
| **Create the `@ausus` npm org** | ❌ **not possible** | 🖐 **REQUIRED** — npmjs.com/org/create |
| Enable npm 2FA | ✅ CLI (`npm profile enable-2fa`) | 🖐 alternative path |

**The only unavoidable pure web-UI step is creating the `@ausus` npm org.**
Everything else is CLI, possibly with a browser sub-step for authentication.

---

## Final "rerun rehearsal" command list

Once all 4 blockers are cleared, re-run the rehearsal to confirm runbook §2 is
all-green. All commands below are `✅ READ-ONLY` / local — **nothing is
published, pushed, tagged, or submitted.**

```bash
cd "<monorepo root>"

# 1. §2.1 toolchain
php --version && composer --version && node --version && npm --version && gh --version

# 2. §2.2 P0-A — clean tree, on main, synced
git fetch origin
git status --porcelain                                   # empty
git branch --show-current                                # main
git rev-list --left-right --count origin/main...HEAD     # 0  0

# 3. §2.3 P0-B — local + remote CI on the exact commit
RELEASE_COMMIT="$(git rev-parse HEAD)"
bash scripts/ci.sh                                       # "all 10 steps passed"
gh run list --commit "${RELEASE_COMMIT}" --limit 1 \
  --json conclusion --jq '.[0].conclusion'               # success

# 4. §2.4 P0-C + §2.5 P0-D — repos exist, empty, no v0.1.0 tag
#    (the combined loop from Step 3.2 above — expect 10× "✓ READY")
git ls-remote --tags origin refs/tags/v0.1.0             # empty (monorepo)

# 5. §2.6 npm identity
npm whoami                                               # account name
npm org ls @ausus                                        # account listed

# 6. §2.7 npm artifact pre-inspection
( cd renderer/react && npm run build && npm pack --dry-run )

# 7. §2.8 registry reachability
curl -fsS https://repo.packagist.org/packages.json >/dev/null && echo "✓ Packagist"
curl -fsS https://registry.npmjs.org/-/ping        >/dev/null && echo "✓ npm"

# 8. Composer artifact readiness
for f in composer.json packages/*/composer.json; do
  composer validate --no-check-publish --no-check-lock --no-check-version "$f" >/dev/null \
    && echo "✓ $f" || echo "✗ $f"
done

# 9. Build / test gates
bash scripts/clean-room.sh                               # "ALL STEPS PASSED"
bash scripts/integration-http.sh                         # "passed=12 failed=0"
```

> **Do NOT run runbook §2.9** (`git tag v0.1.0`) during the rerun rehearsal —
> creating the local tag is the first real step of the actual publication, not
> a rehearsal step. The rerun stops at verification.

---

## Final expected state — before re-running `docs/PUBLICATION-RUNBOOK.md` §2

| # | Condition | Expected |
|---|---|---|
| 1 | `git rev-parse origin/main` == `git rev-parse main` | equal (`a0307cb`) |
| 2 | Working tree | clean, on `main`, `0 0` vs `origin/main` |
| 3 | `chore/publication-runbook` branch | deleted (merged) |
| 4 | `gh auth status` | logged in; account can admin `adonko3xBitters` |
| 5 | `ssh -T git@github.com` | authenticates successfully |
| 6 | GitHub Actions run for the release commit | `completed success` |
| 7 | 10 repos `adonko3xBitters/{kernel … starter}` | all exist, **0 commits**, **no `v0.1.0` tag** |
| 8 | `npm whoami` | prints the operator's npm account |
| 9 | `@ausus` npm org | exists; operator is owner/publisher |
| 10 | npm 2FA | `auth-and-writes`; TOTP device in hand |
| 11 | Packagist + npm registries | reachable (HTTP 200) |
| 12 | `RELEASE-NOTES-v0.1.0.md` | still `PUBLICATION HOLD` (unchanged by this plan) |

When **all 12** rows hold, the 4 HOLD blockers from
`docs/RELEASE-REHEARSAL-v0.1.0.md` are cleared and the operator may execute
`docs/PUBLICATION-RUNBOOK.md` §2 for real. **Publication itself still does not
begin until every P0 gate in §2 passes** and the operator deliberately starts
Phase 1 of runbook §3.

> **Out of scope for this plan:** the other unmerged feature branches on
> `origin` (`chore/contract-governance`, `chore/hardening-pass`, etc.) are
> **not** part of v0.1.0. The runbook and release notes were deliberately
> anchored to `main`. Do **not** merge those branches as part of clearing these
> blockers.
