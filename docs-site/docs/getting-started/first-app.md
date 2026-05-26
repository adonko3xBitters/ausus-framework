---
id: first-app
title: Your First App
sidebar_label: Your First App
description: Bootstrap an AUSUS application with Ausus\Application and invoke an action.
---

# Your First App

This page shows the smallest useful AUSUS program: bootstrap a domain, invoke an
action, and read the result back. It is the same shape as `ausus/starter`'s
`composer boot` script.

If you want the full annotated domain, jump to the
[HelloInvoice tutorial](hello-invoice.md). This page focuses on **how an
application is bootstrapped**.

## The pieces {#the-pieces}

A running AUSUS application is assembled from five things:

1. A **Plugin** — your domain description (see [Plugins](../concepts/plugins.md)).
2. The **Compiler** — turns plugins into a `MetadataGraph`.
3. A **PersistenceDriver** — in v0.1.0, the SQLite driver.
4. The **runtime** — `Invoker`, `PolicyEngine`, `WorkflowRuntime`, etc.
5. An **Actor** and a **Tenant** — who is acting, and in which tenant.

`Ausus\Application` composes all five for you. It ships in
[`ausus/standard-stack`](../packages/index.md) and is a thin convenience layer —
it adds no behaviour, and every object it builds stays directly constructable
(see [Manual wiring](#manual-wiring-advanced) below).

If your domain has a record lifecycle, the plugin declares it explicitly with
`->workflow(field: '…', initial: '…')` — see [Workflows](../concepts/workflows.md).
The runtime then guards every transition. `HelloInvoiceDsl`, used below, declares
an invoice lifecycle this way.

## Bootstrap with Application {#bootstrap-with-application}

`Application` has a four-call lifecycle: **create → register → boot → invoke**.

```php
use Ausus\Application;

$app = Application::create([
        'tenant' => 'acme',
        'roles'  => ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'],
    ])
    ->register(new HelloInvoiceDsl())
    ->boot();
```

What each call does:

- **`create($config)`** — declares the tenant and actor, and the
  configuration surface (see [reference](#configuration-reference)). With no
  `database` key it uses an in-memory SQLite database.
- **`register(...$plugins)`** — adds one or more domain plugins.
- **`boot()`** — compiles the `MetadataGraph`, wires the SQLite driver and the
  runtime, and applies the derived schema (one table per entity, plus the
  internal `kernel_audit_log` table). It is idempotent.

`boot()` is also lazy: calling `invoke()` or an accessor on an un-booted
`Application` boots it first. An explicit `boot()` only controls *when*
compilation happens.

## Invoke an action {#invoke-an-action}

```php
use Ausus\Reference;

// Create — no subject, just inputs.
$created = $app->invoke('billing.invoice.create', null, [
    'number'        => 'INV-2026-001',
    'customer_name' => 'ACME Corporation',
    'amount'        => ['amount' => '1500.00', 'currency' => 'USD'],
]);
// $created['id'] is a 26-char ULID; $created['status'] === 'DRAFT'

// Transition — subject required. $app->reference() scopes it to the tenant.
$ref = $app->reference('billing.invoice', $created['id']);
$app->invoke('billing.invoice.issue', $ref, []);
```

Every `invoke()` call runs the full runtime chain — policy check, workflow
guard, effect, audit — inside one database transaction. See
[The Runtime](../backend/runtime.md).

## Render a projection {#render-a-projection}

```php
$schema = $app->render('billing.invoice.summary');
// $schema is a ViewSchema array: fields, actions, data.items, ...
```

The result is a [ViewSchema](../frontend/viewschema.md) — the wire format the
[React renderer](../frontend/react-renderer.md) consumes.

## Configuration reference {#configuration-reference}

`Application::create()` accepts either the **typed
[`ApplicationConfig`](#typed-config-builder) builder** (recommended) or a plain
config array. Every option has a sensible default; an unknown array key throws
`InvalidArgumentException`.

| Key / builder call | Type | Default | Purpose |
|---|---|---|---|
| `tenant` / `->tenant()` | `string` \| `Tenant` | `'default'` | Active tenant. |
| `actor` / `->actor(Actor)` | `Actor` | — | The acting actor. Overrides `actorId`/`roles`/`permissions`. |
| `actorId` / `->actorId()` or `->actor(string)` | `string` | `'app'` | Id for the default `StubActor`. |
| `roles` / `->roles()` | `string[]` | `[]` | Roles for the default actor. |
| `permissions` / `->permissions()` | `string[]` | `[]` | Permissions for the default actor. |
| `database` / `->sqlite()` or `->pdo()` | `string` \| `PDO` | in-memory SQLite | A SQLite file path or a live `PDO`. |
| `kernelVersion` / `->kernelVersion()` | `string` | `'1.0.0'` | Kernel version recorded in the graph. |
| `migrate` / `->migrate()` | `bool` | `true` | Derive and apply the SQL schema on boot. |
| `driver` / `->driver()` | `PersistenceDriver` | — | Advanced: replace the SQLite driver. |
| `auditSink` / `->auditSink()` | `AuditSink` | — | Advanced: replace the database audit sink. |
| `apiPrefix` / `->apiPrefix()` | `string` | `'/api'` | URL prefix mounted by [`Application::http()`](#one-call-http). |
| `responseFactory` / `->responseFactory()` | `ResponseFactoryInterface` | autodetect nyholm | PSR-17 response factory for [`Application::http()`](#one-call-http). |
| `streamFactory` / `->streamFactory()` | `StreamFactoryInterface` | autodetect nyholm | PSR-17 stream factory for [`Application::http()`](#one-call-http). |

## Typed config builder {#typed-config-builder}

`Ausus\ApplicationConfig` is a fluent, immutable builder that gives the same
configuration surface with named, typed methods. Setters return new instances,
so configs can be safely shared and partially specialized; validation happens
at the call that introduced the bad value:

```php
use Ausus\{Application, ApplicationConfig};

$config = ApplicationConfig::make()
    ->tenant('acme')
    ->actor('user42')
    ->roles(['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'])
    ->sqlite(__DIR__ . '/myapp.sqlite');

$app = Application::create($config)
    ->register(new HelloInvoiceDsl())
    ->boot();
```

Two small wrinkles, and **why** they exist:

- **`->actor(string|Actor)`** is overloaded — a string sets the default
  `StubActor`'s id, an `Actor` object replaces it wholesale. Both `actor('boot')`
  and `actorId('boot')` mean the same thing.
- **`->sqlite($path)` and `->pdo($connection)`** are mutually exclusive. Calling
  the second one after the first throws — there is no silent "last write wins."

Internally, the builder converts to the array shape via `toArray()`, so
`Application::create($array)` keeps working unchanged.

The booted objects are reachable for code that needs a layer down:
`$app->graph()`, `$app->invoker()`, `$app->driver()`, `$app->renderer()`,
`$app->pdo()`, `$app->tenant()`, `$app->actor()`.

:::note Single-tenant, single-actor per Application
An `Application` carries **one** `Tenant` and **one** `Actor`, matching the
v0.1.0 `Invoker` contract. To act as a different tenant or actor, build another
`Application`. This is a deliberate v0.1.0 simplification — see
[The Runtime](../backend/runtime.md).
:::

## Manual wiring (advanced) {#manual-wiring-advanced}

`Application` is optional. The low-level API is unchanged and fully supported —
you can assemble the runtime yourself when you need custom transaction control,
a non-default audit topology, or more than one tenant in a process:

```php
use Ausus\{Compiler, Tenant, TenantId, ActorRef, StubActor};
use Ausus\Persistence\Sql\{SqlitePersistenceDriver, SchemaDeriver, DatabaseAuditSink};
use Ausus\Runtime\{
    PolicyEngine, WorkflowRuntime, TransitionSetIndex,
    EffectDispatcher, DefaultAuditor, SequenceCounter, Invoker,
};

$graph = (new Compiler())->compile([new HelloInvoiceDsl()]);

$pdo = new PDO('sqlite:' . sys_get_temp_dir() . '/myapp.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (SchemaDeriver::deriveAll($graph) as $stmt) {
    $pdo->exec($stmt);
}

$tenant = new Tenant(new TenantId('acme'));
$actor  = new StubActor(
    new ActorRef('user', 'user42', 'acme'),
    ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'],
);

$driver  = new SqlitePersistenceDriver($pdo, $graph);
$invoker = new Invoker(
    $graph,
    $driver,
    new PolicyEngine($graph),
    new WorkflowRuntime(new TransitionSetIndex($graph)),
    new EffectDispatcher(),
    new DefaultAuditor(new DatabaseAuditSink($pdo)),
    new SequenceCounter(),
    $tenant,
    $actor,
);
```

This is exactly what `Application::boot()` does internally. The two paths are
interchangeable — `$app->invoker()` returns an `Invoker` built the same way.

## One-call HTTP {#one-call-http}

When you put the application behind HTTP, you do not need to construct a
`Router` yourself. `Application::http()` takes a PSR-7 `ServerRequest`, lazily
builds the `Router` against the booted graph/driver/audit-sink **once**, and
returns the response. A front controller is six lines:

```php
use Ausus\{Application, ApplicationConfig};
use Ausus\Api\Http\Emitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

$factory = new Psr17Factory();
$app = Application::create(
        ApplicationConfig::make()->tenant('acme')->roles([...])->sqlite(__DIR__.'/app.sqlite')->psr17($factory)
    )
    ->register(new HelloInvoiceDsl())
    ->boot();

$creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
Emitter::emit($app->http($creator->fromGlobals()));
```

The existing `$app->router(...)` factory still builds a fresh `Router` per
call — see [The HTTP API](../backend/http-api.md#application-http) for the
detail, the headers (`X-Tenant-ID`, `X-Actor-*`) and the error mapping.

## What you have built {#what-you-have-built}

You now have the full vertical slice: **graph → schema → runtime → projection**.
The HTTP API ([ausus/api-http](../backend/http-api.md)) is simply this same
wiring placed behind PSR-7/15 request handling.

## Current v0.1.0 limitations {#current-v010-limitations}

- `Application` is a composition helper, not a service container — there is no
  dependency-injection container or auto-wiring of your own classes in v0.1.0.
- `StubActor` is a fixed in-memory actor. There is no authentication layer.
- An `Application` is bound to one tenant and one actor; multi-tenant request
  handling means one `Application` (or `Invoker`) per tenant.

## Next {#next}

- [HelloInvoice tutorial](hello-invoice.md) — the same flow with a real domain
  and assertions.
- [Project structure](project-structure.md) — where files live.
