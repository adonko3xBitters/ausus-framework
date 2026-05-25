---
id: hello-invoice
title: HelloInvoice Tutorial
sidebar_label: HelloInvoice Tutorial
description: Build and exercise a complete invoice domain end to end.
---

# HelloInvoice Tutorial

`HelloInvoice` is the reference domain that ships inside `ausus/starter` and
the repository playground. It is a single `invoice` entity with a three-state
lifecycle. This tutorial walks through declaring it, then exercises every
runtime guarantee against it.

The runnable version lives at `apps/playground/run.php` in the monorepo and is
covered by 36 assertions in the validation gate.

## 1. Declare the domain {#1-declare-the-domain}

A domain in AUSUS is a **plugin**. Here is the complete `HelloInvoice` plugin
written with the [DSL](../backend/php-dsl.md):

```php
namespace Acme\Billing;

use Ausus\{DslPlugin, Dsl, Field, Action};

final class HelloInvoiceDsl extends DslPlugin
{
    public function name(): string        { return 'billing'; }
    public function phpNamespace(): string { return 'Acme\\Billing'; }

    public function dsl(Dsl $dsl): void
    {
        $dsl->entity('invoice')
            ->fields([
                'number'        => Field::string()->unique()->max(32),
                'customer_name' => Field::string()->max(200),
                'amount'        => Field::money()->currency('USD'),
                'status'        => Field::enum('DRAFT', 'ISSUED', 'CANCELLED')->default('DRAFT'),
                'issued_at'     => Field::datetime()->nullable(),
            ])
            ->actions([
                'create' => Action::create('number', 'customer_name', 'amount')
                              ->requireRole('invoice.creator'),
                'issue'  => Action::transition('status', from: 'DRAFT', to: 'ISSUED')
                              ->stamp('issued_at')
                              ->requireRole('invoice.issuer'),
                'cancel' => Action::transition('status', from: 'DRAFT',  to: 'CANCELLED')
                              ->andTransition('status', from: 'ISSUED', to: 'CANCELLED')
                              ->requireRole('invoice.canceler'),
            ])
            ->workflow(field: 'status', initial: 'DRAFT')
            ->projection('summary',
                fields:  ['id', 'number', 'customer_name', 'status', 'amount'],
                actions: ['create', 'cancel'],
                role:    'invoice.viewer')
            ->projection('detail',
                fields:  ['id', 'number', 'customer_name', 'status', 'amount', 'issued_at', 'created_at', 'updated_at'],
                actions: ['issue', 'cancel'],
                role:    'invoice.viewer');
    }
}
```

What this declares:

