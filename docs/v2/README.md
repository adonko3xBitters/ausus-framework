# AUSUS 2.0 — Entity Engine (canonical documentation)

> This is the **official reference for AUSUS 2.0**, the metadata-first *Entity
> Engine* vertical slice (EE-RFC-011 Entity Engine / EE-RFC-012 Entity Definition).
> It is a self-contained line of packages — `ausus/kernel` (Definition/Contracts/
> Compiled), `ausus/entity-engine`, `ausus/authoring`, `ausus/cli`,
> `ausus/persistence-memory`, `ausus/api-runtime`, `ausus/view-system`, and
> `@ausus/react-renderer` — validated by three reference applications (CRM,
> Teranga PMS, SGH).
>
> The repository also contains an earlier `standard-stack` lineage
> (`ausus/standard-stack`, `ausus/api-http`, `ausus/runtime-default`,
> `@ausus/renderer-react`, RFC-001…018) documented by the root `README.md` and
> the historical `RELEASE-NOTES-v0.1.x.md`. **This 2.0 documentation describes
> only the Entity Engine slice** and does not modify the historical material.

## Start here

New to AUSUS 2.0? Follow the **[Quick Start](QUICKSTART.md)** — the single
recommended path, from `composer require` to a rendered UI, outside the monorepo.

## Reference (read in any order)

1. [Introduction](01-introduction.md) — what AUSUS is, why it exists, principles.
2. [Architecture](02-architecture.md) — the L0 → L6 layering with a diagram.
3. [Pipeline](03-pipeline.md) — DSL → … → React Renderer, step by step.
4. [Inside the monorepo — a first project](04-first-project.md) — the same
   walkthrough resolved via Composer **path repositories**, for contributors
   working in this repository. External users should follow the
   [Quick Start](QUICKSTART.md) instead.
5. [Reference applications](05-reference-apps.md) — CRM, Teranga PMS, SGH.
6. [Capabilities](06-capabilities.md) — actions, guards, expand, views, runtime, API, React, repository.
7. [Known limits of 2.0](07-known-limits.md) — documented honestly, no proposed fixes.

## At a glance

```
DSL (entities/*.php)
  → EntityDefinition        (EE-RFC-012 declarative model)
  → Compiler                (global closure + canonicalise + content-hash)
  → EntitySchema            (frozen, content-addressed)
  → FileSchemaRepository    (.ausus/schemas/<hash>.json + index.json)
  → EntityEngine::bind()    (schema + PersistenceDriver → RuntimeEntity)
  → RuntimeEntity           (invoke / read, data-aware authorization)
  → API Runtime             (GET schema · GET projection · POST action)
  → React Renderer          (auto navigation, tables, forms)
  → View System             (ViewDefinition → pages/sections)
```

- **Requirements:** PHP 8.3+ ; Node 18+ (for the React renderer / TS tests).
- **Status:** all 20 PHP test suites and 5 JS suites green (see each section).
- **License:** MIT.
