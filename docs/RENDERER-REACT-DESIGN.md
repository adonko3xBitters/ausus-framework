# @ausus/renderer-react — V0 Implementation Design

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Active design (V0 implementation phase)                |
| Authors       | architect, frontend maintainer                         |
| Date          | 2026-05-19                                             |
| Implements    | RFC-004 react.web.v1 profile consumption (L6)          |
| Owning package | `@ausus/renderer-react` (npm)                         |
| Sister package | `ausus/renderer-react` (Composer, in `packages/presentation-default`) |

This is **NOT a design system project.** The renderer's V0 mission is to prove four things, no more:

1. **Metadata-driven rendering works** — a Projection that adds a Field renders the new Field without code changes.
2. **Actions work** — clicking a button invokes a server-side Action.
3. **Workflow state is visible** — the user sees the current state of an Entity instance.
4. **Forms and tables render** — the two primary UI surfaces for V0.

If V0 ships and these four claims hold, the renderer succeeds. Visual polish, theming sophistication, animation, drag-and-drop builders, live collaboration, offline mode — all explicitly deferred.

The renderer is the L6 layer per RFC-001 §3.1: it consumes ViewSchema JSON (RFC-004 wire format) and emits React elements. It NEVER imports backend code. The only side-channel back to the backend is `useAction` → L4 API Surface (HTTP).

---

## 1. Scope

### 1.1 V0 in scope

| Concern                          | V0                                                        |
|----------------------------------|-----------------------------------------------------------|
| List Projection (table view)     | ✓ — DataTable from ViewSchema.fields + ViewSchema.data.items |
| Detail Projection                | ✓ — FieldList for read-only display                       |
| Edit Projection                  | ✓ — Form with field inputs + submit + validation          |
| Action buttons                   | ✓ — list-level + item-level                                |
| Confirmation dialog              | ✓ — for actions with `confirmation.required: true`         |
| Action modal                     | ✓ — for actions with inputs (collect inputs, submit)       |
| Field display widgets            | ✓ — 11 standard types (text, textarea, money, date, enum, etc.) |
| Field input widgets              | ✓ — editable versions of the above                         |
| Validation errors                | ✓ — server-returned + client-side based on validation block |
| Workflow badge                   | ✓ — `status` field as colored badge                        |
| Pagination                       | ✓ — cursor-based next/prev                                 |
| Filter bar                       | ✓ — simple inline filter inputs per ViewSchema.filters     |
| Loading / error states           | ✓ — spinners + error banners                               |

### 1.2 V0 explicit deferrals

| Deferral                          | Why                                                       |
|-----------------------------------|-----------------------------------------------------------|
| Drag/drop builders                | V3 visual-builder territory (RFC-001 §6 roadmap)          |
| Theming systems                   | One Tailwind theme; no design tokens                       |
| Visual editors                    | Out of V0; RFC-001 §6 V3                                   |
| Live collaboration                | Out of V1                                                  |
| Animations / transitions          | None; instant state changes                                |
| SSR (Next.js server components)   | CSR only; SSR breaks the "consumes JSON only" model        |
| Offline mode / service workers    | Out of V1                                                  |
| Optimistic UI                     | All actions wait for server response                       |
| Skeleton screens                  | Plain spinner during load                                  |
| Mobile-specific layouts          | Responsive Tailwind utility classes; no mobile-first redesign |
| Accessibility audits              | Basic semantic HTML; full WCAG audit post-V1               |
| Internationalization beyond locale string passthrough | All strings come server-side per RFC-004 §9 |
| Embedded relations expansion in tables | Reference cells show "Customer ABC" via embeddedFields; no expand-on-row |
| Multi-select bulk row actions     | One-row-at-a-time only in V0                                |
| Inline editing                    | Edit goes through dedicated Edit Projection                |
| Sort headers                      | V0: default order from server; no client-side sort        |
| Print views, exports              | Out of V0                                                  |

---

## 2. Renderer architecture

### 2.1 High-level

```
[User's browser]
       │
       │ HTTP
       ▼
[L4 API Surface (out of V0 — assumed via mock for tests)]
   GET /api/projections/{fqn}?locale=...&renderer=react.web.v1
   POST /api/actions/{fqn}
       │
       │ JSON
       ▼
[React app in apps/playground/frontend or any consumer]
   AususProvider (context)
       │
       │ React tree
       ▼
   <ViewSchemaConsumer projection="billing.invoice.summary" />
       │
       │ uses useViewSchema hook → fetch → parse
       ▼
   SchemaRenderer (dispatches by Projection kind)
       │
       ├─ ListView (table + filter + actions + pagination)
       ├─ DetailView (read-only field list + actions)
       └─ EditView (editable form + validation + submit)
```

The renderer is a **pure consumer** of JSON. It has no direct knowledge of any Entity, Action, Policy, Workflow. Everything is driven by the ViewSchema shape.

### 2.2 Public API surface

```ts
// Main entry
import { AususProvider, ViewSchemaConsumer } from '@ausus/renderer-react';

// Hooks
import { useAusus, useViewSchema, useAction } from '@ausus/renderer-react';

// Theme (one default)
import '@ausus/renderer-react/themes/default.css';
```

Six public exports. That's the entire API. Internals (DataTable, FieldDisplay, widgets) are private; consumers compose via the high-level surface only.

### 2.3 Tech stack

| Concern               | Choice                                                                     |
|-----------------------|----------------------------------------------------------------------------|
| React version          | 18+ (functional components only; no class components)                      |
| Build                  | TypeScript 5 + tsc → ES2022 + Vite for the playground app                 |
| State                  | `useState` + `useReducer` only. No Redux, no MobX, no Zustand, no Recoil. |
| Data fetching          | Native `fetch` + custom hooks. No TanStack Query, no SWR.                  |
| Styling                | Tailwind utility classes; ~50 lines of global CSS for modal overlay, spinner |
| Forms                  | Manual `useState` per form; no React Hook Form, no Formik                  |
| Routing                | None. Consumer (starter app) supplies routing (React Router, Next.js, etc.) |
| Testing                | Vitest + React Testing Library + Mock Service Worker                       |
| Bundle size target     | < 50 KB gzipped (V0)                                                       |
| Peer deps              | `react ^18 || ^19`, `react-dom` same                                       |
| Dev deps               | `vite`, `vitest`, `typescript`, `@types/react`, `tailwindcss`, ESLint     |

