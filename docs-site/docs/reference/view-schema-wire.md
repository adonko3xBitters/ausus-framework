---
id: view-schema-wire
title: ViewSchema wire reference
sidebar_label: ViewSchema (wire)
description: Field-by-field reference for the ViewSchema JSON document — every key, every type, every reserved field, with example payloads from the runtime.
---

# ViewSchema wire reference

This page is the precise, field-by-field contract for the JSON document
`GET /projections/{fqn}` returns. Use it when building a non-React
client, or when round-tripping the document through any custom layer.

For the renderer-side TypeScript view of the same shape, see
[Frontend · ViewSchema](../frontend/viewschema.md). This page is the
**wire** view and is the source of truth.

## Top-level shape {#shape}

```jsonc
{
  "schemaVersion": "1.0.0",                       // string, fixed in v0.1.x
  "targetProfile": "react.web.v1",                // string, fixed in v0.1.x
  "metadata":      { /* ViewSchemaMetadata */ },
  "fields":        [ /* FieldDescriptor[] */ ],
  "actions":       [ /* ActionDescriptor[] */ ],
  "filters":       [],                            // always empty in v0.1.x
  "data":          { /* see §data */ } | null
}
```

| Field | Type | Stability | Notes |
|---|---|---|---|
| `schemaVersion` | `string` | stable wire | The renderer accepts `1.0.x`; anything else surfaces an error in the consumer. |
| `targetProfile` | `string` | stable wire | Fixed value `react.web.v1` in v0.1.x; future profiles will add cases. |
| `metadata` | object | stable wire | Per-render envelope; see §metadata. |
| `fields` | `FieldDescriptor[]` | stable wire | Every field listed in the projection's `fields` array (system fields permitted). |
| `actions` | `ActionDescriptor[]` | stable wire | Every action listed in the projection's `actions` array. |
| `filters` | array | **reserved** | Always emitted as `[]` in v0.1.x. The slot is shipped now so future filter metadata is additive on the wire. |
| `data` | object \| null | stable wire | List shape, detail shape, or absent — see §data. |

## `metadata` {#metadata}

```jsonc
{
  "projection":  "billing.invoice.summary",     // FQN of the projection rendered
  "entity":      "billing.invoice",             // FQN of the owning entity
  "tenant":      "acme",                        // the active tenant
  "locale":      "en-US",                       // fixed in v0.1.x
  "generatedAt": "2026-05-26T20:14:00Z"         // RFC-3339 UTC, server clock
}
```

`generatedAt` is informational; the renderer does not branch on it.

## `FieldDescriptor` {#field-descriptor}

```jsonc
{
  "name":        "title",            // string, the field's name (source of truth)
  "type":        "string",           // see §field-types
  "label":       "Title",            // string, always populated (explicit ->label() or auto-humanised)
  "typeOptions": { /* per-type */ },
  "required":    true,               // present on action.inputs[] only
  "nullable":    false,              // present on action.inputs[] only
  "default":     "DRAFT"             // present on action.inputs[] only when a default exists
}
```

### Field types {#field-types}

| `type` | Value shape on read (`data.item[name]` / `data.items[i][name]`) | Value shape on write (`inputs[name]`) |
|---|---|---|
| `string` | string \| null | string \| null |
| `integer` | number \| null | number (truncated) \| number-string |
| `datetime` | RFC-3339 string \| null | RFC-3339 string \| null |
| `enum` | one of `typeOptions.options` \| null | one of `typeOptions.options` \| null |
| `money` | `{ "amount": "1500.00", "currency": "USD" }` \| null | same |
| `boolean` | bool \| null | bool \| null |
| `identity` | ULID string (system field `id`) | not writable from `inputs` |
| `version` | ULID string (system field `_version`) | not writable; runtime-managed |
| `system_string` | string (system fields `tenant_id`, `created_at`, `updated_at`) | not writable |

### `typeOptions` {#type-options}

