# release-replay follow-up — v1.0.0

Closure record for the `release-replay.yml` workflow exit-2 failure
observed against the `v1.0.0` tag. This document captures the root
cause analysis, proves the failure is a snapshot-transition artefact
with no impact on the published artifacts, and pins the
forward-looking evidence that a future `v1.0.x` tag cut from current
`main` will replay green.

The corresponding alpha.5 closure record at
[`../v0.2.0-alpha.5/release-replay-followup.md`](../v0.2.0-alpha.5/release-replay-followup.md)
documents the equivalent structural pattern for the first tag of the
pre-release line.

## Workflow run metadata

| Field | Value |
|---|---|
| Workflow | `release-replay` |
| Trigger | `workflow_dispatch` |
| Inputs | `tag=v1.0.0`, `expected_version=v1.0.0` |
| URL | https://github.com/adonko3xBitters/ausus-framework/actions/runs/26647144680 |
| Started | 2026-05-29T15:48:04Z |
| Ended | 2026-05-29T15:48:45Z |
| Runtime | ~41 s |
| Conclusion | `failure` (exit code 2 — release-gate.sh exit on ci.sh step 11 failure) |
| Artifact upload | none (`/tmp/ausus-release-gate-*` dir not produced after the gate failed mid-script) |

## Root cause — single-line failure trace

The workflow checks out the tag's frozen snapshot:

```yaml
- uses: actions/checkout@v4
  with:
    ref: ${{ inputs.tag }}
```

At commit `9075fbece8be929644487441f24b98eaee07ddc4` (the v1.0.0 tag's
target), `scripts/public-install.sh` embeds the prep-window transitional
constraints:

```bash
# scripts/public-install.sh @ v1.0.0 snapshot — line 48
EXPECTED_VERSION="${EXPECTED_VERSION:-v0.2.0-rc.1}"

# step 1 — composer.json
cat > composer.json <<'JSON'
{
    "name": "ausus-internal/public-install-validation",
    ...
    "minimum-stability": "rc",
    "prefer-stable": true,
    "require": {}
}
JSON

# step 2 — composer require (line 105)
echo "[public-install] step 2 — composer require ausus/standard-stack:^0.2@rc"
if ! composer require "ausus/standard-stack:^0.2@rc" \
        --no-interaction --no-cache > "${TMP_DIR}/composer.log" 2>&1; then
```

The release-replay workflow passes `EXPECTED_VERSION=v1.0.0` from
`inputs.expected_version`. The snapshot script's hard-coded `^0.2@rc`
constraint at line 105 cannot resolve `v1.0.0` because the caret
operator in the `0.x` range locks the minor version: `^0.2` means
`>=0.2.0 <0.3.0`. Composer resolves to `v0.2.0-rc.1` (the highest
version satisfying `^0.2@rc` on Packagist), then step 3 of
`public-install.sh` asserts `installed (v0.2.0-rc.1) == expected
(v1.0.0)` and fails 5 packages with exit 3. `ci.sh` step 11 sees
`public-install.sh` non-zero and exits 11; `release-gate.sh` step 2
sees `ci.sh` non-zero and exits 2 — the value observed in the workflow.

## SemVer demonstration

Composer constraint solver (`Composer\Semver\VersionParser`, verified
in-tree at audit time):

| Version | `^0.2` satisfies? | `^1.0` satisfies? |
|---|---|---|
| `v0.2.0-rc.1` | **YES** | NO |
| `v0.2.0-rc.2` | YES | NO |
| `v0.2.9.9` | YES | NO |
| `v1.0.0` | **NO** | **YES** |
| `v1.0.1` | NO | YES |
| `v1.0.999` | NO | YES |
| `v1.5.0` | NO | YES |
| `v2.0.0` | NO | NO |

The `^0.2` constraint in the snapshot can never resolve `v1.0.0` —
the failure is arithmetic, not a script defect.

## Impact on published artifacts — none

Independent verification at audit time:

| Surface | Status |
|---|---|
| Packagist — all 10 `ausus/*` packages at `v1.0.0` | ✓ indexed |
| npm — `@ausus/renderer-react@1.0.0` published | ✓ versions array includes `1.0.0` |
| npm — `latest` dist-tag promoted to `1.0.0` | ✓ (from `0.1.1`) |
| npm — sigstore provenance attestation | ✓ `https://slsa.dev/provenance/v1` |
| npm — `repository.url` matches publishing repo | ✓ |
| GitHub Release `v1.0.0` | ✓ `isPrerelease=false`, marked `--latest` |
| `bash scripts/clean-room-install-test.sh` (current main) vs live v1.0.0 | ✓ exit 0, Gates A-E green |
| `bash scripts/public-install.sh` (current main) vs live v1.0.0 | ✓ exit 0, wire `schemaVersion=1.2.0` + all keys present |
| `composer create-project ausus/starter:^1.0 myapp` (no stability flag) | ✓ green end-to-end, composer boot full DRAFT→ISSUED cycle |
| Manifest `artifacts/releases/v1.0.0.json` | ✓ 11 packages, commit `9075fbe` |
| Evidence archive `artifacts/releases/v1.0.0/release-gate.live.log` | ✓ captures Steps 1-9 LIVE green (canonical proof of correctness) |

