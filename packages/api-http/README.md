# ausus/api-http

**L4 — HTTP API surface for the AUSUS metadata graph.**

Pure PSR-7/15. No framework dependency. Mounts as a single
`Psr\Http\Server\RequestHandlerInterface` in front of any PSR-7 server
(`react/http`, `swoole`, `roadrunner`, `php-fpm`, or `php -S`).

## What it serves

| Route | Purpose |
|---|---|
| `GET  /_health`                          | liveness probe + graph hash |
| `GET  /projections/{fqn}`                | RFC-004 ViewSchema with embedded data; optional `?subject=<id>` for DetailView |
| `POST /actions/{fqn}`                    | invoke an Action; returns `{ok, outputs}` or `{ok:false, error}` |
| `OPTIONS *`                              | CORS preflight |

The routes are **the** routes — no per-Entity controllers, no
hand-rolled DTOs. Every endpoint dispatches into the same Invoker chain
that the in-process kernel uses (RFC-005 §3).

## URL contract — frozen

| Header / param | Source | Purpose |
|---|---|---|
| `X-Tenant-ID`      | required, on `/projections/*` + `/actions/*` | tenant boundary |
| `X-Actor-Id`       | optional | stub-actor id (V0 only) |
| `X-Actor-Roles`    | optional | stub-actor roles, comma-separated (V0 only) |
| `?locale=…`        | query    | passed through to ProjectionRenderer |
| `?renderer=…`      | query    | renderer profile (e.g. `react.web.v1`) |
| `?subject=<id>`    | query    | DetailView subject identityHandle |
| body (POST)        | JSON     | `{ "subject": Reference \| null, "inputs": object }` |

## Error envelope

```json
{ "ok": false, "error": { "kind": "<TypedKind>", "message": "…" } }
```

| Exception                  | HTTP | `error.kind`           |
|---|---|---|
| `BadRequest`               | 400  | `BadRequest`           |
| `MalformedDescriptor`      | 400  | `MalformedDescriptor`  |
| `PolicyDeniedException`    | 403  | `PolicyDenied`         |
| `TenantBoundaryViolation`  | 403  | `TenantBoundaryViolation` |
| (not-found route / FQN)    | 404  | `NotFound` / `ProjectionNotFound` / `ActionNotFound` |
| `WorkflowStateMismatch`    | 409  | `WorkflowStateMismatch` |
| `ConcurrencyConflict`      | 409  | `ConcurrencyConflict`  |
| `EffectFailure` / unknown  | 500  | `EffectFailure` / `InternalError` |

## Usage

```php
use Ausus\Api\Http\{Router, Emitter};
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

$factory = new Psr17Factory();
$creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
$router  = new Router($graph, $driver, $auditSink, $factory, $factory);

Emitter::emit($router->handle($creator->fromGlobals()));
```

`$graph` comes from `ausus/kernel`'s `Compiler`, `$driver` from
`ausus/persistence-sql`, `$auditSink` likewise. The Router builds a
fresh `Invoker` + `ProjectionRenderer` per request using the
per-request tenant + actor; the policy engine, workflow runtime, effect
dispatcher, and sequence counter are shared across requests.

## Security notes (V0)

This package implements the **transport layer** only. It does NOT
implement:

- Authentication — the `X-Actor-*` headers are a stub for the V0 browser
  demo; replace with a real auth middleware (JWT, session, OAuth) in
  front of the Router for any non-local deployment.
- Rate limiting / DOS protection — front with a reverse proxy.
- CSRF — the renderer's flow is API-only (no cookies, JSON bodies).
  Production deployments using cookies must add CSRF tokens.
- TLS — terminate TLS at your reverse proxy.

The CORS headers are permissive (`Access-Control-Allow-Origin: *`) for
the V0 demo. Production must restrict to the allowed origin list.

## License

MIT — see `LICENSE`.
