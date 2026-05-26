# Changelog

All notable changes to AUSUS are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
AUSUS follows [Semantic Versioning](https://semver.org/); the policy
that binds breaking-change handling, additive contracts, and
package alignment is `docs/VERSIONING.md`.

This file consolidates the per-package CHANGELOGs at the repository
root. Per-package changelogs remain the source of truth for
package-local fix releases; this file covers the shared release lines
across `ausus/kernel`, `ausus/persistence-sql`, `ausus/runtime-default`,
`ausus/api-http`, `ausus/standard-stack`, and `@ausus/renderer-react`.

## [0.1.1] — 2026-05-26 — v0.1.x stabilisation

The v0.1.x stabilisation series. Headlines: a complete
`Application` bootstrap facade replacing manual Invoker wiring; the
first end-to-end metadata-driven create/update form path through the
React renderer; the third action kind (`Action::update`, partial
PATCH) defined by ADR-0002; a high-severity nullable-column
serialisation fix; and two intentional breaking changes that correct
behaviour the v0.1.0 contract never promised.

### Breaking changes

Both apply only to consumers running against the HTTP Router; CLI /
`Application::invoke()` paths are unaffected.

- **`Router::resolveActor()` is now fail-closed.** A missing or empty
  `X-Actor-Roles` header yields a roleless actor; every action with a
  `requireRole(...)` policy returns `403 PolicyDenied`. The previous
  behaviour substituted the HelloInvoice-specific role set
  (`invoice.creator, invoice.issuer, invoice.canceler, invoice.viewer`).
  An authenticated gateway must now set `X-Actor-Roles` from the
  verified session — see
  `docs-site/docs/operations/authenticated-gateway.md`.
- **`ErrorMapper::classify()` short-name table corrected.** The map
  referenced `PolicyDeniedException` / `EffectFailure` — class names
  that never existed; the kernel's actual `PolicyDenied`,
  `EffectFailed`, `NotFound`, `UnknownAction`,
  `WorkflowSubjectNotFound`, `PolicySubjectRequired`, `ActorRequired`,
  `TenantContextRequired`, `WorkflowGuardDenied`, and
  `AuditEmissionFailed` silently routed to `500 InternalError`. Each
  now maps to its documented status (403 / 404 / 400 / 500 per
  `docs-site/docs/reference/http-routes.md`). Clients that read
  `response.body.error.kind` and ignored the HTTP status are
  unaffected; clients that branched on `500` for non-server-error
  conditions must switch to the documented codes.

### Added

#### Bootstrap (`ausus/standard-stack`)

- **`Ausus\Application`** — bootstrap facade with a four-call
  lifecycle (`create → register → boot → invoke`). Composes the
  kernel compiler, the SQLite persistence driver, and the default
  runtime, eliminating the manual `Invoker` wiring previously repeated
  across every entry point. Public surface: `create`, `register`,
  `boot`, `invoke`, `run`, `http`, `router`, `render`, plus typed
  accessors (`graph`, `invoker`, `driver`, `renderer`, `auditSink`,
  `pdo`, `tenant`, `actor`, `isBooted`, `reference`).
- **`Ausus\ApplicationConfig`** — typed, immutable, fluent builder
  for `Application::create()`. Every setter returns a new instance.
  `Application::create()` accepts either an `ApplicationConfig` or
  the equivalent associative array (bit-for-bit equivalent). 14
  setters: `tenant`, `actor` / `actorId`, `roles`, `permissions`,
  `sqlite` / `pdo` / `driver` / `auditSink` / `migrate`,
  `kernelVersion`, `apiPrefix`, `psr17` / `responseFactory` /
  `streamFactory`.
- **`Application::http(ServerRequest): Response`** — one-call PSR-7
  entry point. Lazily builds and caches a `Router` against the booted
  graph / driver / audit-sink, autodetects
  `Nyholm\Psr7\Factory\Psr17Factory` when no PSR-17 factory is
  configured, mounts at `ApplicationConfig::apiPrefix()` (default
  `/api`).
- **`Application::run(...): InvocationResult`** — typed wrapper around
  `invoke()`'s loose array return; carries the post-action
  `Reference`, the action FQN, and the raw outputs.
- `ausus/standard-stack` package `type` changed from `metapackage` to
  `library` so it can ship code; `require` now includes
  `ausus/api-http` alongside kernel, runtime-default, and
  persistence-sql.

#### DSL & kernel (`ausus/kernel`)

- **`Ausus\BuiltinEffect`** string-backed enum naming the three
  sentinel `ActionNode::effectClass` values (`Create`, `Transition`,
  `Update`). String values are stable wire metadata.
- **`Action::update(string ...$fieldNames)`** facade and
  `ActionBuilder::update()` builder for the third action kind defined
  by ADR-0002 (partial PATCH). Compile-time validation refuses
  unknown fields, system fields, and the workflow state field.
- **`Repository::findAll(): list<Entity>`** added to the persistence
  contract — the projection renderer no longer reads the driver's
  private PDO via reflection. Custom `Repository` implementations
  must add this method.
- **`Ausus\InvocationResult`** typed wrapper around the loose
  `Invoker::invoke()` array return.
- **`FieldBuilder::label(string)`** — explicit renderer-friendly
  label for columns and form fields. Cosmetic; the field `name`
  remains the source of truth. Falls back to the auto-humanised name
  (`project_id` → "Project id") when not set.
- **`EntityBuilder::workflow(field:, initial:)`** — explicit
  workflow declaration. The implicit "first enum with default wins"
  inference becomes a deprecated fallback emitting
  `E_USER_DEPRECATED`; an entity with two defaulted enum fields and
  no `->workflow()` call now fails fast with
  `AmbiguousWorkflowField`.
- **`ActionBuilder::addTransition()`** canonical chained
  multi-source-transition builder. The earlier `andTransition()`
  continues to work and is PHPDoc-deprecated.
- **`Compiler` initial-state coherence check** — rejects a
  `WorkflowNode` whose `initial` is not among its `states`.
- **`FieldNode`** gains an optional `?string $label` constructor
  parameter at the end of the positional list (additive — every
  existing positional caller, including the manual
  `HelloInvoicePlugin`, keeps compiling).

#### Runtime (`ausus/runtime-default`)

- **`UpdateEffect`** — backs `BuiltinEffect::Update`. Loads the
  entity inside the Invoker transaction, polices the inputs against
  the action's closed `updatableFields` list, refuses null on a
  non-nullable field, and dispatches a partial patch through
  `Repository::update()`. Empty inputs are an idempotent no-op.
- **`EffectDispatcher`** — new `BuiltinEffect::Update` branch;
  `BuiltinEffect::tryFrom()` resolves the sentinel string; unknown
  FQNs continue to dispatch through the existing fallback (no
  signature change).
- **ViewSchema input descriptors.** `ProjectionRenderer` now
  always emits `ActionDescriptor.inputs[]` (it was previously
  missing). This unblocks the renderer's create- and update-form
  generation end-to-end.
- **`ActionDescriptor.initialValues`** injected on update-action
  descriptors when the projection renders a single subject
  (`data.item`). Drives the renderer's prefill + diff-payload
  submit branch.
- **`FieldDescriptor.label`** now respects an explicit
  `FieldBuilder::label(...)` value; falls back to the auto-humanised
  field name when none is declared.

#### Renderer (`@ausus/renderer-react`)

- Eight action-form helpers exported and marked **`@public stable`**:
  `inputDefault`, `initialFor`, `isUnchanged`, `isRequired`,
  `shapeValue`, `validateInputs`, `buildCreatePayload`,
  `buildUpdatePayload`. The `README.md` now carries an explicit "API
  stability" section spelling out the v0.1.x backward-compatibility
  guarantee and the permitted evolutions (additive type-union
  entries, additive optional descriptor keys, new helpers alongside
  the existing ones).

#### Sample application (issue-tracker dogfooding)

- **`apps/issue-tracker/`** — production-style sample driving every
  documented surface (3 entities, 2 workflows, 12 actions including
  `rename`, `reassign`, and `edit` update actions). Used as the
  regression target for the v0.1.x stabilisation work. The companion
  `FRAMEWORK-FINDINGS.md` catalogues the framework gaps the
  dogfooding pass surfaced; four are marked **FIXED** in this
  release (null-serialisation §3, update actions §4, Router fallback
  §6, field labels §9).
- **`apps/issue-tracker/tests/smoke.php`** — 37-assertion smoke test
  wired into CI as step `4g`.

#### Documentation

- Reference shelf: `application.md`, `configuration.md`,
  `http-routes.md`, `view-schema-wire.md`.
- Operations shelf: `deployment.md` (nginx / Apache / Docker
  recipes) and `authenticated-gateway.md` (the `X-Actor-*` injection
  pattern).
- Top-level glossary (24 terms).
- Beginner tutorial — bilingual 7-part "Build a Ticket System"
  walkthrough with 6 SVG architecture diagrams and 5 UI mockups.
- `getting-started/sample-apps.md` surfacing `apps/issue-tracker/`.
- ADR-0002 (`Action::update`) under `docs/adr/`.
- `docs/VERSIONING.md` — repository-wide versioning policy
  (additive-only ViewSchema, deprecation channel per language,
  package version alignment).
- v0.1.x API stability sweep: `@internal` PHPDoc on
  `Dsl::_register*`, `Dsl::emit()`, `EntityBuilder::finalize()`,
  `ActionBuilder::build()`, `DefaultEffectContext`, `SqliteContext`,
  `SqliteRepository`, `SqliteTransactionHandle`,
  `Ausus\Api\Http\BadRequest`, and the three reserved exception
  classes (`ActorRequired`, `TenantContextRequired`,
  `WorkflowGuardDenied`). `ActionNode::$effectClass` carries a
  public-contract docblock distinguishing the `BuiltinEffect`
  sentinel from a custom-Effect FQN. `frontend/viewschema.md` adds
  an explicit reserved-fields table.

### Changed

- **`ProjectionRenderer::render()` no longer reflects into the
  SQLite driver's private PDO.** It iterates entities through the new
  `Repository::findAll()` contract. As a side effect, projection
  `data.items[i]` shapes are now consistent with `data.item` for
  every field type (notably: `money` SQL NULL now reads back as PHP
  null rather than a truncated tuple).
- Per `docs/VERSIONING.md`, the package version alignment scheme is
  now explicit: every Composer package under `packages/*` plus
  `renderer/react` carries the same `MAJOR.MINOR` line. The four
  reserved packages (`ausus/auth-bridge`, `ausus/audit-database`,
  `ausus/tenancy-row`, `ausus/presentation-default`) ship empty
  composer manifests at the line's current version.

### Fixed

- **Nullable-column serialisation (high severity, data corruption).**
  `SqliteRepository::serializeField()` routed PHP null through
  `json_encode(null)` because `is_scalar(null)` is `false`, storing
  the literal 4-character string `"null"` on disk. Symmetric
  corruption hit `integer` (stored `0`), `datetime` (stored `""`),
  and `money` (stored `""` on disk; read back as
  `{amount: '', currency: '…'}`). Fixed by a single null-guard at the
  top of `serializeField()` plus a matching guard in `unwrapFields()`
  — every nullable type now round-trips as a real PHP null /
  SQL NULL. Regression-guarded by
  `apps/playground/null-roundtrip-test.php` (30 assertions, CI step
  `4h`). **Backfill of rows corrupted under 0.1.0 is the consumer's
  responsibility** — the fix only prevents new corruption.
- **`SqliteRepository::findAll()` added** (kernel contract addition,
  consumed by the projection renderer; see "Changed" above).
- PHPDoc clarifications on `ActionNode::effectClass` overload
  semantics and the `Reference` vs `Subject` distinction.

### Notes

- No SQL schema change. Existing custom `Effect` classes continue to
  dispatch through the existing fallback branch — no public method
  signature changed in the runtime.
- The HTTP route layout, request body shapes, and `OPTIONS *` CORS
  behaviour are unchanged from 0.1.0.
- CI gate: `scripts/ci.sh` ends with `[ci] DONE — all 10 steps
  passed`; 350 assertions across PHP playground tests, the
  issue-tracker smoke, the renderer trace, the renderer TypeScript
  build, an `npm pack --dry-run`, and a live HTTP integration test
  (`php -S` + the React renderer).
- Docusaurus EN and FR locales both build `[SUCCESS]`. French
  translations exist for the earlier shelves; v0.1.x additions ship
  EN-only and rely on Docusaurus's EN fallback.

## [0.1.0] — 2026-05-19

First public release of AUSUS. PHP 8.3+, MIT licence.

### Added

#### Kernel (`ausus/kernel`)

- **Compiler.** Deterministic `MetadataGraph` synthesis from a list
  of `Plugin` instances. Graph hash is SHA-256 over the FQN-sorted
  membership set (RFC-001 §6.4); byte-identical hash across manual
  and DSL plugins.
- **Value objects** (all `final readonly`): `TenantId`, `Tenant`,
  `ActorRef`, `Reference`, `Subject`, `Decision`, `Version`,
  `Instant`.
- **`Ulid` generator** — Crockford base32, 26 chars, 80 bits of
  randomness, monotonic within process (RFC-001 §6.5).
- **`Plugin` contract** with the descriptor-array shape for Fields,
  Actions, Policies, Workflows, Transitions, Projections, Entities.
- **DSL facade** — `Dsl`, `DslPlugin`, `EntityBuilder`,
  `FieldBuilder`, `ActionBuilder`, plus `Field`/`Action` static
  facades. Produces graphs byte-identically equivalent to manual
  descriptor-array plugins (RFC-011 §11).
- **Graph nodes** — `FieldNode`, `ActionNode`, `PolicyNode`,
  `WorkflowNode`, `TransitionNode`, `ProjectionNode`, `EntityNode`,
  `MetadataGraph`.
- **Contracts** — `Actor`, `Policy`, `Effect`, `EffectContext`,
  `Repository`, `PersistenceDriver`, `AuditSink`, `Auditor`.
- **Exception taxonomy** rooted at `AususError` — including
  `TenantBoundaryViolation`, `PolicyDenied`, `WorkflowStateMismatch`,
  `ConcurrencyConflict`, `EffectFailed`, `MalformedDescriptor`.

#### Persistence (`ausus/persistence-sql`)

- **`SqlitePersistenceDriver`** implementing the RFC-002 contract on
  PDO — per-tenant transaction handles, row-level read filtering,
  optimistic locking via `_version` column.
- **`SqliteContext`** — bound `(Tenant, TransactionHandle)` pair;
  every read enforces `WHERE tenant_id = ?` at the SQL surface
  (RFC-003 row strategy).
- **`SqliteRepository`** — `find`, `create`, `update` with
  optimistic-lock check; raises `ConcurrencyConflict` on stale write.
- **`SchemaDeriver`** — translates `MetadataGraph` into idempotent
  `CREATE TABLE` statements.
- **`DatabaseAuditSink`** — writes audit rows in the same DB
  transaction as the Effect's data writes (RFC-007 Amendment-01
  in-transaction sink); precludes orphan audit entries by
  architecture.
