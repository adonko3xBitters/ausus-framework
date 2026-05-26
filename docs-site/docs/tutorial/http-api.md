---
id: http-api
title: 'Part 4 — HTTP API'
sidebar_label: 4. HTTP API
description: Expose the Ticket System over HTTP and exercise it with curl.
---

# Part 4 — HTTP API

**Why this step exists:** the CLI runner proved the domain works, but a real
application is reached over the network. This part puts the Ticket System
behind an HTTP API — without writing a single route or controller.

## How the HTTP layer works {#how-the-http-layer-works}

`ausus/api-http` ships a `Router` — one PSR-15 handler that maps three HTTP
routes onto the runtime you already have:

| Method & path | What it does |
|---|---|
| `GET /api/_health` | Liveness probe; returns the graph hash. |
| `GET /api/projections/{fqn}` | Renders a projection to ViewSchema JSON. |
| `POST /api/actions/{fqn}` | Invokes an action. |

The Router does not invent behaviour — it parses the request, calls the same
`Invoker` the CLI used, and serialises the result. **Why this matters:** the
HTTP API and the CLI cannot drift apart, because they run identical code.

## Add the HTTP server dependency {#add-the-http-server-dependency}

The Router speaks PSR-7, so it needs an HTTP message implementation. Install
[nyholm/psr7](https://github.com/Nyholm/psr7) — small and dependency-free:

```bash
composer require nyholm/psr7 nyholm/psr7-server
```

## Write the front controller {#write-the-front-controller}

Create `server.php` in the project root. It bootstraps the application exactly
as `tickets.php` did, then hands the request to `Application::http()` — one
call that builds the `Router` internally and returns a PSR-7 response:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Ausus\{Application, ApplicationConfig};
use Ausus\Api\Http\Emitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Helpdesk\TicketSystem;

// Open the same SQLite file the CLI runner created.
$pdo = new PDO('sqlite:' . __DIR__ . '/tickets.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// One PSR-17 factory; the inbound ServerRequestCreator and the internal
// Router used by $app->http() both consume it.
$factory = new Psr17Factory();

$app = Application::create(
        ApplicationConfig::make()
            ->tenant('helpdesk')
            ->roles(['ticket.agent', 'ticket.viewer'])
            ->pdo($pdo)
            ->psr17($factory)
    )
    ->register(new TicketSystem())
    ->boot();

// Build the incoming request, hand it to the application, emit the response.
$creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
Emitter::emit($app->http($creator->fromGlobals()));
```

:::tip One call, one Router, one process
`$app->http($request)` lazily builds the `Router` against the booted
graph/driver/audit sink and **caches it for the life of the process**. Every
later call reuses the same Router. If you ever need a Router with a different
prefix or factories, the existing `$app->router(...)` factory still works and
returns a fresh instance.
:::

## Run the server {#run-the-server}

First make sure the database has data — run the CLI runner from Part 3 if you
have not already:

```bash
php tickets.php
```

Then start PHP's built-in web server with `server.php` as the front controller:

```bash
php -S localhost:8080 server.php
```

Leave this running. Open a **second terminal** for the requests below.

## Exercise the API {#exercise-the-api}

### Health check {#health-check}

```bash
curl -s http://localhost:8080/api/_health
```

```json
{"ok":true,"service":"ausus/api-http","graphHash":"7c1e9b3a…"}
```

### List tickets {#list-tickets}

Projection and action routes require an **`X-Tenant-ID`** header — it tells
AUSUS which tenant's data to act on. Your tenant is `helpdesk`:

```bash
curl -s -H 'X-Tenant-ID: helpdesk' \
  http://localhost:8080/api/projections/helpdesk.ticket.summary
```

The response is a **ViewSchema** — a JSON document describing the fields, the
available actions, and the data:

```json
{
  "schemaVersion": "1.0.0",
  "targetProfile": "react.web.v1",
  "fields": [
    {"name": "id",       "type": "identity", "label": "Id"},
    {"name": "title",    "type": "string",   "label": "Title"},
    {"name": "requester","type": "string",   "label": "Requester"},
    {"name": "priority", "type": "enum",     "label": "Priority"},
    {"name": "status",   "type": "enum",     "label": "Status"}
  ],
  "actions": [ ... ],
  "data": {
    "items": [
      {"id": "01J…", "title": "Printer on floor 3 is offline", "priority": "HIGH", "status": "OPEN"},
      ...
    ]
  }
}
```

The React renderer in the next part consumes this document directly.

### Create a ticket over HTTP {#create-a-ticket-over-http}

Action routes are `POST` requests. The body has two keys:

- **`subject`** — the record the action targets, as a reference. For a *create*
  action there is no existing record, so it is `null`.
- **`inputs`** — the field values.

Actions are also policy-checked, so you must say which roles the caller holds
with **`X-Actor-Roles`**. The `create` action requires `ticket.agent`:

```bash
curl -s -X POST http://localhost:8080/api/actions/helpdesk.ticket.create \
  -H 'X-Tenant-ID: helpdesk' \
  -H 'X-Actor-Roles: ticket.agent' \
  -H 'Content-Type: application/json' \
  -d '{
        "subject": null,
        "inputs": {
          "title": "Laptop will not boot",
          "requester": "max@example.com",
          "priority": "HIGH"
        }
      }'
```

```json
{"ok":true,"outputs":{"id":"01J…","title":"Laptop will not boot","status":"OPEN","priority":"HIGH",...}}
```

### Drive a transition {#drive-a-transition}

A transition action targets an existing ticket, so `subject` is a reference.
Copy an `id` from the list response and `start` it:

```bash
curl -s -X POST http://localhost:8080/api/actions/helpdesk.ticket.start \
  -H 'X-Tenant-ID: helpdesk' \
  -H 'X-Actor-Roles: ticket.agent' \
  -H 'Content-Type: application/json' \
  -d '{
        "subject": {
          "tenantId": "helpdesk",
          "entityFqn": "helpdesk.ticket",
          "identityHandle": "PASTE_AN_OPEN_TICKET_ID"
        },
        "inputs": {}
      }'
```

```json
{"ok":true,"outputs":{"status":"IN_PROGRESS","_version":"01J…"}}
```

If you `start` a ticket that is not `OPEN`, the API answers `409 Conflict` with
`{"ok":false,"error":{"kind":"WorkflowStateMismatch",...}}` — the same guard
you saw on the CLI, now over HTTP.

:::warning No `X-Actor-Roles` means no roles
If you omit `X-Actor-Roles`, the Router builds a **roleless** actor and
every protected action returns `403 PolicyDenied`. Always send
`X-Actor-Roles` for this tutorial. There is no authentication layer in
v0.1.0; a production deployment puts a real auth middleware in front of the
Router and that middleware is what sets `X-Actor-Roles`.
:::

![Terminal session showing curl calls against /_health, /projections/helpdesk.ticket.summary and /actions/helpdesk.ticket.create — the last one returning ok:true with a generated ULID.](/img/tutorial/curl-session.svg)

## What you have now {#what-you-have-now}

The Ticket System answers HTTP requests: it lists tickets, creates them, and
drives the workflow — all over the wire. The next part puts a real user
interface on top.

**Next: [Part 5 — React UI](react-ui.md).**
