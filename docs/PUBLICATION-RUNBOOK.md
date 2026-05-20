# AUSUS v0.1.0 — Publication Runbook

**Document status:** **NORMATIVE** for the v0.1.0 public release.
**Last updated:** 2026-05-19
**Owner:** release operator

> ### Authority of this document
>
> - This runbook is the **single normative source** for publishing AUSUS
>   v0.1.0 to Packagist and npm.
> - **If this runbook conflicts with `RELEASE-NOTES-v0.1.0.md`, this
>   runbook wins.** The release notes are user-facing and intentionally
>   omit the operator procedure.
> - **Any failed P0 gate means STOP.** Do not "work around" a P0 gate.
>   A failed P0 gate is, by definition, release-blocking.
> - Do not run ad-hoc publish commands. Every public-registry mutation
>   in this release goes through the phased procedure in §3.

This runbook implements the 5 P0 controls and the accepted P1 controls
from the release-operations audit. It supersedes the inline publication
steps that previously lived in `RELEASE-NOTES-v0.1.0.md`.

---

## 0. Scope & ground rules

| Item | Value |
|---|---|
| Release version          | `v0.1.0` |
| Composer packages         | 10 (see §1) |
| npm packages              | 1 (`@ausus/renderer-react`) |
| Canonical GitHub owner    | **`adonko3xBitters`** |
| Monorepo                  | `https://github.com/adonko3xBitters/ausus-framework` |
| Packagist namespace       | `ausus/*` |
| npm scope                 | `@ausus` |

**Irreversible operations in this release** (handle with maximum care):

1. **`npm publish`** — unpublishable only within **72 hours**; after that,
   `npm deprecate` is the only path.
2. **Packagist submission** — **never** reversible. A published version
   is permanent. Packagist has no unpublish.
3. **Pushing a git tag** to a per-package release repo — recoverable only
   by `git push --delete` *if* Packagist has not already scraped it.

Because of (1) and (2), the procedure is **phase-gated**: each phase has
explicit verification before the next phase may begin.

---

## 1. Packages & dependency graph

### Composer (10) — publish in dependency-topological order

```
1.  ausus/kernel               (no ausus/* deps)
2.  ausus/persistence-sql      → kernel
3.  ausus/runtime-default      → kernel
4.  ausus/api-http             → kernel, runtime-default
5.  ausus/tenancy-row          (skeleton; no deps)
6.  ausus/audit-database       (skeleton; no deps)
7.  ausus/auth-bridge          (skeleton; no deps)
8.  ausus/presentation-default (skeleton; no deps)
9.  ausus/standard-stack       → kernel, persistence-sql, runtime-default, api-http
10. ausus/starter              → kernel, persistence-sql, runtime-default
```

### npm (1)

```
@ausus/renderer-react   (peers: react, react-dom — already on npm)
```

**Dependency-safety statement:** the order above is dependency-safe —
every package's `ausus/*` requirements appear strictly earlier in the
list. `standard-stack` (9) and `starter` (10) are published last because
their `require` blocks reference packages 1–4.

---

## 2. Pre-flight checklist — P0 controls

Run top-to-bottom **before any registry mutation**. Every line must be ✓.
**Any ✗ → STOP. Do not proceed.**

### 2.1 Toolchain

```bash
php --version          # PHP 8.3 or 8.4
composer --version     # Composer 2.x
node --version         # Node 18, 20, or 22
npm --version          # npm 8+
gh auth status         # GitHub CLI authenticated
```

### 2.2 P0-A — Clean working tree

Verify a **clean working tree** before any `git subtree split` or tag:

```bash
git status --porcelain      # MUST print nothing
git rev-parse --abbrev-ref HEAD   # MUST be: main
git fetch origin
git rev-list --left-right --count origin/main...HEAD   # MUST be: 0  0
```

> **P0-A gate:** if the working tree is not a clean working tree, or the
> branch is ahead of / behind `origin/main`, **STOP**. A `git subtree
> split` against an unclean tree silently bakes uncommitted work into a
> published artifact.

### 2.3 P0-B — CI green on the exact commit being tagged