- Designed for PDO SQLite (tested), PDO MySQL, PDO Postgres
  (schema deriver emits ANSI DDL; multi-engine validation deferred).

#### Runtime (`ausus/runtime-default`)

- **`Invoker`** implementing the 5-step chain (RFC-005 §3 /
  RFC-001 §A-1.4): Tenant check → Policy chain → Workflow guard →
  Effect → Audit.
- **`PolicyEngine`** — short-circuit ALLOW/DENY evaluator over an
  Action's declared `policies[]`. Default policy is
  `RoleRequired(roles[])`.
- **`WorkflowRuntime`** — per-Workflow source-state selection
  (RFC-006 Amendment-01); raises `WorkflowStateMismatch` if no
  transition applies.
- **`TransitionSetIndex`** — pre-compiles all Workflow transitions
  into an O(1) lookup keyed by `(workflowFqn, sourceState)`.
- **`EffectDispatcher`** — invokes the Effect declared by the
  Action; passes an `EffectContext` bound to
  `(Tenant, TransactionHandle, Actor, Inputs)`.
- **Built-in effects** — `CreateEffect`, `TransitionEffect`.
- **`DefaultAuditor`** wrapping any `AuditSink` with a
  `SequenceCounter` for per-tenant monotonic audit ordering.
- **`ProjectionRenderer`** — executes a `Projection` and returns
  the RFC-004 ViewSchema wire format
  (`{schemaVersion, targetProfile, metadata, fields, actions, data}`).