- One **entity**, `billing.invoice`, with five domain fields. The kernel adds
  five [system fields](../concepts/entities-fields-actions.md#system-fields)
  automatically (`id`, `tenant_id`, `_version`, `created_at`, `updated_at`).
- Three **actions**: `create` (a create action) plus `issue` and `cancel`
  (transition actions).
- A **workflow** declared explicitly on the `status` enum field, starting in
  `DRAFT`. The enum options become the workflow states. See
  [Workflows](../concepts/workflows.md).
- Two **projections** — read-shaped views named `summary` and `detail`.

## 2. Compile and boot {#2-compile-and-boot}

`Ausus\Application` bootstraps the whole stack — it compiles the graph, wires
the runtime, and applies the derived schema:

```php
use Ausus\Application;

$app = Application::create([
        'tenant' => 'acme',
        'roles'  => ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'],
    ])
    ->register(new HelloInvoiceDsl())
    ->boot();

$graph = $app->graph();
echo "entities=", count($graph->entities),
     " actions=",  count($graph->actions),
     " workflows=", count($graph->workflows), "\n";
// entities=1 actions=3 workflows=1
```

The same configuration with the typed
[`ApplicationConfig`](first-app.md#typed-config-builder) builder reads:

```php
use Ausus\{Application, ApplicationConfig};

$app = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')
            ->roles(['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'])
    )
    ->register(new HelloInvoiceDsl())
    ->boot();
```

See [Your first app](first-app.md) for the full configuration surface and the
equivalent manual wiring. The steps below use these booted services:

```php
$invoker  = $app->invoker();
$renderer = $app->renderer();
$driver   = $app->driver();
$tenant   = $app->tenant();
```

## 3. Create an invoice {#3-create-an-invoice}

```php
$created = $invoker->invoke('billing.invoice.create', null, [
    'number'        => 'INV-2026-001',
    'customer_name' => 'ACME Corporation',
    'amount'        => ['amount' => '1500.00', 'currency' => 'USD'],
]);
```

- `$created['id']` — a 26-character ULID.
- `$created['status']` — `'DRAFT'`, applied automatically from the enum
  default. The `create` action did not pass `status`.

## 4. Issue it (a workflow transition) {#4-issue-it-a-workflow-transition}

```php
use Ausus\Reference;

$ref = new Reference('acme', 'billing.invoice', $created['id']);
$out = $invoker->invoke('billing.invoice.issue', $ref, []);
// $out['status']    === 'ISSUED'
// $out['issued_at']  is an RFC-3339 timestamp (the ->stamp('issued_at') effect)
```

The `issue` action is declared `transition('status', from: 'DRAFT', to: 'ISSUED')`.
The workflow runtime checks the invoice is currently `DRAFT` before allowing it.

## 5. Watch the guards work {#5-watch-the-guards-work}

These calls are *supposed* to fail — they demonstrate the runtime guarantees.

```php
// Issue again, from ISSUED -> rejected: no transition declared from ISSUED.
try {
    $invoker->invoke('billing.invoice.issue', $ref, []);
} catch (\Ausus\WorkflowStateMismatch $e) {
    // expected
}

// Cross-tenant reference -> rejected before any work happens.
$wrong = new Reference('other-tenant', 'billing.invoice', $created['id']);
try {
    $invoker->invoke('billing.invoice.issue', $wrong, []);
} catch (\Ausus\TenantBoundaryViolation $e) {
    // expected
}
```

Then a legal transition — `cancel` is declared from **both** `DRAFT` and
`ISSUED`:

```php
$out = $invoker->invoke('billing.invoice.cancel', $ref, []);
// $out['status'] === 'CANCELLED'
```

## 6. Optimistic concurrency {#6-optimistic-concurrency}

Every row carries a `_version` ULID. An `update` with a stale version is
rejected:

```php
$repo    = $driver->context($tenant, $driver->beginTransaction($tenant))
                  ->repository('billing.invoice');
$current = $repo->find($ref);
$stale   = $current->version;

$repo->update($ref, ['customer_name' => 'New Name'], $stale);   // ok — bumps _version
$repo->update($ref, ['customer_name' => 'Bad Name'], $stale);   // throws ConcurrencyConflict
```

## 7. Render a projection {#7-render-a-projection}

```php
$summary = $renderer->render('billing.invoice.summary');
// $summary['schemaVersion']  === '1.0.0'
// $summary['fields']          -> 5 field descriptors
// $summary['actions']         -> 2 action descriptors (create, cancel)
// $summary['data']['items']   -> the invoices for this tenant
```

This [ViewSchema](../frontend/viewschema.md) is exactly what the HTTP API
returns and what the [React renderer](../frontend/react-renderer.md) draws.

## What HelloInvoice proves {#what-helloinvoice-proves}

Running the full playground exercises, in order: persistence round-trip,
enum-default application, workflow transitions, workflow rejection, tenant
isolation, optimistic locking, audit-trail emission, and projection rendering
— plus that the **DSL plugin and an equivalent hand-written plugin compile to
a byte-identical graph hash**.

## Current v0.1.0 limitations {#current-v010-limitations}

- Projection **list** rendering reads rows for the current tenant with no
  filtering or real pagination — `pagination.nextCursor` is always `null`.
- There is no `delete` action kind and no rich input validation beyond
  field presence and declared types.
- `cancel` uses `andTransition()` to declare two explicit sources; wildcard
  (`from: '*'`) transitions are supported by the runtime but not used here.

## Next {#next}

- [Core Concepts](../concepts/metadata-graph.md) — the model behind all of this.
- [The PHP DSL](../backend/php-dsl.md) — every builder method.
