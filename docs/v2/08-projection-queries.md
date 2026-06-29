# 8. Projection Query Language (filters, sorting & pagination)

The **Projection Query Language** (L3) is the official, public **read contract**
of AUSUS 2.0. It is the stable way to ask a projection for *a subset* of its
rows — filtered, sorted, and paginated — without leaking any storage detail.

It is **purely additive**: the write model (Kernel, EntityDefinition,
EntitySchema, Engine, the Runtime write API) is unchanged, every existing test
still passes, and a projection read with no query parameters returns exactly
what it did before.

> **Scope of v1.** This contract covers `where` / `orderBy` / `limit` / `offset`
> over a projection's own exposed scalar fields. It deliberately does **not**
> cover joins, reverse relations, aggregations, computed fields, reporting,
> availability, or anti-joins. Those build on this foundation in later layers.

---

## 1. Where it runs in the pipeline

```
findAll(tenant)  →  WHERE  →  ORDER BY  →  LIMIT/OFFSET  →  visibility + expand  →  rows
```

The query is parsed and **validated before any I/O**, then applied in the
runtime over the rows the driver returns. This keeps it **driver-agnostic**: the
Memory driver works unchanged, and a future SQLite/Postgres driver can push the
*same* contract down to SQL via the existing `Repository::findPaged(limit,
offset, filters, sort)` SPI — without changing a single byte of the public
contract.

Filtering and sorting operate on the projection's **exposed scalar fields**.
Per-field `visibility` guards still apply to the rendered output: a field hidden
from the current actor is omitted from each row, exactly as before.

---

## 2. The query object

The canonical contract is a plain associative array passed as the `params`
argument of `RuntimeEntity::read($projection, $params, $context)`. It has exactly
four optional keys:

| Key       | Shape                                             | Meaning                |
|-----------|---------------------------------------------------|------------------------|
| `where`   | a **filter node** (see below)                     | row predicate          |
| `orderBy` | list of `{ field, dir }` (`dir` = `asc`\|`desc`)  | sort, first key wins   |
| `limit`   | integer `0 … 200`                                 | page size (max **200**)|
| `offset`  | integer `≥ 0`                                      | rows to skip           |

Any **other** top-level key is rejected (fail-closed). An empty array `[]` is a
no-op — the regression-safe default.

### Filter nodes

A `where` is a recursive boolean tree:

- **Condition** — `['field' => 'status', 'op' => 'eq', 'value' => 'open']`
- **AND group** — `['and' => [ node, node, … ]]`
- **OR group** — `['or' => [ node, node, … ]]`
- **Bare list** — `[ node, node, … ]` is an implicit **AND**

Groups nest arbitrarily, so `(status = open AND (priority >= 3 OR assignee is
null))` is expressible.

### Operators

| Operator                          | Applies to        | Value      |
|-----------------------------------|-------------------|------------|
| `eq`, `ne`                        | any scalar        | required   |
| `lt`, `lte`, `gt`, `gte`          | numbers / strings | required   |
| `contains`, `startsWith`, `endsWith` | strings        | required   |
| `isNull`, `isNotNull`             | any nullable      | **omit**   |

Comparisons are numeric when both sides are numeric, else lexicographic. `null`
values never match an ordering/relational operator and **sort last** regardless
of direction.

---

## 3. PHP example (embedded / tests)

```php
use Ausus\Engine\Runtime\DefaultEntityEngine;

$runtime = $engine->bind($schema, $driver);

$rows = $runtime->read('list', [
    'where' => ['and' => [
        ['field' => 'status',   'op' => 'eq',  'value' => 'open'],
        ['field' => 'priority', 'op' => 'gte', 'value' => 3],
    ]],
    'orderBy' => [['field' => 'priority', 'dir' => 'desc']],
    'limit'   => 20,
    'offset'  => 0,
], $context);
```

`null` / OR / nested groups are fully available here. Any malformed query throws
`Ausus\Engine\Query\QueryError` — it is never silently coerced.

---

## 4. Over HTTP

`GET /api/entities/{entity}/projections/{projection}` accepts the query as flat
query-string parameters. The api-runtime translates them into the structured
contract above; the runtime remains the single fail-closed authority.

```
# shorthand: any non-reserved key ⇒ eq
GET /api/entities/task/projections/list?status=open

# explicit filters — comma = AND
GET …/list?where=status:eq:open,priority:gte:3

# valueless operators
GET …/list?where=assignee:isNull

# sorting (alias: sort) + pagination
GET …/list?orderBy=priority:desc,title:asc&limit=20&offset=40
```

Reserved keys: `where`, `orderBy`, `sort`, `limit`, `offset`. Every other key is
treated as a shorthand `eq` filter and merged into one AND list. **OR and nested
groups are intentionally not expressible in the HTTP shorthand** (it is AND-only
by design) — use the structured `params` for those; the public read contract
stays small on purpose.

The response envelope is unchanged: `{ "rows": [ … ] }`.

### Status codes

| Status | When                                                                 |
|--------|----------------------------------------------------------------------|
| `200`  | success (including an empty `rows` list)                             |
| `400`  | **malformed query** — unknown field, unknown operator, missing value, bad sort direction, out-of-range `limit`/`offset`, unknown parameter |
| `403` / `404` / `422` | unchanged authorization / resolution / transition errors |

A malformed query **never** falls through to "all rows": it fails closed.

---

## 5. From the React renderer

`RuntimeClient.readProjection(entity, projection, params)` already forwards a
params map to the query string, so the contract is opt-in and backward
compatible. A typed builder produces the exact encoding:

```ts
import { RuntimeClient, buildProjectionParams } from '@ausus/react-renderer';

const params = buildProjectionParams({
  where: [
    { field: 'status', op: 'eq', value: 'open' },
    { field: 'priority', op: 'gte', value: 3 },
  ],
  orderBy: [{ field: 'priority', dir: 'desc' }],
  limit: 20,
});

const { body } = await client.readProjection('task', 'list', params);
//  → GET …/list?where=status:eq:open,priority:gte:3&orderBy=priority:desc&limit=20
```

`buildProjectionParams` is a pure encoder — it never validates. An unknown field
or operator surfaces as a `400` from the server, not a silently-dropped clause.

---

## 6. Design guarantees

- **Additive & frozen-safe.** No kernel/contract/write-model type changed; the
  `params` array *is* the contract, so nothing new is added to the frozen
  surface.
- **Fail closed.** Unknown field/operator/parameter, missing value, bad
  direction, or out-of-range paging is rejected — never coerced, never widened
  to "all rows".
- **Driver-agnostic.** Applied in the runtime today; a future SQL driver reuses
  the identical contract through `findPaged`.
- **Tenant- & visibility-safe.** The query runs *within* the tenant scope and
  *before* render; per-field visibility guards are unchanged.
- **Bounded.** `limit` is capped at **200** so an unbounded scan is never one
  request away.

See also the **Capabilities** and **Known limits** references (sidebar →
*Concepts* / *Reference*).