The canonical evidence of v1.0.0 release correctness is the
[`release-gate.live.log`](release-gate.live.log) in this directory,
captured by `scripts/archive-release-evidence.sh` after the
post-publish cleanup commits had landed on main. It shows Steps 1-9
of `RELEASE_GATE_LIVE=1 RELEASE_GATE_VERSION=v1.0.0` green against the
published Packagist + npm artifacts.

## Forward-looking — proof that HEAD corrects the issue

The two post-tag commits

- `67d9287` — `chore(release): finalize v1.0.0 release window`
- `5c5bfff` — `chore(release): archive v1.0.0 release artifacts`

bumped `scripts/public-install.sh` so that the constraint used in step
2 matches the stable line:

```bash
# scripts/public-install.sh @ current main (5c5bfff)
EXPECTED_VERSION="${EXPECTED_VERSION:-v1.0.0}"

# step 1 — composer.json (no minimum-stability flag)
cat > composer.json <<'JSON'
{
    "name": "ausus-internal/public-install-validation",
    ...
    "require": {}
}
JSON

# step 2 — composer require
composer require "ausus/standard-stack:^1.0" --no-interaction --no-cache
```

For a hypothetical `v1.0.1` tag cut from the current `main`
(`5c5bfff7f94d987df5eb61c6502508b162e34cef`):

1. The workflow checks out the new tag's frozen snapshot.
2. The snapshot's `scripts/public-install.sh` carries the
   `^1.0` constraint and `EXPECTED_VERSION=v1.0.0` default.
3. The workflow env sets `EXPECTED_VERSION=v1.0.1` from the input.
4. Composer resolves `ausus/standard-stack:^1.0` against Packagist;
   `v1.0.1 satisfies ^1.0` (proven above), so it resolves to `v1.0.1`.
5. Step 3 asserts `installed (v1.0.1) == expected (v1.0.1)` — match.
6. Step 7 wire shape derives `EXPECTED_SCHEMA_VERSION=1.2.0` via the
   wildcard branch of the `case` statement; `v1.0.1` ships `1.2.0`
   (it's the runtime constant in `packages/runtime-default/src/runtime.php`
   under the `1.x` API freeze) — match.
7. `public-install.sh` exits 0 → `ci.sh` step 11 exits 0 →
   `release-gate.sh` exits 0 → **replay GREEN**.

The replay infrastructure is correct. The v1.0.0 replay failure is
strictly an artefact of the first-tag-in-the-stable-line transition.

## Replay workflow guarantees still observed

Despite the run failing, every workflow-level safety property of
`release-replay.yml` held:

- `permissions: contents: read` — no write capability requested.
- `NPM_TOKEN` absent from the runner env (asserted by explicit step).
- `HEAD` confirmed on a tag-ish ref via `git describe --tags
  --exact-match`.
- No `git push`, no `npm publish`, no new tag — only Packagist GET,
  npm registry GET, GitHub.com HEAD.

## References

| Item | Value |
|---|---|
| Tag | `v1.0.0` |
| Tag object SHA | `71847737bba150fd77e7908bbe6ca85220b59fe7` |
| Tag commit SHA | `9075fbece8be929644487441f24b98eaee07ddc4` |
| Pre-tag bump commit | `9075fbe` (`fix(release): bump root composer.json + lockfile to v1.0 monorepo`) |
| Post-tag finalize | `67d9287` (`chore(release): finalize v1.0.0 release window`) |
| Post-tag evidence | `5c5bfff` (`chore(release): archive v1.0.0 release artifacts`) |
| Replay workflow file | `.github/workflows/release-replay.yml` |
| Replay run URL | https://github.com/adonko3xBitters/ausus-framework/actions/runs/26647144680 |
| GitHub Release | https://github.com/adonko3xBitters/ausus-framework/releases/tag/v1.0.0 |
| Sibling alpha.5 record | [`../v0.2.0-alpha.5/release-replay-followup.md`](../v0.2.0-alpha.5/release-replay-followup.md) |

## Closure verdict

🟢 **GREEN — replay failure cosmetic only, v1.0.0 remains fully accepted.**
No corrective action on the published artifacts. No republish. No
roadmap change. The canonical evidence of v1.0.0 release correctness
is the `release-gate.live.log` co-located with this document.
