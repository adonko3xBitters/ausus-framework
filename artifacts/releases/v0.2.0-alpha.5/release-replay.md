# release-replay execution — v0.2.0-alpha.5

First production exercise of `.github/workflows/release-replay.yml`. The
workflow rebuilds the LIVE `release-gate.sh` against an already-published
tag without re-publishing anything; this is the auditable evidence that
the replay infrastructure works as designed.

## Run metadata

| Field | Value |
|---|---|
| Workflow | `release-replay` |
| Trigger | `workflow_dispatch` |
| Inputs | `tag=v0.2.0-alpha.5`, `expected_version=v0.2.0-alpha.5` |
| URL | https://github.com/adonko3xBitters/ausus-framework/actions/runs/26607582291 |
| Started | 2026-05-28T23:07:41Z |
| Ended | 2026-05-28T23:08:36Z |
| Runtime | ~55 s |
| Conclusion | `failure` (exit code 8 — clean-room install failure) |
| Artifact uploaded | yes (`/tmp/ausus-release-gate-*` under retention 30 d) |

## Outcome interpretation

The replay **correctly executed the documented contract**:

- Workflow checked out the v0.2.0-alpha.5 tag (read-only, `contents: read`),
  installed the Composer + npm dependencies frozen at that commit, and ran
  `RELEASE_GATE_LIVE=1 bash scripts/release-gate.sh`.
- Steps 1 — 8 + Step 4b green: structural validation, ci.sh inner steps,
  doc-version, renderer alignment, renderer provenance metadata, homepage
  HTTP 200s, Packagist `create-project` live, scaffold `composer boot`,
  npm registry presence.
- Step 9 (`clean-room starter install`) failed with exit 1 → release-gate
  exit 8.

The Step 9 failure reproduces a known bug in `scripts/clean-room-install-test.sh`
**as it existed at tag time**: the v0.2.0-alpha.5 snapshot's Gate E loop
included `ausus/starter` (the scaffold's root project), which has no
discoverable version via `composer show ausus/starter` from inside `myapp/`.
The bug was fixed in commit `433556d` AFTER the tag — see
`fix(ci): exclude ausus/starter from clean-room Gate E version check`.

This is the **intended demonstration** of replay's value: a tag's
historical snapshot can contain latent regressions invisible to the
running-main gate. The replay surfaced that gap honestly.

## Replay invariants observed

All workflow-level guards held:

- `permissions: contents: read` — no write access requested.
- `NPM_TOKEN` absent from the runner env (asserted by an explicit step).
- HEAD was confirmed on the tag-ish ref (`git describe --tags --exact-match`).
- No new tag created, no `git push`, no `npm publish` — confirmed by
  observing the workflow log; the only outbound traffic was Packagist GET
  + npm registry GET + GitHub.com homepage HEAD.

## Follow-up

- Future releases should land tag-script-state fixes BEFORE cutting the
  tag, so replay produces a green run end-to-end.
- The Gate E fix already on main (commit `433556d`) means a fresh tag
  cut today would replay green; v0.2.0-alpha.5 is the only existing tag
  exposing the gap.