| Key | Applies to | Type | Meaning |
|---|---|---|---|
| `maxLength` | `string` | number | Maximum length the field will accept. Enforced by browser `maxlength` in the renderer; not enforced by SQLite. |
| `currency` | `money` | string | ISO currency code (`"USD"`, `"EUR"`, …). Combined with the input number to form the compound write shape. |
| `options` | `enum` | `string[]` | The closed set of legal values, in declaration order. |

### Form-only fields on `FieldDescriptor` {#field-descriptor-form-fields}

`required`, `nullable`, `default` are emitted **only** when the
`FieldDescriptor` appears under `ActionDescriptor.inputs`. They are
**absent** on `fields[]` (the projection's display field list).

- `required` — true when the field is not nullable and has no default.
  Advisory on update actions (see [Action descriptor](#action-descriptor)).
- `nullable` — whether the underlying field accepts SQL NULL.
- `default` — present only when the underlying field declares one;
  carries the raw default value (`"NORMAL"` for an enum, etc.).

## `ActionDescriptor` {#action-descriptor}

```jsonc
{
  "fqn":             "billing.invoice.issue",
  "name":            "issue",                   // FQN's last segment
  "label":           "Issue",                   // ucfirst(name)
  "subjectRequired": true,                      // true for transition + update
  "inputs":          [ /* FieldDescriptor[] */ ],
  "initialValues":   { "title": "old" },        // optional, see below
  "confirmation":    { "required": true, "prompt": "..." }   // RESERVED — never populated by v0.1.x server
}
```

| Field | Type | Stability | Notes |
|---|---|---|---|
| `fqn` | `string` | stable wire | The action FQN — POST target. |
| `name` | `string` | stable wire | Local name (FQN's last segment). |
| `label` | `string` | stable wire | Humanised `name` by default; overridable in v0.2+. |
| `subjectRequired` | `bool` | stable wire | `false` for `create`; `true` for `transition` and `update`. |
| `inputs` | `FieldDescriptor[]` | stable wire | **Always emitted** as an array (possibly empty). `[]` for transitions. |
| `initialValues` | `Record<string, unknown>` \| absent | stable wire | Emitted only on **update** actions when the projection rendered a single subject (`data.item`). Maps input field names to the subject's current values. |
| `confirmation` | object \| absent | **reserved** | Declared on the TS type for forward compatibility; the v0.1.x server never populates it. Custom clients may surface confirmation UX from other signals. |

### How `inputs[]` is populated per action kind {#inputs-per-kind}

| Action kind | Server-emitted `inputs[]` | Notes |
|---|---|---|
| `Action::create(...$fields)` | One entry per declared field | Drives the renderer's create form. |
| `Action::transition(...)` | `[]` | Transitions take no metadata-driven inputs; the runtime accepts arbitrary inputs for stamping but does not surface them on the wire. |
| `Action::update(...$fields)` | One entry per patchable field | Drives the renderer's update form. The list is closed — `UpdateEffect` rejects any input key outside it. |

### How `initialValues` is populated {#initial-values}

For each update action whose descriptor appears on a **detail**
projection (one with `data.item`):

```
initialValues[fieldName] = data.item[fieldName] ?? null
```

`initialValues` is omitted on list projections (no single subject to
prefill from) and on non-update descriptors. The renderer's
`ActionModal` switches to update-mode (prefill + diff payload) when
this key is present.

## `data` {#data}

Either of two shapes — never both, never neither (`null` only when the
projection rendered against an unknown subject).

### List shape

```jsonc
{
  "items": [
    { "id": "01J…", "title": "first row",  "status": "OPEN" },
    { "id": "01J…", "title": "second row", "status": "DONE" }
  ],
  "pagination": {
    "nextCursor": null,                          // RESERVED — always null in v0.1.x
    "pageSize":   2
  }
}
```

| Field | Stability | Notes |
|---|---|---|
| `items` | stable wire | Every row for the tenant, in `id` order. No filtering, no slicing. |
| `pagination.pageSize` | stable wire | Always equal to `items.length` in v0.1.x. |
| `pagination.nextCursor` | **reserved** | Always `null` — there is no cursor to advance to. Reserved for v0.2+ cursor pagination; consumers that read it must accept `null`. |

### Detail shape

```jsonc
{
  "item": {
    "id":      "01J…",
    "title":   "single row",
    "status":  "OPEN",
    "amount":  { "amount": "10.00", "currency": "USD" }
  }
}
```

`item` is `null` when the `subject` query param did not resolve to an
existing row (the route still returns `200 OK` — only the embedded
`item` differs).

### Reading row values

Row values follow the **field type** table in §field-types. Notable
points:

- `money` is always the compound object `{ amount, currency }` on read.
  Empty/null money columns read back as `null` (not `{amount: '',
  currency: 'USD'}`).
- `datetime` is an RFC-3339 string with `Z` suffix (UTC).
- Nullable columns explicitly set to `null` read back as JSON `null`,
  not the string `"null"`.

## Reserved fields summary {#reserved}

These fields appear on the wire today but the server emits a fixed
value. Custom clients may safely assume the fixed values; future
versions will add behaviour.

| Field | v0.1.x value | Reserved for |
|---|---|---|
| `schemaVersion` | `"1.0.0"` | Future bumps will follow semver; client should accept `1.0.x`. |
| `targetProfile` | `"react.web.v1"` | Additional profiles (mobile, terminal) in future releases. |
| `metadata.locale` | `"en-US"` | Future i18n response selection. |
| `filters` | `[]` | Future projection-level filter descriptors. |
| `data.items.pagination.nextCursor` | `null` | Future cursor pagination. |
| `ActionDescriptor.confirmation` | absent | Future server-emitted confirmation prompts (clients today may render their own). |

## Worked example {#worked-example}

A `tracker.issue.detail` projection rendered for one ticket, with one
update action prefilled and one transition that takes no inputs:

```jsonc
{
  "schemaVersion": "1.0.0",
  "targetProfile": "react.web.v1",
  "metadata": {
    "projection":  "tracker.issue.detail",
    "entity":      "tracker.issue",
    "tenant":      "acme",
    "locale":      "en-US",
    "generatedAt": "2026-05-26T20:14:00Z"
  },
  "fields": [
    { "name": "id",         "type": "identity", "label": "Id" },
    { "name": "project_id", "type": "string",   "label": "Project",  "typeOptions": { "maxLength": 26 } },
    { "name": "title",      "type": "string",   "label": "Title",    "typeOptions": { "maxLength": 200 } },
    { "name": "status",     "type": "enum",     "label": "Status",
      "typeOptions": { "options": ["TODO","DOING","DONE"] } }
  ],
  "actions": [
    {
      "fqn":             "tracker.issue.rename",
      "name":            "rename",
      "label":           "Rename",
      "subjectRequired": true,
      "inputs":          [
        { "name": "title", "type": "string", "label": "Title",
          "required": true, "nullable": false, "typeOptions": { "maxLength": 200 } }
      ],
      "initialValues":   { "title": "Renderer crashes on null money" }
    },
    {
      "fqn":             "tracker.issue.start",
      "name":            "start",
      "label":           "Start",
      "subjectRequired": true,
      "inputs":          []
    }
  ],
  "filters": [],
  "data": {
    "item": {
      "id":         "01J7HG3WC0D3K3M5VS9Y29T1WT",
      "project_id": "01J7HG3WC0D3K3M5VS9Y29T1AB",
      "title":      "Renderer crashes on null money",
      "status":     "TODO"
    }
  }
}
```

## Related {#related}

- [HTTP routes reference](http-routes.md) — the routes that emit this document.
- [Application & ApplicationConfig](application.md) — `Application::render()` returns the same shape as a PHP array.
- [Frontend · ViewSchema](../frontend/viewschema.md) — the renderer-side TypeScript view of this contract.
