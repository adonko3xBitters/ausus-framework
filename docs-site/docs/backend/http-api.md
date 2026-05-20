---
id: http-api
title: The HTTP API
sidebar_label: The HTTP API
description: The PSR-7/15 HTTP surface for projections and actions.
---

# The HTTP API

`ausus/api-http` (layer L4) exposes the runtime over HTTP. It is a single
PSR-15 request handler — `Router` — that serves [projections](../concepts/projections.md)
and dispatches [actions](../concepts/entities-fields-actions.md#actions).

## Routes

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/_health` | liveness probe; returns the graph hash |
| `GET` | `/projections/{fqn}` | render a projection to a [ViewSchema](../frontend/viewschema.md) |
| `POST` | `/actions/{fqn}` | invoke an action; returns an action result |
| `OPTIONS` | `*` | CORS preflight |

Paths are served under a configurable prefix (default `/api`).

### `GET /projections/{fqn}`

Query parameters:

- `subject` — an identity handle. If present, the projection renders in
  **detail** form for that record; if absent, it renders in **list** form.
- `locale`, `renderer`, `acceptSchemaVersions` — accepted and reserved; the
  v0.1.0 renderer emits `locale: en-US`, `targetProfile: react.web.v1`,
  `schemaVersion: 1.0.0`.

```bash
curl -H 'X-Tenant-ID: acme' \
  'http://localhost:8080/api/projections/billing.invoice.summary'
```

### `POST /actions/{fqn}`

Body — a JSON object:

```json
{
  "subject": { "tenantId": "acme", "entityFqn": "billing.invoice", "identityHandle": "..." },
  "inputs":  { "number": "INV-1", "customer_name": "ACME", "amount": { "amount": "10.00", "currency": "USD" } }
}
```

`subject` is `null` for create actions. The response envelope is
`{ "ok": true, "outputs": { ... } }` on success.

```bash
curl -X POST -H 'X-Tenant-ID: acme' -H 'Content-Type: application/json' \
  -d '{"subject":null,"inputs":{"number":"INV-1","customer_name":"ACME","amount":{"amount":"10.00","currency":"USD"}}}' \
  http://localhost:8080/api/actions/billing.invoice.create
```

## Request headers

| Header | Required | Meaning |
|---|---|---|
| `X-Tenant-ID` | yes (on `/projections/*` and `/actions/*`) | the active tenant |
| `X-Actor-Id` | no | the actor id (defaults to `anon`) |
| `X-Actor-Roles` | no | comma-separated role list |

The router builds a `StubActor` from `X-Actor-Id` and `X-Actor-Roles`.

:::danger No authentication — put a guard in front
The HTTP API trusts `X-Tenant-ID`, `X-Actor-Id`, and `X-Actor-Roles` exactly
as sent. There is **no authentication** in v0.1.0 — a caller can claim any
tenant and any roles. You **must** place a real authentication and
authorization layer in front of this handler before exposing it. Treat
`ausus/api-http` as an internal surface until you have done so.
:::

## Error responses

`ErrorMapper` maps the kernel exception taxonomy to HTTP status codes:

| Condition | Status | `error.kind` |
|---|---|---|
| Bad request (missing header, bad body) | 400 | `BadRequest` |
| Policy denied | 403 | `PolicyDenied` |
| Tenant boundary violation | 403 | `TenantBoundaryViolation` |
| Workflow state mismatch | 409 | `WorkflowStateMismatch` |
| Concurrency conflict | 409 | `ConcurrencyConflict` |
| Unmapped / effect failure | 500 | `InternalError` / `EffectFailure` |

The error envelope is `{ "ok": false, "error": { "kind": "...", "message": "..." } }`.

## PSR interop

`Router` implements `Psr\Http\Server\RequestHandlerInterface` and takes
PSR-17 `ResponseFactoryInterface` / `StreamFactoryInterface` in its constructor.
It works with any PSR-7 implementation. A minimal `Emitter` is included for the
demo front controller; production deployments can swap in a fuller PSR-7
emitter.

## Current v0.1.0 limitations

- **No authentication** (see the warning above) and CORS is wide open
  (`Access-Control-Allow-Origin: *`).
- The action and projection routes are the whole surface — there is no
  metadata/graph introspection endpoint beyond `/_health`.
- The `StubActor` role default, when `X-Actor-Roles` is omitted, is the
  HelloInvoice role set — convenient for the demo, not a production default.

## Related

- [ViewSchema](../frontend/viewschema.md) — what `/projections/*` returns.
- [The React renderer](../frontend/react-renderer.md) — the client for this API.
- [Error Reference](../reference/errors.md) — the full exception taxonomy.