Zero runtime npm dependencies beyond React peers. The package is pure consumer code; minimal blast radius for breakage.

---

## 3. Component map

### 3.1 File layout

```
src/
├── index.ts                                  # public exports
├── AususProvider.tsx                         # context: apiBaseUrl, tenant, headers
├── ViewSchemaConsumer.tsx                    # main entry; fetches + dispatches
├── hooks/
│   ├── useAusus.ts                           # consume AususProvider context
│   ├── useViewSchema.ts                      # fetch ViewSchema; cursor state
│   └── useAction.ts                          # invoke action; pending/error/success
├── views/
│   ├── SchemaRenderer.tsx                    # dispatches list / detail / edit
│   ├── ListView.tsx                          # table-based list
│   ├── DetailView.tsx                        # read-only field list
│   └── EditView.tsx                          # form with submit
├── components/
│   ├── DataTable.tsx                         # generic table from schema
│   ├── FilterBar.tsx                         # renders filters; updates state
│   ├── Pagination.tsx                        # cursor next/prev
│   ├── ActionBar.tsx                         # row of action buttons
│   ├── ActionButton.tsx                      # single action button; orchestrates modal/confirm
│   ├── ActionModal.tsx                       # modal hosting input form
│   ├── ConfirmationDialog.tsx                # yes/no destructive confirmation
│   ├── Form.tsx                              # form wrapper; manages validation state
│   ├── FormField.tsx                         # label + input + error per field
│   ├── ValidationError.tsx                   # inline error text
│   ├── WorkflowBadge.tsx                     # colored badge for enum status fields
│   ├── FieldDisplay.tsx                      # read-only field renderer (dispatch by type)
│   ├── FieldInput.tsx                        # editable field input (dispatch by widget)
│   ├── ErrorBanner.tsx                       # top-level error display
│   ├── LoadingSpinner.tsx                    # plain loader
│   └── widgets/
│       ├── TextInput.tsx
│       ├── TextareaInput.tsx
│       ├── NumberInput.tsx
│       ├── MoneyInput.tsx
│       ├── DatePicker.tsx
│       ├── DatetimePicker.tsx
│       ├── TimePicker.tsx
│       ├── Select.tsx
│       ├── MultiSelect.tsx
│       ├── Checkbox.tsx
│       ├── Badge.tsx
│       ├── JsonViewer.tsx
│       └── ReferenceCard.tsx
├── types/
│   ├── ViewSchema.ts                         # TypeScript types matching RFC-004
│   ├── ActionDescriptor.ts
│   ├── FieldDescriptor.ts
│   ├── FilterDescriptor.ts
│   ├── DataItem.ts
│   └── ApiTypes.ts                           # action invocation request/response shapes
└── theme/
    ├── default.css                           # ~50 lines: modal overlay, spinner keyframes, focus rings
    └── tailwind.css                          # consumed by the consumer's Tailwind config
```

**~35 components + 3 hooks + 6 type files.** Approximately **800 lines of TypeScript** including types. Bundle ~30 KB gzipped (estimated).

### 3.2 No subclassing

All components are functional. No class components. No HOCs (Higher-Order Components). No render-prop patterns beyond simple children.

---

## 4. Component tree (runtime)

For a typical `list` Projection request:

```
<AususProvider apiBaseUrl="/api" tenant="acme" authHeaders={{...}}>
  └─ <ViewSchemaConsumer projection="billing.invoice.summary" locale="en-US">
       │  (calls useViewSchema → state {schema, loading, error, filters, cursor})
       └─ if loading:   <LoadingSpinner />
          if error:     <ErrorBanner error={error} onRetry={refetch} />
          if schema:    <SchemaRenderer schema={schema} onRefetch={refetch}>
                         └─ <ListView schema={schema}>
                              ├─ <FilterBar filters={schema.filters} value={filters} onChange={...} />
                              ├─ <ActionBar actions={listLevelActions} subject={null} />
                              │    └─ <ActionButton action={createAction} />
                              ├─ <DataTable
                              │     fields={schema.fields}
                              │     items={schema.data.items}
                              │     itemActions={itemLevelActions} />
                              │    └─ for each row:
                              │       ├─ for each field: <FieldDisplay field={...} value={...} />
                              │       │                  └─ if field.name === workflowStateField: <WorkflowBadge value={...} />
                              │       │                  else: <Badge|TextDisplay|MoneyDisplay|... />
                              │       └─ <ActionBar actions={itemActions} subject={ref} />
                              └─ <Pagination
                                   nextCursor={schema.data.nextCursor}
                                   previousCursor={schema.data.previousCursor}
                                   onChange={setCursor} />
```

For a `detail` Projection:

```
<ViewSchemaConsumer projection="billing.invoice.detail" subject={ref}>
  └─ <SchemaRenderer>
       └─ <DetailView schema={schema}>
            ├─ <FieldList fields={schema.fields} item={schema.data.item} />
            │    └─ for each field: <FieldDisplay ... />
            └─ <ActionBar actions={detailActions} subject={ref} />
```

For an `edit` Projection:

```
<ViewSchemaConsumer projection="billing.invoice.edit" subject={ref}>
  └─ <SchemaRenderer>
       └─ <EditView schema={schema}>
            └─ <Form initialValues={item} onSubmit={submitHandler}>
                 ├─ for each field: <FormField field={...} value={...} error={...} />
                 │                   ├─ <FieldInput field={...} />   // widget-based
                 │                   └─ <ValidationError error={...} />
                 └─ <FormActions submitLabel="Save" cancelLabel="Discard" />
```

---

## 5. Rendering pipeline

### 5.1 Schema fetch

