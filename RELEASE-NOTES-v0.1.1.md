# AUSUS v0.1.x — Consolidated release notes

> Supersedes [`RELEASE-NOTES-v0.1.0.md`](RELEASE-NOTES-v0.1.0.md), which
> covers the initial 0.1.0 release-candidate cut. This document
> consolidates every change in the v0.1.x stabilisation series up to
> the present `feat/v0.2-foundation` branch.
>
> **Status:** release-candidate ready, CI green
> (`[ci] DONE — all 10 steps passed`). The documentation freeze
> blockers identified in the documentation gap audit have all landed.

## At a glance

- **Two intentional breaking changes** versus 0.1.0 (both make the
  framework safer; see [§Breaking changes](#breaking-changes)).
- One major new feature — `Action::update(...)` partial-PATCH actions
  (ADR-0002).
- One critical bug fix — nullable column serialisation.
- A complete bootstrap facade (`Application`, `ApplicationConfig`,
  `Application::http()`) collapsing typical front controllers to
  ≈ 10 lines.
- An end-to-end React renderer that draws working create and update
  forms from metadata, with no entity-specific UI code.
- **350 CI assertions** across 10 steps — 247 PHP, 32 render-trace,
  14 live HTTP, plus build/install gates.

## New public API

### `Ausus\Application` (standard-stack)

The bootstrap facade. Four-call lifecycle
`create → register → boot → invoke` plus typed accessors. Reference:
[`docs-site/docs/reference/application.md`](docs-site/docs/reference/application.md).

```php
$app = Application::create(
        ApplicationConfig::make()->tenant('acme')->roles(['…'])->sqlite($path)
    )
    ->register(new YourPlugin())
    ->boot();

$result   = $app->run('your.entity.create', null, [...]);    // typed InvocationResult
$response = $app->http($psrRequest);                          // one-call HTTP
```

### `Ausus\ApplicationConfig`

A fluent, immutable typed builder for `Application::create()`. Every
setter returns a new instance. 14 setters covering tenancy, actor,
persistence, HTTP, PSR-17 factories.

`Application::create()` accepts either `ApplicationConfig` or the
legacy `array` form — both bit-for-bit equivalent.

### `Application::http(ServerRequest): Response`

One-call PSR-7 entry point. Lazily builds a cached `Router`, autodetects
nyholm/psr7 when no PSR-17 factory is configured, mounts under
`ApplicationConfig::apiPrefix()` (default `/api`).

### `Action::update(...$fields)`

Partial-PATCH action kind (ADR-0002). Adds a third built-in to the
DSL alongside `Action::create()` and `Action::transition()`. Closed
list of patchable fields enforced at runtime; the workflow state
field and system fields are refused at compile time.

```php
'rename'   => Action::update('title')->requireRole('issue.maintainer'),
'reassign' => Action::update('assignee')->requireRole('issue.maintainer'),
'edit'     => Action::update('title', 'assignee', 'priority')->requireRole('issue.maintainer'),
```

### `Field::*()->label(...)`

Explicit human-readable label for renderer columns and form fields,
overriding the auto-humanised default (`project_id` → "Project id").
Strictly cosmetic — the field name remains the source of truth.

### `EntityBuilder::workflow(field:, initial:)`

Explicit workflow declaration. The implicit "first enum with default
wins" inference is preserved as a deprecated fallback with an
`E_USER_DEPRECATED` notice; ambiguous (multiple enum-default fields,
no `->workflow()`) is now a hard `AmbiguousWorkflowField` validation
error instead of a silent first-match guess.

### `Repository::findAll(): list<Entity>`

Added to the kernel `Repository` interface so the projection renderer
no longer reaches into the SQLite driver's private PDO via reflection.

### `Ausus\BuiltinEffect` enum

Names the three sentinel values previously stored as raw strings on
`ActionNode::effectClass`. String values are wire-stable.

### `Ausus\InvocationResult`

Typed wrapper around the loose `invoke()` array return. Carries the
post-action `Reference`, the action FQN, and the raw outputs.

### Renderer (React)

- `ActionDescriptor.inputs[]` now **always** emitted from
  `ProjectionRenderer` — the renderer can build a working create or
  update form from metadata alone.
- `ActionDescriptor.initialValues` populated on update-action
  descriptors when the projection renders a single subject; drives
  the modal's prefill + diff-payload submit.
- Eight pure form helpers exported: `inputDefault`, `isRequired`,
  `shapeValue`, `validateInputs`, `initialFor`, `isUnchanged`,
  `buildCreatePayload`, `buildUpdatePayload`. Reuse them when
  building a custom form widget that stays on the runtime's payload
  contract.

## Bug fixes

### Null-on-nullable serialised as the string `"null"` (high severity)

`SqliteRepository::serializeField()` routed PHP null through
`json_encode(null)` because `is_scalar(null)` is `false`, storing the
literal 4-character string `"null"` on disk. Symmetric corruption hit
`integer` (`0`), `datetime` (`""`) and `money` (`{amount: '', currency: …}`)
columns.

Fixed by a single null-guard at the top of `serializeField()` plus a
matching guard in `unwrapFields()`. Regression-guarded by 30 assertions
in `apps/playground/null-roundtrip-test.php` (CI step `4h`).

**Backfill** is the consumer's responsibility if a 0.1.0 deployment
accumulated corrupted rows — the fix only prevents new corruption.

### `ProjectionRenderer` reflection access to private PDO (medium)

The renderer used to read the SQLite driver's `pdo` property via
`ReflectionProperty` to enumerate rows. Replaced by an explicit
`Repository::findAll()` contract; no more reflection access.

### `ErrorMapper` short-name table referenced legacy names (high)

The HTTP error mapper's `match` listed `PolicyDeniedException` and
`EffectFailure` — class names that never existed. The kernel's actual
`PolicyDenied` and `EffectFailed` silently routed to `500 InternalError`.
Mapping corrected; the full kernel taxonomy now returns its documented
status (see [`docs-site/docs/reference/http-routes.md`](docs-site/docs/reference/http-routes.md#status-codes)).

## Breaking changes

Both changes are intentional security/correctness wins; both apply
**only** to consumers running against the HTTP Router.

### 1. `Router::resolveActor()` is fail-closed

When `X-Actor-Roles` is absent or empty, the Router now constructs a
**roleless** actor. Every action declaring `->requireRole(...)`
returns `403 PolicyDenied`.

The previous behaviour was to substitute a HelloInvoice-specific
fallback role set (`invoice.creator, invoice.issuer,
invoice.canceler, invoice.viewer`). Any HTTP client that relied on the
implicit fallback now receives 403.

**Migration:** an authenticated gateway must set `X-Actor-Roles` from
the verified session — see [Operations · Authenticated gateway](docs-site/docs/operations/authenticated-gateway.md).

### 2. `ErrorMapper` returns the documented HTTP status

Kernel exceptions that previously fell through to `500 InternalError`
now map to their documented codes:

| Exception | 0.1.0 status | v0.1.x status |
|---|---|---|
| `PolicyDenied` | 500 | **403** |
| `EffectFailed` | 500 | **500** (unchanged, name corrected) |
| `NotFound` | 500 | **404** |
| `UnknownAction` | 500 | **404** |
| `WorkflowSubjectNotFound` | 500 | **404** |
| `PolicySubjectRequired`, `ActorRequired`, `TenantContextRequired` | 500 | **400** |
| `WorkflowGuardDenied` | 500 | **403** |
| `AuditEmissionFailed` | 500 | **500** (unchanged) |

**Migration:** clients that treated the HTTP status as a coarse
"500 = something failed" signal should switch to reading
`response.body.error.kind`. Per-kind handling improves; total-failure
handling is unchanged.

## Behaviour changes (additive, not breaking)

- **`ProjectionRenderer.action-descriptors` now carry `inputs[]`.**
  Pre-v0.1.x renderers that ignored the field stay correct; renderers
  that detected the absence to fall through to a confirmation modal
  will now show a form for create / update actions.
- **`Field::*()->nullable()` explicit-null payloads now round-trip
  correctly** — see the bug fix above. Consumers that depended on the
  bug (unlikely) lose that behaviour.
- **`projection.data.items[i]` for `money` columns** is now `null` when
  the SQL value is NULL (was: `{amount: '', currency: ...}`). The React
  renderer's `formatMoney` already handled both shapes; verified
  end-to-end by the live HTTP integration test.

## Documentation deltas

- **Beginner tutorial** — new bilingual 7-part "Build a Ticket System"
  walkthrough with 6 architecture diagrams (SVG) and 5 UI-mockup SVGs.
- **Reference shelf** — new `application.md`, `configuration.md`,
  `http-routes.md`, `view-schema-wire.md`. The DSL and error-taxonomy
  references are unchanged.
- **Operations shelf** — new `deployment.md` (nginx + Apache + Docker
  recipes) and `authenticated-gateway.md` (the X-Actor-* injection
  pattern). Existing publication-runbook / release-rehearsal docs
  unchanged.
- **Glossary** — new top-level glossary covering 24 recurring terms.
- **Sample apps** — new getting-started page surfacing
  `apps/issue-tracker/`.
- **ADR-0002** — design doc for `Action::update(...)`.
- **`apps/issue-tracker/FRAMEWORK-FINDINGS.md`** — six v0.2-track items
  marked **FIXED** as the stabilisation tasks landed:
  null-serialisation (§3), update actions (§4), Router fallback (§6),
  field labels (§9).
- **Bilingual maintenance** — French translations exist for the
  earlier shelves; v0.1.x additions ship EN-only and rely on
  Docusaurus's EN-fallback. The build is green for both locales.

## Test coverage

| Step | What it verifies | Assertions |
|---|---|---|
| `4`  | Playground end-to-end (DSL ↔ manual hash parity, persistence, workflow, audit) | 36 |
| `4b` | `Application` lifecycle + lazy boot | 23 |
| `4c` | Explicit workflow declaration + deprecation surface | 17 |
| `4d` | API consistency (BuiltinEffect, Repository::findAll, label propagation, …) | 50 |
| `4e` | `ApplicationConfig` fluent / immutable contract | 44 |
| `4f` | `Application::http()` + fail-closed actor resolution | 31 |
| `4g` | `apps/issue-tracker` end-to-end (incl. ADR-0002 update actions) | 37 |
| `4h` | Nullable-column round-trip (write → SQL → read → JSON) | 30 |
| `4i` | `Action::update(...)` + `UpdateEffect` implementation | 36 |
| `5`  | Starter `composer boot` standalone | OK |
| `7`  | Renderer (`@ausus/renderer-react`) TypeScript build | OK |
| `8`  | Renderer render-trace (SSR + helper unit tests) | 32 |
| `10` | Live HTTP integration (`php -S` + the React renderer) | 14 |

`scripts/ci.sh` ends with `[ci] DONE — all 10 steps passed`. The docs
site builds clean for both EN and FR locales.

## Known limitations (v0.2 backlog)

Catalogued at length in
[`apps/issue-tracker/FRAMEWORK-FINDINGS.md`](apps/issue-tracker/FRAMEWORK-FINDINGS.md).
Headlines for shipping consumers:

- **No FK contract** between entities. Cross-entity references are
  plain strings; the runtime accepts dangling values.
- **No projection filtering or pagination.** Every list projection
  returns all tenant rows.
- **No authentication layer.** Every deployment must put an
  authenticated gateway in front — see
  [Authenticated gateway](docs-site/docs/operations/authenticated-gateway.md).
- **SQLite only.** The persistence driver story is single-backend in
  v0.1.x.
- **Per-record audit trail not surfaced.** `kernel_audit_log` is
  populated; no built-in projection over it.

## Migration from 0.1.0

For most consumers (CLI / `invoke()` use only), this is a drop-in
update.

For HTTP consumers:

1. **Send `X-Actor-Roles` explicitly.** A missing header now returns
   `403`; the implicit invoice.* fallback is gone.
2. **Read `error.kind`** in response bodies; per-exception HTTP
   status codes are now accurate. A client that ignored anything
   except `200`/`500` continues to work.

Both code paths are exercised by CI step `4f` and step `10`.

## Acknowledgements

The stabilisation work is regression-tested against the
`apps/issue-tracker` sample app, which is itself a real consumer of
every documented surface. Items removed from the v0.2 priority list
during this series:

- Null-serialisation fix (was a critical bug).
- Router fail-closed (was a security wart).
- `Action::update(...)` (was the largest renderer-DX gap).
- `FieldBuilder::label()` (was a cosmetic gap).

The remaining v0.2 backlog (FK references, projection filtering,
audit projection, deeper validator contract) is documented and
costed; v0.1.x ships without them.

---

**Code and artifacts are release-candidate ready.** The publication
checklist itself lives in `docs/PUBLICATION-RUNBOOK.md` and is
unchanged from 0.1.0. Run the publication runbook on green CI to
cut the tag.