#### HTTP API (`ausus/api-http`)

- **`Router`** — single `Psr\Http\Server\RequestHandlerInterface`
  dispatching:
  - `GET  /_health` — liveness + graph hash
  - `GET  /projections/{fqn}` — RFC-004 ViewSchema with embedded
    data; `?subject=<id>` selects detail view
  - `POST /actions/{fqn}` — invoke action; returns `ActionResult`
  - `OPTIONS *` — CORS preflight
- **`ErrorMapper`** — kernel exception taxonomy → HTTP status +
  envelope.
- **`BadRequest`** — internal protocol exception (missing header /
  bad body) feeding into the same error envelope. *(Note: the
  short-name table that maps `ErrorMapper`'s match arms was
  corrected in 0.1.1 — see Breaking changes above.)*
- **`Emitter`** — minimal PSR-7 response → SAPI emit; stand-in for
  `laminas-httphandlerrunner`.
- Wire format frozen: `X-Tenant-ID` header; `ActionResult` body
  `{ok: true, outputs}` on success / `{ok: false, error: {kind, message}}`
  on failure.

#### Renderer (`@ausus/renderer-react`)

- **Public exports (10):** `AususProvider`, `useAusus`,
  `useViewSchema`, `useAction`, `ViewSchemaConsumer`, `ListView`,
  `DetailView`, `ActionModal`, `WorkflowBadge`, `FieldDisplay`.
