# AUSUS 2.0.0 — Entity Engine

> Release notes for the metadata-first **Entity Engine** vertical slice
> (EE-RFC-011 Entity Engine / EE-RFC-012 Entity Definition). Canonical documentation:
> [`docs/v2/`](docs/v2/README.md).
>
> **Lineage note.** This is a new, self-contained line that coexists with the
> earlier `standard-stack` lineage (`RELEASE-NOTES-v0.1.x.md`, README below the
> 2.0 banner). The two use distinct package sets and namespaces. The historical
> RFCs and release artifacts are unchanged.

## What it is

You describe an application as declarative metadata (entities, fields, actions,
projections, authorization expressions). AUSUS compiles it to a content-addressed
`EntitySchema`, binds it to a persistence driver, and runs it — with data-aware,
fail-closed authorization, an HTTP API, and a generic React UI.

```
DSL → EntityDefinition → Compiler → EntitySchema → Repository (.ausus)
    → EntityEngine::bind() → RuntimeEntity (invoke/read)
    → API Runtime → React Renderer → View System
```

## Packages (this line)

| Layer | Package |
|------:|---------|
| L0 | `ausus/kernel` (Definition / Contracts / Compiled) |
| L1 | `ausus/entity-engine`, `ausus/authoring` |
| L3 | `ausus/persistence-memory` |
| L4 | `ausus/api-runtime` |
| L5 | `ausus/view-system`, `@ausus/react-renderer` |
| L6 | `ausus/cli` |

## Highlights

- **Closed authoring DSL** producing exactly an `EntityDefinition`; CLI frontend
  with a forbidden-symbol scan and one-shot evaluation.
- **Atomic, content-addressed compilation**: 16 closure invariants, canonical
  normal form, SHA-256 hash. Same semantics ⇒ same hash; binding never
  recompiles.
- **Data-aware authorization** over `actor` / `tenant` / `now` / `subject` /
  `input`, fail-closed. As of this release, **all** expression operators
  (including `ne/lte/gt/gte/in/or`) evaluate at runtime with the same reductions
  used for the hash (RELEASE-001 stabilization).
- **Driver-agnostic runtime**: transactions with rollback; reference in-memory
  driver passes a conformance harness.
- **Single-hop expand** projections, per-field visibility, framework-agnostic
  HTTP API, and an HTTP-only React renderer.
- **Three reference applications** — CRM, Teranga PMS, SGH — built purely from
  DSL + ViewDefinition.

## Quality

All 20 PHP test suites and 5 JS suites green, including the three reference-app
validations.

## Known limitations

Documented in [`docs/v2/07-known-limits.md`](docs/v2/07-known-limits.md):
expand depth = 1, no cross-entity invariants, single-field transitions, no
aggregation/computed fields, deferred `read()` selection parameters, limited
runtime integrity validation, and actor attributes limited to
`type`/`id`/`homeTenant`.

## Requirements

PHP 8.3+ ; Node 18+ for the React renderer and TypeScript tests. License: MIT.
