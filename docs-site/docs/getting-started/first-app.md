---
id: first-app
title: Your First App
sidebar_label: Your First App
description: Wire the AUSUS layers together and invoke an action.
---

# Your First App

This page shows the smallest useful AUSUS program: compile a domain, apply a
schema, invoke an action, and read the result back. It is the same shape as
`ausus/starter`'s `composer boot` script.

If you want the full annotated domain, jump to the
[HelloInvoice tutorial](hello-invoice.md). This page focuses on **how the
layers connect**.

## The pieces {#the-pieces}

A running AUSUS application is assembled from five things:

1. A **Plugin** — your domain description (see [Plugins](../concepts/plugins.md)).
2. The **Compiler** — turns plugins into a `MetadataGraph`.
3. A **PersistenceDriver** — in v0.1.0, the SQLite driver.
4. The **runtime** — `Invoker`, `PolicyEngine`, `WorkflowRuntime`, etc.
5. An **Actor** and a **Tenant** — who is acting, and in which tenant.

## Step 1 — compile the graph {#step-1--compile-the-graph}

```php
use Ausus\Compiler;

$compiler = new Compiler();
$graph    = $compiler->compile([new HelloInvoiceDsl()]);

echo substr($graph->hash, 0, 12);   // content-addressable graph hash
```

The `MetadataGraph` is immutable and deterministic: the same plugins always
produce the same `hash`. See [The Metadata Graph](../concepts/metadata-graph.md).

## Step 2 — apply the schema {#step-2--apply-the-schema}

The SQL package derives `CREATE TABLE` statements directly from the graph.

```php
use Ausus\Persistence\Sql\SchemaDeriver;

$pdo = new PDO('sqlite:' . sys_get_temp_dir() . '/myapp.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

foreach (SchemaDeriver::deriveAll($graph) as $stmt) {
    $pdo->exec($stmt);
}
```

This creates one table per entity, plus the internal `kernel_audit_log` table.

## Step 3 — wire the runtime {#step-3--wire-the-runtime}

```php
use Ausus\{Tenant, TenantId, ActorRef, StubActor};
use Ausus\Persistence\Sql\{SqlitePersistenceDriver, DatabaseAuditSink};
use Ausus\Runtime\{
    PolicyEngine, WorkflowRuntime, TransitionSetIndex,
    EffectDispatcher, DefaultAuditor, SequenceCounter, Invoker,
};

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

:::note Single-tenant, single-actor per invoker
In v0.1.0 an `Invoker` is constructed with **one** `Tenant` and **one**
`Actor`. To act as a different tenant or actor, build another `Invoker`. This
is a deliberate v0.1.0 simplification — see [The Runtime](../backend/runtime.md).
:::

## Step 4 — invoke an action {#step-4--invoke-an-action}

```php
use Ausus\Reference;

// Create — no subject, just inputs.
$created = $invoker->invoke('billing.invoice.create', null, [
    'number'        => 'INV-2026-001',
    'customer_name' => 'ACME Corporation',
    'amount'        => ['amount' => '1500.00', 'currency' => 'USD'],
]);
// $created['id'] is a 26-char ULID; $created['status'] === 'DRAFT'

// Transition — subject required.
$ref = new Reference('acme', 'billing.invoice', $created['id']);
$invoker->invoke('billing.invoice.issue', $ref, []);
```

Every `invoke()` call runs the full runtime chain — policy check, workflow
guard, effect, audit — inside one database transaction. See
[The Runtime](../backend/runtime.md).

## Step 5 — render a projection {#step-5--render-a-projection}

```php
use Ausus\Runtime\ProjectionRenderer;

$renderer = new ProjectionRenderer($graph, $driver, $tenant);
$schema   = $renderer->render('billing.invoice.summary');
// $schema is a ViewSchema array: fields, actions, data.items, ...
```

The result is a [ViewSchema](../frontend/viewschema.md) — the wire format the
[React renderer](../frontend/react-renderer.md) consumes.

## What you have built {#what-you-have-built}

You now have the full vertical slice: **graph → schema → runtime → projection**.
The HTTP API ([ausus/api-http](../backend/http-api.md)) is simply this same
wiring placed behind PSR-7/15 request handling.

## Current v0.1.0 limitations {#current-v010-limitations}

- There is no service container or auto-wiring helper in v0.1.0 — you assemble
  the runtime explicitly, as above. `ausus/starter` gives you this wiring
  pre-written.
- `StubActor` is a fixed in-memory actor. There is no authentication layer.

## Next {#next}

- [HelloInvoice tutorial](hello-invoice.md) — the same flow with a real domain
  and assertions.
- [Project structure](project-structure.md) — where files live.
