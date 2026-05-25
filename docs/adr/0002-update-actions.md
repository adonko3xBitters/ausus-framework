# ADR-0002 — Metadata-driven `update` actions

| Status | Proposed (v0.2 planning) — **design only, no runtime work** |
| ---: | --- |
| **Date** | 2026-05-25 |
| **Owners** | Kernel / runtime-default / api-http / renderer-react |
| **Supersedes** | none |
| **Closes** | `apps/issue-tracker/FRAMEWORK-FINDINGS.md` §4 (now the highest-priority v0.2 gap) |
| **Depends on** | ADR-0001 (explicit workflow declaration — already implemented in v0.1.x) |

> This document defines the architecture of a third action kind, `update`, for
> v0.2. It commits to a shape and an evolution path; it intentionally does
> **not** specify line-level implementation. Open questions are catalogued in
> §12 and must be answered before code lands.

## 1. Problem statement

v0.1.x ships two `ActionBuilder` kinds:

| Kind | Effect | Inputs surface |
|---|---|---|
| `Action::create(...inputs)` | `CreateEffect` — inserts a row; seeds the workflow initial state if declared | declared inputs flow into `ActionDescriptor.inputs` |
| `Action::transition($field, from:, to:)` | `TransitionEffect` — writes the target state + stamps + any inputs merged into the patch | inputs **hardcoded to `[]`** in `ActionBuilder::build()` |

There is no third kind. Once a record exists, the only way the metadata
graph permits changing it is to invoke a transition action on it. Workflow
transitions are **state-machine moves** — they require a declared `from →
to` arrow on the entity's workflow. They are not the right tool for "rename
this ticket" or "reassign this issue" — those are not state changes.

The framework can store a `title` field but offers **no metadata path** to
let an authorised actor change it after creation through the renderer, the
HTTP API, or any other layer that walks the graph.

## 2. Concrete limitations observed (issue-tracker sample app)

Verbatim from `apps/issue-tracker/FRAMEWORK-FINDINGS.md` §4, reproduced as
the running motivation for this ADR:

- **Reassign an issue.** Changing `assignee` on `tracker.issue` from
  `null` → `'alice@acme'` requires either:
  (a) a transition action wrapped around a non-existent state change
  (e.g. inventing a `TODO → TODO` "no-op transition" with `assignee` as a
  side-effect input), or
  (b) a hand-written custom `Effect` class registered via `effectClass`
  FQN — losing the metadata-driven UI surface entirely.
  Both reject the framework's stated promise.
- **Rename / edit free-text fields.** Same shape: `title` and `body`
  cannot be edited from anywhere in the graph after the originating
  `create`.
- **Re-prioritise.** `priority` is an enum with a default — semantically a
  data field, not a state — yet the only graph-level way to change it is
  to declare a transition per source/target pair, which would explode the
  surface (`LOW → NORMAL`, `LOW → HIGH`, …, 4×3 = 12 transitions for one
  field).
- **Renderer limitations.** `ProjectionRenderer::describeActionInputs()`
  emits `inputs: []` for every transition. `ActionModal` therefore falls
  through to its confirmation-prompt path. Even if the consumer wires a
  custom transition that accepts inputs, the renderer cannot draw a form
  for it. The `apps/issue-tracker/ui` has no rename UI at all.

The smoke test `apps/issue-tracker/tests/smoke.php` (test 8) asserts
positively that transitions emit `inputs: []`, freezing the limitation
into a regression check.

## 3. Goals for v0.2

1. A third action kind, `Action::update(...fields)`, that is **metadata-driven
   from end to end** — DSL → `MetadataGraph` → runtime → ViewSchema → renderer
   form — for the same shape and effort cost as a create action today.
2. **Partial-patch semantics** — only the fields named in the request body
   are written; absent fields are left untouched. (See §5 for the decision
   record.)
3. **Renderer reuse** — the React modal must use the same `InputControl`,
   the same payload-shaping helpers (`shapeValue`, `validateInputs`), and
   the same `useAction` hook as the create form. The visible difference
   between create and update is **input prefill** and the title label,
   nothing else.