```
1. ViewSchemaConsumer mounts with props { projection, locale, subject? }
2. useViewSchema hook constructs URL:
   GET ${apiBaseUrl}/projections/${projection}?locale=${locale}&renderer=react.web.v1&acceptSchemaVersions=1.0.0
   + if subject: &subject=${subject.identityHandle}
3. fetch() with headers from AususProvider (tenant, auth)
4. State transitions:
   { loading: true, schema: null, error: null }
   ↓ on success:
   { loading: false, schema: parsed, error: null }
   ↓ on failure:
   { loading: false, schema: null, error: e }
```

### 5.2 Schema validation

On parse, the consumer verifies:

- `schemaVersion` starts with `1.0` (V0 accepts only `1.0.x`)
- `targetProfile === 'react.web.v1'`
- Required envelope keys present (per RFC-004 §3.1)
- `compatibility.emittedVersion` matches request

If validation fails: render `<ErrorBanner>` with "Incompatible schema received from server." Don't try to render partial.

### 5.3 Field-to-widget mapping

For display (read-only):

```ts
function pickDisplayComponent(field: FieldDescriptor): ComponentType {
    if (isWorkflowStateField(field)) return WorkflowBadge;
    switch (field.type) {
        case 'string':   return field.hints?.widget === 'textarea' ? TextareaDisplay : TextDisplay;
        case 'integer':
        case 'decimal':  return NumberDisplay;
        case 'money':    return MoneyDisplay;
        case 'boolean':  return CheckboxDisplay;
        case 'date':     return DateDisplay;
        case 'datetime': return DatetimeDisplay;
        case 'time':     return TimeDisplay;
        case 'enum':     return field.hints?.widget === 'badge' ? Badge : EnumDisplay;
        case 'json':     return JsonViewer;
        case 'reference':return ReferenceCard;
        default:         return UnknownTypeDisplay;
    }
}
```

For input (editable): same dispatch but to `TextInput`, `NumberInput`, etc.

`isWorkflowStateField` heuristic: the field's name appears in any of the schema's actions' transition metadata (e.g., `Action::transition('status', ...)` declares status as the workflow field). In V0: hardcoded check — if field.type is `enum` AND there is at least one action whose name contains "issue" / "cancel" / "approve" etc., treat as workflow state. M2 adds explicit metadata `field.hints.role === 'workflow_state'`.

### 5.4 Data display flow

For list:

```
schema.data.items: Array<DataItem>
  ↓ for each item:
    schema.fields.map(field => {
        const value = item[field.name];
        const Component = pickDisplayComponent(field);
        return <Component field={field} value={value} />;
    })
```

For detail/edit: same, single item.

### 5.5 Filter flow

```
1. FilterBar renders one input per schema.filters entry.
2. User edits → onChange callback updates `filters` state in useViewSchema.
3. State change triggers re-fetch:
   GET ${apiBaseUrl}/projections/${projection}?...&filter[status]=ISSUED&filter[customer]=ACME
4. New schema arrives; data re-renders.
```

V0 sends filters as URL query params. M2 may switch to POST body for complex filter trees.

### 5.6 Action invocation flow

```
1. User clicks <ActionButton action={action} subject={ref}>.
2. If action.confirmation?.required:
   show <ConfirmationDialog message={action.confirmation.prompt} />
   ↓ User confirms or cancels.
3. If action.inputs.length > 0:
   show <ActionModal action={action} subject={ref}>
     <Form>
        for each input: <FieldInput />
     </Form>
4. User submits → useAction(action.fqn).invoke({ subject, inputs })
5. useAction hook:
   POST ${apiBaseUrl}/actions/${action.fqn}
   body: { subject: ref, inputs }
   headers: tenant + auth
   ↓
6. Response:
   if ok: close modal/dialog; refetch ViewSchema; show toast "Issued INV-001"
   if error: show error inside modal; keep modal open
```

---

## 6. ViewSchema transport (RFC-004 contract)

### 6.1 Request

```http
GET /api/projections/billing.invoice.summary
    ?locale=en-US
    &renderer=react.web.v1
    &acceptSchemaVersions=1.0.0
    [&subject=inv_01JABCXYZ123]
    [&filter[status]=ISSUED]
    [&filter[customer_name]=ACME]
    [&cursor=eyJsYXN0X2lkIjoiaW52XzAxIn0]
Host: acme.app.example
X-Tenant-ID: acme
Authorization: Bearer <token>
```

Subject is required for `detail` and `edit` Projections; absent for `list`.

### 6.2 Response — success (RFC-004 §3.1)

```json
{
  "schemaVersion": "1.0.0",
  "targetProfile": "react.web.v1",
  "metadata": { "projection": "...", "entity": "...", "tenant": "...", "locale": "...", "generatedAt": "...", "cacheKey": "...", "actorRoleHash": "..." },
  "compatibility": { "requestedProfile": "...", "negotiatedProfile": "...", "requestedVersions": [...], "emittedVersion": "1.0.0", "downgrades": [...], "rejectedCapabilities": [...] },
  "fields": [...],
  "actions": [...],
  "filters": [...],
  "data": { ... } | null
}
```

### 6.3 Response — error

```json
{
  "error": {
    "kind": "ProjectionForbidden | ProjectionNotFound | IncompatibleRenderer | UnknownRendererProfile | NoCommonSchemaVersion | ...",
    "message": "Human-readable",
    "code": "RFC004.ProjectionForbidden"
  }
}
```

With HTTP status per RFC-005 §16.1 (deferred to L4 specification).

The renderer's TypeScript type:

```ts
type ViewSchemaResponse =
  | { kind: 'success'; schema: ViewSchema }
  | { kind: 'error';   error: ApiError };
```

### 6.4 Action invocation request

```http
POST /api/actions/billing.invoice.issue
Content-Type: application/json
X-Tenant-ID: acme
Authorization: Bearer <token>

{
  "subject": { "tenant_id": "acme", "entity_fqn": "billing.invoice", "identity_handle": "inv_01J..." },
  "inputs": { /* matches action.inputs schema */ }
}
```

### 6.5 Action invocation response

Success:

```json
{
  "ok": true,
  "outputs": { "status": "ISSUED", "issued_at": "2026-05-19T14:32:00Z" }
}
```

Error:

