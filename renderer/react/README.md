# @ausus/renderer-react

L6 — React renderer for the `react.web.v1` profile.

## Owned RFC surfaces

- **RFC-004** — ViewSchema wire format consumption.
- **RFC-004 §10.2** — `react.web.v1` profile capabilities (widgets, action hints, operators).

## Public surface

```ts
import { AususProvider, ViewSchemaConsumer, useAction } from '@ausus/renderer-react';
```

- `<AususProvider apiBaseUrl tenant>`: top-level context.
- `<ViewSchemaConsumer projection locale>`: fetches and renders a ViewSchema.
- `useAction(actionFqn)`: hook for dispatching Actions via L4 API Surface.

## Widgets shipped (per profile)

`text`, `textarea`, `number`, `money`, `date-picker`, `datetime-picker`, `time-picker`, `select`, `multi-select`, `checkbox`, `badge`, `json-viewer`, `reference-card`, `icon`.

## Hard rule

Never imports backend code. Consumes JSON only (RFC-001 §3.2.3). The only side-channel back to the backend is `useAction` → L4 API Surface.

## Theme

V1 ships one theme using Tailwind utility classes (`@ausus/renderer-react/themes/default`). Themes are CSS-only swaps; component structure is fixed by the profile.

## API stability

The renderer exposes two kinds of surface, with distinct stability commitments
for v0.1.x:

### Stable — covered by backward-compatibility guarantee

These shapes and helpers are the supported extension seam. Their names,
arities, and observable return shapes will not break in any v0.1.x release.

| Surface | Kind | Where |
|---|---|---|
| `AususProvider`, `ViewSchemaConsumer`, `useAction` | React components / hook | `src/components.tsx`, `src/api.ts` |
| `FieldDescriptor`, `ActionDescriptor`, `ViewSchema` | TypeScript types | `src/types.ts` |
| `inputDefault(input)` | Action-form helper | `src/components.tsx` |
| `initialFor(input, prefill)` | Action-form helper | `src/components.tsx` |
| `isUnchanged(input, current, initial)` | Action-form helper | `src/components.tsx` |
| `isRequired(input)` | Action-form helper | `src/components.tsx` |
| `shapeValue(input, raw)` | Action-form helper | `src/components.tsx` |
| `validateInputs(inputs, values)` | Action-form helper | `src/components.tsx` |
| `buildCreatePayload(inputs, values)` | Action-form helper | `src/components.tsx` |
| `buildUpdatePayload(inputs, values, initialValues)` | Action-form helper | `src/components.tsx` |

Permitted v0.1.x evolutions:

- Adding new entries to the `FieldDescriptor.type` union (existing branches stay).
- Adding new optional keys to `FieldDescriptor`, `ActionDescriptor`, or `ViewSchema`.
- Adding new exported helpers alongside the ones listed above.

Renaming, removing, or changing the return shape of any row in the table is
a breaking change and ships only in a major release (≥ v1.0.0).

### Internal — no stability commitment

Everything else in `src/` (including the `InputControl` component, internal
fetcher implementation details, and CSS class names beyond the documented
selectors) is implementation-level. Pinning against it is unsupported.

Inline TSDoc on the stable helpers carries the `@public stable` tag so the
status is discoverable at the call site.
