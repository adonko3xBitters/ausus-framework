# L4 — `ausus/api-http` design doc

Operational counterpart to `packages/api-http/`. Captures the routing
contract, payload schemas, error taxonomy, security stance, and the
integration evidence produced by the first real implementation pass.

---

## 0. Scope

The L4 layer is the **HTTP API surface** for the AUSUS metadata graph.
It serves three real needs:

1. Let `@ausus/renderer-react` (L6) fetch ViewSchemas and invoke Actions
   over plain HTTP, without any mock backend.
2. Sit cleanly under any PSR-7 server (`php -S`, `php-fpm`, `swoole`,
   `roadrunner`, `react/http`) — no framework dependency.
3. Map the kernel exception taxonomy (RFC-001 §A-1.5, RFC-006, RFC-007)
   onto HTTP status codes plus a typed JSON envelope.

Out of scope: GraphQL, server-sent events, websockets, multi-tenant
authentication, rate limiting. Each of these gets its own RFC.

## 1. Routes (frozen)

| Method | Path | Purpose | Required headers | Body |
|---|---|---|---|---|
| `GET`     | `/_health`            | liveness + graph hash | — | — |
| `GET`     | `/projections/{fqn}`  | RFC-004 ViewSchema    | `X-Tenant-ID` | — |
| `POST`    | `/actions/{fqn}`      | invoke Action         | `X-Tenant-ID`, `Content-Type: application/json` | `{subject, inputs}` |
| `OPTIONS` | `*`                   | CORS preflight        | — | — |

Path prefix is configurable via the Router constructor (default `/api`).
`{fqn}` is URL-decoded by the Router. Routes that don't match return
`404 NotFound`.

### Query parameters on `GET /projections/{fqn}`