```json
{
  "ok": false,
  "error": {
    "kind": "PolicyDenied | WorkflowStateMismatch | WorkflowGuardDenied | EffectFailed | ConcurrencyConflict | ConstraintViolation | TenantBoundaryViolation | AuditEmissionFailed | ...",
    "message": "...",
    "code": "RFC005.PolicyDenied",
    "details": { /* error-specific structured data */ }
  }
}
```

The renderer maps known error kinds to user-friendly messages; unknown kinds show the `message` verbatim.

---

## 7. Renderer state model

### 7.1 State shapes

Per `ViewSchemaConsumer` instance (managed inside `useViewSchema`):

```ts
type ViewSchemaState = {
    loading: boolean;
    schema: ViewSchema | null;
    error: ApiError | null;
    filters: Record<string, unknown>;
    cursor: string | null;
};
```

Per `useAction` instance:

```ts
type ActionState = {
    pending: boolean;
    error: ApiError | null;
    lastOutputs: Record<string, unknown> | null;
};
```

Form state (inside `<Form>`):

```ts
type FormState = {
    values: Record<string, unknown>;
    errors: Record<string, string>;
    submitting: boolean;
};
```

That is the **entire** state surface. No global store. No Redux. Each component owns what it needs.

### 7.2 State persistence

V0 does NOT persist state across page reloads (no localStorage / sessionStorage). Each navigation re-fetches. Browser back/forward uses normal `history.back()` semantics; the consumer's router handles.

### 7.3 Re-render triggers

| Trigger                                  | Effect                                                       |
|------------------------------------------|--------------------------------------------------------------|
| Initial mount                            | Fetch schema; render loading                                 |
| Schema arrives                           | Render full view                                             |
| Filter change                            | Re-fetch schema (debounced ~300ms)                           |
| Pagination cursor change                 | Re-fetch with new cursor                                     |
| Action succeeds                          | Re-fetch schema; toast notification                          |
| Action fails                             | Show error in modal/inline; don't re-fetch                   |
| `prop.projection` changes                | Fresh fetch                                                  |
| `prop.locale` changes                    | Fresh fetch                                                  |

### 7.4 Memo strategy

V0: no `useMemo`, no `useCallback` premature optimization. React 18 is fast; HelloInvoice's list has ~10 rows. Add memoization only if measured profiling shows a problem.

---

## 8. Hooks API

### 8.1 `useAusus()`

```ts
function useAusus(): {
    apiBaseUrl: string;
    tenant: string;
    authHeaders: Record<string, string>;
};
```

Reads `<AususProvider>` context. Throws if not within a provider.

### 8.2 `useViewSchema(projection, options)`

```ts
function useViewSchema(
    projection: string,
    options?: {
        locale?: string;
        subject?: Reference;
        initialFilters?: Record<string, unknown>;
    }
): {
    loading: boolean;
    schema: ViewSchema | null;
    error: ApiError | null;
    filters: Record<string, unknown>;
    setFilters: (next: Record<string, unknown>) => void;
    cursor: string | null;
    setCursor: (next: string | null) => void;
    refetch: () => void;
};
```

### 8.3 `useAction(actionFqn)`

```ts
function useAction(actionFqn: string): {
    invoke: (args: { subject?: Reference; inputs: Record<string, unknown> }) => Promise<ActionResult>;
    pending: boolean;
    error: ApiError | null;
};

type ActionResult =
    | { ok: true;  outputs: Record<string, unknown> }
    | { ok: false; error: ApiError };
```

`invoke` returns a Promise; consumers can `await` for fine-grained handling.

---

## 9. Action execution flow (detailed)

### 9.1 Sequence diagram

```
User                ActionButton            ConfirmationDialog       ActionModal           useAction              API
 │                       │                          │                     │                      │                    │
 │── click ───────────►  │                          │                     │                      │                    │
 │                       │                          │                     │                      │                    │
 │                       │ if confirmation.required │                     │                      │                    │
 │                       │── show ────────────────► │                     │                      │                    │
 │                       │                          │                     │                      │                    │
 │── confirm ─────────►  │ ◄────── close ────────── │                     │                      │                    │
 │                       │                          │                     │                      │                    │
 │                       │ if inputs.length > 0     │                     │                      │                    │
 │                       │── show modal ─────────────────────────────────► │                      │                    │
 │                       │                          │                     │                      │                    │
 │── fill + submit ──────────────────────────────────────────────────────► │                      │                    │
 │                       │                          │                     │── invoke() ────────► │                    │
 │                       │                          │                     │                      │── POST /api/... ──► │
 │                       │                          │                     │                      │                    │── (Invoker chain)
 │                       │                          │                     │                      │                    │
 │                       │                          │                     │                      │ ◄── response ──────│
 │                       │                          │                     │ ◄── result ──────────│                    │
 │                       │                          │                     │                      │                    │
 │                       │                          │                     │ if ok: close modal   │                    │
 │                       │                          │                     │       toast success  │                    │
 │                       │                          │                     │       refetch schema │                    │
 │                       │                          │                     │ if error: show in modal                   │
```

### 9.2 Confirmation dialog

For actions with `confirmation.required: true`:

```tsx
<ConfirmationDialog
    title={`${action.label} ${subject ? subjectLabel : ''}`}
    message={action.confirmation.prompt}
    confirmLabel={action.label}
    confirmStyle={action.hints?.style === 'danger' ? 'danger' : 'primary'}
    onConfirm={() => proceedToInputCollection()}
    onCancel={() => setOpen(false)}
/>
```

Simple modal: title, body text, two buttons. No challenge input in V0 (RFC-004 §6.3 `confirmation.challenge` not implemented).

### 9.3 Action modal

For actions with `inputs.length > 0`:

```tsx
<ActionModal
    title={action.label}
    description={action.description}
    onClose={() => setOpen(false)}
>
    <Form
        fields={action.inputs}
        initialValues={{}}
        onSubmit={async (values) => {
            const result = await invoke({ subject, inputs: values });
            if (result.ok) {
                setOpen(false);
                toast.success(`${action.label} succeeded`);
                onSuccess?.(result.outputs);
            } else {
                setFormError(result.error);
            }
        }}
        submitLabel={action.label}
    />
</ActionModal>
```