- React 18 / 19 peer-dep; zero runtime dependencies; Node ≥ 18.
- Bundle: 10.9 kB packed / 45.6 kB unpacked (21 files including
  LICENSE); 16.3 kB minified (5.7 kB gzipped) with React inlined.

#### Standard stack (`ausus/standard-stack`)

- Composer **metapackage** pinning the V0 standard stack:
  `ausus/kernel`, `ausus/persistence-sql`, `ausus/runtime-default`,
  all at `0.1.*`. Reserved-name packages
  (`ausus/tenancy-row`, `ausus/audit-database`, `ausus/auth-bridge`,
  `ausus/presentation-default`) declared under
  `extra.ausus.v0-scope` as forward markers.

### Notes

- Tested with 36 PHP assertions in `apps/playground/run.php`
  covering DSL byte-identical hash equality, ULID monotonicity, the
  full 5-step Invoker chain, optimistic-lock conflict,
  cross-tenant rejection, and RFC-011 §11 KPI deltas.
- The 0.1.0 HTTP transport ships with permissive CORS and a
  stub-actor model read from `X-Actor-*` headers — demo-only;
  replace with a real auth middleware and a restrictive CORS
  allowlist before any non-local deployment.

[0.1.1]: https://github.com/adonko3xBitters/ausus-framework/releases/tag/v0.1.1
[0.1.0]: https://github.com/adonko3xBitters/ausus-framework/releases/tag/v0.1.0
