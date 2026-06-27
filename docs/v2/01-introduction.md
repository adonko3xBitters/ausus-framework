# 1. Introduction

## What is AUSUS 2.0 (Entity Engine)?

AUSUS is a **metadata-first application engine**. You describe an application as
declarative metadata — entities, fields, actions, projections, and authorization
rules — and AUSUS compiles that description into a content-addressed schema and
executes it: it persists data, enforces data-aware authorization, exposes an
HTTP API, and renders a generic React UI. You write **what** the application is;
the engine runs it.

The 2.0 *Entity Engine* is the realization of two frozen RFCs:

- **EE-RFC-012 — Entity Definition:** the declarative model (`EntityDefinition` =
  identity + tenant flag + `Field[]` + `Action[]` + `Projection[]`, with an
  embedded authorization `Expression`).
- **EE-RFC-011 — Entity Engine:** the execution contract (`compile` →
  `EntitySchema`; `bind(EntitySchema, Driver)` → `RuntimeEntity` with
  `invoke`/`read`).

## Why does it exist?

A typical enterprise application re-implements the same plumbing for every
domain: storage mapping, per-record authorization, read shapes, transitions,
and a UI. AUSUS factors that plumbing into a single engine so the application is
expressed once, declaratively, and the cross-cutting concerns (tenancy,
authorization, transactions) are properties of the engine rather than code each
team must get right.

## Principles (as implemented in 2.0)

- **Metadata-first.** The application is data (`EntityDefinition`), not code. The
  authored PHP DSL is a closed notation whose only product is an
  `EntityDefinition`.
- **Content-addressed compilation.** A definition compiles to an `EntitySchema`
  addressed by a hash of its canonical (normalized) form. Same semantics ⇒ same
  hash. Re-running `bind`/`read`/`invoke` never recompiles.
- **Driver-agnostic runtime.** `RuntimeEntity` depends only on the frozen
  `PersistenceDriver`/`Repository` contracts; the concrete driver is injected.
- **Data-aware, fail-closed authorization.** Guards are predicates over facts
  (`actor`, `tenant`, `now`, `subject`, `input`); an unresolved fact denies.
- **Single rendering contract.** The React renderer consumes **only** the HTTP
  API — it has no knowledge of the kernel, the compiler, or the repository.

## What 2.0 is not

It is not (in this slice) an ORM, a UI framework, a workflow engine, or a query
language. Expand is single-hop; projections do not aggregate; `read()` selection
parameters are deferred. See [Known limits](07-known-limits.md).
