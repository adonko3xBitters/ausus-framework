---
id: viewschema
title: ViewSchema
sidebar_label: ViewSchema
description: The JSON wire format between the backend and the renderer.
---

# ViewSchema

A **ViewSchema** is the JSON wire format AUSUS uses to describe a screen. The
backend renders a [projection](../concepts/projections.md) into a ViewSchema;
the [React renderer](react-renderer.md) consumes it. Neither side hard-codes
the domain — the ViewSchema carries it.

ViewSchema is defined by RFC-004. v0.1.0 implements a subset of it.

## Shape {#shape}

```json
{
  "schemaVersion": "1.0.0",
  "targetProfile": "react.web.v1",
  "metadata": {
    "projection": "billing.invoice.summary",
    "entity": "billing.invoice",
    "tenant": "acme",
    "locale": "en-US",
    "generatedAt": "2026-05-20T00:00:00Z"
  },
  "fields":  [ /* FieldDescriptor[] */ ],
  "actions": [ /* ActionDescriptor[] */ ],
  "filters": [],
  "data":    { "items": [ /* ... */ ], "pagination": { "nextCursor": null, "pageSize": 1 } }
}
```

| Key | Meaning |
|---|---|
| `schemaVersion` | the ViewSchema format version — `1.0.0` in v0.1.0 |
| `targetProfile` | the rendering profile — `react.web.v1` in v0.1.0 |
| `metadata` | projection / entity / tenant / locale / generation time |
| `fields` | the columns or detail rows to render |
| `actions` | the actions available on this view |
| `filters` | filter descriptors — always empty in v0.1.0 |
| `data` | the rows themselves (see below) |

## `data` — list vs detail {#data--list-vs-detail}

The `data` member tells the consumer which view to draw:

```ts
// list form
{ items: Record<string, unknown>[], pagination?: { nextCursor: string | null, pageSize: number } }

// detail form
{ item: Record<string, unknown> | null }

// or null
```

`data.items` → render a list. `data.item` → render a detail. The renderer's
`ViewSchemaConsumer` dispatches on exactly this.

## `FieldDescriptor` {#fielddescriptor}

```ts
interface FieldDescriptor {
  name: string;
  type: "string" | "integer" | "datetime" | "enum" | "money"
      | "identity" | "version" | "system_string";
  label: string;
  typeOptions?: { maxLength?: number; currency?: string; options?: string[] };
}
```

The `type` drives how a cell is rendered — `money` is formatted with its
currency, `enum` named `status` becomes a workflow badge, and so on.

## `ActionDescriptor` {#actiondescriptor}

```ts
interface ActionDescriptor {
  fqn: string;
  name: string;
  label: string;
  subjectRequired: boolean;
  inputs?: FieldDescriptor[];
  confirmation?: { required: boolean; prompt?: string };
}
```

`subjectRequired` separates **list actions** (e.g. `create`) from **item
actions** (e.g. `issue`, `cancel`).

## Schema versioning {#schema-versioning}

The renderer checks `schemaVersion`: it accepts `1.0.x` and reports an error
for anything else. `schemaVersion` is how a future ViewSchema revision stays
backward-compatible.

## Current v0.1.0 limitations {#current-v010-limitations}

- `filters` is always empty — there is no filtering in v0.1.0.
- `pagination.nextCursor` is always `null` — list rendering returns all rows
  for the tenant.
- `targetProfile` is fixed to `react.web.v1`; `locale` is fixed to `en-US`.
- `confirmation` is part of the `ActionDescriptor` type but is not populated by
  the v0.1.0 backend renderer.

## Related {#related}

- [Projections](../concepts/projections.md) — what renders into a ViewSchema.
- [The HTTP API](../backend/http-api.md) — serves ViewSchemas.
- [The React renderer](react-renderer.md) — consumes them.