4. **Validation consistent with create's contract.** Required fields are
   required *for create*; on update they are required *only if present and
   set to null*. Nullable and default rules continue to apply.
5. **No coupling to workflows.** Transitions remain the only way to move
   the workflow state field. Update actions explicitly **cannot** touch a
   workflow state field; the DSL rejects it at compile time.
6. **Strictly additive on the wire.** Pre-v0.2 renderers ignore unknown
   action descriptors safely; the HTTP route layout does not change.

## 4. Proposed DSL API

```php
$dsl->entity('issue')
    ->fields([
        'project_id' => Field::string()->max(26)->label('Project'),
        'title'      => Field::string()->max(200),
        'assignee'   => Field::string()->max(120)->nullable(),
        'priority'   => Field::enum('LOW', 'NORMAL', 'HIGH')->default('NORMAL'),
        'status'     => Field::enum('TODO', 'DONE')->default('TODO'),
    ])
    ->actions([
        'create'    => Action::create('project_id', 'title', 'assignee')
                          ->requireRole('issue.author'),
        'reassign'  => Action::update('assignee')
                          ->requireRole('issue.maintainer'),
        'rename'    => Action::update('title')
                          ->requireRole('issue.maintainer'),
        'edit'      => Action::update('title', 'assignee', 'priority')
                          ->requireRole('issue.maintainer'),
        'done'      => Action::transition('status', from: 'TODO', to: 'DONE')
                          ->requireRole('issue.maintainer'),
    ])
    ->workflow(field: 'status', initial: 'TODO');
```

### 4.1 `Action::update(...$fields): ActionBuilder`

Returns a builder with `kind = 'update'`. Each argument is a **field name**
that the action may patch. Validation at compile time:

- Every named field must exist on the entity (same check as `Action::create`).
- The workflow state field (when `->workflow()` is declared) **MUST NOT** be
  among the named fields; the compiler raises a clear error pointing the
  author at the existing transition mechanism.
- System fields (`id`, `tenant_id`, `_version`, `created_at`, `updated_at`)
  are never patchable; the compiler rejects them with the same precision as
  `Action::create`.

### 4.2 `ActionBuilder::update()` build path

```php
return [new ActionNode(
    fqn: $actionFqn,
    entityFqn: $entityFqn,
    policyFqn: $policyFqn,
    subjectRequired: true,                                        // (a)
    effectClass: BuiltinEffect::Update->value,                    // (b)
    effectConfig: [
        'entityFqn'           => $entityFqn,
        'updatableFieldNames' => $this->updateFieldNames,         // (c)
    ],
    inputs: $resolvedInputFields,                                 // (d)
    kind: 'standard',
), [$policy]];
```

Notes:

