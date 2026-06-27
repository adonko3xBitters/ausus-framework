# 4. What changes — side by side

Both columns describe real, present code. "Legacy" = the published
`standard-stack` line; "Entity Engine" = the new, unpublished line.

| Concern | Legacy (Gen 1) | Entity Engine (Gen 2) |
|---|---|---|
| **Model** | `MetadataGraph` aggregate of `EntityNode`/`FieldNode`/`ActionNode`/`ProjectionNode`/`WorkflowNode` + policies | `EntityDefinition` = identity + tenantScoped + `Field[]` + `Action[]` + `Projection[]`, authorization as an embedded `Expression` |
| **Workflows** | First-class `WorkflowNode` + `WorkflowRuntime` | Dissolved into a `Field` (state enum) + `Transition` actions |
| **Relations** | RFC-015 relations layer | A `Field` of type `reference` (+ single-hop `expand`) |
| **Authoring** | Plugin `describe()` descriptors | Closed PHP DSL (`Definition`, `Expr`) → exactly one `EntityDefinition` |
| **Compilation** | Descriptor → `MetadataGraph` (central artifact, carries a hash) | Pure pipeline: ClosureValidator → Canonicalizer → Hasher → `EntitySchema` per entity, **content-addressed** |
| **Schema storage** | In-memory graph (+ a serialized graph cache) | `.ausus/schemas/<hash>.json` + `index.json`; `resolve(EntityId)` with **no recompilation** |
| **Runtime** | `Invoker` + `WorkflowRuntime` + `EffectDispatcher` + `ProjectionRenderer`, wired to policy/effect machinery | `EntityEngine::bind(schema, driver)` → `RuntimeEntity` (`invoke`/`read`), depends only on the `PersistenceDriver` contract |
| **Authorization** | Policy engine (RFC-005) / guard kernel (RFC-018) | `AuthorizationEvaluator` over an `Expression` of facts (`actor`/`tenant`/`now`/`subject`/`input`), fail-closed |
| **Read shape** | ViewSchema (presentation + data) | `Projection` = exposed fields + per-field visibility + single-hop `expand` (presentation kept out of it) |
| **HTTP API** | `api-http` Router serving RFC-004 ViewSchema | `api-runtime`: `resolve → bind → invoke/read` over 3 generic routes, returns `{status, body}` |
| **React** | `@ausus/renderer-react` consuming ViewSchema | `@ausus/react-renderer` consuming only the HTTP API (discovery → auto tables/forms) |
| **Views** | (folded into ViewSchema) | Separate **View System**: `ViewDefinition` → pages → sections (projection **or** action) |
| **Packages** | `kernel, standard-stack, runtime-default, api-http, presentation-default, persistence-sql/postgres, tenancy-row, audit-database, auth-bridge, starter`, `@ausus/renderer-react` | `kernel (Definition/Contracts/Compiled), entity-engine, authoring, cli, persistence-memory, api-runtime, view-system`, `@ausus/react-renderer` |
| **Namespaces** | `Ausus\` (MetadataGraph, …), `Ausus\Runtime\`, `Ausus\Api\Http\` | `Ausus\Definition\`, `Ausus\Engine\`, `Ausus\Api\Runtime\`, `Ausus\View\`, `Ausus\Persistence\Memory\` |
| **Publication** | Tagged & released (v0.1.0 → v1.1.0) | Built + validated, **not yet tagged/released** |

## Reading the table

The change is not cosmetic: the *model* (one aggregate graph vs many
content-addressed schemas), the *compilation* (central artifact vs pure
content-addressed function), and the *runtime coupling* (engine-wired vs
driver-only contract) are genuinely different. But the *concerns themselves* —
entities, actions, projections, authorization, tenancy, an HTTP API, a React UI —
are the same concerns, addressed with different internal shapes.
