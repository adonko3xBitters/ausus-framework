# Error Taxonomy & Failure Semantics — AUSUS v0.1

**Status:** ratified for v0.1.x · last validated 2026-05-19
**Companion documents:**
[`SEMVER-CONTRACT.md`](SEMVER-CONTRACT.md),
[`API-GOVERNANCE.md`](API-GOVERNANCE.md),
[`L4-API-DESIGN.md`](L4-API-DESIGN.md)
**Evidence script:** [`scripts/failure-semantics.sh`](../scripts/failure-semantics.sh) — 16 live HTTP probes, all green (0 stack-trace leaks, 0 InternalError fallthroughs).

This document is the **public failure-semantics contract** for AUSUS
v0.1. Every error a consumer can observe — at the PHP level via a
catchable exception, at the HTTP level via an `error.kind` string, at
the React level via `useAction`'s `lastError` — is listed here with:

- stable **type** (PHP class name + module-relative namespace)
- stable **message** prefix pattern
- stable **category** (compile / runtime / persistence / transport / renderer)
- stable **HTTP mapping** (status + `error.kind` string)
- **retryability** (idempotent retry? after a refetch? never?)
- **fatality** (transaction rolled back? renderer crash contained?)
- **renderer behavior** (`useAction.lastError`, `useViewSchema.error`)

---

## 0. Promises (the floor)

For every error listed in §3:

1. The PHP exception **class** is stable through v0.1.x (`SEMVER-CONTRACT §1.1`).
2. The HTTP `error.kind` string is stable through v0.1.x.
3. The error envelope shape `{ok:false, error:{kind, message}}` is stable.
4. **No stack traces** are returned in HTTP responses — only message + kind.
5. **No silent fallbacks** — every adversarial input either succeeds or surfaces a typed kind. There is no `error.kind: "InternalError"` for any consumer-reachable scenario in the live probe.
6. **Audit trails** continue to write on every transactional failure — `EffectFailed` does NOT swallow the audit row.

The 16-probe run in `scripts/failure-semantics.sh` enforces (4) and
(5) — any future regression (a new fall-through to `InternalError`, a
new code path that leaks `/Users/.../packages/...php:NNN` into a JSON
body) trips a non-zero exit and a one-line summary diff.

---

## 1. Forbidden patterns (audited & cleared)

The following patterns are explicitly **forbidden** in framework code.
This pass audited every call site and removed each remaining instance.

| Pattern | Audit result | Replacement |
|---|---|---|
| `throw new \RuntimeException("KindPrefix: …")` | **0 occurrences** post-pass (was 24 pre-pass) | typed `AususError` subclass |
| `throw new \LogicException(…)` | 0 — never used | typed `AususError` subclass |
| Silent `catch (\Throwable) { /* ignore */ }` | **3 documented exceptions:** Policy fail-closed (RFC-005 §5), rollback cleanup, AuditEmissionFailed wrapping — all called out below in §6 | n/a — these are explicit fail-closed invariants |
| Stack-trace leakage in HTTP body | **0** — `ErrorMapper` emits `{kind, message}` only | n/a |
| Generic `'InternalError'` for known cases | **0** — every kernel exception is mapped explicitly | typed `error.kind` |
| Renderer crash escaping `renderToString` | **0** — defensive coercion in `ListView`/`DetailView` (hardening §R-03/R-14) | typed empty-state fallback |

---

## 2. Categories

Every error belongs to exactly one of these categories:

| Category | Source layer | When it fires |
|---|---|---|
| **compile**     | `ausus/kernel` Compiler | At `Compiler::compile($plugins)` — plugin descriptor invariants |
| **runtime**     | `ausus/runtime-default` | At `Invoker::invoke` — Policy / Workflow / Effect / Audit chain |
| **persistence** | `ausus/persistence-sql` | At `Repository::find`/`create`/`update` |
| **transport**   | `ausus/api-http` Router  | Before/around the runtime chain — HTTP-shape validation |
| **renderer**    | `@ausus/renderer-react`  | In `useViewSchema` / `useAction` / view components |