- **(a)** Update actions **always** target an existing subject. The
  Router's POST handler rejects an `update` with `subject: null` as a
  `400 BadRequest`. (Parallels `transition`'s `subjectRequired: true`.)
- **(b)** A new sentinel `BuiltinEffect::Update` added to the
  `Ausus\BuiltinEffect` enum. The string value, e.g.
  `'kernel.builtin.update'`, is stable wire metadata.
- **(c)** `effectConfig.updatableFieldNames` is the closed list of names
  the effect is allowed to patch. The runtime hard-rejects any input not
  in this list — the `Action::update('title')` declaration cannot be
  abused over the wire to also overwrite `assignee`.
- **(d)** Unlike transitions, the `inputs` array is **populated** with the
  resolved `FieldNode` objects (looked up from the entity's user fields by
  name). This makes `ProjectionRenderer::describeActionInputs()` produce a
  proper `ActionDescriptor.inputs` for the renderer to draw a form.

### 4.3 `UpdateEffect` (kernel.builtin.update)

```php
final class UpdateEffect implements Effect {
    /** @param array{entityFqn:string, updatableFieldNames:list<string>} $config */
    public function __construct(private readonly array $config) {}

    public function execute(EffectContext $context, ?Reference $subject, array $inputs): array {
        if ($subject === null) throw new \RuntimeException("UpdateEffect requires Subject");
        $repo = $context->repository($subject->entityFqn);
        $entity = $repo->find($subject);
        if ($entity === null) throw new NotFound($subject);

        $patch = [];
        foreach ($inputs as $name => $value) {
            if (!in_array($name, $this->config['updatableFieldNames'], true)) {
                throw new \RuntimeException("UpdateEffect: field '{$name}' is not patchable by this action");
            }
            $patch[$name] = $value;        // null-safe thanks to the v0.1.x serializeField null guard
        }
        if ($patch === []) {
            return ['_version' => $entity->version->value];   // no-op call; no row touched
        }
        $updated = $repo->update($subject, $patch, $entity->version);
        return $patch + ['_version' => $updated->version->value];
    }
}
```

Properties of this shape:

- **Closed-list policing**: an input outside `updatableFieldNames` is a
  runtime error, not a silent drop. Authors who declare
  `Action::update('title')` cannot accidentally let a poorly-typed client
  also rewrite `assignee`.
- **Idempotent no-op**: an empty `inputs` body returns the current version
  without writing — symmetric with the rest of the runtime (transitions
  return their patch + `_version`).
- **Optimistic concurrency**: the read-then-update is wrapped in the
  Invoker's existing transaction. The `version` passed to
  `$repo->update()` is the one just read inside the transaction. A
  conflicting writer between read and update is detected by
  `SqliteRepository::update()`'s `WHERE _version = :oldv` clause and
  surfaces as `ConcurrencyConflict`.

  This is the **server-resolved** path — adequate for v0.2 but coarser
  than client-supplied `If-Match`/`_version`. See open question §12.1.

## 5. Decision: **PATCH partiel** — NOT entity replacement

Two viable wire shapes were considered:

| Shape | Semantics |
|---|---|
| **PATCH** (decided) | The request body lists only the fields to change; absent fields are left untouched. |
| **PUT** (rejected) | The request body must list every patchable field; absent fields are reset to defaults / null. |

We commit to PATCH for the following reasons, which compose:

1. **The metadata graph already encodes "patchable fields" closedly.**
   `Action::update('title')` is *defined* as "patch the title." A PUT
   semantics would force every caller to also (re-)send fields they may
   not have permission to read — a violation of least privilege.
2. **Renderer prefill is per-field.** The form prefills from `data.item`
   when it has the field; for fields it does not have (e.g. masked or
   omitted on the projection), the user's submit would otherwise erase
   them under PUT. PATCH lets the form ship only the keys it actually
   collected.
3. **Optimistic concurrency cost.** Under PUT, two unrelated edits to the
   same row (one client edits `title`, another edits `assignee`) collide
   on every conflicting write. PATCH lets them merge naturally —
   provided both update-actions name disjoint fields, which the closed
   `updatableFieldNames` list makes auditable.
4. **HTTP idiom alignment.** RFC 5789 maps directly. The POST/Action
   shape stays unchanged on the wire — what differs is what
   `UpdateEffect` does on the server.

The wire body is unchanged from create/transition:

```json
{
  "subject": { "tenantId": "acme", "entityFqn": "tracker.issue", "identityHandle": "01J…" },
  "inputs":  { "title": "Renderer crashes on null money — confirmed in 0.1.1" }
}
```

Successful response, identical envelope to existing actions:

```json
{ "ok": true, "outputs": { "title": "…", "_version": "01J…" } }
```

`outputs` includes the patched keys plus the new `_version`. (`assignee`
absent from the response if absent from the patch — symmetric.)

## 6. Validation rules

The kernel exception `FieldRequired` exists today for `Action::create` —
thrown by `SqliteRepository::create()` when a non-nullable field is absent
from the payload and has no default. For `Action::update`, this rule does
**not** translate directly; updates are partial.

The validation contract, in precedence order:

1. **Closed list.** Every key in `inputs` must be in the action's
   `updatableFieldNames`. Unknown key → `BadRequest`.
2. **Nullability.** A `null` value for a field declared
   `Field::*()->nullable()` is accepted and written as SQL NULL (per the
   v0.1.x null-serialisation fix). A `null` value for a non-nullable field
   raises a typed `FieldNotNullable` exception → `BadRequest`.
3. **Type coercion.** Same per-type rules as create (`integer` → int,
   `money` → `{amount, currency}`, etc.). Failures raise the same kernel
   exceptions.
4. **`required` is meaningless for update inputs.**
   `ActionDescriptor.inputs[].required` continues to be emitted truthfully
   from the field metadata, but the runtime does **not** enforce it for
   update actions. The renderer's form treats the flag as advisory
   (e.g. mark the input with `*` so the user is reminded it cannot be
   nulled out, but accept an empty submit that omits the key altogether).
5. **System fields untouchable.** A `create_at`/`tenant_id`/`_version`
   key in `inputs` is rejected the same way create rejects it.

## 7. Relation to workflows

Update and transition are kept **strictly orthogonal**:

- A workflow state field (the field passed to `->workflow(field:, initial:)`)
  is not patchable. The DSL rejects an `update('status')` at compile time
  with a message pointing the author at the existing transition mechanism.
  This is the single largest correctness rule of the proposal.
- A transition action keeps its v0.1.x shape — `inputs: []` on the
  descriptor. Existing apps' transitions do not gain inputs by accident.
- Updates and transitions can share the same projection's `actions:`
  list. The renderer treats each by its descriptor kind / `inputs` shape;
  it does not need to know which kind of effect runs server-side.

A pragmatic consequence: an action that conceptually "do all of {move
state, change priority}" must be modelled as two actions, one transition
and one update. This is intentional — it preserves the property that
"every state move shows up in the audit log with a workflow-grade
guard."

