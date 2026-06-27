# 1. Timeline — Legacy → Entity Engine

All dates, tags, and commits below are taken directly from the repository's Git
history and release artifacts.

## Generation 1 — Legacy (`MetadataGraph` / `standard-stack`)

Five stable releases were published, plus a `v0.2.0` pre-release line that was
never stabilised (its `rc.1` went straight to `v1.0.0`).

| Version | Date | Tag / commit | Highlights (from notes & commits) |
|---|---|---|---|
| **v0.1.0** | 2026-05-20 | `v0.1.0` | First release: metadata graph, ViewSchema, `standard-stack`. |
| **v0.1.1** | 2026-05-26 | `v0.1.1` | `Application` bootstrap facade; first end-to-end create/update form path; `Action::update` (ADR-0002); nullable-column fix. |
| v0.2.0-alpha.1…5 | 2026-05-26 → 05-28 | 5 tags | Pre-release line. |
| v0.2.0-beta.1 | 2026-05-29 | `v0.2.0-beta.1` | Pre-release. |
| v0.2.0-rc.1 | 2026-05-29 | `v0.2.0-rc.1` | Release candidate → became `v1.0.0`. |
| **v1.0.0** | 2026-05-29 | `v1.0.0` (`9075fbe`) | First major stable. Published packages (per `artifacts/releases/v1.0.0.json`): `kernel, api-http, audit-database, auth-bridge, persistence-sql, presentation-default, runtime-default, standard-stack, starter, tenancy-row`, `@ausus/renderer-react`. |
| **v1.0.1** | 2026-05-29 | `v1.0.1` (`911e0cc`) | Starter quickstart hotfix. |
| **v1.1.0** | 2026-06-14 | `v1.1.0` (`3345dad`) | Integrated **RFC-015 (relations)** and **RFC-018 (guard kernel)**; added `ausus/persistence-postgres`. **Latest published version.** |

The Legacy generation rests on the RFCs `RFC-001`…`RFC-018` on disk (kernel,
persistence-driver, tenancy, **RFC-004 ViewSchema**, policy engine, workflow
runtime, audit, relations, guard kernel).

## Generation 2 — Entity Engine (`EntityDefinition` / `Compiler` / `RuntimeEntity`)

Developed in the same repository after `v1.1.0`. It was built and validated in a
sequence of internal phases:

- **IMPLEMENTATION-001** — the vertical slice: kernel DTOs + contracts →
  Canonicalizer → Hasher → Compiler + ClosureValidator → Authoring DSL → DSL
  Frontend → Schema Repository → CLI compile → AuthorizationEvaluator → Memory
  Driver → `EntityEngine::bind` + `RuntimeEntity`.
- **IMPLEMENTATION-002** — the HTTP API Runtime (L4).
- **IMPLEMENTATION-003** — the React Renderer (L5).
- **IMPLEMENTATION-004** — the View System (L5).
- **VALIDATION-001 / 002 / 003** — three reference applications (CRM, Teranga
  PMS, SGH) built only from the DSL + ViewDefinition.
- **RELEASE-001** — a single stabilisation fix (runtime evaluation of the
  expression sugar operators).

**Publication status (factual):** the Entity Engine has **no tag, no release, and
no commit** — `git ls-tree HEAD` shows its packages
(`entity-engine, authoring, api-runtime, view-system, persistence-memory,
react-renderer`), the new `kernel/src/{Definition,Contracts,Compiled}` folders,
and `apps/{crm,teranga-pms,sgh}` as **untracked**. It exists today only in the
working tree, alongside the Legacy code.

## Coexistence snapshot (today)

```
v0.1.0 ─ v0.1.1 ─ (v0.2.0-α…rc) ─ v1.0.0 ─ v1.0.1 ─ v1.1.0      ← Legacy (published)
                                                        │
                                                        └─▶  Entity Engine        ← new generation
                                                             (built + validated,
                                                              not yet published)
```

The same `ausus/kernel` package now contains **both** the Legacy `MetadataGraph`
classes (`kernel.php`) and the new `Definition` / `Contracts` / `Compiled`
folders — physical evidence that the two generations coexist.
