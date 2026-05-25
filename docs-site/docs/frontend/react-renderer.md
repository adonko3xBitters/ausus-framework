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

## Install {#install}

```bash
npm install @ausus/renderer-react react@18 react-dom@18
# React 19 is also supported:
# npm install @ausus/renderer-react react@^19 react-dom@^19
```

`react` and `react-dom` are **peer dependencies** (`^18 || ^19`). The package
is **ESM-only** and ships no bundled dependencies.

## Public API {#public-api}

```ts
import {
  AususProvider, useAusus,
  useViewSchema, useAction,
  ViewSchemaConsumer,
  ListView, DetailView, ActionModal, WorkflowBadge, FieldDisplay,
  inputDefault, isRequired, shapeValue, validateInputs,
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
| `ActionModal` | component | confirmation + dynamic input form for an action |
| `WorkflowBadge` | component | colored badge for a workflow state |
| `FieldDisplay` | component | renders one field cell by type |
| `inputDefault`, `isRequired`, `shapeValue`, `validateInputs` | functions | pure form helpers — exposed for consumers building custom action UIs against the same payload contract |

## Data flow {#data-flow}

What a single rendered page does, end-to-end:

![React renderer data flow: the HTTP API serves a ViewSchema; ViewSchemaConsumer dispatches it to ListView (items) or DetailView (item), each of which surfaces actions; an action button opens ActionModal, which builds its form from action.inputs and POSTs back to /actions/{fqn}.](/img/diagrams/renderer-flow.svg)

The renderer never inspects domain types directly — every choice is made from
the ViewSchema. Adding a field to your plugin shows up as a new column or a
new form control on the next request, with no UI change.

## Usage {#usage}

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

### The provider {#the-provider}

```tsx
<AususProvider
  apiBaseUrl="http://localhost:8080/api"
  tenant="acme"
  fetcher={customFetch}   // optional — defaults to window.fetch
/>
```

The optional `fetcher` lets you inject auth headers, retries, or a test double.
It is the seam where you add the authentication the backend does not provide.

### Hooks directly {#hooks-directly}

```tsx
const { schema, loading, error, refetch } = useViewSchema("billing.invoice.summary");
const { invoke, pending, lastError }      = useAction("billing.invoice.issue");

await invoke({ subject: ref, inputs: {} });
```

`useAction` always awaits the server — there is no optimistic UI in v0.1.0.

## Action forms {#action-forms}

`ActionModal` builds its form **entirely from `action.inputs`** — the
[`ActionDescriptor.inputs`](viewschema.md#actiondescriptor) array the runtime
emits. There is no entity-specific code in the renderer: pointing it at any
action that declares inputs renders the matching form.

| `type` | Control | Submitted shape |
|---|---|---|
| `string` | `<input type="text">` (honors `typeOptions.maxLength`) | string |
| `integer` | `<input type="number" step="1">` | number (truncated) |
| `money` | `<input type="number" step="0.01">` + fixed currency label | `{ amount, currency }` |
| `enum` | `<select>` populated from `typeOptions.options` | string |
| `boolean` | `<input type="checkbox">` | boolean |
| `datetime` | `<input type="datetime-local">` | string |
| unknown | text input fallback | string |

Required inputs (`required: true` in the descriptor, i.e. no default and not
nullable) are marked with a `*` indicator and validated before submit. A
failed required-field check shows an inline `ausus-input-error` next to the
control and blocks submission; a server-side failure populates the modal's
top-level error block.

Actions whose `inputs` are empty (transitions like `issue` / `cancel`) skip
the form and show the confirmation prompt instead, matching the existing
behavior.

### Update actions and prefill {#update-actions}

When an `ActionDescriptor` carries `initialValues` (v0.2, see
[`Action::update(...)`](../backend/php-dsl.md#action-kinds)), `ActionModal`
treats the modal as an **update** form:

- Form state is seeded from `initialValues[name]` via the `initialFor`
  helper (compound `money` flattens to its amount string for the input
  box; the submit reconstitutes the tuple).
- The submit handler builds a **diff** payload: only inputs whose shaped
  current value differs from `initialValues` are sent. Unchanged keys are
  omitted, so the wire request is exactly the patch the user typed —
  matching the partial-PATCH semantics enforced server-side by
  `UpdateEffect`.
- Required, nullable, default and `typeOptions` continue to come from the
  same `FieldDescriptor` shape; nothing changes for the per-input control
  itself.

```jsx
// The renderer makes no distinction at the component level — same modal,
// same inputs, same submit hook. The presence of initialValues alone
// switches the payload-building strategy.
<ActionModal action={renameDescriptor} subject={issueRef} onClose={...} />
```

The exported helpers — `inputDefault`, `isRequired`, `shapeValue`,
`validateInputs` plus the v0.2 additions `initialFor`, `isUnchanged`,
`buildCreatePayload`, `buildUpdatePayload` — are the same pure functions
`ActionModal` uses internally. A consumer building a custom form widget
can reuse them to stay on the runtime's payload contract without
re-implementing the type-shaping rules.

## Styling {#styling}

The renderer emits semantic class names (`ausus-table`, `ausus-badge`,
`ausus-modal`, `ausus-btn`, …) but **ships no CSS file**. You provide the
stylesheet. The class names are stable and documented by their use in the
components.

## Current v0.1.0 limitations {#current-v010-limitations}

- **No bundled CSS** — you supply styling for the `ausus-*` class names.
- **No router** — `ViewSchemaConsumer` renders one projection; wiring
  list → detail navigation is the host application's job.
- **No optimistic UI** — every action awaits the server response.
- `ActionModal` exposes one control per field type — `string` / `integer` /
  `money` / `enum` / `boolean` / `datetime`. Richer editors (rich text,
  file uploads, related-record pickers, validation rules beyond *required*)
  are not in v0.1.0.
- `WorkflowBadge` colours are a fixed palette keyed on common state names
  (`DRAFT`, `ISSUED`, `PAID`, `CANCELLED`); other states get a default colour.
- The workflow-state field is detected by a heuristic (an `enum` field named
  `status`).

## Related {#related}

- [ViewSchema](viewschema.md) — the format this renders.
- [The HTTP API](../backend/http-api.md) — where ViewSchemas come from.
- [Packages](../packages/index.md) — the npm package entry.
