---
id: configuration
title: Configuration reference
sidebar_label: Configuration
description: Every config key, every environment variable, every default — for the AUSUS PHP backend and the React renderer.
---

# Configuration reference

Every knob a v0.1.x consumer can turn, on one page.

The configuration story is intentionally short: AUSUS has one config
surface ([`ApplicationConfig`](application.md#applicationconfig)) plus a
handful of environment variables read by the sample apps.

## `ApplicationConfig` keys {#application-config-keys}

These are the keys `Application::create()` accepts — either via
`ApplicationConfig::make()->…` or as an associative array. Every key
is **optional**; defaults are listed.

### Identity & authorization

| Key | Builder | Type | Default | Description |
|---|---|---|---|---|
| `tenant` | `->tenant()` | `string` \| `Ausus\Tenant` | `'default'` | The active tenant for `invoke()` / `run()`. Non-HTTP code paths use this verbatim; the HTTP Router reads `X-Tenant-ID` per request and the Application's `tenant` does not override it. |
| `actor` | `->actor()` | `Ausus\Actor` | — | A pre-built actor. Overrides `actorId` / `roles` / `permissions`. |
| `actorId` | `->actorId()` or `->actor(string)` | `string` | `'app'` | The id for the default `StubActor` (only meaningful when `actor` is unset). |
| `roles` | `->roles()` | `string[]` | `[]` | Roles for the default `StubActor`. Non-string entries are rejected. |
| `permissions` | `->permissions()` | `string[]` | `[]` | Permissions for the default `StubActor`. |

### Persistence

| Key | Builder | Type | Default | Description |
|---|---|---|---|---|
| `database` | `->sqlite()` or `->pdo()` | `string` (SQLite file path) \| `\PDO` | in-memory SQLite | A SQLite file path or a live PDO. `:memory:` is a valid string. **`sqlite()` and `pdo()` are mutually exclusive** — the second call throws. |
| `migrate` | `->migrate()` | `bool` | `true` | Whether `boot()` applies the derived schema (`CREATE TABLE IF NOT EXISTS …`). Set to `false` if you manage migrations elsewhere. |
| `driver` | `->driver()` | `Ausus\PersistenceDriver` | — | Advanced: replace the SQLite driver entirely. |
| `auditSink` | `->auditSink()` | `Ausus\AuditSink` | — | Advanced: replace the audit sink. |

### HTTP

| Key | Builder | Type | Default | Description |
|---|---|---|---|---|
| `apiPrefix` | `->apiPrefix()` | `string` | `'/api'` | URL prefix the [Router](http-routes.md) mounts under. Must start with `/` and not end with `/`. |
| `responseFactory` | `->responseFactory()` or `->psr17()` | `Psr\Http\Message\ResponseFactoryInterface` | autodetect nyholm | PSR-17 factory used by `Application::http()`. |
| `streamFactory` | `->streamFactory()` or `->psr17()` | `Psr\Http\Message\StreamFactoryInterface` | autodetect nyholm | PSR-17 stream factory. |

### Compile-time

| Key | Builder | Type | Default | Description |
|---|---|---|---|---|
| `kernelVersion` | `->kernelVersion()` | `string` | `'1.0.0'` | Stamped into the compiled `MetadataGraph`. Only matters for hash determinism — changing it changes the graph hash. |

## Validation rules {#validation}

Every setter validates at the call site:

| Rule | Trigger | Exception |
|---|---|---|
| Unknown array key in `Application::create([...])` | array form only | `InvalidArgumentException` listing all known keys |
| Empty string for `tenant` / `actorId` / `kernelVersion` / `sqlite` path | builder | `InvalidArgumentException` (`must be non-empty`) |
| Non-string or empty-string entry in `roles` / `permissions` | builder | `InvalidArgumentException` naming the field + the offending index |
| `apiPrefix` that does not start with `/` | `->apiPrefix()` | `InvalidArgumentException` |
| `apiPrefix` that ends with `/` (when longer than `'/'`) | `->apiPrefix()` | `InvalidArgumentException` |
| `psr17(...)` factory that does not implement both `ResponseFactoryInterface` and `StreamFactoryInterface` | `->psr17()` | `InvalidArgumentException` naming the missing interface |
| `pdo()` after `sqlite()` (or vice versa) | builder | `InvalidArgumentException` (`mutually exclusive`) |
| `'database'` that is neither `PDO` nor string | array form | `InvalidArgumentException` |
| `'driver'` that does not implement `Ausus\PersistenceDriver` | array form | `InvalidArgumentException` |
| `'auditSink'` that does not implement `Ausus\AuditSink` | array form | `InvalidArgumentException` |

## Environment variables {#environment-variables}

AUSUS itself reads **no** environment variables. The sample apps and
tutorial scripts read a handful:

| Variable | Read by | Effect |
|---|---|---|
| `AUSUS_DB_PATH` | `apps/playground/server.php`, `apps/issue-tracker/public/server.php` | Path to the SQLite file. Default: a `sys_get_temp_dir()`-relative path per app. |
| `AUSUS_RESET_DB` | `apps/playground/server.php` | If set to `"1"`, the front controller deletes the existing SQLite file on startup and re-seeds. |
| `AUSUS_API_BASE_URL` | `apps/playground/web/live-trace.tsx` | Base URL the live-HTTP trace targets. Default: `http://127.0.0.1:8787/api`. |
| `VITE_API_BASE_URL` | `apps/issue-tracker/ui/src/App.tsx` | Base URL the React UI calls. Default: `http://127.0.0.1:8787/api`. Read at build/dev time by Vite. |

None of these are consumed by the framework itself — they are consumer
conventions you can rename in your own front controllers.

## Required PHP environment {#php-environment}

| Requirement | Why |
|---|---|
| PHP `>= 8.3` | `readonly` classes, enum string-backed cases, named-arg call sites used throughout the kernel. |
| `ext-pdo` + `ext-pdo_sqlite` | The v0.1.x persistence driver. |

The `ausus/starter` and `ausus/persistence-sql` composer manifests
declare both explicitly. CI verifies on PHP 8.3 and 8.4.

## Required JS environment {#js-environment}

| Requirement | Why |
|---|---|
| Node.js `>= 18` | Vite + the renderer's ESM entry. |
| npm `>= 8` | Workspace install. |
| `react` and `react-dom` `^18 \|\| ^19` | Peer dependencies of `@ausus/renderer-react`. **You install them yourself** — the renderer bundles neither. |

The renderer ships ESM-only (`"type": "module"`, NodeNext resolution).
No bundled CSS — see [Tutorial · React UI](../tutorial/react-ui.md) for
a minimal stylesheet.

## HTTP wire configuration {#http-wire}

These are not `ApplicationConfig` keys but headers the Router reads on
every request. They are documented in full in
[HTTP routes reference](http-routes.md); the entries below are for
quick lookup.

| Header | Required on | Default if absent | Purpose |
|---|---|---|---|
| `X-Tenant-ID` | `/projections/*` and `/actions/*` | none — request returns `400 BadRequest` | The active tenant for the request. |
| `X-Actor-Id` | optional | `'anon'` | The actor id. |
| `X-Actor-Roles` | optional | **empty list** (every protected action returns `403 PolicyDenied`) | Comma-separated role names. |

CORS posture: every Router response carries
`Access-Control-Allow-Origin: *` and accepts
`X-Tenant-ID, X-Actor-Id, X-Actor-Roles, Content-Type` cross-origin.
Narrow this in front of the Router for production — see
[Operations · Authenticated gateway](../operations/authenticated-gateway.md).

## Related {#related}

- [Application & ApplicationConfig reference](application.md) — the consumer API.
- [HTTP routes reference](http-routes.md) — the wire surface.
- [Operations · Deployment](../operations/deployment.md) — how to run AUSUS in production.
