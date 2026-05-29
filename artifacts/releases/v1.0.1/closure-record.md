# Closure record — v1.0.1

Permanent closure record for the v1.0.1 hotfix release cycle.

## Release identity

| Field | Value |
|---|---|
| Tag | `v1.0.1` |
| Tag object SHA | `42a8763a0f9bb9922e41de27b4ac9256a1b5ab6c` |
| Tag commit SHA | `911e0cc09ab5203a3f220c6935c513e0cdb95dd3` |
| Release notes (EN) | [`docs-site/docs/releases/v1.0.1.md`](../../../docs-site/docs/releases/v1.0.1.md) |
| Release notes (FR) | [`docs-site/i18n/fr/.../releases/v1.0.1.md`](../../../docs-site/i18n/fr/docusaurus-plugin-content-docs/current/releases/v1.0.1.md) |
| GitHub Release | https://github.com/adonko3xBitters/ausus-framework/releases/tag/v1.0.1 |
| Manifest | [`v1.0.1.json`](../v1.0.1.json) |
| Replay run (green) | https://github.com/adonko3xBitters/ausus-framework/actions/runs/26656383018 |

## Closed work

- **`adonko3xBitters/starter#1`** — `composer serve` quickstart fatal
  on fresh v1.0.0 installs (`Class "Nyholm\Psr7\Factory\Psr17Factory"
  not found`). Closed `COMPLETED` at 2026-05-29T18:44:44Z.
- **`adonko3xBitters/starter` milestone v1.0.1** — closed
  (1/1 issue completed).

## Fix summary

| Surface | Change |
|---|---|
| `packages/starter/composer.json` `require` | + `nyholm/psr7: ^1.8`, + `nyholm/psr7-server: ^1.1` |
| Sibling package version alignment | renderer 1.0.0 → 1.0.1; 6 CHANGELOGs `[1.0.1]` sections |
| `scripts/clean-room-install-test.sh` | new Gate F (`composer serve` + `/api/_health` smoke), self-disables on pre-fix scaffolds |
| Documentation | new release notes EN + FR, sidebar entry, README compatibility matrix, CURRENT_VERSION |
| Runtime, API, wire, `schemaVersion`, peerSchemaVersion | **unchanged** — bit-identical to v1.0.0 |

## Validation evidence

| Gate | Result |
|---|---|
| PR #23 CI (PHP 8.3+8.4 matrix + react18/19 compat + release-gate + docs-version-check + Cloudflare Pages) | ✅ 13/13 SUCCESS |
| `bash scripts/ci.sh` (post-cleanup main) | ✅ 11/11 green |
| `bash scripts/release-gate.sh` (local) | ✅ |
| `bash scripts/clean-room-install-test.sh` against live v1.0.1 Packagist (incl. Gate F running + green) | ✅ exit 0 |
| `bash scripts/public-install.sh` against live v1.0.1 Packagist | ✅ exit 0, wire 1.2.0 keys present |
| Manual `composer create-project ausus/starter myapp` → `composer boot` → `composer serve` → `curl /api/_health` | ✅ `HTTP 200 {"ok":true,"service":"ausus/api-http","graphHash":"3701c198…"}` |
| **release-replay v1.0.1** | ✅ `success`, ~78s (run `26656383018`) |
| npm `@latest` promoted to `1.0.1` | ✅ confirmed via `npm view` |
| Packagist 6/6 sampled (`kernel`, `runtime-default`, `persistence-sql`, `api-http`, `standard-stack`, `starter`) at v1.0.1 | ✅ |
| 10/10 rel-* repos tagged | ✅ release-publish.sh Phase A+B+C green |
| `release-gate.live.log` in this archive | Steps 1–9 LIVE green against published v1.0.1 |

## Comparison with the v1.0.0 cycle

| Field | v1.0.0 | v1.0.1 |
|---|---|---|
| release-replay verdict | failure (snapshot transitional artefact, see [`../v1.0.0/release-replay-followup.md`](../v1.0.0/release-replay-followup.md)) | **success** — first tag in the v1.0.x line whose snapshot embeds `^1.0` constraints |
| Forward-looking claim from v1.0.0 follow-up | "a future v1.0.1 tag cut from HEAD will replay green" | **proven** by run `26656383018` |
| Public artifacts | 11/11 green | 11/11 green |
| Runtime change | 0 LOC | 0 LOC |
| Wire change | 0 LOC | 0 LOC |
| Closure verdict | 🟢 GREEN (cosmetic only) | 🟢 GREEN — v1.0.1 fully accepted as the latest stable |

## Reassessment of `adonko3xBitters/ausus-framework#19` (beta.1 roadmap tracking)

Issue #19 was created during the beta.1 prep cycle as a master tracking
issue for the v0.2 line. As of v1.0.1, every runtime checkbox in the
"Definition of done for v0.2.0-beta.1" has shipped on stable:

| Item | Status |
|---|---|
| Projection filtering | ✓ shipped in beta.1, frozen in v1.0 |
| Projection pagination | ✓ shipped in alpha cycle, frozen in v1.0 |
| Projection sorting | ✓ shipped in beta.1, frozen in v1.0 |
| `bin/server.php` + `composer serve` | ✓ shipped in beta.1; **starter quickstart end-to-end green from v1.0.1** |
| Release evidence archive + immutable release manifests | ✓ shipped, four cycles audited (alpha.5, beta.1, rc.1, v1.0.0, v1.0.1) |
| Release replay CI workflow | ✓ shipped + first-class evidence captured for every release |

Items classified as "acceptable post-v1.0" in the v1.0 readiness audit
(projection cache, async actions, HTTP middleware composition explicit
surface, playground bootstrap, better smoke fixtures, generated release
notes, replay CI weekly cron) remain post-v1.0 work — none surfaced as
v1.0.x blockers. Issue #19's tracking role for the v0.2 → v1.0 cycle is
**effectively complete**; reopening the discussion belongs to a future
post-1.0 roadmap, not the v1.0.x line.

## Verdict

🟢 **v1.0.1 PUBLISHED** — starter quickstart hotfix landed end to end.
No further v1.0.x corrective action queued. The 1.x contract (frozen
public API, `schemaVersion 1.2.0`, `peerSchemaVersion ^1.0.0`) remains
intact and unchanged.
