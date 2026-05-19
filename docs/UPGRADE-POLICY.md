# Upgrade · Deprecation · Rollback — AUSUS v0.1

**Status:** ratified for v0.1.x · last validated 2026-05-19
**Companion documents:** [`COMPATIBILITY-MATRIX.md`](COMPATIBILITY-MATRIX.md),
[`SEMVER-CONTRACT.md`](SEMVER-CONTRACT.md), [`RELEASE-NOTES-v0.1.0.md`](../RELEASE-NOTES-v0.1.0.md)

This document is the **operator runbook** for moving consumers across
versions. It complements `SEMVER-CONTRACT.md` (what's allowed to
change) with concrete commands and decision trees (how to do it
safely).

---

## 1. Decision tree — am I doing an upgrade or a downgrade?

```
Source version  Target version  → Action
──────────────  ──────────────    ───────────────────
0.1.0           0.1.1           → §2  patch upgrade
0.1.0           0.1.x (latest)  → §2  patch upgrade
0.1.5           0.1.3           → §3  patch downgrade
0.1.x           0.2.0           → §4  minor upgrade
0.1.x           1.0.0           → §5  major upgrade (post-1.0)
0.1.5           0.1.4 (yanked)  → §6  rollback from a broken release
React 18        React 19        → §7  peer-dep upgrade
PHP 8.3         PHP 8.4         → §8  platform upgrade
```

---

## 2. Patch upgrade (e.g. 0.1.0 → 0.1.1)

Patch releases are **always backward-compatible** with their MINOR
ancestors (see `SEMVER-CONTRACT.md §1.1`). Operator procedure:

```bash
# Composer side
composer update ausus/* --no-interaction        # picks all ausus/* patches at once
composer install                                 # lockfile refreshed by previous step

# Or, more selective:
composer update ausus/kernel --with-all-dependencies

# npm side
npm update @ausus/renderer-react
```

After update, run the consumer's existing tests. No code change should
be required. If anything breaks, the patch release is defective — file
an issue and pin back to the prior patch (§3).

**CI evidence this works:** SIM-1 in [`scripts/upgrade-sim.sh`](../scripts/upgrade-sim.sh)
reproduces a full patch bump in a sandbox and confirms `composer boot`
still completes.

---

## 3. Patch downgrade (e.g. 0.1.5 → 0.1.3)

Downgrading is safe within `0.1.*` (every patch is bidirectionally
compatible — see `COMPATIBILITY-MATRIX.md §7`).

```bash
# Composer — re-pin to the desired patch
composer require "ausus/kernel:0.1.3" "ausus/runtime-default:0.1.3" \
                 "ausus/persistence-sql:0.1.3" "ausus/api-http:0.1.3"
composer update ausus/* --with-all-dependencies

# npm — re-pin renderer
npm install @ausus/renderer-react@0.1.3
```

Or, if you simply want to roll back to the previous lockfile state:

```bash
git revert HEAD                       # the commit that updated the lockfiles
composer install                       # restore from the reverted lockfile
npm ci                                 # strict-lockfile install
```

Reverting the lockfile commit is the safest path because every
constraint resolution that worked at lockfile-commit time is
reproduced bit-for-bit.

---

## 4. Minor upgrade — 0.1.x → 0.2.x

Minor upgrades **MAY** carry breaking changes during the v0.x series
(per SemVer §4, and our v0.x interim rules in `SEMVER-CONTRACT §1.2`).
Every breaking change is called out under a `## ⚠️ Breaking changes`
section at the top of the target version's `CHANGELOG.md`.

### 4.1 Standard procedure

```bash
# 1. Read RELEASE-NOTES-v0.2.0.md AND the ⚠️ section of each affected
#    package's CHANGELOG.md.
# 2. Stage the upgrade in a feature branch.
git switch -c upgrade/v0.2.0

# 3. Bump constraints in your consumer's composer.json:
#    "ausus/kernel": "^0.1.0" → "^0.2.0"   (etc. for every ausus/* dep)
# (or simply pull the bumped starter's composer.json from
#  https://github.com/adonko3xBitters/ausus-framework/blob/v0.2.0/packages/starter/composer.json)

# 4. Resolve + install
composer update ausus/* --with-all-dependencies

# 5. Run your local suite. Address any breakage one CHANGELOG bullet at
#    a time.

# 6. Run scripts/ci.sh against your project (or the AUSUS analogue).
# 7. Merge upgrade branch only after CI is green.
```

### 4.2 If a minor upgrade introduces an opt-in flag

