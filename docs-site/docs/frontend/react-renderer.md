---
id: react-renderer
title: The React Renderer
sidebar_label: The React Renderer
description: "@ausus/renderer-react — the React client for ViewSchema."
---

# The React Renderer

`@ausus/renderer-react` is the React client for AUSUS. It fetches a
[ViewSchema](viewschema.md) from the [HTTP API](../backend/http-api.md) and
renders it. It is the L6 layer of the stack.

React is treated as a **rendering engine only** — the renderer holds no domain
knowledge. Everything it draws comes from the ViewSchema.

## Install

```bash
npm install @ausus/renderer-react react@18 react-dom@18
# React 19 is also supported:
# npm install @ausus/renderer-react react@^19 react-dom@^19
```

`react` and `react-dom` are **peer dependencies** (`^18 || ^19`). The package
is **ESM-only** and ships no bundled dependencies.

## Public API

```ts
import {
  AususProvider, useAusus,
  useViewSchema, useAction,
  ViewSchemaConsumer,
  ListView, DetailView, ActionModal, WorkflowBadge, FieldDisplay,
} from "@ausus/renderer-react";
```

| Export | Kind | Purpose |
|---|---|---|
| `AususProvider` | component | injects API base URL, tenant, and fetcher |
| `useAusus` | hook | reads the provider context |
| `useViewSchema` | hook | fetches a projection's ViewSchema |
| `useAction` | hook | invokes an action |
| `ViewSchemaConsumer` | component | fetches a projection and dispatches to a view |
| `ListView` / `DetailView` | components | render a list / detail ViewSchema |
| `ActionModal` | component | confirmation + input form for an action |
| `WorkflowBadge` | component | colored badge for a workflow state |
| `FieldDisplay` | component | renders one field cell by type |

## Usage

Wrap your app once in `AususProvider`, then render a projection:

```tsx
import { AususProvider, ViewSchemaConsumer } from "@ausus/renderer-react";

function App() {
  return (
    <AususProvider apiBaseUrl="http://localhost:8080/api" tenant="acme">
      <ViewSchemaConsumer projection="billing.invoice.summary" />
    </AususProvider>
  );
}
```

`ViewSchemaConsumer` fetches the ViewSchema, then:

- renders `ListView` if the schema's `data.items` is present;
- renders `DetailView` if `data.item` is present (a `subject` prop is required);
- shows a loading state while fetching and an error state with a retry button
  on failure.

### The provider

```tsx
<AususProvider
  apiBaseUrl="http://localhost:8080/api"
  tenant="acme"
  fetcher={customFetch}   // optional — defaults to window.fetch
/>
```

The optional `fetcher` lets you inject auth headers, retries, or a test double.
It is the seam where you add the authentication the backend does not provide.

### Hooks directly

```tsx
const { schema, loading, error, refetch } = useViewSchema("billing.invoice.summary");
const { invoke, pending, lastError }      = useAction("billing.invoice.issue");

await invoke({ subject: ref, inputs: {} });
```

`useAction` always awaits the server — there is no optimistic UI in v0.1.0.

## Styling

The renderer emits semantic class names (`ausus-table`, `ausus-badge`,
`ausus-modal`, `ausus-btn`, …) but **ships no CSS file**. You provide the
stylesheet. The class names are stable and documented by their use in the
components.

## Current v0.1.0 limitations

- **No bundled CSS** — you supply styling for the `ausus-*` class names.
- **No router** — `ViewSchemaConsumer` renders one projection; wiring
  list → detail navigation is the host application's job.
- **No optimistic UI** — every action awaits the server response.
- `ActionModal` renders only simple text inputs; rich field editors are not in
  v0.1.0.
- `WorkflowBadge` colours are a fixed palette keyed on common state names
  (`DRAFT`, `ISSUED`, `PAID`, `CANCELLED`); other states get a default colour.
- The workflow-state field is detected by a heuristic (an `enum` field named
  `status`).

## Related

- [ViewSchema](viewschema.md) — the format this renders.
- [The HTTP API](../backend/http-api.md) — where ViewSchemas come from.
- [Packages](../packages/index.md) — the npm package entry.
