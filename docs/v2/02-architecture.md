# 2. Architecture

AUSUS 2.0 is a layered monorepo. Each layer depends only on layers below it;
there are no upward or sideways dependencies between peers. Layer numbers come
from each package's `composer.json` (`extra.ausus.layer`).

| Layer | Package | Role | Depends on |
|------:|---------|------|------------|
| **L0** | `ausus/kernel` | Frozen DTOs + contracts (`Definition`, `Contracts`, `Compiled`) | — |
| **L1** | `ausus/entity-engine` | Compiler, Canonicalizer, Hasher, ClosureValidator, AuthorizationEvaluator, `EntityEngine::bind`, `RuntimeEntity`, in-memory schema repo | kernel |
| **L1** | `ausus/authoring` | Closed PHP DSL (`Definition`, `Expr`) → `EntityDefinition` | kernel |
| **L3** | `ausus/persistence-memory` | Reference in-memory `PersistenceDriver` | kernel |
| **L4** | `ausus/api-runtime` | HTTP API: `resolve → bind → invoke/read` | kernel, entity-engine |
| **L5** | `ausus/view-system` | `ViewDefinition` / `PageDefinition` / `SectionDefinition` metadata | kernel |
| **L5** | `@ausus/react-renderer` | Generic React UI over the HTTP API | (HTTP only) |
| **L6** | `ausus/cli` | DSL frontend (discover/scan/load) + `ausus compile` + `FileSchemaRepository` | kernel, authoring, entity-engine |

## Diagram

```
                         ┌─────────────────────────────────────────────┐
   L6  CLI               │  DslFrontend ── Compiler ── FileSchemaRepo   │
       (ausus/cli)       │  entities/*.php → .ausus/schemas + index     │
                         └───────────────┬─────────────────────────────┘
                                         │ writes / reads
   L5  Presentation   ┌─────────────────▼──────────────┐   ┌──────────────────┐
       view-system    │  ViewDefinition → pages/sections│   │ @ausus/react-    │
       react-renderer │  (projection XOR action)        │──▶│ renderer (HTTP)  │
                      └─────────────────┬───────────────┘   └────────┬─────────┘
                                        │                            │ fetch
   L4  API            ┌─────────────────▼────────────────────────────▼─────────┐
       api-runtime    │  RuntimeApi: GET /api/entities/{e}[/projections|/actions]│
                      └─────────────────┬───────────────────────────────────────┘
                                        │ resolve → bind → invoke/read
   L3  Persistence    ┌─────────────────▼───────────┐
       persistence-   │  PersistenceDriver (contract)│  ← MemoryDriver (reference)
       memory         └─────────────────┬───────────┘
                                        │
   L1  Engine         ┌─────────────────▼───────────────────────────────────────┐
       entity-engine  │  Compiler · Canonicalizer · Hasher · ClosureValidator    │
       authoring      │  AuthorizationEvaluator · EntityEngine::bind             │
                      │  RuntimeEntity (invoke/read)   ◀── DSL (authoring)        │
                      └─────────────────┬───────────────────────────────────────┘
                                        │ consumes
   L0  Kernel         ┌─────────────────▼───────────────────────────────────────┐
       kernel         │  EntityDefinition, FieldDefinition, ActionDefinition,    │
                      │  ProjectionDefinition, Expression, EntitySchema,         │
                      │  Contracts (EntityEngine, RuntimeEntity, SchemaRepository,│
                      │  AuthorizationEvaluator, Context, PersistenceDriver)     │
                      └─────────────────────────────────────────────────────────┘
```

## Boundary rules enforced by 2.0

- The **kernel** (L0) has zero dependencies and knows no source format, no
  storage, no HTTP.
- The **runtime** (`RuntimeEntity`) never recompiles, never loads the DSL, and
  depends only on the `PersistenceDriver` contract (not a concrete driver).
- The **API runtime** (L4) consumes only `SchemaRepository`, `EntityEngine`, and
  `RuntimeEntity` — never the Compiler/Canonicalizer/Hasher/DSL frontend.
- The **React renderer** (L5) imports no AUSUS PHP package; it speaks only the
  HTTP contract.
