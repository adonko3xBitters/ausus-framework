---
id: authenticated-gateway
title: Authenticated gateway
sidebar_label: Authenticated gateway
description: The required pattern for putting a real authentication layer in front of the v0.1.x HTTP Router — injecting X-Tenant-ID and X-Actor-* from a verified identity.
---

# Authenticated gateway

AUSUS v0.1.x **has no authentication layer**. The Router trusts
`X-Tenant-ID` / `X-Actor-Id` / `X-Actor-Roles` exactly as sent. In
production, **a verified gateway must sit in front of the Router** and
set those headers from an authenticated session.

Without that gateway, two outcomes are equally bad:

- If the gateway is missing entirely, every protected action returns
  `403 PolicyDenied` (the fail-closed default — see
  [HTTP routes · X-Actor-Roles](../reference/http-routes.md#request-headers)).
- If callers can set the headers themselves, they can impersonate any
  tenant and claim any role.

This page describes the production-grade pattern. It applies to every
deployment shape in [Deployment](deployment.md).

## The contract {#contract}

The gateway is anything that, on every authenticated request:

1. **Verifies** the caller's identity (JWT signature + expiry, session
   cookie, mTLS, API key — whatever your access model already has).
2. **Resolves** the caller to a tenant id, an actor id, and a role
   list. This mapping is yours: a database lookup, a JWT claim, an
   LDAP query, …
3. **Forwards** the request to the AUSUS process with `X-Tenant-ID`,
   `X-Actor-Id`, `X-Actor-Roles` set; any headers the client sent with
   the same names are **dropped** before forwarding.

The Router has zero way to tell a header that the gateway set apart
from a header the client set directly. Step 3 is therefore the
security boundary.

## Pattern 1 — PHP middleware wrapping `$app->http()` {#php-middleware}

The simplest, no-extra-process shape. Useful when AUSUS runs behind a
single PHP-FPM pool that also owns auth.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ausus\{Application, ApplicationConfig};
use Ausus\Api\Http\Emitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use App\YourPlugin;
use Psr\Http\Message\ServerRequestInterface;

$factory = new Psr17Factory();
$app = Application::create(
        ApplicationConfig::make()
            ->sqlite(getenv('APP_DB_PATH'))
            ->psr17($factory)
    )
    ->register(new YourPlugin())
    ->boot();

$creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
$request = $creator->fromGlobals();

// ─────────── auth-resolved injection ──────────────────────────────────────
// 1. Verify whatever auth your app uses. This is the only place untrusted
//    input is accepted; failure short-circuits with 401 and the Router is
//    never entered.
$session = SessionStore::verify($request);    // your code, throws/returns null
if ($session === null) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo '{"ok":false,"error":{"kind":"Unauthenticated","message":"no valid session"}}';
    exit;
}

// 2. Strip every client-supplied trust header — defense in depth.
$request = $request
    ->withoutHeader('X-Tenant-ID')
    ->withoutHeader('X-Actor-Id')
    ->withoutHeader('X-Actor-Roles');

// 3. Inject from the verified session.
$request = $request
    ->withHeader('X-Tenant-ID',   $session->tenantId)
    ->withHeader('X-Actor-Id',    $session->userId)
    ->withHeader('X-Actor-Roles', implode(',', $session->roles));

// ─────────── hand off to AUSUS ────────────────────────────────────────────
Emitter::emit($app->http($request));
```

Three properties that matter:

- The `withoutHeader('X-Tenant-ID')` calls are **not optional**. PSR-7
  requests merge case-insensitively, so a header the client sent
  survives without an explicit strip.
- `$app->http()` is the same call as in the development front
  controller; the only added work is what runs **before** it.
- Health checks reach `/_health` without auth — that route does not
  call `resolveActor()`. Most orchestrators want this for liveness.

## Pattern 2 — nginx `auth_request` to a separate service {#nginx-auth-request}

Use this when AUSUS lives behind a stateless edge that delegates auth
to a sidecar (your existing IAM service, a Keycloak / Authelia /
oauth2-proxy / Pomerium instance). nginx calls the auth service on
every request; the service returns the trust headers via the
`X-…` response headers nginx is told to forward.

```nginx
server {
    listen 443 ssl http2;
    server_name app.example.com;
    root /var/www/your-app/public;

    location /api/_health {
        # No auth on health checks.
        try_files $uri /server.php?$query_string;
    }

    location /api/ {
        # 1. Verify the request via the auth sidecar.
        auth_request /_auth;

        # 2. Capture the trust headers the auth sidecar set on its response.
        auth_request_set $ausus_tenant $upstream_http_x_tenant_id;
        auth_request_set $ausus_actor  $upstream_http_x_actor_id;
        auth_request_set $ausus_roles  $upstream_http_x_actor_roles;

        # 3. Strip whatever the client tried to send.
        proxy_set_header X-Tenant-ID    $ausus_tenant;
        proxy_set_header X-Actor-Id     $ausus_actor;
        proxy_set_header X-Actor-Roles  $ausus_roles;

        # 4. Forward to the PHP front controller.
        try_files $uri /server.php?$query_string;
    }

    location = /_auth {
        internal;
        proxy_pass http://auth-sidecar.internal:8081/verify;
        proxy_pass_request_body off;
        proxy_set_header Content-Length "";
        proxy_set_header X-Original-URI $request_uri;
    }

    location ~ \.php$ {
        fastcgi_pass            unix:/run/php/php8.3-fpm.sock;
        include                 fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/server.php;
    }
}
```

`auth_request` denies the original request if the sidecar returns any
status outside 2xx. On 401, nginx propagates 401 — the AUSUS process
is never reached.

`auth_request_set` reads from the sidecar's response headers
(`X-Tenant-ID: …` etc.). **Headers on the original client request
named X-Tenant-ID never reach the upstream** as long as you
`proxy_set_header` them yourself — that's the strip.

## Pattern 3 — JWT-claim mapping {#jwt}

When the gateway already has a signed JWT, the mapping is purely
algebraic. Inside the PHP middleware variant of Pattern 1:

```php
$jwt = $request->getHeaderLine('Authorization');
if (!str_starts_with($jwt, 'Bearer ')) {
    return unauthenticated();
}
$claims = JWT::decode(substr($jwt, 7), $publicKey, ['ES256']);
// $claims->sub      → user id
// $claims->tenant   → tenant id (must be present)
// $claims->roles    → array of strings

