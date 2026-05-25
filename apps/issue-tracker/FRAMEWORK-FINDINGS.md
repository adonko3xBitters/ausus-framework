# AUSUS v0.1.x — Framework findings report

Built while assembling the `apps/issue-tracker` sample. **Reproduced on
v0.1.x as committed**; the smoke test `apps/issue-tracker/tests/smoke.php`
exercises each numbered finding and currently passes 27/27.

## Summary

Things that worked cleanly:

- The four-call lifecycle `create → register → boot → invoke` was enough to
  bootstrap a 3-entity domain without touching the runtime.
- `Application::http()` collapsed the HTTP entry point to ~10 lines.
- `ActionDescriptor.inputs` drove the create-form UI **with no domain code in
  the renderer** — adding a new field to the plugin would show up as a new
  form control on the next request.
- Workflow enforcement: every illegal transition rejected with
  `WorkflowStateMismatch` before any data was written. Same path on CLI and
  HTTP — no drift.
- Policy denial: `member` can create projects but cannot archive; `viewer`
  can do nothing. Verified end-to-end.

Things that bit. Each subsection below states **what hurt**, **how the
sample worked around it**, and **what v0.2 should do**.

---

## 1. No foreign-key contract between entities

**What hurts.** `Issue.project_id` and `Comment.issue_id` are the most
fundamental relationships in this domain. The DSL has no way to say
"`project_id` points at a `tracker.project`." They are declared as
`Field::string()->max(26)`. The runtime accepts any value — the smoke
test's "ghost" issue (`project_id = '01J-DOES-NOT-EXIST-________'`) is
stored and rendered without complaint.

There is also no relation surface in projections: the issue list shows raw
26-character ULIDs in the `project_id` column, not the parent's `key` or
`name`. The user has to memorise project ULIDs to read the board.

**Workaround.** Encode parent ids as plain strings; document the lack of
referential integrity; rely on the application code (the seeder, the API
client, your future migrations) not to invent dangling references.
Validation of references can be done with a custom `Effect` class
(`effectClass` accepts any FQN), but the cost is significant — each create
action needs its own effect class.

**v0.2 priority — HIGH.** Two concrete additions:

- `Field::reference(string $targetEntityFqn)` — a typed field whose runtime
  validation rejects unknown ids and whose `FieldDescriptor` exposes the
  target FQN to the renderer.
- Projection `expand(['project_id' => 'project.summary'])` (or equivalent)
  so the runtime can fold parent display fields into the rendered row.

## 2. Projections have no filtering or pagination

**What hurts.** `GET /api/projections/tracker.issue.board` always returns
**every** issue for the tenant. Showing "issues in project ENG" requires the
UI to fetch all issues, then filter client-side. With 6 seed records this is
a non-issue; with 60,000 it would be a hard outage. The renderer has no API
to push a filter and no opinion about pagination.

**Workaround.** Build per-project projections by hand (`tracker.issue.board.eng`,
`...ops`, `...doc`) — works for a fixed small set of parents but doesn't
scale beyond demoware. The sample does not do this and instead surfaces the
limitation in the UI's footnote so users see why the list mixes projects.

**v0.2 priority — HIGH.** A minimal start:

- A `filters` array on `ProjectionNode` whose entries describe the available
  filter fields + operators (`tracker.issue.board?project_id=...`).
- The renderer reading `viewSchema.filters` and adding controls for them.
- Cursor-based pagination on list projections — `pagination.nextCursor`
  already exists in the wire format but is always `null` in v0.1.x.

## 3. ~~`serializeField` mishandles explicit null~~ — **FIXED**

> **Status:** fixed in `packages/persistence-sql/src/persistence.php`.
> Regression test: `apps/playground/null-roundtrip-test.php` (30 assertions
> covering write → SQL → read → JSON projection on every nullable scalar
> type, plus the existing `Field::datetime()->nullable()` path). Wired into
> CI as step 4h.

**What hurt.** Passing `'assignee' => null` to a `Field::string()->nullable()`
column stored the literal four-character string `"null"`, not SQL NULL. The
cause was in `packages/persistence-sql/src/persistence.php::serializeField`:

```php
default => is_scalar($value) ? (string) $value : json_encode($value),
```

`is_scalar(null)` is false in PHP, so the path fell through to
`json_encode(null)` which returns the string `"null"`. Similar corruption
hit every nullable type:

