# AUSUS — Release Engineering

This document explains the publication architecture of AUSUS, the
historical packaging defect that broke `v0.1.0` / `v0.1.1` /
`v0.2.0-alpha.1`, the root cause, the remediation that landed in
`v0.2.0-alpha.3`, and the operational checklist for cutting any
future release. It is binding on every operator who tags or publishes
under the `ausus/` namespace.

## 1. Publication architecture

```
  ┌─────────────────────────────────┐
  │  ausus-framework (monorepo)     │  ← contributors push here
  │  github.com/adonko3xBitters/    │
  │  ausus-framework                │
  └────────────┬────────────────────┘
               │  git subtree split --prefix=packages/<name>
               │  (deterministic, content-addressable)
               ▼
  ┌─────────────────────────────────────────────────────────────┐
  │  Dedicated subtree-split repos (rel-* remotes)              │
  │  github.com/adonko3xBitters/<package>  (× 10)               │
  │    kernel, runtime-default, persistence-sql, api-http,      │
  │    standard-stack, starter,                                 │
  │    tenancy-row, audit-database, auth-bridge,                │
  │    presentation-default                                     │
  └────────────┬────────────────────────────────────────────────┘
               │  GitHub webhook → Packagist
               │  (auto-indexes any pushed tag)
               ▼
  ┌─────────────────────────────────┐
  │  Packagist                      │  ← `composer require ausus/*`
  │  packagist.org/packages/ausus/* │     pulls from here
  └─────────────────────────────────┘
```

Three invariants:

1. **The monorepo is never the Packagist source.** It contains code
   for ten packages plus apps, docs, RFCs — none of that belongs in a
   single-package tarball.
2. **Each `ausus/<name>` package is published from
   `github.com/adonko3xBitters/<name>`** (its dedicated subtree-split
   repo, mounted as remote `rel-<name>` from the monorepo).
3. **Tags are created on the subtree-split repos**, not on the
   monorepo. Packagist's auto-indexer reads tags from the configured
   source; tags on the monorepo are operator-internal markers only.

## 2. The historical bug (v0.1.0 → v0.2.0-alpha.1)

For three releases (`v0.1.0`, `v0.1.1`, `v0.2.0-alpha.1`), every
`ausus/<name>` package tarball on Packagist **contained the entire
monorepo**, not the per-package subtree.

Concretely, an external consumer running `composer require
ausus/kernel:^0.1` would end up with:

```
vendor/ausus/kernel/
├── composer.json    "name": "ausus/monorepo"     ← WRONG
├── packages/
│   ├── kernel/      ← the real kernel was buried here
│   ├── api-http/
│   ├── persistence-sql/
│   ├── runtime-default/
│   └── … (6 more)
├── apps/
├── docs-site/
├── renderer/
├── scripts/
└── .github/
```

Visible symptoms reported by external testers:

- `class_exists('Ausus\Compiler')` → `false` (autoload classmap pointed
  at `apps/playground`, not at the real `packages/kernel/src/`)
- `class_exists('Ausus\Application')` → `false`
- `interface_exists('Psr\Http\Server\RequestHandlerInterface')` →
  `false` (the monorepo `composer.json` shipped as the package
  manifest did not list `psr/http-server-handler` in `require`)
- `vendor/ausus/<pkg>/composer.json` was a copy of the root monorepo
  manifest, complete with `"type": "path"` repositories pointing at
  `packages/*` paths that don't exist in a Packagist install
- Ten packages × ~20 MB each = 200 MB of duplicated monorepo content
  per single-package install
- Only workaround was to manually point a PSR-4 autoload at
  `vendor/ausus/<one-of-them>/packages/<actual-package>/src/`

Tooling around the monorepo (the local `scripts/clean-room.sh`)
installed via `composer install` against the **path repositories**, so
the bug was never visible in local CI. Every release's `[ci] DONE —
all 10 steps passed` was technically true and substantively
misleading.

## 3. Root cause

Three causally linked failures, in chronological order:

### 3.1 Packagist sourced from the wrong repo

The Packagist entry for each `ausus/<name>` was originally configured
to fetch from the **monorepo** URL
(`github.com/adonko3xBitters/ausus-framework`) instead of from the
dedicated subtree-split repo
(`github.com/adonko3xBitters/<name>`). On every tag push to the
monorepo, Packagist ran `git archive` against the entire monorepo
tree, packaged it whole, and indexed that tarball under whichever
`ausus/*` name had been requested.

This was the **direct cause** of the monorepo-in-tarball symptom.

### 3.2 Subtree-split script danger without fail-fast

The procedure to populate the `rel-*` repos was a hand-run loop
combining `git subtree split` + `git checkout split/<pkg>` + `git tag`
+ `git push`. When this loop was rerun **without** `set -euo
pipefail` and **without** returning to `main` between iterations, a
mid-loop failure could cascade silently: a `subtree split` that
errored out left the previous package's `split/<previous>` branch
checked out, the next iteration's `git tag` and `git push` then
operated on the **wrong SHA** without any visible error. The result —
a corrupted tag on a downstream `rel-*` repo — was indistinguishable
from a healthy publication until consumers complained.

Independent of the Packagist source bug above, this script-shape bug
could (and did) corrupt individual package tags during remediation.

### 3.3 Validation was path-repo-only

`scripts/ci.sh` and `scripts/clean-room.sh` both run `composer
install` against the monorepo with **path-repo** entries declared in
the root `composer.json`'s `repositories` block. Path repos resolve
locally without touching Packagist. Both scripts have always exited
`OK` against working source, regardless of whether the published
Packagist tarballs were correct. There was no automated gate that
talked to the public registry.

## 4. Remediation (v0.2.0-alpha.2 → v0.2.0-alpha.3)

Six interventions, in the order they were applied:

| # | Intervention | Effect |
|---|---|---|
| 1 | Confirmed the 10 `rel-*` repos contain only the correct subtree-split content (no monorepo embedding) | Eliminated 3.1 *upstream of* Packagist |
| 2 | Reconfigured each Packagist entry to source from `github.com/adonko3xBitters/<name>` instead of from the monorepo | Eliminated 3.1 *at* Packagist |
| 3 | Published `v0.2.0-alpha.2` to validate the reconfigured pipeline (still with old `0.1.*` internal constraints) | Demonstrated end-to-end that consumer install produces a clean `vendor/ausus/*` |
| 4 | Bumped 17 internal `ausus/*` constraints from `0.1.*` to `^0.2@alpha` across the 6 composer.json files that have inter-package deps; bumped path-repo `versions` overrides from `0.1.999` to `0.2.999` | Allowed `composer require ausus/standard-stack:^0.2@alpha` to pull the v0.2 line throughout the dependency chain |
| 5 | Published `v0.2.0-alpha.3` with the new constraints | Made the runtime hardening Phase A+B+C accessible to public consumers |
| 6 | Added `scripts/public-install.sh` + CI step `11` | Catches any future regression of 3.1 / 3.2 / 3.3 at build time |

Validation procedure (now codified in `scripts/public-install.sh`):

- `composer init -n --type=project --stability=alpha` in a fresh tmp dir
- `composer require ausus/standard-stack:^0.2@alpha` with `--no-cache`
- Assert all `ausus/*` installed at `v0.2.0-alpha.3`
- Assert `vendor/ausus/<each>/` contains no `packages/`, `apps/`,
  `docs-site/`, `renderer/`, `scripts/`, or `.github/`
- Assert 12 public classes/interfaces are reachable via Composer autoload
- Smoke `Application::create(...)` against SQLite returns an
  `Application` instance

## 5. Official release checklist

Run, in order, **before** pushing any new `v0.X.Y` tag to a `rel-*`
repo. Stop and reset state if any step fails. Do not push partial
releases.

```
[ ] 1. Working tree clean, on `main`, fast-forwarded to origin/main.
[ ] 2. Bumps to composer.json (if any) committed and pushed to main.
[ ] 3. scripts/ci.sh ends with "[ci] DONE — all 11 steps passed".
[ ] 4. scripts/clean-room.sh ends with "[clean-room] ALL STEPS PASSED".
[ ] 5. scripts/public-install.sh ends with "[public-install] OK"
       against the *previous* release (regression check that the
       Packagist chain still works for the latest published version).
[ ] 6. Delete all local split/* branches:
       for p in api-http audit-database auth-bridge kernel persistence-sql \
                presentation-default runtime-default standard-stack starter tenancy-row; do
           git branch -D split/$p 2>/dev/null || true
       done
[ ] 7. Regenerate all 10 splits ONCE, from main (NOT in the same
       loop as checkout/tag/push):
       for p in <10 packages>; do
           git subtree split --prefix=packages/$p -b split/$p
       done
[ ] 8. Tag + push, topological order, return to main between each
       checkout (or accept that all subtree splits are pre-done in
       step 7 and only checkout/tag/push runs here):
       - Level 1: kernel + 4 reserved (no ausus deps)
       - Level 2: runtime-default + persistence-sql (deps: kernel)
       - Level 3: api-http (deps: kernel + runtime-default)
       - Level 4: standard-stack (deps: 4 of the above)
       - Level 5: starter (deps: 4 of the above)
[ ] 9. Verify the 10 remote tags:
       for p in <10>; do
           tag=$(git ls-remote --tags rel-$p vX.Y.Z | awk '{print $1}' | head -1)
           expected=$(git rev-parse split/$p)
           [ "$tag" = "$expected" ] || echo "MISMATCH: $p"
       done
[ ] 10. Wait 1–3 minutes for Packagist webhook auto-indexing.
[ ] 11. Validate the new release against Packagist:
        EXPECTED_VERSION="vX.Y.Z" bash scripts/public-install.sh
[ ] 12. Tag the monorepo (operator-internal marker, NOT a Packagist
        source) at the same SHA on main that produced the splits.
[ ] 13. Update RELEASE-NOTES-vX.Y.Z.md and CHANGELOG.md if not yet done.
[ ] 14. Update README.md badge + compatibility matrix.
```

## 6. Anti-patterns

The following anti-patterns are how the historical bug landed and how
remediation almost re-introduced it. None of them should appear in any
operator's workflow:

| Anti-pattern | Why it breaks |
|---|---|
| `git subtree split` from a `split/*` branch | The current branch's history is the subtree-split history of a *different* package; the new split for the current package finds no matching prefix and either errors out (visibly) or, worse, silently no-ops while the subsequent `git tag` operates on the WRONG SHA. |
| Combining `subtree split` + `checkout` + `tag` + `push` in a single for-loop without returning to `main` between iterations | The second iteration's subtree split runs from the first iteration's `checkout split/<previous>`, triggering the bug above. **All 10 subtree splits MUST be generated from `main` first**, then a separate loop does only `checkout + tag + push`. |
| Bash scripts without `set -euo pipefail` | A failed `git subtree split` exits non-zero but the loop continues; the failure is silently absorbed and downstream `tag` / `push` operate on stale state. |
| Tagging a release before validating it on Packagist | If Packagist still serves a broken tarball (cache, indexing latency, source-URL misconfiguration), the tag is published before anyone can know it's invalid. |
| Treating `scripts/clean-room.sh` (path-repo install) as proof of distribution health | Path repos and Packagist tarballs are different artifacts. A path-repo install can be green while the public install is broken end-to-end — that is exactly how the v0.1.x defect went undetected for three releases. **Only `scripts/public-install.sh` proves the public distribution is healthy.** |
| Tagging on the monorepo and assuming Packagist will use that tag | Packagist sources from the configured repo per package — the dedicated `rel-*` repo. Monorepo tags are operator-internal only. The remediation explicitly disconnected Packagist from the monorepo to prevent this confusion. |
| Hand-rolled "this time I'll be careful" subtree-split runs | The procedure above is mechanical for a reason. Use `scripts/release-subtree.sh` (when it exists) or the documented for-loops verbatim. No manual checkpoints. |
| Skipping step 5 of the checklist ("Validate the previous release still installs") | A Packagist webhook misconfiguration or registry hiccup can break the previous version while you weren't looking. Catching it before adding a new tag is cheap; catching it after, with a downstream consumer report, is expensive. |

## 7. Operational notes

- **Webhook trust.** GitHub → Packagist auto-indexing has been
  verified functional for the AUSUS namespace; tags pushed to `rel-*`
  appear on Packagist within 1–3 minutes. If a tag does not appear,
  the manual fallback is the "Update" button on each package's
  Packagist page, or the API call documented at
  <https://packagist.org/about#how-to-update-packages>.
- **`composer.lock` in the consumer.** Once a consumer has resolved
  the chain, `composer.lock` pins the exact versions. `composer
  update` re-resolves against current Packagist state. Consumers
  should `composer update --no-cache` periodically to pick up
  re-indexed tarballs after any registry-side correction.
- **Yanking historical broken versions.** `v0.1.0`, `v0.1.1`, and
  `v0.2.0-alpha.1` remain on Packagist's index for traceability.
  They can be marked `abandoned` per package via Packagist's admin
  if a future external consumer would otherwise pull them by
  mistake. Marking abandoned does not remove them; it just emits a
  deprecation warning on install.
- **Custom Packagist source URLs.** Should never be needed for AUSUS
  consumers. The default `https://repo.packagist.org` is correct.
  Anyone documenting an alternate "satis" or "private packagist" URL
  for AUSUS in their own setup must run `scripts/public-install.sh`
  against that registry — point `COMPOSER_REPOSITORIES` at it before
  invocation.

## 8. Permanent invariants (locked at v0.2.0-alpha.4)

These rules are non-negotiable. Any PR or release procedure that
violates them must be rejected without debate.

### 8.1 Tag protection ruleset (HIGH-12)

Every tag matching `v*.*.*` patterns is protected by a GitHub Ruleset.
No tag can land without `release-gate / gate` workflow success.

**Activation (one-time, BEFORE the first v0.2.0-alpha.4 tag push):**

Via GitHub UI:

```
Settings → Rules → Rulesets → New ruleset
  Name:               alpha-tag-protection
  Enforcement status: Active
  Target:             Tags
  Target patterns:    v*.*.*-alpha.*, v*.*.*-beta.*, v*.*.*-rc.*, v*.*.*
  Bypass actors:      (none)
  Rules:
    [x] Restrict creations
    [x] Restrict updates
    [x] Restrict deletions
    [x] Require status checks to pass:
        - release-gate / gate (required)
```

Via `gh` CLI:

```bash
gh api -X POST repos/adonko3xBitters/ausus-framework/rulesets \
  -H "Accept: application/vnd.github+json" \
  -F name='alpha-tag-protection' \
  -F target='tag' \
  -F enforcement='active' \
  -F 'conditions[ref_name][include][]=refs/tags/v*' \
  -F 'rules[][type]=required_status_checks' \
  -F 'rules[][parameters][required_status_checks][][context]=release-gate / gate' \
  -F 'rules[][type]=non_fast_forward' \
  -F 'rules[][type]=deletion'
```

**Verify the rule is active:**

```bash
gh api repos/adonko3xBitters/ausus-framework/rulesets \
  | jq '.[] | select(.name=="alpha-tag-protection")'
```

This rule **must** be in place before any v0.2.0-alpha.4+ tag push. Any
deactivation triggers an audit log entry and must be justified in a
release postmortem.

### 8.2 Rebase merge prerequisite (MED-3)

The repository `Settings → General → Pull Requests` MUST have:

| Setting | Required value |
|---|---|
| Allow rebase merging | ☑ enabled |
| Allow squash merging | ☑ enabled (acceptable but loses commit granularity) |
| Allow merge commits | ☐ disabled (creates noise on main) |

The v0.2.0-alpha.4 hotfix used 8 granular commits intentionally. Squash
merging it would erase the commit-by-commit traceability required by
`docs/RELEASE-ENGINEERING.md` §5 checklist step 3 (`scripts/ci.sh ends
with [ci] DONE`).

**Verify:**

```bash
gh repo view adonko3xBitters/ausus-framework --json mergeCommitAllowed,rebaseMergeAllowed,squashMergeAllowed
# Expected: {"mergeCommitAllowed":false,"rebaseMergeAllowed":true,"squashMergeAllowed":true}
```

### 8.3 npm dist-tag policy

The `@ausus/renderer-react` package is published with this dist-tag rule:

| Tag shape | dist-tag(s) applied |
|---|---|
| `vX.Y.Z-alpha.N`, `vX.Y.Z-beta.N`, `vX.Y.Z-rc.N` | `@next` |
| `vX.Y.Z` (stable) | `@latest` |
| Any pre-release, **IFF no stable `1.x.x` exists on npm** | `@next` AND promoted to `@latest` |

Rationale:
- During the alpha-only phase, `@latest` points at the current alpha so
  `npm install @ausus/renderer-react` (default `@latest`) returns
  something installable.
- Once a stable `1.x.x` ships, `@latest` moves to it and stays. Subsequent
  pre-releases only update `@next`.

Enforced in `.github/workflows/npm-publish.yml`. The `HAS_STABLE` check
queries `npm view @ausus/renderer-react versions --json`.

### 8.4 ViewSchema compatibility (peerSchemaVersion)

`@ausus/renderer-react/package.json` declares a top-level
`peerSchemaVersion` field. The renderer accepts any backend release
whose `schemaVersion` satisfies this semver range.

| Renderer release | Backend `schemaVersion` change required? |
|---|---|
| Adds optional widgets/props | No |
| Bumps `peerSchemaVersion` minor (`^1.0.0` → `^1.0.0 || ^2.0.0`) | Yes (coordinated with backend `schemaVersion: 2.0.0`) |
| Drops `peerSchemaVersion` minor range | Breaking — major bump |

The backend release that bumps `schemaVersion` MUST coordinate a
renderer release that expands `peerSchemaVersion` to include the new
range. CI gate: `scripts/check-renderer-alignment.sh` in
`release-gate.yml`.

### 8.5 Source-of-truth files

| File | Purpose | Read by |
|---|---|---|
| `docs-site/CURRENT_VERSION` | Documented current alpha version | `scripts/check-doc-version.sh` |
| `renderer/react/package.json` `version` | Active npm tag | `scripts/check-renderer-alignment.sh`, `.github/workflows/npm-publish.yml` |
| `renderer/react/package.json` `peerSchemaVersion` | ViewSchema compat range | `scripts/check-renderer-alignment.sh` |
| `packages/runtime-default/src/runtime.php` `schemaVersion` | Wire format version emitted | `scripts/check-renderer-alignment.sh` |

No other file is permitted to claim a version. README badges + Docusaurus
quickstart pull from these source files. Drift = CI red.

## 9. Document version

This document describes the release engineering state as of the
`v0.2.0-alpha.4` cut. Update Section 4 (`Remediation`) and Section 5
(`Checklist`) on every release that materially changes the
publication procedure. Section 6 (`Anti-patterns`) and Section 8
(`Permanent invariants`) are append-only — adding new lessons learned
or new locked-down rules but never removing the history that explains
them.
