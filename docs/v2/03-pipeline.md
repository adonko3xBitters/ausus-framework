# 3. The pipeline

Every AUSUS 2.0 application flows through one pipeline, from authored DSL to a
rendered UI.

```
DSL → EntityDefinition → Compiler → EntitySchema → Repository
    → EntityEngine::bind() → RuntimeEntity → API Runtime → React Renderer → View System
```

### 1. DSL → EntityDefinition  (`ausus/authoring`, L1)

An author writes `entities/*.php`. The closed DSL (`Definition`, `Expr`) produces
exactly one `EntityDefinition` (EE-RFC-012) — no side effects, no external
dependencies. The DSL frontend (`ausus/cli`) discovers the files, statically
**scans them for forbidden symbols** (e.g. `eval`, `getenv`, IO, reflection),
then evaluates each once.

### 2. Compiler → EntitySchema  (`ausus/entity-engine`, L1)

`Compiler::compile(EntityDefinition[])` runs in three steps, atomically (any
error aborts with `CompilationError` and produces nothing):

1. **ClosureValidator** — the 16 EE-RFC-012 §Q6 invariants over the whole set
   (reference targets exist, enums coherent, transitions valid, writeProtected
   respected, expand depth ≤ 1, unique names, …).
2. **Canonicalizer** — reduces the definition to a semantic normal form
   (sugar operators → primitives `{eq, lt, not, and}`, sets sorted, semantic
   lists preserved).
3. **Hasher** — SHA-256 over the canonical JSON. Same semantics ⇒ same hash.

The result is one `EntitySchema` per entity (frozen, content-addressed) plus a
`SchemaIndex` (EntityId → hash).

### 3. Repository → `.ausus`  (`ausus/cli` file repo, L6)

`ausus compile` persists each schema to `.ausus/schemas/<hash>.json` and writes
`.ausus/index.json` (`{ EntityId: hash }`). Persistence is atomic (staging +
single index rename); re-storing an unchanged hash leaves its file untouched.

### 4. resolve + bind  (`ausus/api-runtime` / host)

`SchemaRepository::resolve(EntityId)` reads `index → hash → schema` **without
recompiling**. `EntityEngine::bind(EntitySchema, PersistenceDriver)` returns a
`RuntimeEntity`.

### 5. RuntimeEntity — invoke / read  (`ausus/entity-engine`, L1)

- `invoke(action, inputs, context)` — `create`/`transition`/`update`: resolve the
  action, evaluate the guard (fail-closed), open a transaction, apply the effect
  via the driver, commit (rollback on any error), return the `Entity`.
- `read(projection, params, context)` — load instances, apply per-field
  visibility, fold single-hop `expand`, return rows.

Authorization is delegated to `DefaultAuthorizationEvaluator`; facts
(`actor`/`tenant`/`now`/`subject`/`input`) are assembled from the `Context`, the
current entity, and the call parameters.

### 6. API Runtime  (`ausus/api-runtime`, L4)

A framework-agnostic dispatcher exposes three routes:

```
GET  /api/entities/{entity}                          → schema (actions + projections)
GET  /api/entities/{entity}/projections/{projection} → { rows: [...] }
POST /api/entities/{entity}/actions/{action}         → invoked entity / 403 on deny
```

It uses only `SchemaRepository`, `EntityEngine`, and a `PersistenceDriver`
(injected). It never compiles, hashes, or loads DSL.

### 7. React Renderer + View System  (L5)

`@ausus/react-renderer` discovers an entity (`GET schema`), then auto-builds a
navigation, a projection **table**, and an action **form** — driving everything
through the HTTP client. `ausus/view-system` adds a `ViewDefinition` layer
(pages → sections, each a projection **or** an action) that the renderer flattens
and renders. Neither contains business knowledge.