```bash
RELEASE_COMMIT="$(git rev-parse HEAD)"
echo "Release commit: ${RELEASE_COMMIT}"

# Local validation gate
bash scripts/ci.sh | tee "/tmp/ci-preflight-$(date -u +%Y%m%dT%H%M%SZ).log"
# MUST end with the line:  [ci] DONE — all 10 steps passed

# Remote CI gate — the GitHub Actions run for THIS commit must be green
gh run list --commit "${RELEASE_COMMIT}" --limit 1 \
  --json conclusion --jq '.[0].conclusion'
# MUST print: success
```

> **P0-B gate:** the CI status must belong to **`${RELEASE_COMMIT}`** — the
> exact commit you will tag. A green status for an older commit does NOT
> satisfy this gate. If CI is red or belongs to a different commit, **STOP**.

### 2.4 P0-C — Per-package GitHub repos exist AND are EMPTY

The 10 per-package release repos must already exist under
`github.com/adonko3xBitters/<pkg>` **and contain zero commits**. A repo
created via the GitHub UI with an auto-generated README or LICENSE is
**not empty** and will reject the first push as a non-fast-forward.

```bash
for PKG in kernel persistence-sql runtime-default api-http \
           tenancy-row audit-database auth-bridge presentation-default \
           standard-stack starter; do
  if ! gh repo view "adonko3xBitters/${PKG}" >/dev/null 2>&1; then
    echo "✗ MISSING repo: adonko3xBitters/${PKG}"; continue
  fi
  COMMITS="$(gh api "repos/adonko3xBitters/${PKG}/commits" --jq 'length' 2>/dev/null || echo 0)"
  if [ "${COMMITS}" = "0" ]; then
    echo "✓ EMPTY  adonko3xBitters/${PKG}"
  else
    echo "✗ NOT EMPTY adonko3xBitters/${PKG} (${COMMITS} commits) — recreate empty"
  fi
done
```

> **P0-C gate:** every line must read `✓ EMPTY`. If any repo is missing or
> non-empty, **STOP**. Create the missing repos with **no** README, **no**
> LICENSE, **no** .gitignore. Recreate any non-empty repo as empty.

### 2.5 P0-D — Remote tag `v0.1.0` must NOT already exist

For each per-package release repo, verify the remote tag `v0.1.0` is
absent before any push (a pre-existing remote tag indicates a prior,
possibly aborted, publication attempt):

```bash
for PKG in kernel persistence-sql runtime-default api-http \
           tenancy-row audit-database auth-bridge presentation-default \
           standard-stack starter; do
  EXISTING="$(git ls-remote --tags "git@github.com:adonko3xBitters/${PKG}.git" \
              refs/tags/v0.1.0 2>/dev/null)"
  if [ -z "${EXISTING}" ]; then
    echo "✓ no v0.1.0 tag yet on ${PKG}"
  else
    echo "✗ v0.1.0 ALREADY EXISTS on ${PKG} — STOP"
  fi
done

# Also the monorepo:
git ls-remote --tags origin refs/tags/v0.1.0    # MUST print nothing
```

> **P0-D gate:** if `v0.1.0` already exists on any per-package repo or on
> the monorepo, **STOP**. A pre-existing tag means a prior (possibly
> aborted) publication attempt. Tag immutability + Packagist scraping make
> this a release-blocking hazard — investigate before doing anything.

### 2.6 npm — P1 identity + 2FA controls

```bash
npm whoami                       # MUST print your npm account name
npm org ls @ausus                # MUST list your account with publish rights
```

- **2FA / TOTP readiness:** npm enforces 2FA on scoped public publishes.
  Have your authenticator (TOTP) device in hand **before** Phase 5.
- If `npm whoami` is empty → `npm login` first.
- If `npm org ls @ausus` does not list your account → you cannot publish;
  fix org membership before proceeding.

### 2.7 npm — artifact pre-inspection (P1)

```bash
cd renderer/react
npm run build
npm pack --dry-run | tee "/tmp/npm-pack-preflight-$(date -u +%Y%m%dT%H%M%SZ).log"
cd - >/dev/null
```