| Param | Required | Purpose |
|---|---|---|
| `subject`               | for DetailView | identityHandle to render |
| `locale`                | no | locale resolution hint (default falls back to projection's declared default) |
| `renderer`              | no | renderer profile (`react.web.v1` by default) |
| `acceptSchemaVersions`  | no | comma-separated semver constraints |

The renderer's `useViewSchema` hook sets all three by default
(see `renderer/react/src/hooks.tsx`).

## 2. Wire payloads

### 2.1 GET /projections/{fqn} response (ViewSchema, RFC-004)

```json
{
  "schemaVersion": "1.0.0",
  "targetProfile": "react.web.v1",
  "metadata": {
    "projection": "billing.invoice.summary",
    "tenant":     "acme",
    "entity":     "billing.invoice"
  },
  "fields":  [ { "name": "id", "label": "ID", "type": "string" }, ... ],
  "actions": [ { "fqn": "billing.invoice.issue", "label": "Issue", "subjectRequired": true }, ... ],
  "filters": [],
  "data": {
    "items": [ { "id": "01KRZ...", "number": "INV-2026-001", "status": "DRAFT", ... } ],
    "nextCursor": null
  }
}
```

DetailView returns `data.item` instead of `data.items`:

```json
"data": { "item": { "id": "...", "status": "ISSUED", ... } }
```

### 2.2 POST /actions/{fqn} request

```json
{
  "subject": {
    "tenantId":       "acme",
    "entityFqn":      "billing.invoice",
    "identityHandle": "01KRZ..."
  },
  "inputs": { }
}
```

`subject` is `null` for `Action.creates()`-style actions (no prior
entity). For transitions/updates it carries the Reference exactly as
the renderer received it from the previous projection fetch.

### 2.3 Response — success envelope

```json
{ "ok": true, "outputs": { "id": "01KRZ...", "status": "ISSUED" } }
```

### 2.4 Response — error envelope

```json
{ "ok": false, "error": { "kind": "WorkflowStateMismatch", "message": "..." } }
```

Always paired with a non-2xx HTTP status (see §4).

## 3. Headers

| Header | Direction | Required | Purpose |
|---|---|---|---|
| `X-Tenant-ID`             | client → server | yes on `/projections`, `/actions` | tenant boundary |
| `X-Actor-Id`              | client → server | no (V0 stub) | actor identity |
| `X-Actor-Roles`           | client → server | no (V0 stub) | comma-separated role names |
| `Content-Type: application/json` | client → server | yes on POST | body parse |
| `Access-Control-Allow-Origin: *` | server → client | always | CORS for browser consumers |
| `Access-Control-Allow-Methods`   | server → client | always | `GET, POST, OPTIONS` |
| `Access-Control-Allow-Headers`   | server → client | always | `Content-Type, X-Tenant-ID, X-Actor-Id, X-Actor-Roles` |
| `Access-Control-Max-Age: 600`    | server → client | always | preflight cache |

## 4. Error taxonomy → HTTP status

Mapped by `ErrorMapper::classify()` in `packages/api-http/src/api.php`.

| Kernel exception           | HTTP | `error.kind`               | Cause |
|---|---|---|---|
| `BadRequest`               | 400  | `BadRequest`               | missing `X-Tenant-ID`, malformed body, unparseable JSON |
| `MalformedDescriptor`      | 400  | `MalformedDescriptor`      | plugin descriptor invariant violated |
| `PolicyDeniedException`    | 403  | `PolicyDenied`             | Policy chain DENY |
| `TenantBoundaryViolation`  | 403  | `TenantBoundaryViolation`  | cross-tenant Reference |
| (route not found)          | 404  | `NotFound`                 | path mismatch |
| (projection not found)     | 404  | `ProjectionNotFound`       | `/projections/{unknownFqn}` |
| (action not found)         | 404  | `ActionNotFound`           | `/actions/{unknownFqn}` |
| `WorkflowStateMismatch`    | 409  | `WorkflowStateMismatch`    | source-state doesn't match any declared transition |
| `ConcurrencyConflict`      | 409  | `ConcurrencyConflict`      | stale `_version` on update |
| `EffectFailure`            | 500  | `EffectFailure`            | Effect implementation threw |
| _(other)_                  | 500  | `InternalError`            | catch-all |

The error envelope ALWAYS has `ok: false` and an `error.kind` discriminator
the client can switch on. `renderer-react`'s `useAction` hook reads
`error.kind` to decide how to surface the failure.

## 5. Security stance (V0)

The V0 transport ships **demo-grade** primitives. Real deployments must
swap each of these out before any non-local use.

| Concern | V0 surface | Production answer |
|---|---|---|
| Authentication | `X-Actor-Id` / `X-Actor-Roles` headers → `StubActor` | Front the Router with a PSR-15 middleware that decodes a JWT/session and replaces the resolved actor |
| Authorization | `StubActor` returns a static role list | `RoleRequired` policies + a real `Actor` impl backed by `ausus/auth-bridge` (RFC-014) |
| CSRF          | API-only flow, JSON bodies, no cookies | If cookies are introduced, add a CSRF middleware before the Router |
| CORS          | `Access-Control-Allow-Origin: *` | Restrict to the allow-listed origin set |
| Rate limiting | none | Reverse-proxy (nginx, Cloudflare) |
| TLS           | none (`http://`) | Terminate TLS at the reverse proxy; document HSTS |
| Input validation | basic JSON parse + Reference shape check | The graph's Field types + RFC-013 ActionEffect contract |
| Tenant isolation | enforced at every Repository read (RFC-003 row strategy) | unchanged — already production-grade |
| Audit         | every Invoker call writes an audit row in the same DB transaction (RFC-007 Amendment-01) | unchanged — already production-grade |

The header-based stub-actor model is the only piece that is genuinely
**not** production-suitable. Tenant + audit + workflow + optimistic
locking already live at the Invoker chain inside `runtime-default`, so
the API surface inherits those guarantees regardless of which auth
middleware fronts it.

## 6. Concurrency model

| Concern | Detection | Wire surface |
|---|---|---|
| Stale update (`_version` mismatch) | `SqliteRepository::update` raises `ConcurrencyConflict` | HTTP 409 + `{ error.kind: "ConcurrencyConflict" }` |
| Workflow source-state mismatch | `WorkflowRuntime` scans transitions per Workflow (RFC-006 Amendment-01) | HTTP 409 + `{ error.kind: "WorkflowStateMismatch" }` |
| Cross-tenant Reference | `SqliteContext::context()` checks `$h->tenant() === $tenant` | HTTP 403 + `{ error.kind: "TenantBoundaryViolation" }` |

Clients should treat 409s as "your view of the state is stale, refetch
and retry" — not as terminal errors. `renderer-react`'s `useAction`
exposes both `pending` and `lastError` to drive that UX.

## 7. Integration evidence (real, this pass)

```
══════════════════════════════════════════════════════════════════════
  AUSUS L4 — live HTTP integration trace
══════════════════════════════════════════════════════════════════════

[1] GET  /api/_health                              200  health probe
[2] GET  /api/projections/billing.invoice.summary  200  schemaVersion=1.0.0, 2 items
[3] renderToString(ListView, schema1)              HTML — 2 DRAFT badges
[4] POST /api/actions/billing.invoice.issue        200  outputs = {id, status:ISSUED}
[5] re-render ListView                             HTML — first row → ausus-badge--blue (ISSUED)
[6] POST /api/actions/billing.invoice.cancel       200  outputs = {id, status:CANCELLED}
[7] re-render ListView                             HTML — second row → ausus-badge--red (CANCELLED)
[8] GET  /api/projections/billing.invoice.detail   200  DetailView with 8 fields
[9] POST /api/actions/billing.invoice.cancel       409  error.kind = WorkflowStateMismatch
[10] GET /api/projections/billing.invoice.summary  400  error.kind = BadRequest (no tenant)

── assertions ─────────────────────────────────────────────────────────
  ✓ health 200 + graph hash echoed
  ✓ summary schemaVersion = 1.0.0
  ✓ summary contains 2 seeded invoices
  ✓ initial render shows DRAFT for invoice 1
  ✓ issue returns 200 ok
  ✓ after issue: invoice 1 is ISSUED
  ✓ cancel returns 200 ok
  ✓ after cancel: invoice 2 is CANCELLED
  ✓ DetailView renders 8 dt headers
  ✓ DetailView shows ISSUED badge
  ✓ stale cancel → 409 WorkflowStateMismatch
  ✓ missing X-Tenant-ID → 400 BadRequest

RESULT: passed=12 failed=0
```

### Measured latency (real, single PHP-built-in-server process, M-series Mac)

| Roundtrip | Time |
|---|---|
| `GET /api/_health` (cold — first request triggers seed) | **12.63 ms** |
| `GET /api/projections/billing.invoice.summary` | **2.50 ms** |
| `POST /api/actions/billing.invoice.issue` | **2.62 ms** |
| `POST /api/actions/billing.invoice.cancel` | **1.77 ms** |
| `GET /api/projections/billing.invoice.detail?subject=…` | **1.36 ms** |
| `POST /api/actions/billing.invoice.cancel` (409 stale) | **1.19 ms** |
| **total wall time (9 roundtrips + 4 renderToStrings)** | **32.88 ms** |

Cold start is dominated by the first request's schema-apply + seed; once
the SQLite file exists, every subsequent request is sub-3 ms. PHP's
built-in server is single-threaded, so production deployments under
php-fpm + opcache will be faster in throughput but similar in per-request
latency.

## 8. Files in this pass

| File | LOC | Role |
|---|---|---|
| `packages/api-http/composer.json`                  |  ~25 | manifest |
| `packages/api-http/src/api.php`                    |  ~245 | Router + ErrorMapper + Emitter |
| `packages/api-http/README.md`                      |  ~115 | consumer doc |
| `packages/api-http/CHANGELOG.md`                   |  ~50 | release notes |
| `packages/api-http/LICENSE`                        |  21 | MIT |
| `apps/playground/server.php`                       |  ~75 | demo front controller (PSR-7 bootstrap + seed) |
| `apps/playground/web/live-trace.tsx`               |  ~165 | Node-side trace consumer (real fetch) |
| `scripts/integration-http.sh`                      |  ~80 | server boot + poll + run + teardown |
| `docs/L4-API-DESIGN.md`                            |  this file | |

Total new PHP LOC: ~245 (Router) + ~75 (demo) = **~320**.
Total new TS LOC: **~165** (live-trace).
Total new shell LOC: **~80** (integration runner).

## 9. Reproducibility

```bash
# from monorepo root
composer install
npm install
npm run build
bash scripts/integration-http.sh
# expected: RESULT: passed=12 failed=0
```

Or interactively, in two terminals:

```bash
# terminal 1
php -S 127.0.0.1:8787 apps/playground/server.php

# terminal 2
AUSUS_API_BASE_URL=http://127.0.0.1:8787/api \
  npx tsx apps/playground/web/live-trace.tsx
```

## 10. Determination

**GO.** The L4 surface is complete enough for the renderer to consume
without `mockApi.ts`, all kernel exceptions surface as typed HTTP
envelopes, and 12 end-to-end assertions pass against real network
roundtrips in under 33 ms wall.

What this **does not** yet ship (deferred to subsequent passes):

- Real authentication (V0 stub-actor only)
- OpenAPI / JSON-Schema descriptor of the API (would belong in
  `ausus/presentation-default` once it lands)
- Pagination cursor semantics on `?after=…&limit=…` (graph supports it;
  Router doesn't expose it yet)
- Server-sent events / WebSocket transport for live updates
- Per-Action method tunneling (currently every Action uses POST)

None of these block the V0 renderer consumer flow.