| Field type | Pre-fix value stored for explicit null |
|---|---|
| `string` / `enum` / `identity` / `system_string` | `"null"` (4-char string) |
| `integer` | `0` |
| `datetime` | `""` (empty string) |
| `money`   | `""` on disk; `["amount" => "", "currency" => …]` on read-back |

**The fix.** A single null-guard in `serializeField`, plus a symmetric guard
on the read side in `unwrapFields` so a SQL NULL `money` column reads back
as PHP null (rather than `["amount" => "", "currency" => …]`):

```php
private function serializeField(FieldNode $f, mixed $value): mixed {
    if ($value === null) {
        return null;   // ← short-circuit; PDO binds php-null as SQL NULL
    }
    return match ($f->type) { /* unchanged */ };
}

private function unwrapFields(array $row): array {
    foreach ($this->entity->fields as $f) {
        if (!array_key_exists($f->name, $row)) continue;
        $v = $row[$f->name];
        if ($v === null) { $out[$f->name] = null; continue; }   // ← symmetric guard
        $out[$f->name] = match ($f->type) { /* unchanged */ };
    }
    /* … */
}
```

Strictly additive: no SQL schema change, no public-API change, every
existing non-null code path takes the exact same per-type branch as before.

The original sample's seeder still uses the *omit-the-field* idiom (which
also produces SQL NULL via a different code path) — both idioms now produce
identical, correct behaviour.

## 4. ~~No metadata-driven update form~~ — **FIXED** (ADR-0002, v0.2)

> **Status:** fixed by `Action::update(...)` per
> [ADR-0002 — Metadata-driven update actions](../../docs/adr/0002-update-actions.md).
> The issue-tracker plugin now declares `tracker.issue.rename`,
> `tracker.issue.reassign`, `tracker.issue.edit`, and
> `tracker.project.edit`; the smoke test (CI step 4g) exercises every
> branch end-to-end with positive PATCH semantics. A dedicated test
> (`apps/playground/update-action-test.php`, CI step 4i, 36 assertions)
> guards the kernel + runtime + ViewSchema + HTTP layers.

**What hurt.** A real issue tracker needed to **reassign**, **re-prioritise**
and **edit the title** of records after creation. v0.1.x had only two
action kinds — `create` and `transition`. `ActionBuilder::transition()`
hardcoded `inputs: []`, `ProjectionRenderer::describeActionInputs()`
mirrored that, and `ActionModal` fell through to a confirmation prompt.
Updates were possible only via hand-built HTTP clients that bypassed the
metadata.

**The fix.** A third action kind `Action::update(...$fieldNames)`:

```diff
 'create'   => Action::create('project_id', 'title', 'reporter', 'assignee', 'priority'),
+'rename'   => Action::update('title'),
+'reassign' => Action::update('assignee'),
+'edit'     => Action::update('title', 'assignee', 'priority'),
```

End-to-end behaviour:
1. The DSL refuses `update('status')` at compile (the workflow state field
   is reserved for transitions — ADR-0002 §7).
2. The DSL refuses `update('id')` and other system fields.
3. `UpdateEffect` runs in the same Invoker chain as create / transition —
   preflight → policy → workflow guard (no-op) → effect → audit, atomic.
4. Closed-list policing: a payload key outside the action's declared
   patchable fields is rejected at runtime (not silently dropped).
5. Null on a non-nullable field is rejected; null on a nullable field
   writes a real SQL NULL (rides on the v0.1.x null-serialisation fix).
6. `ProjectionRenderer` emits `ActionDescriptor.inputs` for update
   descriptors (with their `FieldDescriptor`-grade metadata: `required`,
   `nullable`, `default`, `typeOptions`, `label`).
7. On a detail projection, `ProjectionRenderer` additionally emits
   `ActionDescriptor.initialValues` so the renderer can prefill.
8. `ActionModal` switches to a diff payload when `initialValues` is
   present — only changed fields ship on the wire.

The smoke test's "transition actions still emit inputs=[]" assertion is
preserved (state moves remain the exclusive job of `Action::transition`).

**v0.2 priority — HIGH.** A third action kind — `Action::update(...fields)`
— with:

- input field metadata emitted to `ActionDescriptor.inputs` exactly like
  `Action::create`,
- an `UpdateEffect` that writes the named fields with optimistic-concurrency
  via `_version`,
- the renderer drawing the same form as create, but pre-populated from
  `data.item` and dispatched as a `PUT` (or a `POST` carrying the
  `_version`).

This single addition unlocks ~80% of the "real-app feel" the sample lacks.