> Record the reported **file count** and **package size** in the
> publication log (§9). The real `npm publish` in Phase 5 must produce the
> same numbers. A divergence indicates source/dist desync — **STOP** and
> rebuild from a clean state.

### 2.8 Registry reachability

```bash
curl -fsS https://repo.packagist.org/packages.json   >/dev/null && echo "✓ Packagist reachable"
curl -fsS https://registry.npmjs.org/-/ping          >/dev/null && echo "✓ npm reachable"
```

### 2.9 Monorepo tag — created LOCALLY only (not pushed)

```bash
git tag v0.1.0           # local tag only
git tag -l v0.1.0        # confirm it exists locally
# DO NOT push it yet — the monorepo tag is pushed last, in Phase 7.
```

---

## 3. Corrected safest-possible publication order

Phase-gated. Each phase ends with a verification gate. **A failed gate
means STOP** — see the matrix in §4.

```
PHASE 0  Pre-flight (§2)                — zero registry mutations
PHASE 1  Publish kernel + 4 skeletons   — dependency leaves
PHASE 2  Publish persistence-sql, runtime-default
PHASE 3  Publish api-http
PHASE 4  Publish standard-stack, starter
PHASE 5  Publish @ausus/renderer-react (npm)
PHASE 6  Post-publish smoke (real public registries)
PHASE 7  Push monorepo tag + GitHub release
PHASE 8  72-hour monitoring window
```

### 3.1 The per-package publish function (P0-safe, idempotent)

Use this exact function for every Composer package. It implements P0-D
(tag pre-check), P0-E (`/tmp` cleanup), and the no-`--tags` rule.

```bash
# Run from the monorepo root. MONOREPO_ROOT is captured once.
MONOREPO_ROOT="$(pwd)"
VERSION="v0.1.0"
ORG="adonko3xBitters"

publish_php_package() {
  local PKG="$1"
  echo "── publishing ausus/${PKG} ─────────────────────────────────"

  # P0-E — clean any leftover from a prior interrupted run
  rm -rf "/tmp/release-${PKG}"

  # P0-D — refuse to proceed if the tag already exists remotely
  if [ -n "$(git ls-remote --tags "git@github.com:${ORG}/${PKG}.git" \
             "refs/tags/${VERSION}" 2>/dev/null)" ]; then
    echo "✗ STOP: ${VERSION} already exists on ${ORG}/${PKG}"
    return 1
  fi

  # 1. Subtree-split this package into its own branch.
  #    Safe to retry: -b fails if the branch exists, so delete it first.
  git branch -D "split/${PKG}" 2>/dev/null || true
  git subtree split --prefix="packages/${PKG}" -b "split/${PKG}"

  # 2. Push the split branch to the per-package repo's main.
  git remote remove "rel-${PKG}" 2>/dev/null || true
  git remote add "rel-${PKG}" "git@github.com:${ORG}/${PKG}.git"
  git push "rel-${PKG}" "split/${PKG}:main"

  # 3. Tag the release ON THE PER-PACKAGE REPO (cloned into a clean dir).
  git clone "git@github.com:${ORG}/${PKG}.git" "/tmp/release-${PKG}"
  ( cd "/tmp/release-${PKG}" \
    && git tag -a "${VERSION}" -m "Release ${VERSION}" \
    && git push origin "${VERSION}" )       # single tag — NEVER git push --tags

  # 4. Submit to Packagist (one-time per package).
  echo "→ Open: https://packagist.org/packages/submit?repo_url=https://github.com/${ORG}/${PKG}"
  echo "  After first submission, add the Packagist webhook on the GitHub repo:"
  echo "  Settings → Webhooks → Add → https://packagist.org/api/github"

  cd "${MONOREPO_ROOT}"
  echo "✓ ausus/${PKG} pushed + tagged. Submit to Packagist now, then poll (§3.2)."
}
```