## 8. ViewSchema — `ActionDescriptor.inputs` + `initialValues`

The current `ActionDescriptor` shape (TS):

```ts
interface ActionDescriptor {
  fqn: string;
  name: string;
  label: string;
  subjectRequired: boolean;
  inputs: FieldDescriptor[];                              // already non-optional in v0.1.x
  confirmation?: { required: boolean; prompt?: string };
}
```

For v0.2 update actions, **two additions**:

```ts
interface ActionDescriptor {
  fqn: string;
  name: string;
  label: string;
  subjectRequired: boolean;
  inputs: FieldDescriptor[];
  // NEW — only present on update actions.
  // Populated by ProjectionRenderer when emitting a detail-view projection
  // (`data.item`): the keys map the action's input field names to the
  // current values from `data.item`. The renderer uses these to prefill
  // the form on open.
  initialValues?: Record<string, unknown>;
  confirmation?: { required: boolean; prompt?: string };
}
```

Server-side rule, in pseudocode:

```
for each action in projection.actions:
    if action.kind == 'update' and projection.data.item != null:
        action.initialValues = { f.name: projection.data.item[f.name] for f in action.inputs }
```

On a **list** projection (`data.items[]`), `initialValues` is left absent
— there is no single subject to prefill from. The renderer's ListView
opens the modal without prefill in that case, exactly like create today.

The field's existing `default` (on `FieldDescriptor`) continues to
populate the form when both `initialValues[name]` and the data are
absent. The precedence in `ActionModal`'s form is:

```
initialValues[name]  →  data.item[name]  →  field.default  →  type-specific empty
```

The first two collapse into the same source for v0.2; the rule
distinguishes the two only because future projections might prefill from
something other than `data.item` (e.g. a related projection — see ADR-N
on related projections).

## 9. Renderer impact (React)

**Reusable** without change:

- `InputControl` (the per-type `<input>` / `<select>` / etc.).
- `shapeValue` (per-type payload shaping — already null-safe in v0.1.x).
- `validateInputs` (becomes advisory on update — see §6.4; the renderer
  may still call it to surface required-but-empty in the UI without
  blocking submit).
- `inputDefault` (used for create initial state — refactored to take an
  optional `initialValues` map for update; falls through to the existing
  field default → empty when neither is supplied).