---

## 3. Full taxonomy

### 3.1 STABLE PUBLIC catchable exceptions

| PHP class | Category | HTTP | `error.kind` | Retryable? | Fatal to tx? | Renderer surface |
|---|---|---|---|---|---|---|
| `Ausus\AususError`           | (base)        | n/a | n/a | — | — | — |
| `Ausus\MalformedDescriptor`  | compile       | **400** | `MalformedDescriptor`         | no — fix plugin | n/a (compile-time) | n/a |
| `Ausus\DuplicateRegistration`| compile       | **500** | `DuplicateRegistration`       | no — fix plugin set | n/a | n/a |
| `Ausus\DanglingReference`    | compile       | **500** | `DanglingReference`           | no — fix plugin | n/a | n/a |
| `Ausus\WorkflowCoherence`    | compile       | **500** | `WorkflowCoherence`           | no — fix plugin | n/a | n/a |
| `Ausus\UnknownAction`        | runtime/transport | **404** | `UnknownAction` / `ActionNotFound`* | no | no | `lastError.kind` |
| `Ausus\UnknownEntity`        | persistence   | **404** | `UnknownEntity`               | no | yes | `lastError.kind` |
| `Ausus\UnknownPolicy`        | runtime       | **404** | `UnknownPolicy`               | no — fix plugin | n/a | rare |
| `Ausus\UnknownProjection`    | runtime       | **404** | `UnknownProjection` / `ProjectionNotFound`* | no | n/a | `useViewSchema.error` |
| `Ausus\UnknownField`         | persistence   | **422** | `UnknownField`                | no — fix inputs | yes | `lastError.kind` |
| `Ausus\FieldRequired`        | persistence   | **422** | `FieldRequired`               | no — fix inputs | yes | `lastError.kind` |
| `Ausus\NotFound`             | persistence   | **404** | `NotFound`                    | no | yes | `lastError.kind` |
| `Ausus\TenantBoundaryViolation` | runtime    | **403** | `TenantBoundaryViolation`     | no | yes | `lastError.kind` |
| `Ausus\PolicyDenied`         | runtime       | **403** | `PolicyDenied`                | only with different actor | n/a | `lastError.kind` |
| `Ausus\PolicySubjectRequired`| runtime       | **400** | `PolicySubjectRequired`       | yes — supply subject | n/a | `lastError.kind` |
| `Ausus\WorkflowSubjectRequired` | runtime    | **400** | `WorkflowSubjectRequired`     | yes — supply subject | n/a | `lastError.kind` |
| `Ausus\WorkflowSubjectNotFound` | runtime    | **404** | `WorkflowSubjectNotFound`     | only after refetch | no — read-only | `lastError.kind` |
| `Ausus\WorkflowStateMismatch`   | runtime    | **409** | `WorkflowStateMismatch`       | **YES after refetch** | yes | `lastError.kind` |
| `Ausus\WorkflowAmbiguousTransition` | runtime | **409** | `WorkflowAmbiguousTransition` | no — fix workflow declarations | yes | `lastError.kind` |
| `Ausus\ConcurrencyConflict`  | persistence   | **409** | `ConcurrencyConflict`         | **YES after refetch** | yes | `lastError.kind` |
| `Ausus\EffectFailed`         | runtime       | **500** | `EffectFailed`                | no — see cause | yes | `lastError.kind` |
| `Ausus\AuditEmissionFailed`  | runtime       | **500** | `AuditEmissionFailed`         | rare — see RFC-007 amend-01 | yes | `lastError.kind` |
| `Ausus\WorkflowGuardDenied`  | runtime       | **403** | `PolicyDenied` (mapped same) | no | n/a | `lastError.kind` |
| `Ausus\Api\Http\BadRequest`  | transport     | **400** | `BadRequest`                  | no — fix request | n/a | `lastError.kind` |