> **Idempotency / safe-retry:** the function is retry-safe. `rm -rf
> /tmp/release-${PKG}` (P0-E) clears stale clones; `git branch -D
> split/${PKG}` clears a stale split branch; `git remote remove` clears a
> stale remote; the P0-D tag check aborts cleanly if a prior run already
> tagged. If a run is interrupted, re-running `publish_php_package <pkg>`
> resumes safely **unless** the tag was already pushed — in which case
> P0-D stops you and you must decide roll-forward vs investigate (§5).
>
> **Never `git push --tags`.** Always `git push origin v0.1.0` (a single
> explicit tag). `--tags` would push every local tag, including stray
> `split/*`-related or test tags.

### 3.2 Packagist propagation polling (P0 control)

After submitting a package to Packagist, **wait until it is actually
indexed** before publishing anything that depends on it. Packagist
propagation typically takes 30–120 s.

```bash
poll_packagist() {
  local PKG="$1"
  local URL="https://repo.packagist.org/p2/ausus/${PKG}.json"
  echo "polling Packagist for ausus/${PKG} …"
  for i in $(seq 1 30); do          # up to 30 × 10 s = 5 min
    if curl -fsS "${URL}" >/dev/null 2>&1; then
      echo "✓ ausus/${PKG} indexed on Packagist"
      return 0
    fi
    sleep 10
  done
  echo "✗ ausus/${PKG} not indexed after 5 min — STOP, investigate"
  return 1
}
```

### 3.3 Phase-by-phase

```bash
# ── PHASE 1 — kernel + 4 skeletons ──────────────────────────────────
publish_php_package kernel
poll_packagist kernel                       # GATE — must pass
for PKG in tenancy-row audit-database auth-bridge presentation-default; do
  publish_php_package "${PKG}"
done
for PKG in tenancy-row audit-database auth-bridge presentation-default; do
  poll_packagist "${PKG}"                    # GATE — all must pass
done

# ── PHASE 2 — kernel-dependent libraries ────────────────────────────
publish_php_package persistence-sql
publish_php_package runtime-default
poll_packagist persistence-sql               # GATE
poll_packagist runtime-default               # GATE

# ── PHASE 3 — runtime-dependent library ─────────────────────────────
publish_php_package api-http
poll_packagist api-http                      # GATE

# ── PHASE 4 — top-level compositions ────────────────────────────────
publish_php_package standard-stack
publish_php_package starter
poll_packagist standard-stack                # GATE
poll_packagist starter                       # GATE
```

### 3.4 PHASE 5 — npm publication

> npm and Packagist are independent registries. Publish Composer FIRST
> (Phases 1–4). Begin Phase 5 only after every Packagist gate is green.
> This ordering avoids an asymmetric state where the React renderer is
> live but its PHP backend is not.

```bash
cd renderer/react

# P1 — dry-run BEFORE the irreversible publish
npm publish --dry-run | tee "/tmp/npm-publish-dryrun-$(date -u +%Y%m%dT%H%M%SZ).log"
# Compare file count + size against the §2.7 pre-flight log. They MUST match.

# P1 — confirm identity once more, immediately before publish
npm whoami
npm org ls @ausus

# The irreversible step. Have the TOTP device ready.
npm publish                  # publishConfig.access=public is set in package.json

cd "${MONOREPO_ROOT}"
sleep 30                     # allow npm registry propagation
```

> **PHASE 5 opens the 72-hour npm-unpublish window.** From the moment
> `npm publish` succeeds, you have 72 h during which `npm unpublish` is
> permitted. After 72 h only `npm deprecate` remains.

### 3.5 PHASE 6 — post-publish smoke (real public registries)

Run from a clean directory **outside** the monorepo. This exercises real
Packagist + npm resolution end-to-end.

```bash
# PHP path
cd "$(mktemp -d)"
composer create-project ausus/starter myapp
cd myapp && composer boot
# expected: "OK — ausus/starter boots cleanly."

# npm path — React 18
cd "$(mktemp -d)"
npm init -y
npm install @ausus/renderer-react react@18 react-dom@18
node -e "console.log(Object.keys(require('@ausus/renderer-react')))"
# expected keys: AususProvider, useAusus, useViewSchema, useAction,
#                ViewSchemaConsumer, ListView, DetailView, ActionModal,
#                WorkflowBadge, FieldDisplay

# npm path — React 19 (fresh dir; the renderer's peerDependency allows ^18 || ^19)
cd "$(mktemp -d)"
npm init -y
npm install @ausus/renderer-react react@^19 react-dom@^19
node -e "console.log(Object.keys(require('@ausus/renderer-react')))"
```