- `useAction` (the HTTP POST hook).
- `ActionModal`'s confirmation-prompt fallback (for actions with `inputs:
  []`).

**Changes** confined to `ActionModal`:

1. Accept an optional `subject` to forward to `useAction` (already
   accepted today).
2. Initial form state is now seeded by `initialValues ?? field.default ??
   ''` per input — a one-line precedence change.
3. The submit handler shapes only the keys the user touched. (Open
   question §12.4 — UX for empty-vs-untouched on a string field.)
4. Title and confirm-button label come from `action.label` (already
   today); no `"Create"` vs `"Update"` hardcoding.

`ListView`/`DetailView` themselves do **not** change. They both render
`ActionBar` over `schema.actions`; an update action is just another row
button with subject-required semantics. `ViewSchemaConsumer` is
unchanged.

## 10. Backward compatibility

The change is **strictly additive on the wire**:

- `BuiltinEffect::Update` is a new enum case with a new string value
  (e.g. `'kernel.builtin.update'`). `EffectDispatcher::tryFrom()`
  recognises it; older runtimes that don't recognise it would route to
  the `new ($action->effectClass)()` arm and fail loudly (acceptable —
  there is no scenario where a v0.1.x runtime is asked to execute a v0.2
  plugin's update effect).
- `ActionDescriptor.initialValues` is added as **optional** in the TS
  type. Pre-v0.2 renderers that read `ActionDescriptor` either ignore the
  new key entirely or destructure with default values; neither breaks.
- Pre-v0.2 plugins that never call `Action::update(...)` produce graphs
  byte-identical to v0.1.x (assuming `BuiltinEffect` enum case ordering
  is preserved). The compiled-graph hash for HelloInvoice stays stable.
- The HTTP route layout is unchanged. Update actions are POSTed to the
  same `/api/actions/{fqn}` endpoint as create and transition.
- `apps/issue-tracker` becomes the first dogfood candidate (the
  `reassign`/`rename`/`edit` actions promised by §4).

A `breaking-change` note is **not** required.

## 11. Alternatives considered and rejected

### 11.1 Full PUT replacement of the entity

A request body would carry every patchable field; absent fields would
reset to defaults / null.

**Rejected because:**
- Renders forms unfit for partial mutation surfaces (per §5).
- Forces clients to read fields they may not have permission to write.
- Misaligns with the cross-action concurrency story (per §5.3).
- Wire shape would diverge from create/transition for no semantic gain.

### 11.2 Free-form JSON mutation (no `updatableFieldNames`)

A single `Action::edit` per entity with no closed list of patchable
fields; the runtime accepts whatever inputs the caller sends and writes
them.

**Rejected because:**
- Loses the closed-graph property the rest of AUSUS depends on: a
  field's writability becomes implicit rather than declared.
- Removes the natural authorisation seam: today each action carries one
  policy. Merging all edits into one action either forces a single
  blanket role, or pushes per-field authorisation into the policy
  evaluator — which is a v0.3+ direction and out of scope.
- Eliminates the audit-log signal: today the audit row names the action
  FQN; "edit" tells the operator nothing.

### 11.3 Transitions that carry inputs (status quo, surfaced)

The runtime's `TransitionEffect` already merges `$inputs` into the
patch. The "feature" could be unblocked by having
`ActionBuilder::transition()` populate `inputs` from declared field names.

**Rejected because:**
- Pretends every update is a state move. The renderer would have to
  invent a fake target state per field; the audit log would name a
  transition for a thing that does not transition.
- Couples authorisation: a "reassign" would need a `requireRole` shared
  with whatever state change the transition pretends to model.
- Combined create/update on the same action would still be wrong: the
  `from`/`to` arrow is part of the transition's identity and cannot
  encode "no state change."
- Breaks the §7 invariant (workflow ↔ data separation).

### 11.4 An `Action::patch(...)` instead of `Action::update(...)`

Naming-only. `update` is the established noun in adjacent ecosystems
(Filament, Nova, Symfony Form, Eloquent's `->update()`); `patch` would
read as "the wire verb" and conflate the HTTP layer with the DSL. We
keep `update`.

## 12. Open questions — must be answered before code lands

### 12.1 Optimistic concurrency: server-resolved vs. client-supplied `_version`

`UpdateEffect` as sketched in §4.3 does a read-then-update **inside the
Invoker transaction**. SQLite's transaction isolation rejects a
conflicting writer between the two statements, surfacing as
`ConcurrencyConflict`.

This is correct but coarse: two unrelated edits to the same row that
patch **disjoint fields** still conflict if they arrive concurrently —
SQLite's row lock does not look at column overlap.

Alternative: require the client to send the `_version` they fetched (in
the body or via an HTTP `If-Match` header). The server passes it
through to `$repo->update()` and lets the existing optimistic-lock
machinery do its thing.

**Decision pending.** Body-carried `_version` is the more rigorous
shape but couples the renderer to ViewSchema's `_version` exposure —
which today is implicit and not on the wire. **Recommendation**:
default to server-resolved for v0.2.0; document the
client-supplied option as a v0.2.1+ addition behind an
`Action::update(...)->withClientVersion()` builder method.

### 12.2 Audit log: store the diff or the full patch?

Today the audit log stores the `outputs` array as JSON. For update, the
"diff" is exactly `$patch`. We store `$patch` — but we do **not**
currently store the *prior* values. An operator answering "what did
this change overwrite?" cannot reconstruct it from the audit log alone.

Options:
- (A) Store `$patch` only (current shape extended). Smallest delta.
- (B) Store `{ before: <old values>, after: <patch> }`. Doubles row size
  in the worst case; requires the effect to capture before-values
  inside the transaction.
- (C) Add a separate audit stream for "value changes" (kept as a
  separate ADR).

**Decision pending.** (A) for v0.2.0 — the diff is recoverable by
walking the audit log backwards if all writes go through actions.
(B) is the right long-term answer; tracked as a follow-up ADR.

### 12.3 Async / domain validation

Today, validation is synchronous: type checks in `serializeField`, FK
checks in custom effects, policy denial via `RoleRequired`. Update
introduces a new class of validation — "is this title unique within the
project?", "does this assignee exist as a user record?" — that requires
**cross-record / cross-entity reads**.

The runtime layer has no contract for async validators.

**Decision pending.** Out of scope for the update mechanism itself; it
reuses whatever validator/policy story the framework adopts. The
update effect must not embed a bespoke validator API that would later
have to be rewritten. **Recommendation**: rely on `Policy::evaluate()`
for the cross-record gate (e.g. a `UniqueWithinProject` policy
implementation) until a dedicated validator contract lands. ADR-N
will cover policy expressiveness.

### 12.4 Renderer UX for "untouched" vs "explicitly empty"

On a `string?` field whose `initialValues` carry `"old title"`, what
does the renderer do when the user clears the input box?

- (A) Send `{ title: "" }` and let `serializeField` reject it (string
  field, but empty-string-as-null is its own ambiguity).
- (B) Send `{ title: null }` if the field is nullable; raise an inline
  validation error if not.
- (C) Treat the cleared input as "untouched" — i.e. drop the key from
  the payload entirely — and require an explicit "clear" affordance.

(C) loses information from the user's intent. (A) corrupts. **Recommendation**:
(B) — clearing a nullable field sends `null`; clearing a non-nullable
field is a client-side validation error that blocks submit. This rides
on the v0.1.x null-serialisation fix.

### 12.5 Nested / partial updates inside compound values

`money` is the existing compound type (`{ amount, currency }`).
`Action::update('amount')` currently sends the whole compound. There is
no path to update only `amount.amount` without re-supplying `currency`.

**Decision pending.** Document the compound-as-atomic rule for v0.2.0:
updates write the **whole compound value** that a field exposes. Deep
patching is out of scope. Future v0.3+ may model nested fields via a
related-entity pattern rather than nested compounds.

### 12.6 What happens to `Action::update(...)` when the action has zero patchable fields?

The compiler should reject the empty form `Action::update()` —
declaring an update with no fields is a meaningless action and almost
certainly a typo. Same error severity as `Action::create()` with no
inputs (which today silently creates a no-input create — also worth
revisiting).

## 13. Acceptance criteria for implementation (v0.2.0)

A v0.2 implementation can close this ADR by satisfying:

1. `Action::update(...)` declared on the DSL, validated as in §4.1.
2. `BuiltinEffect::Update` case added; `EffectDispatcher::dispatch()`
   resolves it to `UpdateEffect`.
3. `UpdateEffect` running inside the existing Invoker chain (preflight →
   policy → workflow guard *(no-op for update)* → effect → audit), inside
   one DB transaction.
4. `ProjectionRenderer::describeActionInputs()` populates `inputs[]` for
   update actions, exactly like it does for create today.
5. `ProjectionRenderer::render()` populates `ActionDescriptor.initialValues`
   when both the descriptor is an update and `data.item` is present.
6. TS `ActionDescriptor` type extended with optional `initialValues`. No
   other public TS surface change.
7. `ActionModal` seeds form state from `initialValues ?? field.default ??
   ''`; submit goes through `useAction` unchanged.
8. `apps/issue-tracker` adds `reassign` / `rename` / `edit` and the
   smoke test asserts: a rename POST changes only `title`; concurrent
   disjoint updates do not corrupt unrelated columns; an `update('status')`
   declaration fails to compile.
9. `apps/playground/api-consistency-test.php` adds a section asserting
   the wire shape (`inputs[]` populated, `initialValues` populated on
   detail projections, closed-list rejection on a stray input).
10. `FRAMEWORK-FINDINGS.md` §4 moved to FIXED. The v0.2 priority list
    above it loses its #1.

Nothing in this ADR commits to a release date; only to the shape the
implementation must take.

## 14. References

- v0.1.x kernel — `packages/kernel/src/{kernel.php,dsl.php}` (`FieldNode`,
  `ActionNode`, `BuiltinEffect`, `ActionBuilder::build()`).
- v0.1.x runtime — `packages/runtime-default/src/runtime.php`
  (`TransitionEffect`, `EffectDispatcher::dispatch()`,
  `ProjectionRenderer::render()`, `ProjectionRenderer::describeActionInputs()`).
- v0.1.x persistence contract — `Repository::update($ref, $patch, $expectedVersion)`
  (already optimistic-locked).
- v0.1.x ViewSchema — `renderer/react/src/types.ts` (`ActionDescriptor`,
  `FieldDescriptor`).
- v0.1.x renderer form — `renderer/react/src/components.tsx`
  (`ActionModal`, `InputControl`, `inputDefault`, `shapeValue`,
  `validateInputs`).
- ADR-0001 — explicit workflow declaration (already shipped).
- Sample-app findings — `apps/issue-tracker/FRAMEWORK-FINDINGS.md` §4.
- Recently shipped enablers in v0.1.x that this design assumes:
  `ApplicationConfig::make()->…`, `Application::http()`,
  `Application::run()`, `Repository::findAll()`, `BuiltinEffect` enum,
  `FieldBuilder::label()`, the null-serialisation fix, the
  `X-Actor-Roles` fail-closed Router.

---

## Appendix A — Worked example, end-to-end

```php
// plugin
$dsl->entity('issue')
    ->fields([
        'project_id' => Field::string()->max(26)->label('Project'),
        'title'      => Field::string()->max(200),
        'assignee'   => Field::string()->max(120)->nullable()->label('Assignee'),
        'priority'   => Field::enum('LOW', 'NORMAL', 'HIGH')->default('NORMAL'),
        'status'     => Field::enum('TODO', 'DOING', 'DONE')->default('TODO'),
    ])
    ->actions([
        'create'   => Action::create('project_id', 'title', 'assignee', 'priority')
                          ->requireRole('issue.author'),
        'rename'   => Action::update('title')
                          ->requireRole('issue.maintainer'),
        'reassign' => Action::update('assignee')
                          ->requireRole('issue.maintainer'),
        'edit'     => Action::update('title', 'assignee', 'priority')
                          ->requireRole('issue.maintainer'),
        'start'    => Action::transition('status', from: 'TODO',  to: 'DOING')
                          ->requireRole('issue.maintainer'),
        'done'     => Action::transition('status', from: 'DOING', to: 'DONE')
                          ->requireRole('issue.maintainer'),
    ])
    ->workflow(field: 'status', initial: 'TODO')
    ->projection('detail',
        fields:  ['id', 'project_id', 'title', 'assignee', 'priority', 'status'],
        actions: ['rename', 'reassign', 'edit', 'start', 'done'],
        role:    'issue.viewer');
