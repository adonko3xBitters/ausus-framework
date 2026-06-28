# 5. Why this is not a new framework

It would be easy to mistake the Entity Engine for a different project. It is not.
AUSUS remains AUSUS. This document states that explicitly, with evidence.

## It is not a fork

A fork is a copy of a repository that diverges under separate ownership and
history. The Entity Engine lives in the **same repository**, on the **same
history**, under the **same `ausus/*` package namespace**, and reuses the **same
kernel value objects and `PersistenceDriver` contract** (see
[What is kept](03-what-is-kept.md)). There is no second repository and no
divergent ownership.

## It is not a reboot

A reboot discards the prior work and starts over. The Legacy generation is
**intact and untouched**: its packages, RFCs (`RFC-001`…`RFC-018`), releases
(`v0.1.0` → `v1.1.0`), and artifacts remain in place. The new model is **added
alongside** — even inside the same `ausus/kernel` package, the Legacy
`MetadataGraph` classes and the new `Definition`/`Contracts`/`Compiled` folders
sit side by side.

## It is not an abandonment

Nothing has been deleted, deprecated by removal, or unpublished. The published
v1.x line still exists exactly as released. The Entity Engine does not remove a
single Legacy file.

## It is a new generation of the same framework

The Entity Engine keeps AUSUS's identity — metadata-first, domain-first,
tenant-first, layered monorepo of `ausus/*` packages, PHP DSL, swappable driver —
and re-derives the *internal model and pipeline* around a smaller frozen core. It
is the **second generation** of AUSUS, in the same way a framework can ship a v2
that reorganises its core while remaining the same product.

This is also why the next publication is expected to be a **major** version of
the AUSUS line rather than a minor continuation of v1.1.0: the shared
`ausus/kernel` package gains a new, incompatible model, which SemVer treats as a
major change. (The version *number itself* is a release-engineering decision and
is not asserted here; see the version-history forensics report.)

## In one sentence

> The Entity Engine is AUSUS, generation 2 — same framework, same values, same
> repository; a re-derived core, not a new project.
