# 6. Capabilities (2.0)

Exactly what the Entity Engine slice does today. Everything here is exercised by
the test suites and the reference apps.

## Fields

`FieldType`: `String`, `Integer`, `Decimal`, `Boolean`, `Date`, `Enum`,
`Reference`, `Identity` (system). Options: `nullable`, `default`,
`writeProtected`, `typeOptions` (e.g. `values` for enums, `target` for
references).

## Actions

Three kinds (`ActionKind`):

- **`Create`** — builds a new instance from the action's declared inputs plus
  field defaults (a `writeProtected` enum's default sets the initial state).
- **`Transition`** — flips one enum **state field** from one (or several) `from`
  values to a `to` value. The subject is identified by `inputs['id']`.
- **`Update`** — patches the declared input fields of an existing subject.

An action may carry an optional **guard** (`Expression`).

## Guards (authorization)

A guard is an `Expression` evaluated **fail-closed** by
`DefaultAuthorizationEvaluator`. Operators (all evaluated as of 2.0, via the same
EE-RFC-012 §Q5 reductions used for hashing):

- Primitives: `eq`, `lt`, `not`, `and`.
- Sugar (now fully supported): `ne`, `lte`, `gt`, `gte`, `in`, `or`.

Facts (`FactSource`): `actor` (`type`, `id`, `homeTenant`), `tenant` (`id`),
`now` (`timestamp`, `iso`), `subject` (the instance's own fields), `input` (the
call's inputs). An unresolved fact, a missing path segment, or a malformed node
all **deny**.

```php
Expr::lt(Expr::input('amount'), 1000000)          // input-dependent
Expr::eq(Expr::actor('type'), 'manager')          // actor-dependent
Expr::not(Expr::lt(Expr::subject('total'), 1))     // subject-dependent (total ≥ 1)
Expr::eq(Expr::tenant('id'), 'sgh')               // tenant-dependent
```

## Projections & expand

A projection is a **read shape**: a list of exposed fields (each with optional
per-field `visibility` guard — denied fields are omitted) plus **single-hop**
`expand` entries. Each expand follows a `reference` field to a **target
projection that must itself have no expand** (depth ≤ 1, enforced by the
compiler). Pattern: give every entity a flat `board` (the expand target) and put
expands on `detail`.

## Runtime

`RuntimeEntity::invoke` (create/transition/update) and `read` (projection
resolution + visibility + single-hop expand). Mutations run in a driver
transaction and **roll back on any error**. The runtime never recompiles and is
Driver-agnostic.

## API Runtime

Framework-agnostic dispatcher returning `['status' => int, 'body' => array]`:

```
GET  /api/entities/{entity}                          → { identity, tenantScoped, actions[], projections[] }
GET  /api/entities/{entity}/projections/{projection} → { rows: [...] }
POST /api/entities/{entity}/actions/{action}         → { reference, version, fields } | { error } (403/404/422/500)
```

Context is built from request headers (`X-Tenant-ID`, `X-Actor-Type`,
`X-Actor-Id`).

## React Renderer (`@ausus/react-renderer`)

- `RuntimeClient` — the only backend door (HTTP).
- `EntityRegistry` — discovery + navigation from a configured entity list.
- `buildProjectionTable` / `ProjectionTable` — generic data table.
- `buildActionForm` / `ActionForm` — auto-generated form (validation, submit).
- `EntityPage`, `ProjectionPage`, `RendererApp` — pages and navigation.

No business knowledge is hard-coded; a newly compiled entity becomes visible by
adding its name to the entity list (configuration, not code).

## View System (`ausus/view-system`)

`ViewDefinition` → `PageDefinition[]` → `SectionDefinition[]`. A section displays
**either** a projection **or** an action (never both — structurally enforced).
`ViewRegistry` serialises to JSON and derives navigation; the `ui/` adapter
renders a view by delegating to the existing renderer components.

## Repository

Content-addressed: `.ausus/schemas/<hash>.json` + `index.json` (`EntityId →
hash`). `resolve(EntityId)` → schema with **no recompilation and no re-hash**.
`InMemorySchemaRepository` provides the same contract for tests/embedding.
