# 2. Why a new architecture

The Legacy generation succeeded at what it set out to do: it shipped a working,
multi-tenant, metadata-first platform across five releases. The Entity Engine did
not arise because the first generation was wrong, but because a second set of
goals — a smaller, frozen core; content-addressed compilation; a runtime fully
decoupled from storage and presentation — were easier to reach by re-deriving the
model from first principles. This document explains the technical drivers, with
respect for what came before.

## The Legacy model in brief

In Generation 1, a plugin's `describe()` returns a descriptor that the compiler
turns into a **`MetadataGraph`** — a single in-memory artifact aggregating
`EntityNode`, `FieldNode`, `ActionNode`, `ProjectionNode`, `WorkflowNode`, and
policies. The runtime (`Invoker`, `WorkflowRuntime`, `EffectDispatcher`,
`ProjectionRenderer`) executes against that graph, an HTTP Router (`api-http`)
exposes RFC-004 **ViewSchema**, and `@ausus/renderer-react` renders it.

## Driver 1 — A frozen, minimal core (`EntityDefinition`)

The `MetadataGraph` is a rich aggregate that grew several concept families
(nodes, workflows, policies, effects). The Entity Engine asked a narrower
question: *what is the irreducible model?* The answer is **`EntityDefinition`** —
identity + tenant flag + `Field[]` + `Action[]` + `Projection[]`, with the
authorization rule reduced to an embedded `Expression`. Workflows become a
`Field` (the state enum) plus `Transition` actions; relations become a `Field` of
type `reference`. A smaller frozen vocabulary is easier to reason about, version,
and keep stable.

## Driver 2 — Compilation as a pure, content-addressed function (`Compiler`)

In Legacy, the compiled `MetadataGraph` carries a hash but is a materialised
central artifact. The Entity Engine makes compilation an explicit, pure pipeline
— **ClosureValidator → Canonicalizer → Hasher** — producing one **`EntitySchema`
per entity, addressed by the SHA-256 of its canonical (normalised) form**. Same
semantics ⇒ same hash. This gives a stable on-disk cache (`.ausus/schemas/<hash>.json`
+ `index.json`) and a guarantee that *binding never recompiles*.

## Driver 3 — A runtime independent of storage and presentation (`RuntimeEntity`)

The Legacy runtime is wired to its effect/workflow/policy machinery. The Entity
Engine separates the two halves of EE-RFC-011 cleanly: **the Compiler** produces an
`EntitySchema`; **`EntityEngine::bind(EntitySchema, PersistenceDriver)`** returns
a **`RuntimeEntity`** that depends *only* on the `PersistenceDriver` contract (the
concrete driver is injected). The runtime imports no DSL, no compiler, and no
concrete driver — proven by the reference apps swapping a faulty driver in tests.

## Driver 4 — A presentation-agnostic HTTP boundary (`API Runtime`)

Legacy's `api-http` serves RFC-004 ViewSchema (a presentation contract) directly.
The Entity Engine introduces a thinner **API Runtime** that exposes only
`resolve → bind → invoke/read` over three routes
(`GET /api/entities/{e}`, `GET …/projections/{p}`, `POST …/actions/{a}`),
consuming only `SchemaRepository`, `EntityEngine`, and a `PersistenceDriver`. The
API never compiles, hashes, or loads DSL — so the HTTP layer can evolve
independently of both the engine and the UI.

## Driver 5 — Presentation as data, separate from the engine (`View System`)

In Legacy, the read shape and its presentation are intertwined in ViewSchema. The
Entity Engine keeps the **read shape** in the model (a `Projection` with
visibility and single-hop expand) and moves **presentation composition** into a
separate, optional **View System**: `ViewDefinition` → `PageDefinition` →
`SectionDefinition` (a section shows a projection *or* an action). The React
renderer can consume entities directly *or* a `ViewDefinition`; it has no business
knowledge either way.

## Summary

The Entity Engine is a re-derivation around four properties the second set of
goals prioritised: a **smaller frozen core**, **content-addressed compilation**, a
**driver-agnostic runtime**, and **presentation kept out of the engine**. None of
this invalidates the Legacy generation — it is a different set of trade-offs,
reached by starting the model again from the irreducible kernel.
