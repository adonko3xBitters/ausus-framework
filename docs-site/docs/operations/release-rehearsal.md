---
id: release-rehearsal
title: Release Rehearsal
sidebar_label: Release Rehearsal
description: The publication dry-run that verifies the release is ready.
---

# Release Rehearsal

A **release rehearsal** is a dry-run of the [Publication Runbook](publication-runbook.md):
it executes every safe pre-flight check and verifies prerequisites for the
unsafe steps **without publishing, pushing, tagging, or submitting anything**.

It is how the project confirms a release is genuinely ready before any
irreversible operation runs.

:::info Source
The rehearsal results live in `docs/RELEASE-REHEARSAL-v0.1.0.md` in the
repository. This page summarises that document.
:::

## What a rehearsal checks

A rehearsal walks the runbook's §2 pre-flight top to bottom:

- **Toolchain** — PHP, Composer, Node, npm, GitHub CLI versions.
- **P0-A** — clean working tree, on `main`, synced with `origin`.
- **P0-B** — CI green on the exact release commit.
- **P0-C** — the 10 per-package release repos exist and are empty.
- **P0-D** — no `v0.1.0` tag exists yet on any release repo or the monorepo.
- **npm identity** — `npm whoami`, `npm org ls @ausus`, and 2FA readiness.
- **Artifacts** — `composer validate` on all manifests, `composer archive` on
  every package, and `npm pack --dry-run` for the renderer.
- **Local gates** — `scripts/ci.sh`, `scripts/clean-room.sh`,
  `scripts/integration-http.sh`.

Every registry-mutating command is replaced with a read-only equivalent.

## Determination

A rehearsal ends with one of two determinations:

- **HOLD** — one or more P0 gates failed; publication must not proceed.
- **READY TO PUBLISH** — all P0 gates passed; the operator may begin the
  runbook's phased procedure.

The v0.1.0 rehearsal was run iteratively. Early runs returned **HOLD** while
release infrastructure was still being set up (the runbook branch needed
merging, the GitHub CLI needed authenticating, the 10 release repos needed
creating, the npm `@ausus` org needed creating). Once that infrastructure was
in place, the rehearsal returned **READY TO PUBLISH** with all 14 gates green.

## Why rehearse

The rehearsal exists because the alternative — discovering a missing release
repo or an unauthenticated CLI **mid-publication** — risks leaving the
ecosystem in a half-published, partially-irreversible state. A rehearsal moves
every discoverable failure to *before* the first irreversible operation.

## Re-verification rule

A rehearsal verifies the runbook against **one specific commit**. If any new
commit lands on `main` afterwards, the pre-flight — especially P0-A and P0-B —
must be re-run against the new `HEAD` before publishing.

## Related

- [Publication Runbook](publication-runbook.md) — the procedure being rehearsed.
- [Package Integrity](package-integrity.md) — artifact verification detail.