Submits via `useAction(action.fqn).invoke(...)`. On error, modal stays open and shows the error inline.

### 9.4 No-input no-confirmation actions

For actions with neither inputs nor confirmation (e.g., a trivial "refresh" button):

```tsx
<ActionButton action={action} subject={ref} onClick={async () => {
    const result = await invoke({ subject: ref, inputs: {} });
    if (result.ok) toast.success(action.label);
    else toast.error(result.error.message);
}} />
```

Direct invocation; no modal flow.

---

## 10. Optimistic UI policy

### 10.1 V0 decision: no optimistic UI

Every action waits for the server response before updating UI. Reasons:

- **No rollback complexity.** Optimistic UI requires reverting state on failure. V0 avoids the bug surface entirely.
- **Conflict surfacing.** Server may reject due to ConcurrencyConflict, PolicyDenied, WorkflowStateMismatch — none of these are predictable client-side.
- **Pedagogical clarity.** Users see the round-trip; the latency makes the system behavior obvious.

### 10.2 Pending states

While an action is in flight:

- Action button shows a spinner / "Pending..." label.
- Modal is disabled (form inputs read-only).
- Other actions on the same subject are NOT disabled (V0 simplification).

After response: re-fetch the ViewSchema. The fresh server state is the source of truth.

### 10.3 Future (deferred)

Per-action `optimistic: true` flag in the ActionDescriptor + a predicted outputs schema. The renderer applies predicted outputs to local state, then reconciles with server response. Out of V0; possibly out of V1 entirely depending on user demand.

---

## 11. Error handling

### 11.1 Three error classes

| Class                                 | Where surfaced                                    | Recovery                                            |
|---------------------------------------|---------------------------------------------------|----------------------------------------------------|
| Schema fetch error (network, 5xx)     | `<ErrorBanner>` at top of view                    | "Retry" button → `refetch()`                       |
| Schema validation error (bad shape)   | `<ErrorBanner>` at top                            | No retry; needs server-side fix                    |
| Action invocation error               | Inside `<ActionModal>` or as `<Toast>`            | User dismisses or retries manually                 |
| Form validation error (client-side)   | `<ValidationError>` next to each field            | User corrects input                                |

### 11.2 Error display

```tsx
<ErrorBanner error={schemaError} onRetry={refetch}>
    Could not load projection {projection}.
    {error.message}
</ErrorBanner>
```

Plain HTML. Red border. Optional "Retry" button.

For action errors:

```tsx
<div className="action-error">
    <strong>{action.label} failed:</strong>
    <span>{error.message}</span>
    {error.kind === 'ConcurrencyConflict' && (
        <span>The data changed since you loaded it. Reload and try again.</span>
    )}
</div>
```

### 11.3 Error kind translation table

| `error.kind`                  | User message                                                                  |
|-------------------------------|------------------------------------------------------------------------------|
| `PolicyDenied`                | "You do not have permission to perform this action."                          |
| `WorkflowStateMismatch`       | "This {Entity} is no longer in the expected state."                           |
| `WorkflowGuardDenied`         | "This transition is not allowed at this time."                                |
| `ConcurrencyConflict`         | "The data was changed since you loaded it. Please reload and try again."     |
| `ConstraintViolation`         | "Validation failed: {details.constraint_name}"                                |
| `TenantBoundaryViolation`     | "This record does not belong to your account."                                |
| `EffectFailed`                | "The operation failed: {error.message}"                                       |
| `AuditEmissionFailed`         | "Could not record the operation. Please contact support."                     |
| `ProjectionForbidden`         | "You do not have permission to view this page."                               |
| `ProjectionNotFound`          | "This page no longer exists."                                                 |
| `IncompatibleRenderer`        | "This page is not supported by your browser. Please update."                  |
| (unknown)                     | The error's `message` field verbatim                                          |

V0 ships English messages. Localization via the `locale` param on ViewSchema requests is server-side; error message localization is M2.

### 11.4 React error boundaries

V0 uses one top-level error boundary at the `ViewSchemaConsumer` level. Unhandled component errors render a generic "Something went wrong" fallback. No per-component boundaries; React's default error handling is sufficient for V0.

---

## 12. API contract assumptions

### 12.1 What V0 assumes about L4

L4 (API Surface) is NOT built yet in M1. The renderer assumes the following endpoints exist:

```
GET    /api/projections/{projectionFqn}    → ViewSchema JSON
POST   /api/actions/{actionFqn}             → action invocation
```

For M1 testing without L4: the playground uses Mock Service Worker (MSW) to stub these endpoints with hand-authored fixtures. The renderer code is the same.

For M2: a minimal L4 ships in `ausus/presentation-default` (for projection GET) and `ausus/runtime-default` (for action POST). M3 may extract these into a dedicated `ausus/api-surface` package.

### 12.2 Request headers

```
X-Tenant-ID: <tenant_short_name>          // from AususProvider
Authorization: Bearer <token>             // from AususProvider
Content-Type: application/json            // for POST
Accept: application/json
```

The renderer does not implement auth. The consumer (starter app) passes auth headers via `<AususProvider authHeaders={...}>`.

### 12.3 Response codes (assumed; locked in RFC-005 §16.1 + L4 RFC)

| Status | Meaning                                   |
|--------|-------------------------------------------|
| 200    | Success (GET or POST with `ok: true`)     |
| 400    | Bad request (malformed ViewSchema request)|
| 401    | Unauthorized (no/invalid auth)            |
| 403    | Forbidden (PolicyDenied, ProjectionForbidden) |
| 404    | Not found (ProjectionNotFound)            |
| 406    | Not acceptable (IncompatibleRenderer, NoCommonSchemaVersion) |
| 409    | Conflict (ConcurrencyConflict, WorkflowStateMismatch) |
| 422    | Unprocessable (EffectFailed, ConstraintViolation) |
| 500    | Server error                              |
| 503    | Audit emission failure, primary sink down |

V0 renderer reads `response.ok` (boolean) primarily; the specific status is used for error categorization but not for behavior.