\* The L4 Router emits the more-specific `ActionNotFound` /
`ProjectionNotFound` kinds **before** the runtime is invoked when the
FQN is unknown at the route level. The underlying `UnknownAction` /
`UnknownProjection` kernel exceptions surface unchanged if the FQN
appears valid but is then rejected by the resolver.

### 3.2 EXPERIMENTAL kinds (still subject to change in 0.1.x)

| `error.kind` | Where | Notes |
|---|---|---|
| `MalformedBody`   | api-http | future split of `BadRequest` for JSON-shape errors only |
| `InternalError`   | api-http | last-resort default; NEVER intentionally raised — its appearance in a probe is a regression |

### 3.3 INTERNAL exceptions (consumer not expected to catch)

These exist in the kernel for invariant-defense but should never reach
a consumer in a correct deployment. If they do, that's a framework
bug — file a security advisory (`SECURITY.md`).

| PHP class | Triggered when |
|---|---|
| `Ausus\ActorRequired`            | `Invoker.actor` is null at construct — programmer error |
| `Ausus\TenantContextRequired`    | `Invoker.tenant` is null at construct — programmer error |

These two are NOT mapped in `ErrorMapper` because they can't fire on a
correctly-built Router (the Router always passes both). If the
framework ever raises one in production, it's a bug, not a contract.

---

## 4. Retryability matrix

The retryability column above is summarised here. A consumer's
default policy can mechanically key off `error.kind`:

| Kind | Retry strategy |
|---|---|
| `WorkflowStateMismatch`    | **refetch then retry** — the row's state moved; reload the ViewSchema first |
| `ConcurrencyConflict`      | **refetch then retry** — another write won; reload to get fresh `_version` |
| `WorkflowSubjectNotFound`  | refetch — the row was deleted or never existed |
| `PolicyDenied`             | retry only with a different actor (different roles) |
| `PolicySubjectRequired`    | retry with the missing subject supplied |
| `WorkflowSubjectRequired`  | same |
| `BadRequest`               | fix the request; retry has no effect |
| `MalformedDescriptor`      | fix the plugin; no retry possible at runtime |
| `TenantBoundaryViolation`  | never retry — security violation, do NOT silently switch tenants |
| `UnknownAction` / `ActionNotFound` / `UnknownEntity` / `UnknownProjection` / `ProjectionNotFound` / `UnknownField` / `UnknownPolicy` / `FieldRequired` / `NotFound` | no retry — fix request or plugin |
| `EffectFailed`             | no automatic retry — read `error.message` for the cause and decide |
| `AuditEmissionFailed`      | no retry — surface to operator (sink may be unreachable) |
| `DuplicateRegistration` / `DanglingReference` / `WorkflowCoherence` / `WorkflowAmbiguousTransition` | no retry — fix plugin code |
| `InternalError` (should never appear) | file a bug |

### 4.1 Renderer-side retry helpers

`useAction` exposes `pending` + `lastError`. A consumer's UI pattern:

```ts
const { invoke, pending, lastError } = useAction("billing.invoice.cancel");
async function onCancel() {
  const result = await invoke({ subject, inputs: {} });
  if (!result.ok && result.error.kind === "WorkflowStateMismatch") {
    refetch();          // pull a fresh ViewSchema, then user can retry
    return;
  }
  if (!result.ok && result.error.kind === "ConcurrencyConflict") {
    refetch();          // same — the row's _version moved
    return;
  }
  // any other error.kind: show the message to the user; do NOT retry.
}
```

This pattern is the documented v0.1 default.

---

## 5. Fatal vs recoverable

