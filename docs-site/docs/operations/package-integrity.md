---
id: package-integrity
title: Package Integrity
sidebar_label: Package Integrity
description: How AUSUS release artifacts are verified before publication.
---

# Package Integrity

Before AUSUS is published, its release artifacts are verified — that the
Composer packages build, that the npm tarball contains what it should, and that
the whole stack works from a clean checkout. This page describes those checks.

:::info Source
The checks here are drawn from the in-repo validation scripts
(`scripts/ci.sh`, `scripts/clean-room.sh`, `scripts/integration-http.sh`), the
[Publication Runbook](publication-runbook.md) artifact pre-inspection step, and
the [Release Rehearsal](release-rehearsal.md) results.
:::

## Composer manifest validation {#composer-manifest-validation}

Every package manifest is validated:

```bash
composer validate composer.json
composer validate packages/<pkg>/composer.json
```

The release gate validates all **11 manifests** — the workspace root plus the
10 packages — and requires zero failures.

## Composer artifact build {#composer-artifact-build}

Each Composer package is built into an archive and its contents inspected:

```bash
composer archive --working-dir=packages/<pkg> --format=tar --dir=/tmp/registry
tar -tf /tmp/registry/ausus-<pkg>-0.1.0.tar
```

This confirms each package produces a clean, self-contained archive at version
`0.1.0`. The rehearsal runs this for all 10 Composer packages.

## npm tarball inspection {#npm-tarball-inspection}

The renderer's npm tarball is inspected with a dry-run pack — **never** a real
publish during verification:

```bash
cd renderer/react
npm run build
npm pack --dry-run
```

The output reports the package name, version, file count, and packed size. The
runbook records these figures during pre-flight; the real `npm publish` later
must produce the **same** figures. A divergence means a source/dist desync and
is a STOP condition.

## End-to-end gates {#end-to-end-gates}

Artifact validity is necessary but not sufficient — the stack must also *work*.
Three gates exercise it:

| Gate | What it proves |
|---|---|
| `scripts/ci.sh` | the 10-step build: validate, install, playground, boot, renderer build, render trace, `npm pack --dry-run`, HTTP integration |
| `scripts/clean-room.sh` | the whole stack rebuilds and passes in an **isolated temp directory** — no reliance on local state |
| `scripts/integration-http.sh` | 12 assertions against a **live** `php -S` server and the renderer — a real HTTP round-trip |

The clean-room rebuild is the strongest integrity signal: it copies the
sources into a fresh location and proves the packages install and run with
nothing left over from the development environment.

## Irreversibility — why verification is strict {#irreversibility--why-verification-is-strict}

Verification is strict because publication is **partly irreversible**:

- a published **Packagist** version is permanent;
- a published **npm** version is unpublishable only within **72 hours**.

There is no "undo" to fall back on, so every discoverable defect must be caught
*before* the first publish. See the [Publication Runbook](publication-runbook.md).

## Deferred — supply-chain attestation {#deferred--supply-chain-attestation}

The following supply-chain controls are **not** in v0.1.0 and are accepted as
deferred risk for v0.2.0:

- npm provenance (`npm publish --provenance`) — build attestation;
- GPG-signed git tags;
- a software bill of materials (SBOM);
- a reproducible-build container.

Until those land, artifact trust rests on the validation gates above and on
GitHub/registry transport security.

## Related {#related}

- [Publication Runbook](publication-runbook.md) · [Release Rehearsal](release-rehearsal.md)
- [Packages](../packages/index.md) — the catalog being verified.