### 12.4 Idempotency

The renderer does NOT send idempotency keys in V0. If the user clicks "Issue" twice rapidly, two invocations may fire. The Invoker's optimistic locking handles: first succeeds; second sees ConcurrencyConflict; renderer shows error.

M2 may add idempotency keys (header `X-Idempotency-Key`) on POST. Out of V0.

---

## 13. Minimal routing strategy

### 13.1 Renderer is router-agnostic

`@ausus/renderer-react` ships ZERO routing primitives. Components are pure; routing is the consumer's concern.

### 13.2 Starter app routing (HelloInvoice)

In `packages/starter/src/frontend/src/App.tsx`:

```tsx
import { BrowserRouter, Routes, Route, useParams } from 'react-router-dom';
import { AususProvider, ViewSchemaConsumer } from '@ausus/renderer-react';

function InvoiceListPage() {
    return <ViewSchemaConsumer projection="billing.invoice.summary" locale="en-US" />;
}

function InvoiceDetailPage() {
    const { id } = useParams();
    const subject = { tenant_id: 'acme', entity_fqn: 'billing.invoice', identity_handle: id };
    return <ViewSchemaConsumer projection="billing.invoice.detail" locale="en-US" subject={subject} />;
}

function App() {
    return (
        <AususProvider apiBaseUrl="/api" tenant="acme" authHeaders={{}}>
            <BrowserRouter>
                <Routes>
                    <Route path="/" element={<InvoiceListPage />} />
                    <Route path="/invoices/:id" element={<InvoiceDetailPage />} />
                    <Route path="/invoices/:id/edit" element={<InvoiceEditPage />} />
                </Routes>
            </BrowserRouter>
        </AususProvider>
    );
}
```

The renderer doesn't care if the consumer uses React Router, Next.js routing, TanStack Router, or hardcoded conditional rendering. It exposes components; consumers compose.

### 13.3 Programmatic navigation after actions