> **PHASE 6 gate:** all three smokes must succeed. If `composer
> create-project` fails with "Could not find package ausus/kernel",
> Packagist propagation is incomplete — wait 2 minutes and retry **before**
> concluding the release is broken (premature rollback = an unnecessary
> version bump).

### 3.6 PHASE 7 — monorepo tag + GitHub release

Only after Phase 6 is fully green:

```bash
git push origin v0.1.0          # single tag — NEVER git push --tags
gh release create v0.1.0 --title "v0.1.0" --notes-file RELEASE-NOTES-v0.1.0.md
```

### 3.7 PHASE 8 — 72-hour monitoring window

- Watch GitHub issues, Packagist package pages, npm download/error stats.
- If a defect is found **within 72 h**, the npm side can still be
  `npm unpublish`-ed. Use the rollback procedure in §5 immediately.

---

## 4. STOP-if-this-fails matrix

Each row is a hard gate. **Gate fails → do not advance to the next phase.**

| Phase / step | Gate | If it fails |
|---|---|---|
| §2.2 P0-A | working tree clean + on `main` + synced with `origin` | STOP — commit/clean/sync, then re-run pre-flight |
| §2.3 P0-B | `scripts/ci.sh` ends `all 10 steps passed` **and** GitHub Actions `success` on `${RELEASE_COMMIT}` | STOP — fix → new commit → new green CI → re-tag locally |
| §2.4 P0-C | every per-package repo `✓ EMPTY` | STOP — create missing repos empty; recreate non-empty ones |
| §2.5 P0-D | `v0.1.0` absent on all 10 per-package repos + monorepo | STOP — investigate the prior attempt before any push |
| §2.6 | `npm whoami` + `npm org ls @ausus` both succeed | STOP — fix npm auth / org membership |
| §2.7 | `npm pack --dry-run` succeeds; numbers recorded | STOP — rebuild from clean state if it errors |
| §3.1 step 2 | `git push rel-${PKG} split/${PKG}:main` accepted | STOP — repo not empty (P0-C regression); do NOT force-push |
| §3.1 step 3 | `git push origin v0.1.0` (per-package) accepted | STOP — tag drift (P0-D); investigate before continuing |
| §3.2 | `poll_packagist <pkg>` returns ✓ within 5 min | STOP — check Packagist submission status + webhook |
| §3.3 Phase 3 | `api-http` indexed on Packagist | STOP — Phase 4 `standard-stack` would fail satisfiability |
| §3.3 Phase 4 | `standard-stack` indexed without satisfiability error | STOP — re-poll all 4 dependencies; do not publish `starter` yet |
| §3.4 Phase 5 | `npm publish --dry-run` numbers match §2.7 pre-flight | STOP — source/dist desync; rebuild |
| §3.4 Phase 5 | `npm publish` returns success (not E403 / OTP timeout) | STOP — re-auth; verify @ausus membership; retry in-session |
| §3.5 Phase 6 | all 3 smokes pass | wait 2 min for propagation, retry; only then consider §5 rollback |
| §3.6 Phase 7 | `git push origin v0.1.0` (monorepo) accepted | STOP — another publication run may be in flight |

---

## 5. Rollback procedure (realistic)

A "broken release" = a published version that breaks consumers.

> **Honest effort estimate.** Rolling back is **not** a one-line operation.
> A `0.1.1` re-cut requires bumping every affected manifest, re-running the
> entire phased publication for the affected packages, and re-polling
> Packagist. Budget ~30 minutes.

### 5.1 Within 72 hours of `npm publish`

```bash
# 1. npm — unpublish is permitted within the 72-hour window
npm unpublish @ausus/renderer-react@0.1.0
#    Caveat: the exact name+version is locked for 24 h after unpublish.

# 2. Packagist — NO unpublish exists. Roll FORWARD to 0.1.1 (see §5.3).
```

### 5.2 After 72 hours

