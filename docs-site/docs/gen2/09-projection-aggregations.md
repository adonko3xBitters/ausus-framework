# 9. Projection Aggregations (KPI / dashboards)

**Projection Aggregations** (L4) are the official, public **statistics contract**
of the AUSUS Gen2 read model. They let a projection return `count`, `sum`, `avg`,
`min`, and `max` over its rows — the foundation for dashboards, KPI cards,
badges, and simple reporting — *without changing the business (write) model*.

It builds directly on the [Projection Query Language](./08-projection-queries.md)
(L3) and is **purely additive**: the Kernel, Authoring, EntitySchema, Compiler
and the write runtime are unchanged; an existing read with no `aggregate`
parameter behaves exactly as before.

> **Scope of v1.** `count` / `sum` / `avg` / `min` / `max` over a projection's
> own exposed scalar fields. It deliberately does **not** include grouping
> (group-by), computed/derived fields, joins, reverse relations, or reporting.
> Those build on this foundation in later layers.

---

## 1. Where it runs in the pipeline

```
findAll(tenant) → WHERE (L3 filter)
   → AGGREGATE (L4: count/sum/avg/min/max over the filtered, visible set)
   → ORDER BY → LIMIT/OFFSET → render (visibility + expand)
```

- Aggregates are computed **after** the `where` filter and **after** per-field
  visibility, but **independently of `limit`/`offset`**: a KPI card sums *every*
  matching row, not the current page. The `rows` array is still paginated.
- **Tenant- and visibility-safe.** Aggregation runs inside the tenant scope, over
  rows whose per-field visibility has already been applied — a value hidden from
  the current actor never contributes (it is treated as absent, like SQL `NULL`).
- **Driver-agnostic.** Applied in the runtime today; a future SQLite/Postgres
  driver can push the identical contract down to SQL `COUNT/SUM/AVG/MIN/MAX`.

---

## 2. The contract

Aggregations are requested through a new, optional `aggregate` key on the same
`params` argument used by L3. The response gains an `aggregates` map; **the
`rows` format never changes**, and `aggregates` is present only when requested.

```php
$params = [
    'where'     => [ ['field' => 'status', 'op' => 'eq', 'value' => 'available'] ],
    'aggregate' => [
        ['op' => 'count',                    'as' => 'rooms'],
        ['op' => 'sum', 'field' => 'total',  'as' => 'revenue'],
        ['op' => 'avg', 'field' => 'price',  'as' => 'averagePrice'],
    ],
];
```

Response:

```json
{
  "rows": [ ... ],
  "aggregates": { "rooms": 42, "revenue": 580000, "averagePrice": 13500 }
}
```

### Operators

| Operator | `field`    | Result                                                     |
|----------|------------|------------------------------------------------------------|
| `count`  | optional   | number of rows; with a field, count of non-null values     |
| `sum`    | required   | sum of numeric values (empty set → `0`)                    |
| `avg`    | required   | mean of numeric values (empty set → `null`)               |
| `min`    | required   | smallest value (numeric or lexicographic; empty → `null`) |
| `max`    | required   | largest value (numeric or lexicographic; empty → `null`)  |

Each entry needs an `as` alias; aliases must be **unique**. `field` must be a
scalar field the projection **exposes** (the same allow-list as L3).

---

## 3. PHP example (embedded / tests)

```php
use Ausus\Engine\Runtime\AggregatingRuntimeEntity;

$runtime = $engine->bind($schema, $driver);   // DefaultRuntimeEntity

if ($runtime instanceof AggregatingRuntimeEntity) {
    $result = $runtime->readWithAggregates('board', [
        'where'     => [ ['field' => 'status', 'op' => 'eq', 'value' => 'available'] ],
        'aggregate' => [
            ['op' => 'count',                   'as' => 'rooms'],
            ['op' => 'sum', 'field' => 'price', 'as' => 'revenue'],
        ],
    ], $context);

    $result['rows'];        // paginated rows (unchanged shape)
    $result['aggregates'];  // ['rooms' => 42, 'revenue' => 580000]
}
```

`read()` itself is unchanged — it still returns a bare `list<row>`. The richer
`{ rows, aggregates }` envelope is exposed by the additive
`AggregatingRuntimeEntity` interface (in `ausus/entity-engine`, **not** the frozen
kernel). Any malformed aggregate throws `Ausus\Engine\Query\QueryError`.

---

## 4. Over HTTP

`GET /api/entities/{entity}/projections/{projection}` accepts an `aggregate`
clause alongside the L3 query parameters.

```
# op:as (count) | op:field:as (sum/avg/min/max, count-of-field); comma = list
GET …/board?aggregate=count:rooms,sum:total:revenue,avg:price:averagePrice

# combine with where (and orderBy / limit / offset)
GET …/board?where=status:eq:available&aggregate=count:rooms,sum:price:revenue
```

Response envelope:

```json
{ "rows": [ ... ], "aggregates": { "rooms": 42, "revenue": 580000 } }
```

When no `aggregate` is supplied, the response is the unchanged `{ "rows": [...] }`
— no `aggregates` key is added.

### Status codes

| Status | When                                                                   |
|--------|------------------------------------------------------------------------|
| `200`  | success                                                                |
| `400`  | **malformed aggregate** — unknown operator, unexposed/missing field, missing alias, duplicate alias, type-incompatible value (e.g. `sum` on a text field) |

A malformed aggregate **never** falls through to a silent result: it fails closed.

---

## 5. From the React renderer

The typed builder encodes the exact `aggregate` clause, and
`ProjectionResponse.aggregates` is typed:

```ts
import { RuntimeClient, buildProjectionParams } from '@ausus/react-renderer';

const params = buildProjectionParams({
  where: [{ field: 'status', op: 'eq', value: 'available' }],
  aggregate: [
    { op: 'count', as: 'rooms' },
    { op: 'sum', field: 'price', as: 'revenue' },
  ],
});

const { body } = await client.readProjection('room', 'board', params);
//  → GET …/board?where=status:eq:available&aggregate=count:rooms,sum:price:revenue
body.aggregates?.rooms;    // 42
body.aggregates?.revenue;  // 580000
```

`buildProjectionParams` is a pure encoder — it never validates. An unknown field
or operator surfaces as a `400` from the server.

---

## 6. Design guarantees

- **Additive & frozen-safe.** No kernel/contract/write-model type changed;
  `read()` keeps returning `list<row>`. Aggregation is exposed via the additive
  `AggregatingRuntimeEntity` interface.
- **Fail closed.** Unknown operator, unexposed/missing field, missing/duplicate
  alias, or a type-incompatible value is rejected — never coerced, never a silent
  result.
- **Visibility-safe.** Hidden field values never contribute to an aggregate; a
  field hidden from the actor aggregates as if absent.
- **Pagination-independent.** Aggregates cover the full WHERE-filtered tenant set
  regardless of `limit`/`offset`; `rows` remain paginated.
- **Driver-agnostic.** Reuses the identical contract through a future SQL driver.

See also the **Projection Query Language** and **Known limits** references
(sidebar → *Reference*).
