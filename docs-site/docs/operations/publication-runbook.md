---
id: publication-runbook
title: Publication Runbook
sidebar_label: Publication Runbook
description: How AUSUS v0.1.0 is published to Packagist and npm — safely.
---

# Publication Runbook

Publishing AUSUS means pushing 10 Composer packages to Packagist and one
package to npm. Some of those steps are **irreversible**. This page summarises
the controlled procedure used to do it safely.

:::info Normative source
The authoritative, normative runbook is `docs/PUBLICATION-RUNBOOK.md` in the
repository. This page is a browsable summary. If the two ever disagree, the
in-repo runbook wins.
:::

## Irreversible operations {#irreversible-operations}

Three operations in a release cannot be cleanly undone — handle them with care:

| Operation | Reversibility |
|---|---|
| **`npm publish`** | unpublishable only within a **72-hour window**; after that, only `npm deprecate` |
| **Packagist submission** | **never reversible** — a published version is permanent |
| **Pushing a git tag** to a release repo | recoverable only if Packagist has not yet scraped it |

Because of these, publication is **phase-gated**: each phase verifies before
the next begins.

## P0 pre-flight gates {#p0-pre-flight-gates}

Publication does not start until every **P0** control passes. A failed P0 gate
means **STOP** — it is release-blocking by definition.

| Gate | Requirement |
|---|---|
| P0-A | clean working tree, on `main`, synced with `origin` |
| P0-B | CI green on the **exact commit** being tagged |
| P0-C | all 10 per-package release repos exist **and are empty** |
| P0-D | no `v0.1.0` tag pre-exists on any release repo or the monorepo |

Plus npm identity checks: `npm whoami`, `npm org ls @ausus`, and 2FA readiness.

## Publication order {#publication-order}

Packages are published in **dependency-topological order** so that every
package's dependencies are already on Packagist when it is submitted:

```
Phase 1  kernel + the 4 reserved skeletons
Phase 2  persistence-sql, runtime-default        (depend on kernel)
Phase 3  api-http                                (depends on runtime-default)
Phase 4  standard-stack, starter                 (top-level compositions)
Phase 5  @ausus/renderer-react  (npm)
Phase 6  post-publish smoke tests
Phase 7  monorepo tag + GitHub release
```

All Composer packages are published **before** npm, so the framework is never
in a state where the renderer is live but its backend is not.

## Packagist propagation {#packagist-propagation}

After a package is submitted, Packagist takes **roughly 30–120 seconds** to
index it. The runbook **polls** for indexing before publishing any package that
depends on it — publishing a dependent too early causes a resolution failure
on a fresh install. Propagation delay is expected behaviour, not a fault.

## STOP-if-this-fails {#stop-if-this-fails}

Every phase ends in a gate. If a gate fails the procedure **stops** rather than
working around it — for example, if a per-package push is rejected as a
non-fast-forward, the repo was not empty (a P0-C regression) and the operator
investigates instead of force-pushing.

## Rollback {#rollback}

There is no true "unpublish":

- **npm** — within 72 hours, `npm unpublish` is possible; after that,
  `npm deprecate`.
- **Packagist** — never reversible. The only remedy is to **roll forward** to
  `0.1.1`: bump every affected manifest, re-run the phased publication, and
  re-poll Packagist. Budget around 30 minutes.

## Legacy v0.1.x line {#legacy-v01x}

AUSUS `v0.1.x` is **legacy** and superseded by the v0.2 alpha line. The
`v0.1.0` and `v0.1.1` artifacts on Packagist predate the dedicated
subtree-split repositories and were published from the monorepo, which
means a `composer create-project ausus/starter` invocation without
`--stability=alpha` resolves to those legacy tarballs and scaffolds a
monorepo-shaped project rather than a starter (see
[Troubleshooting → monorepo installed instead of the starter](../tutorial/troubleshooting.md#monorepo-installed-as-starter)).

The legacy tags are **not deleted** — they remain in Packagist's index for
historical traceability and to satisfy any external lockfile that still
pins them. Manual operator actions during a future release window:

1. Mark `ausus/starter` `v0.1.x` as `abandoned` on packagist.org (with a
   pointer to the v0.2 alpha line) so users without an explicit
   `--stability=alpha` flag see a clear deprecation notice. Apply the
   same `abandoned` marker to the other `ausus/*` `v0.1.x` packages if
   discoverability becomes a problem.
2. Do **not** delete or rewrite the `v0.1.x` tags on GitHub. Immutable
   release history is a documented invariant.
3. Keep the v0.1.x release notes (`v0.1.0.md`, `v0.1.1.md`) shipped in
   `docs-site/docs/releases/` and the sidebar — they remain the source
   of truth for what those legacy artifacts contain.

The canonical install command for every new project, until `v1.0.0`
stable ships, is:

```bash
composer create-project "ausus/starter:^0.2@alpha" myapp --stability=alpha
```

## Related {#related}

- [Release Rehearsal](release-rehearsal.md) — the dry-run that verifies the gates.
- [Package Integrity](package-integrity.md) — artifact verification.
- [Release Notes v0.1.0](../releases/v0.1.0.md)
