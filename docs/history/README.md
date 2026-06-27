# AUSUS — Architecture History

This folder explains **how AUSUS evolved** from its first published architecture
(*Legacy*, the `MetadataGraph`/`standard-stack` line) to its second generation
(*Entity Engine*, the `EntityDefinition`/`Compiler`/`RuntimeEntity` line), why the
change happened, what is kept, what changes, and how the two generations coexist
today.

It is written for a developer who discovers AUSUS years from now and needs the
context immediately. It describes only the **state actually present in this
repository** (tags, packages, tests). It does not propose changes, does not
announce a roadmap, and does not modify any code, RFC, package, or release.

## Read in order

1. [Timeline](01-timeline.md) — the full version history, Legacy → Entity Engine.
2. [Why a new architecture](02-why-new-architecture.md) — the technical drivers,
   without disparaging the first generation.
3. [What is kept](03-what-is-kept.md) — the invariants shared by both generations.
4. [What changes](04-what-changes.md) — a side-by-side comparison table.
5. [Why this is not a new framework](05-not-a-new-framework.md) — same framework,
   new generation.
6. [Status of both generations](06-status-of-generations.md) — roles, maintenance,
   compatibility.
7. [Lessons learned](07-lessons-learned.md) — what CRM / PMS / SGH validated and
   the limits they exposed.
8. [Glossary](08-glossary.md) — the vocabulary of both generations.

## One-paragraph summary

AUSUS shipped five stable releases (`v0.1.0` → `v1.1.0`) of a metadata-first
platform built around a compiled `MetadataGraph`, a ViewSchema renderer, and the
`standard-stack` packages. A second generation — the **Entity Engine** — was then
developed in the same repository: a declarative `EntityDefinition` compiled to a
content-addressed `EntitySchema`, executed by a driver-agnostic `RuntimeEntity`,
exposed by an HTTP API Runtime, and rendered by a generic React renderer and a
View System. The Entity Engine is **not yet published** (no tag, no commit) but is
validated by three reference applications (CRM, Teranga PMS, SGH). The two
generations **coexist** in the tree; the next publication will be a **new
generation of the same framework**, not a fork or a reboot.
