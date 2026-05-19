# Contributing to AUSUS

Thanks for your interest in AUSUS. This document is the canonical source
for branching, commits, releases, and local validation.

---

## 1. Local setup

```bash
git clone https://github.com/adonko3xBitters/ausus-framework.git
cd ausus-framework
composer install     # PHP workspace via path repositories
npm install          # npm workspace (renderer + playground)
bash scripts/ci.sh   # 9-step validation gate — must end with "all 9 steps passed"
```

**Requirements** (matches the v0.1.0 compatibility matrix):

- PHP ≥ 8.3 with `ext-pdo` + `ext-pdo_sqlite`
- Composer ≥ 2.0
- Node ≥ 18
- npm ≥ 8

---

## 2. Branching strategy — trunk-based

We use **trunk-based development**:

| Branch | Rule |
|---|---|
| `main` | always green. Every merge passes `bash scripts/ci.sh`. Direct commits to `main` are forbidden after v0.1.0; everything lands via PR. |
| `feature/<short-slug>`  | new features. Short-lived (< 1 week). Rebase onto `main` before merge. |
| `fix/<short-slug>`      | bug fixes. Same lifecycle as `feature/*`. |
| `release/v<x.y.z>`      | release preparation (changelog, version bump). Merged then deleted. |
| `hotfix/v<x.y.z>`       | emergency fixes off a release tag. Cherry-pick to `main`. |

**No `develop` branch.** Trunk-based gives faster iteration and works
well with one maintainer.

### Branch naming

```
feature/renderer-edit-view
fix/workflow-state-mismatch-from-issued
release/v0.2.0
hotfix/v0.1.1
```

---

## 3. Commit convention — Conventional Commits

Format:

```
<type>(<scope>): <subject>

<body — optional, wrap at 72 chars>

<footer — optional: BREAKING CHANGE, Refs #, etc.>
```

### Types

| Type | When |
|---|---|
| `feat`     | new feature or capability |
| `fix`      | bug fix |
| `perf`     | performance-only change |
| `refactor` | code change that does not change behavior |
| `docs`     | documentation only |
| `test`     | adding/fixing tests |
| `chore`    | tooling, CI, build, deps |
| `style`    | formatting only (no logic change) |
| `revert`   | reverts a prior commit |

### Scopes

Use the package short name when applicable:

```
feat(kernel): add Reference::equals()
fix(persistence-sql): handle null _version on first write
docs(renderer): add EditView usage example
chore(ci): bump GitHub Actions PHP matrix to 8.4
```

### Breaking changes

```
feat(kernel)!: rename Compiler::compile() to Compiler::build()

BREAKING CHANGE: callers must update Compiler usage. See MIGRATION-v0.2.md.
```

The `!` after the scope **and** the `BREAKING CHANGE:` footer together
trigger a major version bump in semver-release tooling.

---

## 4. Pull request workflow

1. Open the PR against `main`.
2. Reference any related RFC or issue in the body.
3. PR description must include a "How was this tested" section.
4. CI must be green (`scripts/ci.sh` runs as part of GitHub Actions).
5. One approval required for merge.
6. **Squash-merge** is the default (keeps `main` history linear).

### PR template

See `.github/PULL_REQUEST_TEMPLATE.md`.

---

## 5. Release process — Semantic Versioning

We follow [SemVer 2.0](https://semver.org/):

| Bump | When |
|---|---|
| MAJOR (`0.x.0` → `1.0.0`) | Breaking change to any public contract |
| MINOR (`0.1.0` → `0.2.0`) | New feature, fully backward-compatible |
| PATCH (`0.1.0` → `0.1.1`) | Bug fix, no API change |

**During the `0.x` series**, MINOR bumps may carry breaking changes
(per SemVer §4) — but we minimize these and call them out in
`CHANGELOG.md`.

### Step-by-step

```bash
# 1. Branch off main
git switch -c release/v0.2.0

# 2. Bump all 10 manifests in lockstep:
#    - packages/*/composer.json: "version": "0.1.0" → "0.2.0"
#    - renderer/react/package.json: "version": "0.1.0" → "0.2.0"
#    - all cross-references "0.1.*" → "0.2.*"

# 3. Update every CHANGELOG.md with the new section
# 4. Write RELEASE-NOTES-v0.2.0.md

# 5. Validate end-to-end
bash scripts/ci.sh         # in-place
bash scripts/clean-room.sh # isolated

# 6. Commit, PR, merge
git commit -am "release: v0.2.0"
git push origin release/v0.2.0
# → open PR → merge

# 7. Tag from main
git switch main && git pull
git tag -a v0.2.0 -m "Release v0.2.0"
git push origin v0.2.0

# 8. Publish (see RELEASE-NOTES-v0.2.0.md §5 for exact commands)
```

### Hotfix flow

```bash
git switch -c hotfix/v0.1.1 v0.1.0    # branch off the tag, not main
# ... fix ...
git commit -am "fix(persistence-sql): handle null _version on first write"
git tag -a v0.1.1 -m "Hotfix v0.1.1"
git push origin v0.1.1
git switch main && git cherry-pick v0.1.1
```

---

## 6. Local validation gates

Before opening a PR, every contributor runs:

```bash
bash scripts/ci.sh         # 9 steps — composer validate, install, smoke,
                           # composer boot, npm install, build, trace, pack
bash scripts/clean-room.sh # 8 steps — isolated /tmp install
```

Both scripts must end with their respective "all steps passed" line.

---

## 7. Documentation conventions

- **RFCs** live in `rfcs/`. New architecture proposals get a new RFC.
- **Design docs** live in `docs/`. They are the operational counterpart
  to RFCs (deployment notes, real-pass evidence, post-mortems).
- **CHANGELOG.md** per publishable package follows
  [Keep a Changelog](https://keepachangelog.com/).
- **README.md** per package is consumer-facing; **CHANGELOG.md** is
  history.

---

## 8. Code style

- **PHP:** strict types (`declare(strict_types=1)`), readonly value
  objects, `final` by default. No global state. PSR-12 indentation.
- **TypeScript:** strict mode, `moduleResolution: NodeNext`, **explicit
  `.js` extensions** on relative imports (required by Node ESM).
- **No comments explaining the obvious** — names should carry meaning.
  Comments document *why*, not *what*.

---

## 9. License

By contributing, you agree your work is licensed under the
[MIT License](LICENSE).
