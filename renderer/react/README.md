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