```bash
# npm — deprecate (consumers still install, but see a warning)
npm deprecate @ausus/renderer-react@0.1.0 "Defective release — see CHANGELOG; use 0.1.1+"

# Packagist — roll forward to 0.1.1; optionally mark 0.1.0 abandoned in the Packagist UI.
```

### 5.3 Cutting `0.1.1` (the actual roll-forward — P1 realistic steps)

```bash
git switch -c release/v0.1.1

# 1. Bump the version in EVERY affected manifest:
#    - packages/<pkg>/composer.json   "version": "0.1.0" → "0.1.1"
#    - renderer/react/package.json    via:
cd renderer/react && npm version patch --no-git-tag-version && cd -
#      ^ --no-git-tag-version is MANDATORY: it bumps package.json WITHOUT
#        creating a stray git tag that would collide with the monorepo
#        tag flow.
#    - update cross-references "0.1.*" if a constraint must tighten.

# 2. Update each affected CHANGELOG.md with the 0.1.1 section + the fix.

# 3. Re-validate.
bash scripts/ci.sh                 # must end "all 10 steps passed"
bash scripts/clean-room.sh         # must end "ALL STEPS PASSED"

# 4. Commit, tag locally.
git commit -am "release: v0.1.1 (fix: <reason>)"
git tag v0.1.1

# 5. Re-run Phases 1–7 of §3 with VERSION=v0.1.1 for the affected packages.
#    Consumers pinned to "^0.1.0" float forward to 0.1.1 automatically.
```

> **Packagist is never reversible.** The only true rollback on the
> Composer side is roll-forward. Plan accordingly.

---

## 6. Structured publication log

Maintain a single dated log for the entire publication. It is the audit
trail if anything goes wrong.

```bash
PUBLISH_LOG="publication-log-$(date -u +%Y%m%d).txt"
# Tee every phase into it:
{
  echo "=== AUSUS v0.1.0 publication — $(date -u +%FT%TZ) ==="
  echo "release commit: $(git rev-parse HEAD)"
  echo "operator: $(git config user.name)"
} >> "${PUBLISH_LOG}"
```

Record, per phase:
- the exact commit hash being released,
- each `publish_php_package` invocation + its Packagist submit URL,
- each `poll_packagist` result + timestamp,
- the `npm pack --dry-run` file count + size (must match §2.7),
- the `npm publish` timestamp (starts the 72-hour clock),
- the Phase 6 smoke results,
- the Phase 7 tag-push timestamp.

Keep `${PUBLISH_LOG}` with the release records. Do **not** commit it if it
contains anything sensitive; it is an operator artifact.

---

## 7. Deferred to v0.2.0 — explicitly listed release risk

The following supply-chain controls are **not** in v0.1.0 and are
accepted as **deferred risk**. They are listed here so the operator
publishes with eyes open:

| Deferred control | Risk while absent |
|---|---|
| `npm publish --provenance` (OIDC build attestation) | consumers cannot cryptographically verify the npm tarball was built from the published source |
| GPG-signed git tags (`git tag -s`)                  | per-package release tags are unsigned; tamper-evidence relies on GitHub transport security only |
| SBOM (software bill of materials)                   | no machine-readable dependency inventory ships with the artifacts |
| Reproducible-build container                        | builds are deterministic by tool version, but not bit-reproducible across arbitrary machines |

None of these is release-blocking for v0.1.0. They are tracked for v0.2.0.

---

## 8. Final operator determination

After the full §2 pre-flight passes and Phases 1–7 complete with every
gate in §4 green:

- the 10 Composer packages are live on Packagist,
- `@ausus/renderer-react` is live on npm,
- all three Phase-6 smokes passed against the real public registries,
- the monorepo `v0.1.0` tag + GitHub release are published.

Only then is v0.1.0 **published**. Until every P0 gate in §2 has passed,
the release status is **PUBLICATION HOLD** (see `RELEASE-NOTES-v0.1.0.md`).

> **Reminder:** if this runbook and `RELEASE-NOTES-v0.1.0.md` ever
> disagree, **this runbook is authoritative.** A failed P0 gate is
> release-blocking — STOP, do not work around it.
