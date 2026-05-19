# AUSUS v0.1.0 — Release Notes

**Release date:** 2026-05-19
**Release type:** Release Candidate / initial public release
**Status:** **GO for publication** (see §10 for the GO determination)

---

## 1. Summary

First public release of the AUSUS framework — a metadata-first, plugin-first
Laravel-native enterprise application platform. This release ships:

- **3 implemented PHP libraries** (kernel, persistence-sql, runtime-default)
- **1 PHP project template** (starter)
- **1 PHP metapackage** (standard-stack) pinning the V0 set
- **4 reserved PHP names** (tenancy-row, audit-database, auth-bridge, presentation-default) — name reservations only, no source code
- **1 npm package** (`@ausus/renderer-react`) — React 18+ renderer for RFC-004 ViewSchema

All 10 publishable packages are at version **0.1.0**.

## 2. Packages in this release

### Publishable to Packagist

| Order | Package | Type | Implementation | LOC |
|---|---|---|---|---|
| 1 | `ausus/kernel`              | library     | full | 442 |
| 2 | `ausus/persistence-sql`     | library     | full | 315 |
| 3 | `ausus/runtime-default`     | library     | full | 400 |
| 4 | `ausus/tenancy-row`         | library     | name reservation | 0 |
| 5 | `ausus/audit-database`      | library     | name reservation | 0 |
| 6 | `ausus/auth-bridge`         | library     | name reservation | 0 |
| 7 | `ausus/presentation-default`| library     | name reservation | 0 |
| 8 | `ausus/standard-stack`      | metapackage | meta | 0 |
| 9 | `ausus/starter`             | project     | full + boot script | 171 + 117 (bin/) |

### Publishable to npm

| Order | Package | Type | LOC | Tarball |
|---|---|---|---|---|
| 1 | `@ausus/renderer-react` | React 18+ library | 549 src | **10.9 kB packed** (21 files, incl. LICENSE) |

## 3. Compatibility matrix

| Layer | Tool | Minimum | Tested with | Notes |
|---|---|---|---|---|
| **Runtime: PHP** | `php`           | 8.3   | 8.4.18 | strict types, readonly classes, `final` by default |
| **Runtime: PHP** | `ext-pdo`       | bundled | bundled | required by `ausus/persistence-sql` |
| **Runtime: PHP** | `ext-pdo_sqlite` | bundled | bundled | required by `ausus/starter` (other PDO drivers work for the library) |
| **Tooling: PHP** | `composer`      | 2.0   | 2.9.5 | path repositories, artifact repositories, `--no-install` |
| **Runtime: JS**  | `node`          | 18    | 22.22.0 | strict ESM (`type: module`, NodeNext resolution) |
| **Runtime: JS**  | `react`         | ^18 or ^19 | 18.3.1 | declared as `peerDependency` |
| **Runtime: JS**  | `react-dom`     | ^18 or ^19 | 18.3.1 | declared as `peerDependency` |
| **Tooling: JS**  | `npm`           | 8     | 10.9.4 | workspaces |
| **Tooling: JS**  | `typescript` (dev) | 5.4 | 5.x | `moduleResolution: NodeNext` |

**Explicit non-dependencies (none of these are required):**
Laravel framework, Eloquent, Filament, Tailwind, any UI component library,
Vite, Webpack, Babel, Jest, PHPUnit, Doctrine, Symfony components.

## 4. Publish order (dependency-graph topological)

### 4.1 Packagist (PHP)

PHP packages MUST publish in this order — each depends only on packages
already published above it.

```
1. ausus/kernel                  (no ausus/* deps)
2. ausus/persistence-sql         (deps: ausus/kernel)
3. ausus/runtime-default         (deps: ausus/kernel)
4. ausus/tenancy-row             (name reservation, no deps)
5. ausus/audit-database          (name reservation, no deps)
6. ausus/auth-bridge             (name reservation, no deps)
7. ausus/presentation-default    (name reservation, no deps)
8. ausus/standard-stack          (deps: kernel + persistence-sql + runtime-default)
9. ausus/starter                 (deps: kernel + persistence-sql + runtime-default)
```