| Layer | Operation | Failure | DB tx | Audit | Renderer tree |
|---|---|---|---|---|---|
| Invoker | Policy `Deny` | none — Policy short-circuited | n/a (tx not opened) | not emitted | no impact |
| Invoker | `TenantBoundaryViolation` | none — caught before tx | n/a | not emitted | `lastError` set |
| Invoker | `WorkflowStateMismatch` | runtime exception | **rolled back** | not emitted | `lastError` set |
| Invoker | `EffectFailed` (Effect threw) | EffectFailed wrapping the cause | **rolled back** | not emitted | `lastError` set |
| Invoker | `AuditEmissionFailed` | rare — primary sink rejected | **rolled back** | not committed | `lastError` set |
| Persistence | `ConcurrencyConflict` on update | typed exception | **rolled back** | not emitted | `lastError` set |
| Persistence | SQLite I/O error | wrapped as `EffectFailed` (cause = `\PDOException`) | **rolled back** | not emitted | `lastError` set |
| Renderer | malformed `ViewSchema` | none — defensive coercion (hardening §R-03/R-14) | n/a | n/a | renders empty state, no throw |
| Renderer | crashing child component | bubbles to React (no global ErrorBoundary in V0) | n/a | n/a | host app's boundary catches |
| Renderer | network error in `fetcher` | `lastError.kind = "NetworkError"` | n/a | n/a | retry button available |

> **Atomicity guarantee (RFC-007 amend-01).** For every failed
> `Invoker::invoke`, either both the entity write AND the audit row
> commit, or neither commits. There is no path where business state
> changes without an audit trail, and no path where an audit row
> exists for a write that didn't happen. This invariant is enforced
> by sharing one PDO transaction across `Effect::execute` and
> `Auditor::emit` — see `DatabaseAuditSink::writeInTransaction`.

---

## 6. Documented exceptions (explicit fail-closed sites)

Three places in the framework deliberately swallow errors. Each is
documented so a future contributor doesn't "fix" them.

| Site | Behavior | Why |
|---|---|---|
| `PolicyEngine::evaluateAction` catches `\Throwable` → returns `Deny` | A Policy that throws is treated as `Deny`, never `Permit` | **fail-closed** by RFC-005 §5; preserves authorization safety even if a custom Policy implementation crashes |
| `Invoker` rollback `try { driver->rollback(); } catch (\Throwable) {}` | Secondary rollback failure is silenced | The primary error already explains the failure; surfacing the rollback failure would mask the actual cause |
| `DefaultAuditor::emit` catches sink failure → wraps in `AuditEmissionFailed` | Re-throws as typed | The cause is preserved in `$causeError`; consumers see one wrapped exception, not the underlying PDO/IO error |

No other `catch (\Throwable)` exists in framework code. Verified by
`grep`; recorded for regression by `scripts/failure-semantics.sh`.

---

## 7. HTTP wire format (frozen)

Success:

```json
{ "ok": true, "outputs": { ... } }
```

Failure:

```json
{ "ok": false, "error": { "kind": "<TypedKind>", "message": "<human-readable>" } }
```

| Field | Stability |
|---|---|
| `ok` (boolean)               | frozen — discriminator |
| `error.kind` (string)        | frozen — drawn from §3 table |
| `error.message` (string)     | **prefix stable, suffix free-form** — the prefix matches the class name; the remainder explains the specific instance |

Examples (verbatim from the failure-semantics probe):

```
POST /actions/billing.invoice.cancel  (body=[1,2,3])
→ HTTP 400 BadRequest
   {"ok":false,"error":{"kind":"BadRequest",
    "message":"request body must be a JSON object (got: array)"}}

POST /actions/billing.invoice.ghost
→ HTTP 404 ActionNotFound
   {"ok":false,"error":{"kind":"ActionNotFound",
    "message":"action billing.invoice.ghost not found"}}

POST /actions/billing.invoice.cancel  (subject already CANCELLED)
→ HTTP 409 WorkflowStateMismatch
   {"ok":false,"error":{"kind":"WorkflowStateMismatch",
    "message":"workflow billing.invoice.lifecycle: current state 'CANCELLED'
              does not match any declared source [DRAFT,ISSUED] for action
              billing.invoice.cancel"}}
```

---

## 8. Probe coverage (this pass)

Run: `bash scripts/failure-semantics.sh`.

```
Total probes: 16    Stack-trace leaks: 0   InternalError fallthroughs: 0
```