## 5. Renderer cannot link from a foreign-id column

**What hurts.** Even setting aside §1, the renderer doesn't know that
`project_id` *means* a project. The board view shows
`01J7HG3WC0D3K…` — perfectly accurate, completely unhelpful for the user.
Clicking does nothing; the row is a dead end.

**Workaround.** The sample's top-nav has separate "Projects" / "Issues" /
"Comments" tabs and a footnote reminding the user to memorise the ULID. No
contextual navigation between related entities.

**v0.2 priority — MEDIUM, tied to §1.** Once `Field::reference(...)`
exists, the `FieldDescriptor.type` could be `"reference"` with a
`typeOptions.targetProjection` pointing at e.g. `tracker.project.summary` —
the renderer would then draw the cell as a link that opens the detail
projection for that id.

## 6. ~~`X-Actor-Roles` default in the Router is HelloInvoice-specific~~ — **FIXED**

> **Status:** fixed in `packages/api-http/src/api.php::Router::resolveActor()`.
> Regression coverage in `apps/playground/application-http-test.php` test 11
> (CI step 4f, 6 new assertions). `live-trace.tsx` now sends the role header
> explicitly; the issue-tracker UI's custom fetcher was already doing it.

**What hurt.** When a client omitted `X-Actor-Roles`, `Router::resolveActor()`
substituted the demo role set `['invoice.creator', 'invoice.issuer',
'invoice.canceler', 'invoice.viewer']`. For the issue tracker, none of
those roles matched any policy, so a roleless request to
`/api/actions/tracker.issue.create` returned 403 — but with a
`PolicyDenied` naming a role the consumer never declared. Worse, for any
domain that *did* declare an `invoice.*` role the fallback silently granted
privileges to anonymous callers.

**The fix.** Drop the fallback. Missing or empty `X-Actor-Roles` now yields
a **roleless** actor (`roles = []`), and every protected action returns
`403 PolicyDenied` consistently. The error message names the role the
action actually requires.

```diff
 private function resolveActor(ServerRequestInterface $request, string $tenantId): StubActor
 {
     $id       = $request->getHeaderLine('X-Actor-Id') ?: 'anon';
     $rolesRaw = $request->getHeaderLine('X-Actor-Roles');
     $roles    = $rolesRaw === ''
-        ? ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer']
+        ? []
         : array_values(array_filter(array_map('trim', explode(',', $rolesRaw))));
     return new StubActor(new ActorRef('user', $id, $tenantId), $roles);
 }
```

No public-API change, no schema change, no new auth system — the Router
simply fails closed instead of failing open. The issue-tracker UI was
already correctly passing `X-Actor-Roles: tracker.member,tracker.admin,tracker.viewer`,
so no consumer change was required there.

## 7. Comment posting is decoupled from the issue detail view

**What hurts.** `Comment.post` lives on the `comment` entity. Its form
needs an `issue_id` input, which a user viewing an issue would expect to be
prefilled and hidden. The renderer has no concept of "parent entity
context" — when ListView/DetailView render an issue, they don't carry a
hint that `comment.post` is contextually a child action. The sample's UI
has a separate "Comments" tab that lists every comment in the tenant, with
no per-issue grouping.

**Workaround.** Treat comments as a standalone projection; rely on the
human to copy/paste the issue id into the comment-post form. Painful, but
documented.

**v0.2 priority — MEDIUM.** Allow projections to declare *related*
projections / actions that the renderer can surface on the parent's detail
view — e.g. `comment.list?issue_id={subject.id}` rendered as a section of
`issue.detail`, with a "Post comment" button prefilling `issue_id` from the
parent subject.

## 8. No "where am I" navigation in the renderer

**What hurts.** Once you click into an issue's detail view, there is no
breadcrumb back to the issue board for that project. The renderer doesn't
ship a router; the sample's tab UI is the smallest possible state machine
to make navigation tolerable. Anything richer than three tabs would need a
real router added by the consumer.

**Workaround.** State-based "tab" routing in `App.tsx`, identical in spirit
to the playground's `View` union.

**v0.2 priority — LOW.** Not really a framework job — `react-router` /
`@tanstack/router` exist. A documentation pattern showing how to wire one
in around `ViewSchemaConsumer` would close the gap.

## 9. ~~Action-descriptor input labels are derived from field names~~ — **FIXED**

