# 8. Glossary

Terms from both generations. **(Gen 1)** = Legacy / `MetadataGraph`;
**(Gen 2)** = Entity Engine. Some terms (Projection, Guard, Tenant) span both.

### MetadataGraph **(Gen 1)**
The single compiled aggregate of the Legacy generation: a graph of `EntityNode`,
`FieldNode`, `ActionNode`, `ProjectionNode`, `WorkflowNode`, and policies, hashed
and executed by the Legacy runtime. Lives in `packages/kernel/src/kernel.php`.

### ViewSchema **(Gen 1)**
The RFC-004 presentation-and-data contract served by `api-http` and consumed by
`@ausus/renderer-react` in the Legacy generation.

### EntityDefinition **(Gen 2)**
The declarative model of one entity in the Entity Engine: `identity` +
`tenantScoped` + `Field[]` + `Action[]` + `Projection[]`, with authorization as an
embedded `Expression`. Produced by the Authoring DSL (`Definition`, `Expr`).

### EntitySchema **(Gen 2)**
The compiled, frozen, **content-addressed** form of an `EntityDefinition`
(normalised + SHA-256 hashed). Persisted as `.ausus/schemas/<hash>.json` and
consumed by `EntityEngine::bind()`.

### Compiler **(Gen 2)**
The pure pipeline `EntityDefinition[] → EntitySchema[] + SchemaIndex`:
**ClosureValidator** (16 invariants) → **Canonicalizer** (semantic normal form) →
**Hasher** (content hash). Atomic — any error produces nothing.

### Canonicalizer / Hasher **(Gen 2)**
The Canonicalizer reduces a definition to a deterministic normal form (sugar
operators → primitives `{eq,lt,not,and}`, sets sorted). The Hasher SHA-256s the
canonical JSON. Same semantics ⇒ same hash.

### SchemaRepository **(Gen 2)**
Content-addressed store: `resolve(EntityId) → hash → EntitySchema`, with **no
recompilation**. File-backed (`.ausus/`) or in-memory.

### EntityEngine / bind **(Gen 2)**
`EntityEngine::bind(EntitySchema, PersistenceDriver) → RuntimeEntity`. The "bind"
half of RFC-011 (compilation is the other half).

### Runtime / RuntimeEntity **(both, different shapes)**
The execution layer. **(Gen 1)** `Invoker`/`WorkflowRuntime`/`EffectDispatcher`/
`ProjectionRenderer`. **(Gen 2)** `RuntimeEntity` with `invoke(action, inputs,
context)` and `read(projection, params, context)`, depending only on the
`PersistenceDriver` contract.

### Action **(both)**
A declared mutation. **(Gen 2)** kinds: `Create`, `Transition` (flip one state
field), `Update`. May carry a guard.

### Transition **(Gen 2)**
An `Action` that moves one enum **state field** from a `from` value (or set) to a
`to` value. Replaces the Legacy `WorkflowNode`.

### Projection **(both)**
A declared **read shape** over an entity. **(Gen 2)** = exposed fields (each with
optional visibility) + single-hop `expand`. Selection (filter/sort/pagination) is
deferred.

### Expand **(Gen 2)**
A single-hop inclusion: follow a `reference` field to a target projection that has
no expand of its own (**depth ≤ 1**, enforced by the compiler).

### Guard **(both)**
A declared, data-aware authorization predicate. **(Gen 2)** an `Expression` over
facts `actor` / `tenant` / `now` / `subject` / `input`, evaluated **fail-closed**
(unresolved ⇒ deny).

### View / ViewDefinition **(Gen 2)**
Presentation metadata in the View System: `ViewDefinition` → `PageDefinition[]` →
`SectionDefinition[]`, where a section shows a projection **or** an action.

### Context **(Gen 2)**
The runtime facts of a call — `actor()`, `tenant()`, `now()` — supplied by the
host (e.g. built from HTTP headers by the API Runtime).

### PersistenceDriver / Repository **(both)**
The kernel storage contract (`beginTransaction`/`commit`/`rollback`/`context`/
`generateIdentity`; `find`/`create`/`update`/`findAll`). Reused **unchanged** by
the Entity Engine; the concrete driver is injected.

### Tenant / ActorRef / Reference / Entity / Decision **(both)**
Kernel value objects shared by both generations. `Tenant`/`TenantId` (where),
`ActorRef` (who: type/id/homeTenant), `Reference` (a record's identity),
`Entity` (a persisted instance), `Decision` (permit/deny/abstain).

### Layer (L0–L6) **(both)**
The dependency tier declared per package (`extra.ausus.layer`): L0 kernel, L1
engine/authoring, L2/L3 runtime/persistence, L4 API, L5 presentation, L6
CLI/tooling. Dependencies only point downward.