```

Wire — `GET /api/projections/tracker.issue.detail?subject=01J…` :

```jsonc
{
  "schemaVersion": "1.0.0",
  "targetProfile": "react.web.v1",
  "fields":  [ /* … */ ],
  "actions": [
    {
      "fqn": "tracker.issue.rename",
      "name": "rename", "label": "Rename", "subjectRequired": true,
      "inputs": [
        { "name": "title", "type": "string", "label": "Title",
          "required": true, "nullable": false,
          "typeOptions": { "maxLength": 200 } }
      ],
      "initialValues": { "title": "Renderer crashes on null money" }
    },
    {
      "fqn": "tracker.issue.reassign",
      "name": "reassign", "label": "Reassign", "subjectRequired": true,
      "inputs": [
        { "name": "assignee", "type": "string", "label": "Assignee",
          "required": false, "nullable": true,
          "typeOptions": { "maxLength": 120 } }
      ],
      "initialValues": { "assignee": "carol@acme" }
    },
    {
      "fqn": "tracker.issue.start",
      "name": "start", "label": "Start", "subjectRequired": true,
      "inputs": []          // unchanged from v0.1.x
    }
    // …
  ],
  "data": { "item": { /* … current values … */ } }
}
```

Wire — `POST /api/actions/tracker.issue.rename` :

```json
{
  "subject": { "tenantId": "acme", "entityFqn": "tracker.issue",
               "identityHandle": "01J…" },
  "inputs":  { "title": "Renderer crashes on null money — confirmed in 0.1.1" }
}
```

Response:

```json
{ "ok": true,
  "outputs": { "title": "Renderer crashes on null money — confirmed in 0.1.1",
               "_version": "01J…NEW" } }
