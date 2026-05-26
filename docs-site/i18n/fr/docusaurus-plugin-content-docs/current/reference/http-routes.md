---
id: http-routes
title: HTTP routes reference
sidebar_label: HTTP routes
description: Every AUSUS HTTP route — method, path, required headers, request body, response envelope, and the full status-code table — for non-React consumers.
---

# HTTP routes reference

A precise reference for the AUSUS HTTP surface. Use this page when
building a non-React client (mobile, Vue, server-to-server, CLI). The
narrative version lives at [Backend · HTTP API](../backend/http-api.md);
this page is the lookup table.

The Router is a single PSR-15 `RequestHandlerInterface` shipped in
`ausus/api-http`. It serves three resource routes under a configurable
prefix (default `/api`) plus CORS preflight.

## Route table {#route-table}

| Method | Path | Purpose | Headers required |
|---|---|---|---|
| `GET` | `/_health` | Liveness probe; returns the compiled-graph hash. | — |
| `GET` | `/projections/{fqn}` | Render a projection to a [ViewSchema](view-schema-wire.md). | `X-Tenant-ID` |
| `POST` | `/actions/{fqn}` | Invoke an action through the full runtime chain. | `X-Tenant-ID` (+ `X-Actor-Roles` for any action with `requireRole`) |
| `OPTIONS` | `*` | CORS preflight; always `204 No Content` with `Access-Control-Allow-*`. | — |

`{fqn}` is URL-decoded; pass the dotted FQN directly
(`billing.invoice.summary`, not URL-encoded).

