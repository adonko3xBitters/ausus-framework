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
  type: "string" | "integer" | "datetime" | "enum" | "money" | "boolean"
      | "identity" | "version" | "system_string";
  label: string;
  typeOptions?: { maxLength?: number; currency?: string; options?: string[] };
  // The fields below are only populated when this descriptor describes an
  // *action input* (i.e. inside ActionDescriptor.inputs). They are absent on
  // projection field descriptors.
  required?: boolean;
  nullable?: boolean;
  default?: unknown;
}
```

The `type` drives how a cell or form control is rendered — `money` is
formatted with its currency, `enum` named `status` becomes a workflow badge,
and so on. When a descriptor appears under an action's `inputs`, `required` is
true if the runtime cannot supply a value (the field is not nullable and
carries no default); `default` is the field default if one was declared.

The `label` field is **always populated**, with the following precedence:

1. The plugin's explicit `Field::*()->label('…')` value, when set.
2. Otherwise the runtime humanises the field name —
   `ucfirst(str_replace('_', ' ', $name))` — so `project_id` becomes
   `"Project id"` and `created_at` becomes `"Created at"`.

The wire format is unchanged; the TypeScript type still types `label` as
`string` (non-optional). Consumers never need a client-side fallback —
explicit-or-humanised is decided server-side.

## `ActionDescriptor` {#actiondescriptor}

```ts
interface ActionDescriptor {
  fqn: string;
  name: string;
  label: string;
  subjectRequired: boolean;
  inputs: FieldDescriptor[];          // always emitted; [] for transition actions
  // v0.2 — populated on update-action descriptors when the projection
  // renders a single subject (data.item). Keys map input field names to
  // the subject's current values; the renderer uses them to prefill the
  // form. Absent on create / transition descriptors and on list views.
  initialValues?: Record<string, unknown>;
  confirmation?: { required: boolean; prompt?: string };
}
```

`subjectRequired` separates **list actions** (e.g. `create`) from **item
actions** (e.g. `issue`, `cancel`).

`inputs` mirrors the action's declared inputs (`Action::create('a','b',…)` on
the DSL side) as FieldDescriptors, with `required` / `default` / `nullable`
hints. Transition actions have no declared inputs and emit `[]`. The renderer
uses this list to build a working create / update form — see
[The React renderer](react-renderer.md#action-forms).

**Example — HelloInvoice `create` action descriptor:**

```json
{
  "fqn": "billing.invoice.create",
  "name": "create",
  "label": "Create",
  "subjectRequired": false,
  "inputs": [
    { "name": "number",        "type": "string", "label": "Number",
      "required": true, "nullable": false, "typeOptions": { "maxLength": 32 } },
    { "name": "customer_name", "type": "string", "label": "Customer name",
      "required": true, "nullable": false, "typeOptions": { "maxLength": 200 } },
    { "name": "amount",        "type": "money",  "label": "Amount",
      "required": true, "nullable": false, "typeOptions": { "currency": "USD" } }
  ]
}
```

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

## Reserved fields {#reserved-fields}

Some wire fields appear in the schema for forward compatibility — they are
emitted today with a fixed v0.1.x value, but their value will become
**dynamic** in a later minor release. Consumers MUST tolerate the documented
v0.1.x value, MUST NOT pin assertions to it, and SHOULD render the future
shape without code changes.

| Field | v0.1.x value | Will carry in a later release |
|---|---|---|
| `targetProfile` | exactly `"react.web.v1"` | Other rendering profiles (e.g. `react.web.v2`, `react.native.v1`). |
| `metadata.locale` | exactly `"en-US"` | Per-request locale negotiated from `Accept-Language`. |
| `filters` | always `[]` | A list of `FilterDescriptor` items once filtering ships. |
| `data.pagination.nextCursor` | always `null` (when `pagination` is present) | An opaque cursor string when there is another page; `null` when the current page is the last. |
| `ActionDescriptor.confirmation` | declared in the TS type, never emitted by the v0.1.x backend renderer | `{ required: boolean, prompt?: string }` when the action is declared to require confirmation. |

Forward-compatibility contract:

- Reading code: treat `targetProfile` and `metadata.locale` as opaque strings;
  do not branch on the exact v0.1.x value beyond a single "do I support this
  profile?" gate at the consumer boundary.
- Reading code: treat `filters: []` and `nextCursor: null` as the empty case of
  the future shape — render the empty case today, render the populated case
  when it ships.
- Reading code: treat the **absence** of `ActionDescriptor.confirmation` and a
  populated `confirmation.required: false` as equivalent ("no confirmation
  required"). A populated `confirmation.required: true` will mean what the
  TS type already says.
- Producing code: outside the framework you SHOULD NOT emit non-default values
  for these fields in v0.1.x — the renderer does not yet act on them and they
  are reserved for the runtime to populate.

## Related {#related}

- [Projections](../concepts/projections.md) — what renders into a ViewSchema.
- [The HTTP API](../backend/http-api.md) — serves ViewSchemas.
- [The React renderer](react-renderer.md) — consumes them.