```

The audit row, per §12.2 decision (A) for v0.2.0:

```jsonc
{
  "action_fqn": "tracker.issue.rename",
  "actor":     { "type":"user", "id":"alice", "homeTenant":"acme" },
  "subject":   { "tenantId":"acme", "entityFqn":"tracker.issue",
                 "identityHandle":"01J…" },
  "inputs":    { "title": "Renderer crashes on null money — confirmed in 0.1.1" },
  "outputs":   { "title": "Renderer crashes on null money — confirmed in 0.1.1",
                 "_version": "01J…NEW" },
  "timestamp": "2026-05-25T20:14:02.117Z"
}
```

---

## Appendix B — Decisions taken in this ADR

| # | Decision | Status |
|---|---|---|
| §3 | Update is a third action kind, metadata-driven end-to-end | **Taken** |
| §4 | `Action::update(...$fieldNames)` builder, closed `updatableFieldNames` | **Taken** |
| §5 | Partial-PATCH semantics; not PUT | **Taken** |
| §6 | `required` is meaningful on create, advisory on update | **Taken** |
| §7 | Update cannot touch a workflow state field; DSL rejects at compile | **Taken** |
| §8 | `ActionDescriptor.initialValues` (optional, additive) | **Taken** |
| §9 | Renderer reuses `InputControl`/helpers; `ActionModal` extended for prefill only | **Taken** |
| §10 | Strictly additive wire change; no breaking change | **Taken** |
| §12.1 | Server-resolved concurrency for v0.2.0; client `_version` deferred | **Provisional** |
| §12.2 | Audit stores `$patch`; before-values deferred | **Provisional** |
| §12.3 | Async validation deferred to a future ADR | **Open** |
| §12.4 | Cleared nullable → `null`; cleared non-nullable → client error | **Provisional** |
| §12.5 | Compound values updated atomically; no deep partial patch | **Taken** |
| §12.6 | `Action::update()` (no fields) is a compile error | **Taken** |