The prefix is set by the Router constructor's last argument (default
`'/api'`) or via [`ApplicationConfig::apiPrefix('/v2')`](application.md#applicationconfig).

## Request headers {#request-headers}

| Header | Required | Format | Behaviour |
|---|---|---|---|
| `X-Tenant-ID` | yes on `/projections/*` and `/actions/*` | non-empty string | The active tenant for the request. Missing → `400 BadRequest`. |
| `X-Actor-Id` | no | string | The acting actor id. Default `'anon'` when absent. |
| `X-Actor-Roles` | no | comma-separated role names, e.g. `invoice.creator,invoice.viewer` | Whitespace around each role is trimmed; empty entries are dropped. **Missing or empty → roleless actor → every action with `requireRole(...)` returns `403 PolicyDenied`.** No fallback role set is substituted. |
| `Content-Type` | yes on `POST /actions/*` | `application/json` | Other values are parsed best-effort; a body that does not JSON-decode to an object raises `400 BadRequest`. |

## `GET /_health` {#health}

```bash
curl -s http://localhost:8080/api/_health
```

Always returns `200 OK` with:

```json
{ "ok": true, "service": "ausus/api-http", "graphHash": "7c1e9b3a…" }
```

The `graphHash` is the SHA-256 of the canonical `MetadataGraph` form;
two processes serving identical plugin sets return the same hash. Useful
as a rolling-deploy sanity check.

## `GET /projections/{fqn}` {#get-projection}

### Query parameters

| Name | Required | Effect |
|---|---|---|
| `subject` | no | When present, the projection renders in **detail** form for the entity whose identity is the value. When absent, **list** form. |
| `locale` | no | Reserved. v0.1.x emits `locale: 'en-US'` regardless. |
| `renderer` | no | Reserved. v0.1.x targets `react.web.v1`. |
| `acceptSchemaVersions` | no | Reserved. v0.1.x emits `schemaVersion: '1.0.0'`. |

### Examples

List view:

```bash
curl -s -H 'X-Tenant-ID: acme' \
  'http://localhost:8080/api/projections/billing.invoice.summary'
```

Detail view:

```bash
curl -s -H 'X-Tenant-ID: acme' \
  'http://localhost:8080/api/projections/billing.invoice.detail?subject=01J7HG3WC0D3K…'
```

### Response

`200 OK` with the full [ViewSchema JSON document](view-schema-wire.md):

```json
{
  "schemaVersion": "1.0.0",
  "targetProfile": "react.web.v1",
  "metadata":   { "projection": "billing.invoice.summary", "entity": "billing.invoice",
                  "tenant": "acme", "locale": "en-US", "generatedAt": "2026-05-26T20:14:00Z" },
  "fields":   [ /* FieldDescriptor[] */ ],
  "actions":  [ /* ActionDescriptor[] — inputs[] always emitted, initialValues on update + detail */ ],
  "filters":  [],
  "data":     { "items": [ /* rows */ ], "pagination": { "nextCursor": null, "pageSize": 3 } }
}
```

Or `{ "item": { … } | null }` in the `data` slot for detail.

Projection routes do not invoke a policy in v0.1.x — they return the
full row set for the tenant. Plan authorisation outside the Router or
narrow projections per-tenant.

## `POST /actions/{fqn}` {#post-action}

### Request body

```json
{
  "subject": { "tenantId": "acme", "entityFqn": "billing.invoice",
               "identityHandle": "01J…" } | null,
  "inputs":  { /* per-action key/value map */ }
}
```

- `subject` is `null` for `create` actions; an object for `transition`
  and `update` actions. The `tenantId` MUST match `X-Tenant-ID` —
  mismatch raises `403 TenantBoundaryViolation`.
- `inputs` carries the action's payload. Shape rules:
  - `string` / `enum` → string;
  - `integer` → number;
  - `datetime` → ISO-8601 string;
  - `money` → `{ "amount": "12.34", "currency": "USD" }`;
  - nullable fields → `null` is accepted and stored as SQL NULL.
  See [ViewSchema wire reference](view-schema-wire.md) for the
  per-input metadata that drives this.

### Examples

Create:

```bash
curl -s -X POST http://localhost:8080/api/actions/billing.invoice.create \
  -H 'X-Tenant-ID: acme' \
  -H 'X-Actor-Roles: invoice.creator' \
  -H 'Content-Type: application/json' \
  -d '{
        "subject": null,
        "inputs": {
          "number": "INV-2026-001",
          "customer_name": "ACME",
          "amount": { "amount": "1500.00", "currency": "USD" }
        }
      }'
```

Transition:

```bash
curl -s -X POST http://localhost:8080/api/actions/billing.invoice.issue \
  -H 'X-Tenant-ID: acme' \
  -H 'X-Actor-Roles: invoice.issuer' \
  -H 'Content-Type: application/json' \
  -d '{"subject":{"tenantId":"acme","entityFqn":"billing.invoice","identityHandle":"01J…"},"inputs":{}}'
```

Update (partial PATCH):

```bash
curl -s -X POST http://localhost:8080/api/actions/tracker.issue.rename \
  -H 'X-Tenant-ID: acme' \
  -H 'X-Actor-Roles: tracker.member' \
  -H 'Content-Type: application/json' \
  -d '{"subject":{"tenantId":"acme","entityFqn":"tracker.issue","identityHandle":"01J…"},
       "inputs":{"title":"new title"}}'
```

### Success response

```json
{ "ok": true, "outputs": { /* effect outputs */ } }
```

`outputs` content depends on the action's effect:

| Effect | `outputs` keys |
|---|---|
| `create` | new entity `id` + every field the create payload included (or that the workflow seeded). |
| `transition` | the state field's new value, every stamped field, `_version`. |
| `update` | every patched field + `_version` (PATCH semantics — untouched fields are **not** echoed). |

### Error response

```json
{ "ok": false, "error": { "kind": "<KindName>", "message": "<human-readable>" } }
```

## Status code table {#status-codes}

`ErrorMapper` classifies every thrown exception by its short PHP class
name. The mapping is the same on every route.

| Status | `error.kind` | Cause |
|---|---|---|
| `200 OK` | — | Successful response. |
| `204 No Content` | — | `OPTIONS *` preflight only. |
| `400 BadRequest` | `BadRequest` | Missing required header, malformed JSON body, missing `subject` on a transition/update action. |
| `400` | `PolicySubjectRequired` | Action declared with `subjectRequired: true` invoked with `subject: null`. |
| `400` | `ActorRequired` / `TenantContextRequired` | Declared but never thrown by v0.1.x runtime — reserved. |
| `403 Forbidden` | `PolicyDenied` | The actor does not hold a role the action's policy requires. |
| `403` | `TenantBoundaryViolation` | The `subject.tenantId` differs from the active tenant. |
| `403` | `WorkflowGuardDenied` | Declared, reserved; not thrown by v0.1.x. |
| `404 Not Found` | `UnknownAction` | The action FQN does not exist in the graph. |
| `404` | `NotFound` | An update or transition referenced a subject that does not exist. |
| `404` | `WorkflowSubjectNotFound` | Workflow guard could not find the subject mid-evaluation. |
| `404` | `ProjectionNotFound` | The projection FQN does not exist in the graph. |
| `409 Conflict` | `WorkflowStateMismatch` | Transition source does not match the entity's current state. |
| `409` | `ConcurrencyConflict` | Optimistic lock failed — the row was modified since the read. |
| `500 Internal Server Error` | `EffectFailed` | A custom effect threw an exception not in the closed taxonomy. The wrapped cause is logged but not exposed. |
| `500` | `AuditEmissionFailed` | Audit-sink write failed inside the transaction. |
| `500` | `InternalError` | Any other unrecognised throwable. |

## CORS {#cors}

Every response — success or error — carries:

```
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, POST, OPTIONS
Access-Control-Allow-Headers: Content-Type, X-Tenant-ID, X-Actor-Id, X-Actor-Roles
Access-Control-Max-Age: 600
```

`OPTIONS *` returns `204 No Content` with the same headers. Narrow the
origin (and verify request signatures) in front of the Router — see
[Operations · Authenticated gateway](../operations/authenticated-gateway.md).

## What the Router does **not** do {#not-implemented}

- No authentication. No JWT verification, no session lookup. Treat
  `X-Tenant-ID` / `X-Actor-*` as untrusted unless an authenticated
  gateway sets them.
- No rate limiting.
- No CSRF protection (POSTs are JSON and require `Content-Type:
  application/json` — adequate against form-style CSRF but not a
  general-purpose mitigation).
- No request validation beyond the kernel taxonomy. Custom validation
  belongs in a custom `Policy` or `Effect`.
- No projection-level authorisation enforcement (v0.1.x limitation).

## Related {#related}

- [ViewSchema wire reference](view-schema-wire.md) — what
  `/projections/*` returns, field by field.
- [Application & ApplicationConfig reference](application.md) — how
  `Application::http()` wraps the Router.
- [Error reference](errors.md) — the kernel exception taxonomy.
- [Operations · Authenticated gateway](../operations/authenticated-gateway.md) — production posture.
