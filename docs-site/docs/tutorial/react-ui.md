---
id: react-ui
title: 'Part 5 — React UI'
sidebar_label: 5. React UI
description: Render the Ticket System in the browser with the AUSUS React renderer.
---

# Part 5 — React UI

**Why this step exists:** the HTTP API returns **ViewSchema** — JSON that
describes fields, actions and data. The AUSUS React renderer turns that JSON
into a working interface. You will not write a table component or a form: the
renderer reads the schema and draws the UI.

## How the renderer works {#how-the-renderer-works}

`@ausus/renderer-react` is a small React package. The two pieces you need:

- **`AususProvider`** — wraps your app once; it holds the API base URL and the
  tenant.
- **`ViewSchemaConsumer`** — given a projection name, it fetches that
  projection's ViewSchema and renders it: a list becomes a table with action
  buttons, a single record becomes a detail view.

The renderer is **metadata-driven**: it has no knowledge of "tickets." Point it
at `helpdesk.ticket.summary` and it draws whatever that projection describes.

## Scaffold a React app {#scaffold-a-react-app}

From the **project root**, create a Vite React app in a `ui/` folder:

```bash
npm create vite@latest ui -- --template react-ts
cd ui
npm install
```

Then add the AUSUS renderer (`react` and `react-dom` are already installed by
the template and satisfy the renderer's peer dependency):

```bash
npm install @ausus/renderer-react
```

## Write the App component {#write-the-app-component}

Replace the contents of `ui/src/App.tsx` with:

```jsx
import { AususProvider, ViewSchemaConsumer } from "@ausus/renderer-react";
import type { Fetcher } from "@ausus/renderer-react";
import "./index.css";

/**
 * The renderer sends the X-Tenant-ID header on its own. We wrap fetch to also
 * send X-Actor-Roles, so action calls pass the policy check.
 *
 * v0.1.0 has no authentication layer — in a real deployment this header is set
 * by an authenticated gateway in front of the API, never in the browser.
 */
const fetcher: Fetcher = (url, init) =>
  fetch(url, {
    ...init,
    headers: {
      ...(init?.headers ?? {}),
      "X-Actor-Roles": "ticket.agent,ticket.viewer",
    },
  });

export default function App() {
  return (
    <AususProvider
      apiBaseUrl="http://localhost:8080/api"
      tenant="helpdesk"
      fetcher={fetcher}
    >
      <header className="ausus-header">
        <strong>Ticket System</strong> · tenant <code>helpdesk</code>
      </header>
      <ViewSchemaConsumer projection="helpdesk.ticket.summary" />
    </AususProvider>
  );
}
```

Three things, and **why**:

- **`apiBaseUrl`** points at the PHP server from Part 4. The browser and the
  API are on different ports, but the `Router` sends permissive CORS headers,
  so cross-origin requests work.
- **`tenant="helpdesk"`** is sent as `X-Tenant-ID` on every request.
- **`fetcher`** is a wrapped `fetch` that adds `X-Actor-Roles`. Without it,
  action buttons would be denied — the same `403` you would get from `curl`.

## Add minimal styles {#add-minimal-styles}

The renderer ships **no CSS** in v0.1.0 — it only sets class names. Replace
`ui/src/index.css` with this minimal stylesheet so the UI is legible:

```css
body { font-family: system-ui, sans-serif; margin: 0; background: #f6f7f9; color: #1a1a1a; }
.ausus-header { padding: 12px 20px; background: #fff; border-bottom: 1px solid #e3e3e3; }
.ausus-list { padding: 20px; }
.ausus-list__header { display: flex; justify-content: space-between; align-items: center; }
.ausus-table { width: 100%; border-collapse: collapse; background: #fff; margin-top: 12px; }
.ausus-table th, .ausus-table td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #eee; }
.ausus-badge { padding: 2px 8px; border-radius: 10px; font-size: 12px; background: #e6e6e6; }
.ausus-btn { padding: 5px 10px; margin: 0 3px; border: 1px solid #ccc; border-radius: 6px;
  background: #fff; cursor: pointer; }
.ausus-btn--primary { background: #2563eb; color: #fff; border-color: #2563eb; }
.ausus-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.4);
  display: flex; align-items: center; justify-content: center; }
.ausus-modal { background: #fff; padding: 20px; border-radius: 8px; min-width: 320px; }
.ausus-loading, .ausus-empty, .ausus-error { padding: 20px; }
```

## Run the whole application {#run-the-whole-application}

You now need **two processes**. In the **first terminal**, from the project
root, start the API (and seed the database if you have not yet):

```bash
php tickets.php          # seeds tickets.sqlite — run once
php -S localhost:8080 server.php
```

In a **second terminal**, start the React dev server:

```bash
cd ui
npm run dev
```

Vite prints a local URL — open it (it is `http://localhost:5173` by default).
You should see the three tickets from Part 3, each with a colored status badge
and per-row workflow buttons.

![Ticket System list view rendered by the AUSUS React renderer — three tickets in a table with colored status badges and per-row workflow buttons.](/img/tutorial/ticket-list.svg)

## Drive the workflow from the browser {#drive-the-workflow-from-the-browser}

Click **Start** on the `OPEN` ticket. A confirmation dialog appears; confirm
it. The row refreshes and the status badge changes to `IN_PROGRESS`. Now
**Resolve** is the legal next step; clicking **Start** again would return a
`WorkflowStateMismatch` error in the dialog — the workflow guard, enforced in
the browser exactly as it was on the CLI.

## Create a ticket from the UI {#create-a-ticket-from-the-ui}

Click **Create** in the list header. The renderer now reads the create
action's input descriptors from the ViewSchema and draws a real form — a text
field for **Title**, another for **Requester**, and a select for **Priority**
(populated with `LOW` / `NORMAL` / `HIGH` and preset to `NORMAL`, the field
default). The title and requester inputs are marked required with a `*`.

Submitting with an empty required field shows an inline `… is required.`
message and blocks the request before it leaves the browser. Submitting with
valid values `POST`s `/api/actions/helpdesk.ticket.create` and the new ticket
appears in the list when the renderer refetches.

![ActionModal create form rendered from the create action's input descriptors — Title and Requester required, Priority preset to NORMAL, with an inline "Title is required." error.](/img/tutorial/create-modal.svg)

This is not entity-specific code in the UI — the renderer builds the form
from the [`ActionDescriptor.inputs`](../frontend/viewschema.md#actiondescriptor)
array the runtime emits. Adding a new field to your domain plugin causes a
new control to appear in the form on the next request, with no UI change.

## What you have now {#what-you-have-now}

```
ticket-system/
├── src/TicketSystem.php
├── tickets.php
├── server.php
├── tickets.sqlite
├── vendor/
└── ui/                  ← Vite + React + @ausus/renderer-react
    └── src/{App.tsx,index.css}
```

A complete vertical slice: a domain, a database, an HTTP API, and a browser UI
— all driven by the one plugin you wrote in Part 2.

**Next: [Part 6 — Troubleshooting & recap](troubleshooting.md).**
