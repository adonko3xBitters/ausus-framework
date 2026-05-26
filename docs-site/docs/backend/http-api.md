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

## Routes {#routes}

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/_health` | liveness probe; returns the graph hash |
| `GET` | `/projections/{fqn}` | render a projection to a [ViewSchema](../frontend/viewschema.md) |
| `POST` | `/actions/{fqn}` | invoke an action; returns an action result |
| `OPTIONS` | `*` | CORS preflight |

Paths are served under a configurable prefix (default `/api`).

A single request's path through the system:

![HTTP request lifecycle: an inbound request enters the Router, which dispatches to /_health (direct), /projections/{fqn} (via ProjectionRenderer) or /actions/{fqn} (via the Invoker pipeline); any kernel exception is caught and classified by the ErrorMapper into an HTTP status, and the final response is JSON with permissive CORS.](/img/diagrams/http-lifecycle.svg)

### `GET /projections/{fqn}` {#get-projectionsfqn}

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

### `POST /actions/{fqn}` {#post-actionsfqn}

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

## Request headers {#request-headers}

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

## Error responses {#error-responses}

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

## PSR interop {#psr-interop}

`Router` implements `Psr\Http\Server\RequestHandlerInterface` and takes
PSR-17 `ResponseFactoryInterface` / `StreamFactoryInterface` in its constructor.
It works with any PSR-7 implementation. A minimal `Emitter` is included for the
demo front controller; production deployments can swap in a fuller PSR-7
emitter.

## `Application::http()` — one-call entry point {#application-http}

For typical front controllers, you do not need to construct the `Router`
yourself. `Ausus\Application::http()` accepts a PSR-7 `ServerRequest`, lazily
builds a `Router` against the booted graph/driver/audit-sink **once**, and
returns the response:

```php
use Ausus\{Application, ApplicationConfig};
use Ausus\Api\Http\Emitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

$factory = new Psr17Factory();
$app = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')
            ->roles(['invoice.creator', 'invoice.issuer'])
            ->sqlite(__DIR__ . '/app.sqlite')
            ->psr17($factory)
    )
    ->register(new HelloInvoiceDsl())
    ->boot();

$creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
Emitter::emit($app->http($creator->fromGlobals()));
```

Notes, and **why**:

- **One `Router` per process.** `http()` caches the Router on first call and
  reuses it; the existing `$app->router(...)` factory is unchanged and still
  builds a fresh instance per call when you need a custom configuration.
- **PSR-17 factories.** `ApplicationConfig::psr17($factory)` is the simplest
  form (one object that implements both `ResponseFactoryInterface` and
  `StreamFactoryInterface`, like nyholm's). Split factories
  (`->responseFactory()`/`->streamFactory()`) are accepted if your library
  ships them separately. **If you supply none**, `http()` auto-instantiates
  `Nyholm\Psr7\Factory\Psr17Factory` when it is available on the autoloader;
  otherwise it throws a clear message naming the fix.
- **Tenant / actor behaviour is preserved.** `http()` does not bind a tenant
  or actor — the Router still reads `X-Tenant-ID` and `X-Actor-*` per request,
  exactly as before. The `Application`'s configured tenant/actor are used by
  `invoke()` / `run()`, not by HTTP.
- **Custom URL prefix.** `ApplicationConfig::apiPrefix('/v2')` mounts the
  routes under a different prefix.

## Current v0.1.0 limitations {#current-v010-limitations}

- **No authentication** (see the warning above) and CORS is wide open
  (`Access-Control-Allow-Origin: *`).
- The action and projection routes are the whole surface — there is no
  metadata/graph introspection endpoint beyond `/_health`.
- A missing `X-Actor-Roles` header produces a **roleless** actor — every
  action that declares `->requireRole(...)` returns `403 PolicyDenied`. An
  authenticated gateway in front of the Router is responsible for setting
  the header from the verified identity.

## Related {#related}

- [ViewSchema](../frontend/viewschema.md) — what `/projections/*` returns.
- [The React renderer](../frontend/react-renderer.md) — the client for this API.
- [Error Reference](../reference/errors.md) — the full exception taxonomy.
