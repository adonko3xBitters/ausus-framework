# Changelog — ausus/runtime-default

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

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