> **Status:** fixed by `FieldBuilder::label(string)`. The plugin now sets
> friendlier labels on `project_id`/`issue_id`/`resolved_at`; the renderer
> shows them automatically because `FieldDescriptor.label` was already part
> of the wire format.

**What hurt.** The renderer labelled `project_id` as `"Project id"` — the
literal name with underscores expanded. No hook existed to set a friendlier
label ("Project") at the field level, so snake-cased FK columns looked
utilitarian and screen readers read out the field name rather than the
intended noun.

**The fix.** Add `FieldBuilder::label(string)` (strictly additive); the
plugin can opt into explicit labels per field; the runtime emits them in
`ViewSchema.fields[].label` and `ActionDescriptor.inputs[].label`; when a
field carries no explicit label, the existing humanised fallback is used.

```diff
- 'project_id' => Field::string()->max(26),
+ 'project_id' => Field::string()->max(26)->label('Project'),
- 'issue_id'   => Field::string()->max(26),
+ 'issue_id'   => Field::string()->max(26)->label('Issue'),
- 'resolved_at'=> Field::datetime()->nullable(),
+ 'resolved_at'=> Field::datetime()->nullable()->label('Resolved'),
```

No TS or wire-format change (`FieldDescriptor.label: string` was already
the published shape); no schema change; no breakage for plugins that did
not opt in.

## 10. The kernel audit log is invisible from the UI

**What hurts.** Every action invocation writes a beautifully-shaped audit
entry to `kernel_audit_log`. None of this is rendered. There is no
`Projection` over the audit log, no built-in admin view, no per-record
activity feed. For a real tracker, "who changed what when" is *the*
question — and v0.1.x has the data but no surface for it.

**Workaround.** None inside the renderer. Operators can `sqlite3
tracker.sqlite 'SELECT … FROM kernel_audit_log'`.

**v0.2 priority — MEDIUM.** Expose the audit log through a built-in
projection (filterable by `subject_*` so each detail view can render its
record's history). Pairs with §2's filter work — the audit log is a list
that *must* be filterable to be usable.

---

## Recommended v0.2 priorities — ranked

In order of how much each unlocks for a v0.2 consumer building a similar
app:

1. ~~§4 — `Action::update(...)`.~~ **Done in v0.2** per
   [ADR-0002](../../docs/adr/0002-update-actions.md). Plugin dogfoods
   `rename` / `reassign` / `edit` (issue) + `edit` (project); CI step 4i
   guards the kernel + runtime + ViewSchema + live-HTTP layers.
2. ~~§3 — null-serialisation fix.~~ **Done** — landed with the regression
   test (see §3).
3. **§2 — projection filtering + cursor pagination.** Necessary the moment
   the tenant grows past a few dozen rows.
4. **§1 + §5 — `Field::reference(...)` + linked cells.** Closes the
   parent/child gap; together they make multi-entity UIs feel real.
5. ~~§6 — configurable Router default actor.~~ **Done** — the
   HelloInvoice-specific fallback was removed; missing `X-Actor-Roles`
   now means a roleless actor.
6. **§7 + §10 — related projections + audit projection.** Make detail views
   into real "subject pages."
7. ~~§9 — `FieldBuilder::label(...)`.~~ **Done** — strictly additive;
   issue-tracker dogfoods it on `project_id` / `issue_id` / `resolved_at`.

## What v0.1.x got right

A separate list, because the friction is easy to over-index on.

- **One graph, three consumers.** Persistence schema, HTTP routes, and the
  renderer all derived from the compiled `MetadataGraph` — adding a field
  is a one-line change to the plugin, and it shows up everywhere on the
  next request.
- **Honest invocation chain.** The CLI smoke and the HTTP path went through
  the exact same `Invoker`. Behaviour drift between the two is not
  possible.
- **Atomic effects + audit.** Every transition wrote one row of state and
  one row of audit log inside the same transaction. The wontfix smoke test
  proves this works across multi-source transitions too.
- **Typed bootstrap.** `ApplicationConfig::make()->…` caught one
  configuration error (a typo in role names) at the call site rather than
  at boot — the kind of small UX win that compounds in a real codebase.
- **`Application::http()` end-to-end.** One PSR-7 entry point, one cached
  Router, no plumbing per request. The sample's `public/server.php` is 22
  lines including the autoload shim.
- **Explicit workflow declaration.** No surprise inference, clear error
  messages on misconfiguration.
- **Renderer create form.** The single largest renderer DX improvement of
  the recent stabilisation tasks: a new field shows up as a real form
  control with no UI changes.
