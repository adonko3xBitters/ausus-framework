# release-replay follow-up — v0.2.0-alpha.5

Second production run of `.github/workflows/release-replay.yml` against
v0.2.0-alpha.5 after the post-tag script fixes landed on main
(`433556d`, `e2f06ce`, `5439a9b`). This note captures the run result
plus a parallel local demonstration that the current-main scripts
running against the same alpha.5 published artifacts are green —
which is what a fresh tag would replay as.

## Workflow run #2 metadata

| Field | Value |
|---|---|
| Workflow | `release-replay` |
| Trigger | `workflow_dispatch` |
| Inputs | `tag=v0.2.0-alpha.5`, `expected_version=v0.2.0-alpha.5` |
| URL | https://github.com/adonko3xBitters/ausus-framework/actions/runs/26608693886 |
| Started | 2026-05-28T23:37:37Z |
| Ended | 2026-05-28T23:38:38Z |
| Runtime | ~60 s |
| Conclusion | `failure` (exit code 8 — clean-room install failure) |
| Artifact upload | none (no `/tmp/ausus-release-gate-*` dir produced after the gate failed mid-script) |

## Why the workflow still fails on alpha.5

The workflow checks out **the tag**:

```yaml
- uses: actions/checkout@v4
  with:
    ref: ${{ inputs.tag }}
```

That snapshot contains `scripts/clean-room-install-test.sh` **as it
was at tag time** — i.e. with the Gate E loop that includes
`ausus/starter` (the bug fixed in `433556d` on main but not on the tag).
The post-tag fixes never reach the replay because annotated tags are
immutable.

This is the **expected, documented semantics of replay-against-tag**:
the replay's job is to reproduce exactly what an operator would have
seen at the tagged moment in time. The first replay (run 26607582291)
exposed the latent bug; the second replay confirms the snapshot is
unchanged.

## Parallel demonstration: current main scripts vs alpha.5 live artifacts

Run locally on `2026-05-28T23:43Z`:

```bash
EXPECTED_VERSION=v0.2.0-alpha.5 bash scripts/clean-room-install-test.sh
# → [clean-room] OK — quickstart works end to end (exit 0)

bash scripts/public-install.sh
# → [public-install] OK (exit 0)
```

| Gate | Result | Notes |
|---|---|---|
| clean-room A — no monorepo dirs | ✓ | `packages/ apps/ docs-site/ renderer/ .github/ scripts/` all absent |
| clean-room B — composer.json.name == ausus/starter | ✓ | scaffold name correct |
| clean-room C — no `repositories[]` | ✓ | length 0 |
| clean-room D — `composer boot` | ✓ | full DRAFT → ISSUED → projection render cycle |
| clean-room E — installed versions | ✓ | kernel + runtime-default + persistence-sql + api-http + standard-stack all at `v0.2.0-alpha.5` (starter excluded per `433556d` — it IS the root) |
| public-install steps 1–6 | ✓ | composer require, vendor structure clean, autoload reaches v0.2 surface, Application::create smoke succeeds |
| public-install step 7 — wire shape | ✓ | `EXPECTED_SCHEMA_VERSION=1.0.0` derived from alpha.5, all required keys present |

The alpha.5 **published artifacts are clean**. The replay-against-tag
failure is a script-snapshot artefact, not a runtime regression.

## Implication for beta.1

The beta.1 tag will be cut with the current main scripts already
baked in. A future `release-replay` invocation against the beta.1 tag
will check out a snapshot containing:

- `scripts/clean-room-install-test.sh` with the Gate E starter
  exclusion (`433556d`);
- `scripts/check-renderer-provenance.sh` (`e2f06ce`);
- `scripts/public-install.sh` with the schemaVersion 1.2.0 assertion
  (`eb7bd7a`);
- `scripts/integration-http.sh` with the 25 live filter+sort
  assertions (`6b01c5a`);
- the matrix release-gate workflow (`5439a9b`).

That replay is expected to land green end to end — the v0.2.0-alpha.5
gap was a one-time, documented, post-tag-fix-window slip.

## Workflow guarantees still observed (replay #2)

The workflow-level safety properties from the first replay continue
to hold:

- `permissions: contents: read` — no write capability requested.
- `NPM_TOKEN` absent from the runner env (asserted in the workflow
  itself by an explicit step).
- HEAD confirmed on the tag-ish ref (`git describe --tags --exact-match`).
- No `git push`, no `npm publish`, no new tag — only Packagist GET,
  npm registry GET, GitHub.com HEAD.
- Reproducible: same inputs produce the same failure on the same
  snapshot.