$request = $request
    ->withoutHeader('X-Tenant-ID')
    ->withoutHeader('X-Actor-Id')
    ->withoutHeader('X-Actor-Roles')
    ->withHeader('X-Tenant-ID',   $claims->tenant)
    ->withHeader('X-Actor-Id',    $claims->sub)
    ->withHeader('X-Actor-Roles', implode(',', $claims->roles ?? []));
```

Keep `kid` rotation and signature validation in your library of choice
(`firebase/php-jwt`, `web-token/jwt-framework`, …). The framework
treats the JWT as opaque — what reaches the Router is the resolved
header set.

## Browser clients — never set the headers from JavaScript {#browser}

The `apps/issue-tracker/ui/src/App.tsx` sample wraps `fetch` to add
`X-Actor-Roles: tracker.member,tracker.admin,tracker.viewer`. That
**is a development convenience** so the dev server works without an
auth setup. In production, the React app **must not** add the trust
headers; the gateway sets them.

A production-shaped fetcher looks like this:

```tsx
import type { Fetcher } from "@ausus/renderer-react";

// Auth is done by cookies or a Bearer token — the renderer just forwards
// what the browser already sends. The gateway resolves identity and adds
// X-Tenant-ID / X-Actor-* before reaching the Router.
const fetcher: Fetcher = (url, init) =>
  fetch(url, { ...init, credentials: "include" });
```

The `X-Tenant-ID` the renderer **does** set comes from
`AususProvider tenant="…"` — that one is benign (it tells the
projection cache which tenant to render). The gateway should overwrite
it on the way through.

## What the gateway is responsible for {#responsibilities}

| Concern | Gateway | AUSUS |
|---|---|---|
| Authentication (who is the user?) | yes | no |
| Session lifecycle / refresh | yes | no |
| Role resolution (user → role list) | yes | no |
| Per-tenant authorisation (role → action) | no | **yes** (the policy gate inside the Invoker chain) |
| Workflow gating | no | yes |
| Audit logging | no | yes (kernel_audit_log, in-tx) |
| Stripping client trust headers | yes | n/a |
| CORS narrowing | yes (at the webserver) | no |
| Rate limiting | yes | no |
| Request signature / replay protection | yes | no |

If the gateway gets the X-Actor-* mapping wrong (e.g. inflates a
user's roles), AUSUS will happily authorise actions on that basis.
The gateway is the trust boundary.

## Smoke-testing the gateway {#smoke}

A one-line check that proves the gateway is doing its job:

```bash
# As an unauthenticated caller — should never reach a protected action.
curl -i -X POST https://app.example.com/api/actions/your.entity.create \
  -H 'Content-Type: application/json' \
  -H 'X-Tenant-ID: notmytenant' \
  -H 'X-Actor-Roles: super.admin' \
  -d '{"subject":null,"inputs":{}}'

# Expected: 401 from the gateway. NOT 200. NOT 403 from AUSUS.
# If you see 200 or 403, the strip-and-inject step is missing.
```

Then again as an authenticated caller — that one should get the action
result.

## Related {#related}

- [Deployment](deployment.md) — the surrounding nginx / Apache / Docker recipes.
- [HTTP routes · Request headers](../reference/http-routes.md#request-headers) — what AUSUS actually reads.
- [Backend · HTTP API](../backend/http-api.md) — the danger admonition that points here.
- [Security model](../intro.md#architecture-first) — the v0.1.x posture this pattern complements.