Some breaking changes ship behind feature flags during the same MINOR
that introduces them. Read each flag's CHANGELOG entry for the opt-in
timeline; flags become default-on in a later MINOR and required in a
MAJOR (post-1.0). See §11 for the deprecation cycle that drives this.

### 4.3 Skipping a MINOR

We support skipping MINORs (e.g. 0.1.x → 0.5.0 directly), but every
breaking change in every intermediate MINOR must be applied
cumulatively. The release notes for the target version cite every
prior MINOR's breaking-changes block; following them in order is the
safest path.

---

## 5. Major upgrade — post-1.0

This applies *after* the framework reaches v1.0.0. The strict-SemVer
regime in `SEMVER-CONTRACT §1.1` takes effect:

1. Every breaking change has been preceded by a `@deprecated` MINOR
   warning at least 1 cycle prior (§11).
2. Read the migration guide in the target MAJOR's
   `RELEASE-NOTES-vN.0.0.md`.
3. Apply migrations one bullet at a time, running tests between each.
4. Bump constraints to `^N.0.0` once green.

Until v1.0.0 ships, this section is forward-looking — no MAJOR
upgrade procedure has yet been exercised in anger.

---

## 6. Rollback from a broken release

A "broken release" is one where a published version (Packagist or npm)
breaks consumers in a way that wasn't caught before publish.

### 6.1 If detected within 72 hours of publish

```bash
# npm — unpublish is allowed within 72 h
npm unpublish @ausus/renderer-react@0.1.X

# Packagist — unpublish is NEVER allowed. Publish 0.1.X+1 with the fix.
git tag v0.1.X+1
git push origin v0.1.X+1
# Packagist's webhook auto-publishes the new tag.
```

### 6.2 If detected after 72 hours

```bash
# npm — deprecate instead of unpublish
npm deprecate @ausus/renderer-react@0.1.X "broken; use 0.1.X+1"

# Packagist — same path: publish 0.1.X+1; consumers on ^0.1.0 float forward.
#             For critical CVE, email security@packagist.org for a
#             manual version-yank (avg response 24 h).
```

### 6.3 Operator-side rollback (consumer perspective)

```bash
# Re-pin to a known-good patch
composer require "ausus/kernel:0.1.X-1" "ausus/runtime-default:0.1.X-1" \
                 "ausus/persistence-sql:0.1.X-1" "ausus/api-http:0.1.X-1"
composer update ausus/*
npm install @ausus/renderer-react@0.1.X-1
```

Or, if your lockfile from before the bad upgrade is still in git history:

```bash
git checkout <commit-before-bad-upgrade> -- composer.lock package-lock.json
composer install                                 # restore from old lockfile
npm ci
```

### 6.4 The rollback procedure exercised here

The two PR branches `chore/hardening-pass` and `chore/contract-governance`
in this repo are dry-runs of "partial-rollback" by branch-revert: each
adds atomic, independently-revertable commits. If any of them broke
something, `git revert` would restore main without affecting the
others.

---

## 7. React peer-dep upgrade — 18 → 19 (or future 19 → 20)

`@ausus/renderer-react` declares `peerDependency: "^18 || ^19"`. The
upgrade procedure for consumers:

```bash
# Inside the consumer project:
npm install react@^19 react-dom@^19

# That's it — @ausus/renderer-react itself does not need a bump. The
# CI react-compat job exercises both 18 and 19 on every PR (matrix
# in .github/workflows/ci.yml).
```

If a future MINOR (e.g. v0.2.0) drops React 18 from peer:

1. The renderer's CHANGELOG will carry a `## ⚠️ Breaking changes`
   bullet announcing the drop.
2. The `peerDependencies` block will change from `"^18 || ^19"` to
   `"^19 || ^20"`.
3. Consumers still on React 18 will see `npm install` produce an
   `ERESOLVE` error (same shape as `COMPATIBILITY-MATRIX §2.2`) — never
   silent breakage.

### 7.1 React downgrade (e.g. testing on React 18 after CI moved to 19)

```bash
npm install react@^18 react-dom@^18    # within current peer range
```

Validated by SIM-5 and the `react-compat` CI job (both pass against 18
and 19).

---

## 8. PHP version upgrade — 8.3 → 8.4 (and beyond)