Within the same dependency level, package order is alphabetical for
reproducibility. Steps 4–7 can be parallelized.

### 4.2 npm

```
1. @ausus/renderer-react   (peers: react, react-dom — both already on npm)
```

## 5. Exact publish commands

### 5.1 Per-PHP-package — git subtree split + tag + Packagist submit

Performed once per package, in the order from §4.1. Run from the monorepo
root:

```bash
PKG=kernel    # repeat for each package in §4.1 order
VERSION=v0.1.0
ORG=ausus-framework   # GitHub organization (must exist)

# 1. Subtree-split the package into its own branch
git subtree split --prefix="packages/${PKG}" -b "split/${PKG}"

# 2. Push to the per-package repo on GitHub
git remote add "rel-${PKG}" "git@github.com:${ORG}/${PKG}.git" 2>/dev/null || true
git push "rel-${PKG}" "split/${PKG}:main"

# 3. Tag the release on the per-package repo
git clone "git@github.com:${ORG}/${PKG}.git" "/tmp/release-${PKG}"
cd "/tmp/release-${PKG}"
git tag -a "${VERSION}" -m "Release ${VERSION}"
git push origin "${VERSION}"

# 4. Submit to Packagist (one-time per package)
echo "Open: https://packagist.org/packages/submit?repo_url=https://github.com/${ORG}/${PKG}"
# After first submission, set the Packagist webhook on the GitHub repo:
#   Settings → Webhooks → Add → https://packagist.org/api/github
#   Future tags then auto-update Packagist.

cd -
```

Total time per package: ~30 seconds (most of it git remote setup).
Total for all 9 packages: ~5 minutes interactively.

### 5.2 @ausus/renderer-react — npm publish

```bash
# 1. One-time: claim @ausus on npmjs.org
#    https://www.npmjs.com/org/create  →  org name: ausus
#    Add yourself as owner. Grant publish-permission.

# 2. Build + verify from monorepo root
cd "/path/to/ausus-monorepo"
npm install
npm run build
cd renderer/react
npm pack --dry-run    # expected: ausus-renderer-react-0.1.0.tgz, 21 files, 10.9 kB

# 3. Publish (still inside renderer/react/)
npm login             # interactive
npm publish           # publishConfig.access=public is already set in package.json
```

Wall time: ~10 seconds for the actual publish call.

## 6. Post-publish smoke (for the operator)

Run from any clean directory (NOT inside the monorepo):

```bash
# PHP path — exercises Packagist resolution end-to-end
composer create-project ausus/starter myapp
cd myapp && composer boot
# expected: "OK — ausus/starter boots cleanly."

# npm path — exercises npm registry resolution end-to-end
mkdir /tmp/consumer && cd /tmp/consumer
npm init -y
npm install @ausus/renderer-react react@18 react-dom@18
node -e "console.log(Object.keys(require('@ausus/renderer-react')))"
# expected: AususProvider, useAusus, useViewSchema, useAction, ViewSchemaConsumer,
#           ListView, DetailView, ActionModal, WorkflowBadge, FieldDisplay
```

## 7. Rollback procedure

### 7.1 PHP (Packagist)

**Packagist does not support deleting published versions.** The accepted
rollback patterns are:

| Severity | Procedure |
|---|---|
| **Broken release** (security, crashes) | Publish `0.1.1` with the fix immediately. Add `<0.1.1` to security advisories. Optionally mark `0.1.0` deprecated via the Packagist UI ("Mark abandoned and recommend …"). |
| **Critical security** | Email security@packagist.org with the CVE and request manual version-yank. They will mark it unavailable. Average response: 24 h. |
| **Wrong content shipped** | Tag `0.1.1` from the correct content; `0.1.0` remains visible but `0.1.*` consumers float to `0.1.1`. |

The 9 packages are **independent** on Packagist — rolling back one does
not require rolling back the others.