| Probe family | Cases | All matched |
|---|---|---|
| Malformed JSON                  | 4  (`{not json}`, `null`, `"string"`, `[1,2,3]`) | ✅ |
| Oversized payload               | 1  (1 MiB body — V0 has no cap; accepted)         | ✅ |
| Invalid UTF-8                   | 1  (`\xC3\x28` in customer_name)                  | ✅ |
| Unsupported HTTP methods        | 3  (PUT, DELETE, PATCH)                           | ✅ |
| Unknown FQNs (route)            | 2  (ghost action, nope projection)                | ✅ |
| Workflow + concurrency          | 2  (1st cancel ok, 2nd → 409)                     | ✅ |
| Tenant boundary + bad subject   | 2  (cross-tenant, bogus ULID)                     | ✅ |
| Missing required header         | 1  (no X-Tenant-ID)                               | ✅ |
| Stack-trace leak detection      | scan over all bodies                              | ✅ 0 |
| InternalError fallthrough check | scan for `kind=InternalError`                     | ✅ 0 |

Out of probe scope (not regressible via HTTP) but covered elsewhere:
- **invalid schemaVersion** — covered by hardening probe R-13 in `apps/playground/web/hardening-trace.tsx` (renderer accepts; `useViewSchema` rejects).
- **corrupted persistence file** — covered by the `WorkflowSubjectNotFound` probe (mimics what a vanished row would do). Real disk corruption would surface as `EffectFailed` wrapping a `\PDOException`.
- **interrupted writes** — covered by §5 atomicity contract: PDO `rollback()` in the Invoker's `finally` path ensures partial state is never persisted. The probe doesn't kill the process mid-transaction (would crash `php -S`), but the rollback path is exercised by every `WorkflowStateMismatch` / `ConcurrencyConflict` probe.

---

## 9. Renderer-side error behavior (stable)

| Hook / component | Failure mode | Surfaces as |
|---|---|---|
| `useViewSchema(...)`          | network error / non-2xx HTTP | `error: { message }` returned; `loading=false`, `schema=null` |
| `useViewSchema(...)`          | `schema.schemaVersion` not `1.0.x` | `error: { message: "incompatible schemaVersion=…" }` |
| `useAction(actionFqn)`        | server returns `{ok:false, error}` | `lastError = { kind, message }` |
| `useAction(actionFqn)`        | network thrown (`fetch` rejection)  | `lastError = { kind: "NetworkError", message }` |
| `ListView` / `DetailView`      | malformed `schema.data` / `schema.metadata` / `schema.fields` | renders empty state — never throws (hardening §R-03/R-14) |
| `WorkflowBadge`                | unknown enum value | renders `ausus-badge--default` with the raw value |
| `FieldDisplay`                 | unknown `field.type` | falls through to default `<span className="ausus-cell">` |

`NetworkError` is the only renderer-only `error.kind` not produced by
the L4 server. Consumers must handle it explicitly (no automatic retry
in v0.1; documented as a deferred feature).

---

## 10. Determination

**GO** — failure semantics ratified for v0.1.x.

- ✓ Every consumer-reachable error has a stable type, message prefix, category, HTTP mapping, and renderer surface.
- ✓ 24 generic `\RuntimeException` throws replaced with typed `AususError` subclasses; 0 generic throws remain in framework code.
- ✓ 16-probe adversarial run: every probe matches its expected envelope; 0 `InternalError` fallthroughs; 0 stack-trace leaks.
- ✓ Atomicity guarantee preserved (entity + audit commit/rollback together).
- ✓ Fail-closed Policy semantics, rollback cleanup, and audit wrapping are the only documented silent catches.
- ✓ HTTP wire format frozen; error envelope shape part of the SemVer contract.

Any future regression — a new throw of `\RuntimeException` in framework
code, a new `error.kind` string not listed in §3, a stack-trace leak —
is caught by `scripts/failure-semantics.sh` and exits non-zero. The
script is wired into `scripts/ci.sh` (see step 12).