After an action succeeds, the consumer may want to navigate (e.g., after `create`, go to the new invoice's detail). V0 surfaces this via the optional `onSuccess` callback on `<ActionButton>`:

```tsx
<ActionButton
    action={createAction}
    onSuccess={(outputs) => navigate(`/invoices/${outputs.id}`)}
/>
```

The consumer wires `navigate` from React Router (or whatever). The renderer just calls the callback.

---

## 14. Widget set (V0)

### 14.1 Display widgets (read-only)

| Widget              | Renders                                                            |
|---------------------|--------------------------------------------------------------------|
| `TextDisplay`       | `<span>{value}</span>`                                              |
| `NumberDisplay`     | `<span>{value.toLocaleString(locale)}</span>`                       |
| `MoneyDisplay`      | `<span>{currency} {amount.toFixed(2)}</span>`                       |
| `DateDisplay`       | `<span>{formatDate(value, locale)}</span>`                          |
| `DatetimeDisplay`   | `<span>{formatDatetime(value, locale)}</span>`                      |
| `TimeDisplay`       | `<span>{formatTime(value, locale)}</span>`                          |
| `EnumDisplay`       | `<span>{labelFor(value, field.typeOptions.options)}</span>`         |
| `Badge` (enum)      | `<span class="badge badge-{value}">{label}</span>`                  |
| `WorkflowBadge`     | Badge with color-by-state mapping (DRAFT=gray, ISSUED=blue, PAID=green, CANCELLED=red) |
| `CheckboxDisplay`   | `<input type="checkbox" checked={value} disabled />`                |
| `JsonViewer`        | `<pre>{JSON.stringify(value, null, 2)}</pre>` (collapsed by default) |
| `ReferenceCard`     | For `embedded` mode: shows `embeddedFields` inline; for `reference-only`: `<a href="...">{id}</a>` |

### 14.2 Input widgets (editable)

| Widget               | Implementation                                                          |
|----------------------|-------------------------------------------------------------------------|
| `TextInput`          | `<input type="text" maxLength={field.typeOptions.maxLength} />`         |
| `TextareaInput`      | `<textarea maxLength={...} />`                                          |
| `NumberInput`        | `<input type="number" step={...} />`                                    |
| `MoneyInput`         | NumberInput + currency dropdown (V0: currency disabled if single)       |
| `DatePicker`         | `<input type="date" />` (native HTML5)                                  |
| `DatetimePicker`     | `<input type="datetime-local" />`                                       |
| `TimePicker`         | `<input type="time" />`                                                 |
| `Select`             | `<select>` with options from `field.typeOptions.options`                |
| `MultiSelect`        | `<select multiple>` (V0 minimum; a richer multi-select widget is M2)    |
| `Checkbox`           | `<input type="checkbox" />`                                             |

### 14.3 What V0 widgets DON'T do

- No rich-text editor (textarea is plain text only).
- No autocomplete dropdowns (Select is plain HTML).
- No date range pickers (two date inputs).
- No image upload (deferred entirely).
- No file inputs (deferred entirely).
- No color pickers.
- No drag handles, no reorder.

The native HTML5 widgets are deliberately chosen for V0: zero JS overhead, accessible by default, work without polyfills, mobile-friendly by browser.

### 14.4 Widget downgrade per profile

Per RFC-004 §10.4: if the profile lacks a widget the field requests (e.g., field hints `widget: 'date-picker'` but profile lacks it), the schema's `compatibility.downgrades` lists the substitution. The renderer logs a console warning (dev mode) and uses the fallback widget. V0's `react.web.v1` profile claims all 13 widgets; no downgrades expected.

---

## 15. Theme strategy

### 15.1 One default Tailwind theme

V0 ships exactly one theme. CSS imported as `@ausus/renderer-react/themes/default.css`. Components use Tailwind utility classes directly:

```tsx
<button className="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
    {label}
</button>
```

### 15.2 No design tokens, no CSS variables

V0 hardcodes color choices (blue for primary, red for danger, gray for neutral). No CSS variables, no token API, no `<ThemeProvider>` for color customization.

Consumers wanting custom colors:

1. Fork the CSS file.
2. OR override Tailwind utility classes in their own CSS with higher specificity.
3. OR (post-V0) wait for M2's CSS variable extraction.

### 15.3 Minimal global CSS (`themes/default.css`)

```css
/* ~50 lines: modal overlay backdrop, spinner keyframes, focus rings,
   table zebra striping, badge color palette. Everything else is Tailwind. */

.ausus-modal-backdrop {
    @apply fixed inset-0 bg-black/50 flex items-center justify-center z-50;
}

.ausus-modal {
    @apply bg-white rounded-lg shadow-xl max-w-lg w-full max-h-[90vh] overflow-auto;
}

.ausus-spinner {
    @apply inline-block w-4 h-4 border-2 border-gray-300 border-t-blue-600 rounded-full animate-spin;
}

.ausus-badge-DRAFT     { @apply bg-gray-100 text-gray-700; }
.ausus-badge-ISSUED    { @apply bg-blue-100 text-blue-700; }
.ausus-badge-PAID      { @apply bg-green-100 text-green-700; }
.ausus-badge-CANCELLED { @apply bg-red-100 text-red-700; }
/* etc */
```

50 lines. That's the entire CSS surface.

---

## 16. V0 UI screenshots (conceptual)

ASCII mockups of the three views the renderer produces for `HelloInvoice`:

### 16.1 List view (`billing.invoice.summary`)

```
┌──────────────────────────────────────────────────────────────────────────┐
│ AUSUS · Acme Tenant · user42 (invoice.issuer, invoice.viewer, invoice.creator) │
├──────────────────────────────────────────────────────────────────────────┤
│  Invoices                                              [ + New invoice ] │
├──────────────────────────────────────────────────────────────────────────┤
│  Status:  [ All       ▼ ]                                                │
├──────────────────────────────────────────────────────────────────────────┤
│  Number      │ Customer name      │ Status     │ Amount       │ Actions │
├──────────────┼────────────────────┼────────────┼──────────────┼─────────┤
│  INV-2026-001│ ACME Corporation   │ ● Issued   │ $1,500.00 USD│ Cancel  │
│  INV-2026-002│ Globex Inc         │ ○ Draft    │   $850.00 USD│ Issue ⋮ │
│  INV-2026-003│ Initech            │ ● Paid     │ $2,100.00 USD│   ⋮     │
│  INV-2026-004│ Pied Piper         │ ✕ Cancelled│   $500.00 USD│         │
├──────────────────────────────────────────────────────────────────────────┤
│                                                       ◀ Prev    Next ▶  │
└──────────────────────────────────────────────────────────────────────────┘
```

- `Status` column uses `WorkflowBadge` with color per state.
- Per-row `Actions` column shows actions where `subjectRequired: true` AND user has permission AND state transition is valid (V0: shows all available; M2 may hide invalid).
- Top-right `[ + New invoice ]` is the `create` action where `subjectRequired: false`.

### 16.2 Detail view (`billing.invoice.detail`)

```
┌──────────────────────────────────────────────────────────────────────────┐
│ AUSUS · Acme Tenant · user42                          [ ← Back to list ] │
├──────────────────────────────────────────────────────────────────────────┤
│  Invoice INV-2026-001                                                    │
├──────────────────────────────────────────────────────────────────────────┤
│  ID:              inv_01JABCXYZ123                                       │
│  Number:          INV-2026-001                                           │
│  Customer name:   ACME Corporation                                       │
│  Status:          ● Issued                                               │
│  Amount:          $1,500.00 USD                                          │
│  Issued at:       2026-05-19 14:32 UTC                                   │
│  Created at:      2026-05-18 09:12 UTC                                   │
│  Updated at:      2026-05-19 14:32 UTC                                   │
├──────────────────────────────────────────────────────────────────────────┤
│                                                              [ Cancel ]  │
└──────────────────────────────────────────────────────────────────────────┘
```

- All fields rendered read-only via `FieldDisplay`.
- Bottom-right shows applicable item-level actions.

### 16.3 Action modal (`cancel` with reason)

```
                ┌──────────────────────────────────────────────────────┐
                │  Cancel invoice INV-2026-001                    ✕    │
                ├──────────────────────────────────────────────────────┤
                │                                                      │
                │  Are you sure you want to cancel this invoice?       │
                │                                                      │
                │  Reason: ⚠ Required                                  │
                │  ┌──────────────────────────────────────────────────┐│
                │  │ Customer requested cancellation due to revised   ││
                │  │ order quantity                                   ││
                │  └──────────────────────────────────────────────────┘│
                │  500 characters maximum (108/500)                    │
                │                                                      │
                ├──────────────────────────────────────────────────────┤
                │                          [ Discard ]  [ ⊗ Cancel ]   │
                └──────────────────────────────────────────────────────┘
```

- Confirmation prompt at top (from `confirmation.prompt`).
- Input form from `action.inputs`.
- Submit button labeled with action name and danger style.

### 16.4 Action error in modal

```
                ┌──────────────────────────────────────────────────────┐
                │  Cancel invoice INV-2026-001                    ✕    │
                ├──────────────────────────────────────────────────────┤
                │                                                      │
                │  ⚠ Action failed                                     │
                │  ┌──────────────────────────────────────────────────┐│
                │  │ ConcurrencyConflict: The data was changed since  ││
                │  │ you loaded it. Please reload and try again.       ││
                │  └──────────────────────────────────────────────────┘│
                │                                                      │
                │  [ form fields above, still editable ]               │
                │                                                      │
                ├──────────────────────────────────────────────────────┤
                │                          [ Discard ]  [ Retry ]      │
                └──────────────────────────────────────────────────────┘
```

- Modal stays open on error.
- Error displayed inline; user can edit and retry.

---

## 17. Implementation order

Aligned with M2 sprint (after kernel + L3 + runtime ship in M1).

| Day | Focus                                                                                              |
|-----|----------------------------------------------------------------------------------------------------|
| 1   | TypeScript types matching RFC-004 (ViewSchema, FieldDescriptor, ActionDescriptor, etc.)            |
| 1   | `AususProvider`, `useAusus`, `useViewSchema` hooks with fetch + state                              |
| 2   | `SchemaRenderer`, `ListView`, `DataTable`, `Pagination`                                            |
| 3   | `FieldDisplay` + read-only widgets (TextDisplay, NumberDisplay, MoneyDisplay, DateDisplay, EnumDisplay, Badge, WorkflowBadge) |
| 4   | `DetailView`, `FieldList`                                                                          |
| 4   | `EditView`, `Form`, `FormField`, `FieldInput` + editable widgets                                   |
| 5   | `ActionBar`, `ActionButton`, `ConfirmationDialog`                                                  |
| 5   | `ActionModal` + form integration + `useAction` hook                                                |
| 6   | `FilterBar` + filter state in `useViewSchema`                                                      |
| 6   | `ErrorBanner`, `ValidationError`, error kind translation                                           |
| 7   | Integration with `apps/playground` frontend; MSW stubs for the API endpoints                       |
| 7   | Default theme CSS; bundle size verification                                                        |
| 8   | Unit tests (Vitest + RTL); snapshot tests for stable widgets                                       |
| 8   | Integration test: render HelloInvoice list, click Issue, verify UI updates after re-fetch          |

8 working days. ~800 LOC TS/TSX + ~400 LOC tests.

### 17.1 Parallelization

- Day 2 (ListView) and day 4 (EditView) can be worked in parallel by two maintainers.
- Widgets (days 3 and 4) are independent files; can be split.
- Hooks (day 1) and components (days 2+) can be partially parallel once types are set.

With one maintainer: 8 days serial. With two: ~5 days.

---

## 18. Minimal browser flow

Concrete user journey through the V0 renderer for the HelloInvoice slice. This is what the M2 acceptance test exercises (after L4 ships):

```
[1] User opens http://acme.app.example/
[2] React app mounts: <AususProvider apiBaseUrl="/api" tenant="acme" />
[3] React Router resolves "/" → <InvoiceListPage>
[4] <ViewSchemaConsumer projection="billing.invoice.summary" /> mounts
[5] useViewSchema fires:
    GET /api/projections/billing.invoice.summary?locale=en-US&renderer=react.web.v1&acceptSchemaVersions=1.0.0
    Headers: X-Tenant-ID: acme, Authorization: Bearer ...
[6] Backend (L4 → Presentation layer → Repository) returns ViewSchema with 4 invoices
[7] Renderer parses; ListView renders table with 4 rows
    - Status column shows colored badges (Draft, Issued, Paid, Cancelled)
    - Per-row actions: Issue button on Draft rows; Cancel button on Draft/Issued rows
[8] User clicks "Issue" on the INV-2026-002 (Draft) row
[9] ActionButton checks: action has no confirmation, no inputs → direct invoke
[10] useAction("billing.invoice.issue").invoke({ subject: <ref>, inputs: {} })
[11] POST /api/actions/billing.invoice.issue
     Body: { subject: { tenant_id: "acme", entity_fqn: "billing.invoice", identity_handle: "inv_02" }, inputs: {} }
[12] Backend Invoker runs the chain:
     - Step 1: Tenant check ✓
     - Step 2: Policy (RoleRequired('invoice.issuer')) → Permit
     - Step 3: Workflow guard (DRAFT → ISSUED via issue) → pass
     - Step 4: TransitionEffect updates row (status=ISSUED, issued_at=now)
     - Step 5: Audit emitted in same transaction
     - Commit
     Returns: { ok: true, outputs: { status: "ISSUED", issued_at: "..." } }
[13] Renderer:
     - Closes any pending state
     - Triggers refetch of ViewSchema
     - Shows toast: "Issued INV-2026-002"
[14] New ViewSchema arrives; table re-renders
     - INV-2026-002 row now shows "Issued" badge
[15] User clicks INV-2026-001 to view detail
[16] React Router resolves "/invoices/inv_01" → <InvoiceDetailPage>
[17] <ViewSchemaConsumer projection="billing.invoice.detail" subject={...}> fetches
[18] Backend returns ViewSchema with item populated
[19] DetailView renders 8 fields (id, number, customer_name, status, amount, issued_at, created_at, updated_at)
[20] User clicks "Cancel" button at bottom
[21] action.confirmation.required === true → ConfirmationDialog opens
[22] User clicks "Confirm Cancel"
[23] action.inputs has "reason" field → ActionModal opens
[24] User types "Customer revised order" → submits
[25] POST /api/actions/billing.invoice.cancel ... (Invoker chain runs)
[26] Returns ok; modal closes; refetch
[27] Detail view re-renders showing status=Cancelled
```

27 steps. Every primitive exercised. This is the V0 minimal browser flow that proves the four claims of §0.

---

## 19. Summary

- **35 components + 3 hooks + 6 type files. ~800 LOC TS/TSX + ~400 LOC tests.** Bundle ~30 KB gzipped.
- **Three views**: ListView (table), DetailView (read-only fields), EditView (form).
- **13 widgets**: text, textarea, number, money, date, datetime, time, select, multi-select, checkbox, badge, json-viewer, reference-card. All HTML5-native; no third-party UI library.
- **Six public exports**: `AususProvider`, `ViewSchemaConsumer`, `useAusus`, `useViewSchema`, `useAction`, default theme CSS.
- **No optimistic UI** in V0; every action waits for server.
- **No router**; consumer apps supply their own.
- **One Tailwind theme**; no design tokens, no `<ThemeProvider>`.
- **State: pure React `useState`/`useReducer`**. No Redux, no MobX, no TanStack Query, no SWR.
- **Three error classes** with kind-based user message translation.
- **API contract assumed**: GET `/api/projections/{fqn}` + POST `/api/actions/{fqn}`; L4 ships in M2; renderer is testable in isolation via MSW stubs.
- **Workflow visibility**: status field renders as colored badge via `WorkflowBadge` component.
- **27-step browser flow** exercises every primitive end-to-end.

Implementation: 8 working days within M2. Parallelizable to ~5 days with two maintainers.

When this renderer ships and L4 follows: the M2 deliverable (RFC-012 §15 "30-minute TTFS") becomes measurable for the first time.
