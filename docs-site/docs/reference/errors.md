---
id: errors
title: Error Reference
sidebar_label: Error Reference
description: The AUSUS kernel exception taxonomy and HTTP mapping.
---

# Error Reference

AUSUS uses a small, explicit exception taxonomy. Every kernel error extends one
base class, so you can catch broadly or narrowly.

## The base class {#the-base-class}

```php
class AususError extends \RuntimeException {}
```

Every error below extends `Ausus\AususError`. Catching `AususError` catches all
of them; the runtime distinguishes an AUSUS error from an unexpected one (an
unexpected error thrown inside an effect is wrapped as `EffectFailed`).

## Kernel exceptions {#kernel-exceptions}

| Exception | Raised when |
|---|---|
| `UnknownAction` | `invoke()` is given an action FQN not in the graph |
| `PolicySubjectRequired` | an action needs a subject but none was passed |
| `ActorRequired` | an actor is required but absent |
| `TenantContextRequired` | a tenant context is required but absent |
| `TenantBoundaryViolation` | a `Reference`'s tenant differs from the active tenant |
| `PolicyDenied` | the policy returned a non-`Permit` decision |
| `WorkflowStateMismatch` | no transition allows the action from the current state |
| `WorkflowSubjectNotFound` | the workflow subject record does not exist |
| `WorkflowGuardDenied` | a workflow guard refused the transition |
| `EffectFailed` | an effect threw; carries the underlying cause |
| `ConcurrencyConflict` | an `update` was attempted with a stale `_version` |
| `NotFound` | a referenced record does not exist |
| `AuditEmissionFailed` | the audit sink rejected the audit entry |

`ConcurrencyConflict` and `NotFound` carry the offending `Reference`;
`ConcurrencyConflict` also carries the `expected` and `actual` versions.

## HTTP mapping {#http-mapping}

`ausus/api-http` adds one transport-level exception, `BadRequest`, and maps the
taxonomy to HTTP status codes via `ErrorMapper`:

| Exception | HTTP status | `error.kind` |
|---|---|---|
| `BadRequest` | 400 | `BadRequest` |
| `MalformedDescriptor` | 400 | `MalformedDescriptor` |
| `PolicyDenied` | 403 | `PolicyDenied` |
| `TenantBoundaryViolation` | 403 | `TenantBoundaryViolation` |
| `WorkflowStateMismatch` | 409 | `WorkflowStateMismatch` |
| `ConcurrencyConflict` | 409 | `ConcurrencyConflict` |
| `EffectFailure` | 500 | `EffectFailure` |
| anything else | 500 | `InternalError` |

The HTTP error envelope:

```json
{ "ok": false, "error": { "kind": "WorkflowStateMismatch", "message": "..." } }
```

## Catching errors {#catching-errors}

```php
use Ausus\{PolicyDenied, WorkflowStateMismatch, ConcurrencyConflict, AususError};

try {
    $invoker->invoke('billing.invoice.issue', $ref, []);
} catch (PolicyDenied $e) {
    // actor lacks the required role
} catch (WorkflowStateMismatch $e) {
    // the invoice is not in a state that allows `issue`
} catch (ConcurrencyConflict $e) {
    // someone else changed the record — re-read and retry
} catch (AususError $e) {
    // any other AUSUS error
}
```

## Current v0.1.0 limitations {#current-v010-limitations}

- The taxonomy is intentionally minimal. It is described in the source as a
  "V0 minimal closed-ish taxonomy" — later versions may add finer-grained
  errors.
- Errors carry a message and (for some) a `Reference`; there is no structured
  error-code catalogue or i18n of error messages in v0.1.0.

## Related {#related}

- [The Runtime](../backend/runtime.md) — where most errors originate.
- [The HTTP API](../backend/http-api.md) — the HTTP status mapping.
- [Policies](../concepts/policies.md) · [Workflows](../concepts/workflows.md)