### 7.2 npm

```bash
# Within 72 hours of publish — unpublish is allowed
npm unpublish @ausus/renderer-react@0.1.0
# Caveat: name is locked for 24 h after unpublish; cannot republish same version
```

After 72 h:

```bash
# Mark broken — consumers see warning on install but install still proceeds
npm deprecate @ausus/renderer-react@0.1.0 "Broken on Node ESM; use 0.1.1+"
# Then publish a fixed 0.1.1:
cd renderer/react && npm version patch && npm publish
```

### 7.3 Decision tree

```
Issue detected within 72 h of publish?
├── Yes, npm only       → npm unpublish (still must publish 0.1.1 afterward)
├── Yes, Packagist only → publish 0.1.1; Packagist consumers pin floats forward
└── Yes, both           → both of above, in sequence

Issue detected after 72 h?
├── Always → publish 0.1.1; deprecate 0.1.0 with reason text
```

## 8. Known limitations (deferred to v0.2.0)

| Limitation | Tracked under |
|---|---|
| Skeleton packages (tenancy-row, audit-database, auth-bridge, presentation-default) ship no code — names reserved | per-package CHANGELOG |
| `composer create-project ausus/starter myapp` uses 2 commands post-Packagist; 3 commands clean-room with `--no-install` flag | starter README |
| Persistence verified on SQLite; MySQL/Postgres designed-for but not validated under V0 | persistence-sql CHANGELOG |
| Renderer has no built-in router, theme tokens, optimistic UI, or default CSS file | renderer CHANGELOG |
| No PHPUnit test suite yet (the playground's 36 assertions cover the same surface) | CI script step 3 |

## 9. Reproducibility — final clean-room evidence

This release was validated end-to-end against the V0R2 + remediation
pass criteria. See `docs/RFC-000-v0r2-remediation.md` for the full
report. Summary:

| Suite | Result | Wall time |
|---|---|---|
| 9 composer manifests validated   | **PASS** (10/10 manifests including root) | < 1 s |
| `scripts/clean-room.sh`          | **PASS** (8/8 steps; isolated `mktemp`)   | ~15 s |
| `scripts/ci.sh`                  | **PASS** (9/9 steps; in-place)            | ~5 s  |
| Vanilla `node consumer.mjs`      | **PASS** (12/12 ESM consumer assertions)  | 0.04 s |
| `composer create-project` (clean-room) | **PASS** (3-command flow, 523 ms wall) | 0.49 s composer CPU |
| `npm pack --dry-run`             | **PASS** (10.9 kB / 21 files, incl. LICENSE) | < 1 s |

**Determination: GO for v0.1.0 publication. Publication blockers are zero.**

## 10. Release checklist (operator-runnable)

Tick each item before invoking the publish commands in §5.

- [ ] `composer validate` clean for all 9 manifests
- [ ] `composer install` clean from root (path-repo mode)
- [ ] `php apps/playground/run.php` → 36/36 assertions
- [ ] `npm install && npm run build` clean
- [ ] `npm run trace` → 12/12 assertions
- [ ] `bash scripts/ci.sh` → all 9 steps PASS
- [ ] `bash scripts/clean-room.sh` → all 8 steps PASS
- [ ] LICENSE file present in monorepo root + each publishable package
- [ ] CHANGELOG.md present in each publishable package
- [ ] `RELEASE-NOTES-v0.1.0.md` reviewed (this file)
- [ ] GitHub organization `ausus-framework` exists; per-package repos exist; SSH keys configured
- [ ] Packagist account exists with submission permission
- [ ] npm organization `ausus` claimed; current `npm whoami` has publish rights
- [ ] All edits committed to the source-of-truth monorepo branch
- [ ] **§5 publish commands executed in order**
- [ ] §6 post-publish smoke run in a fresh dir, **outside the monorepo**
- [ ] Tag the monorepo: `git tag v0.1.0 && git push --tags`
- [ ] Announce the release (channel of your choice)

---

**Final determination: GO.**
