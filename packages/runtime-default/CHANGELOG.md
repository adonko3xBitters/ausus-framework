# Changelog — ausus/runtime-default

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

## [Unreleased] — v0.2.0-beta.1 prep

### Added
- `ProjectionRenderer::render()` accepts optional `$limit` (default 50, max
  1000) and `$offset` (default 0) parameters. When the underlying repository
  implements `Ausus\PagedRepository`, the window is pushed into SQL; otherwise
  an in-memory `array_slice` fallback preserves the same wire output. Negative
  inputs are defensively clamped to legal values.
- Wire shape `data.pagination` now carries `limit`, `offset`, `totalCount`,
  `pageSize`. `nextCursor` is preserved at `null` as the reserved slot for
  the future cursor-based pagination axis. Additive — consumers reading the
  old shape continue to work.

### Changed
- `schemaVersion` bumped to **`1.1.0`**. Renderer `peerSchemaVersion: ^1.0.0`
  satisfies `1.1.0`, so no coordinated renderer release is required.

## [0.2.0-alpha.5] — 2026-05-28

### Changed
- No runtime changes in v0.2.0-alpha.5.
- Release engineering and installation guidance were updated for alpha
  distribution consistency.

## [0.2.0-alpha.4] — 2026-05-27

### Release engineering
- **No runtime, Invoker, or wire change.** Zero code changes vs
  `v0.2.0-alpha.3`. The runtime continues to emit
  `schemaVersion: '1.0.0'` in every ViewSchema response, bit-identical
  to the previous alpha.
- **ViewSchema compatibility contract formalised.** The renderer
  (`@ausus/renderer-react@0.2.0-alpha.4`) now declares a
  `peerSchemaVersion: "^1.0.0"` field that pins the accepted backend
  `schemaVersion` window. The runtime's emitted `schemaVersion` is
  asserted to satisfy this range by
  `scripts/check-renderer-alignment.sh` (CI step in
  `release-gate.yml`). A backend release that bumps `schemaVersion`
  must now coordinate a renderer release expanding
  `peerSchemaVersion` — enforced at PR time, not at deploy.
- **Renderer alignment enforcement.** The CI gate fails fast on any
  drift between the runtime's emitted `schemaVersion` and the
  renderer's declared `peerSchemaVersion`. Extraction is anchored on
  the literal ProjectionRenderer pattern in `src/runtime.php`
  (excludes comments, requires correct indent + quote style + trailing
  comma) — fragile grep patterns from previous tooling are replaced.

## [Unreleased] — v0.1.x stabilisation

### Documentation
- **API stability sweep.** `DefaultEffectContext` now carries
  `@internal` PHPDoc — consumers MUST depend on the
  `Ausus\EffectContext` interface (the public contract), not on the
  concrete class.

### Added
- **`UpdateEffect`** — backs `BuiltinEffect::Update`. Loads the entity
  inside the Invoker transaction, polices the inputs against the
  action's closed `updatableFields` list, refuses null on a
  non-nullable field, and dispatches a partial patch through
  `Repository::update()`. Empty inputs are an idempotent no-op
  (returns the current `_version` without writing).
- **`EffectDispatcher`** new `BuiltinEffect::Update` branch.
- **`ProjectionRenderer.action-descriptors`** now always emit
  `inputs[]` (was: missing). This unblocks the renderer's create- and
  update-form generation end-to-end.
- **`ProjectionRenderer`** injects `ActionDescriptor.initialValues` on
  update-action descriptors when the projection renders a single
  subject (`data.item`). Drives the renderer's prefill +
  diff-payload submit branch.
- **`FieldDescriptor.label`** now respects an explicit
  `FieldBuilder::label(...)` value; falls back to the auto-humanised
  field name only when none is declared.

### Changed
- **`ProjectionRenderer::render()` no longer reflects into the SQLite
  driver's private PDO** — it iterates entities through the new
  `Repository::findAll()` contract. As a side effect, projection
  `data.items[i]` shapes are now consistent with `data.item` for
  every field type (notably: `money` SQL NULL now reads back as PHP
  null rather than the truncated tuple).
- **`EffectDispatcher::dispatch()`** uses `BuiltinEffect::tryFrom()`
  to resolve the sentinel string; behaviour for unknown class FQNs
  is unchanged (`new ($action->effectClass)()`).

### Notes
- Existing custom `Effect` classes continue to dispatch through the
  fallback branch. No public method signature changed.

## [0.1.0] — 2026-05-19

First public release. L2 runtime: the Invoker chain and its co-engines
that turn an Action FQN into a persisted state transition + audit trail.

### Added
- **`Invoker`** — implements the 5-step chain (RFC-005 §3 / RFC-001 §A-1.4):
  Tenant check → Policy chain → Workflow guard → Effect → Audit.
- **`PolicyEngine`** — short-circuit ALLOW/DENY evaluator over an Action's
  declared `policies[]`. Default policy is `RoleRequired(roles[])`.
- **`WorkflowRuntime`** — per-Workflow source-state selection (RFC-006
  Amendment-01). For each Workflow attached to an Action, scans its
  transitions for the unique applicable source-state and routes accordingly;
  raises `WorkflowStateMismatch` if no transition applies.
- **`TransitionSetIndex`** — pre-compiles all Workflow transitions into an
  O(1) lookup keyed by `(workflowFqn, sourceState)` (RFC-006 §5).
- **`EffectDispatcher`** — invokes the Effect implementation declared by
  the Action; passes an `EffectContext` bound to `(Tenant, TransactionHandle, Actor, Inputs)`.
- **`CreateEffect`** — built-in Effect for `Action.creates(entity)`.
- **`TransitionEffect`** — built-in Effect for `Action.transitions(workflow)`.
- **`DefaultAuditor`** — wraps any `AuditSink` with a `SequenceCounter` for
  per-tenant monotonic audit ordering.
- **`SequenceCounter`** — yields per-tenant monotonic 64-bit sequences,
  re-bound on each `Invoker::invoke` call.
- **`ProjectionRenderer`** — executes a `Projection` and returns the RFC-004
  ViewSchema wire format (`{schemaVersion, targetProfile, metadata, fields, actions, data}`).

### Tested
- 36 PHP assertions in upstream playground including workflow gate,
  optimistic-lock conflict, cross-tenant rejection, DSL parity,
  RFC-011 §11 KPI deltas.

### Dependencies
- PHP ≥ 8.3
- `ausus/kernel` 0.1.*

### License
MIT — see `LICENSE`.

[0.1.0]: https://github.com/ausus-framework/ausus/releases/tag/runtime-default-v0.1.0