Every ausus/* package declares `"php": ">=8.3"`. Operator procedure:

```bash
# Install the new PHP. Then:
composer install --no-interaction
composer --working-dir=packages/starter boot
```

If the framework ever bumps its minimum PHP version (e.g. to 8.4 or
8.5), the change ships in a MINOR with a `## ⚠️ Breaking changes`
bullet. Composer's platform check produces the verbatim error in
`COMPATIBILITY-MATRIX §2.3` if the consumer's PHP doesn't satisfy.

---

## 9. Mixed-version upgrades (partial install)

Within v0.1.x, consumers can upgrade individual packages without
upgrading the others — SIM-2 in `upgrade-sim.sh` proves this works.

```bash
# Upgrade kernel alone:
composer require "ausus/kernel:^0.1.1"
# composer auto-resolves the consistent state: kernel@0.1.1 +
# runtime-default@0.1.0 + persistence-sql@0.1.0 + api-http@0.1.0.
```

Across MINORs this is **not** supported — bumping one package to 0.2.x
while peers stay at 0.1.x produces the verbatim error in
`COMPATIBILITY-MATRIX §2.1` (SIM-3).

---

## 10. Lockfile management

| Action | Effect on lockfiles | Recommendation |
|---|---|---|
| Bumping any ausus/* constraint in a manifest | mark lockfile stale | run `composer update <pkg>` immediately |
| Running `composer install` with mismatched lockfile | warning + install from lock | resolve drift before commit |
| Committing manifest changes alone (no lockfile) | CI fails on stale-lock check | always commit both manifest + lockfile together |
| Running `npm ci` with stale `package-lock.json` | hard failure | regenerate via `npm install` |
| Running `npm install` (consumer side) | partial resolve; may pick newer transitives | use `npm ci` in CI for determinism |

The workspace commits both `composer.lock` and `package-lock.json` —
they're the reproducibility contract. Don't add them to `.gitignore`
in consumer projects either.

---

## 11. Deprecation strategy (post-1.0)

Until v1.0.0, MINORs may ship breaking changes directly. Once v1.0
arrives, the following deprecation cycle becomes mandatory for any
STABLE-tier surface (see `API-GOVERNANCE.md` for tier definitions):

```
MINOR N    │ Symbol marked @deprecated in source + CHANGELOG.
           │ Behavior unchanged. Consumers may begin migrating.
           │
MINOR N+1+ │ Symbol still works. Continued deprecation notice.
           │ Coexistence with the replacement is mandatory.
           │
MAJOR (next)│ Symbol removed. CHANGELOG entry for the MAJOR cites the
           │ deprecation notice from MINOR N.
```

### 11.1 Deprecation surface

Each deprecation entry in a CHANGELOG must contain:

```
- ⚠️ DEPRECATED: <FQN of the symbol>
    Replace with: <new FQN / pattern>
    Removed in:   v<X+1>.0.0
    Migration:    <one-paragraph guide, or link to MIGRATION-vX.md>
```

### 11.2 What never gets deprecated (just changed)

- INTERNAL surfaces (per API-GOVERNANCE.md §6) — changed at will, never deprecated.
- EXPERIMENTAL surfaces — called out in the CHANGELOG when changed, but
  no deprecation cycle required.
- ACCIDENTAL exposures — fixed in place, no deprecation.
- EXAMPLE code (starter Hello*, playground) — replaced freely.

---

## 12. Rollback playbook (atomic)

In emergency, the operator's rollback runbook is:

```bash
# 1. Identify the bad version (e.g., ausus/kernel@0.1.5)
# 2. Pick the last known-good patch (e.g., 0.1.4)
# 3. Run the consumer-side downgrade:

composer require "ausus/kernel:0.1.4" --no-update
composer require "ausus/runtime-default:0.1.4" --no-update
composer require "ausus/persistence-sql:0.1.4" --no-update
composer require "ausus/api-http:0.1.4" --no-update
composer update ausus/* --with-all-dependencies

npm install @ausus/renderer-react@0.1.4

# 4. Re-run consumer's existing test suite. If green, redeploy.
# 5. File an issue on github with the failing scenario from 0.1.5.
```

**Estimated rollback wall time:** < 60 seconds on a 2025-era laptop
(npm 1.5 s, composer 0.3 s, your suite varies).

---

## 13. Determination

GO — every upgrade path enumerated above is either

- exercised by `scripts/upgrade-sim.sh` (SIM-1 through SIM-7), or
- documented as a future-MAJOR procedure (§5, §11) that mirrors the
  already-proven `0.x → 0.x` mechanics.

No silent-breakage path was discovered. Every incompatible state
produces an explicit composer / npm error with the verbatim format
captured in `COMPATIBILITY-MATRIX §2.1–§2.3`.

For the per-tier semver guarantees, see
[`SEMVER-CONTRACT.md`](SEMVER-CONTRACT.md).
For the matrix of supported runtimes, see
[`COMPATIBILITY-MATRIX.md`](COMPATIBILITY-MATRIX.md).
