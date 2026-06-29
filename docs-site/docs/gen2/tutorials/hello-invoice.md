---
id: hello-invoice
title: "Tutorial — Hello Invoice"
sidebar_label: Hello Invoice
description: Build your first AUSUS 2.0 application — a small invoice manager — using only the public packages, from DSL declaration to a rendered React UI.
---

# Hello Invoice

The official first application of AUSUS 2.0. It is a small invoice manager that
walks through the **entire Gen2 pipeline** — Authoring → Compiler → immutable
graph → Runtime → HTTP API → React Renderer — using **only the public packages**.
It is exactly the project you would download from GitHub: no monorepo, no path
repositories, no internal code.

## 1. Introduction

You declare an `Invoice` entity as data. The Entity Engine compiles that
declaration into a frozen, content-addressed schema and runs it: storage,
data-aware authorization, an HTTP API, and a React UI — all derived from the one
declaration. You should reach a working application in about fifteen minutes.

## 2. Installation

Requirements: **PHP 8.3+** and (for the UI) **Node 18+**. Create a project folder
and install the public Composer packages from Packagist:

```bash
mkdir hello-invoice && cd hello-invoice
composer require ausus/authoring:^2.0 ausus/entity-engine:^2.0 \
                 ausus/persistence-memory:^2.0 ausus/api-runtime:^2.0
```

`ausus/authoring` provides the DSL, `ausus/entity-engine` the Compiler and
runtime, `ausus/persistence-memory` the reference driver, and `ausus/api-runtime`
the HTTP surface. `ausus/kernel` is pulled in automatically.

## 3. Create the project

```
hello-invoice/
  composer.json
  entities/
    Invoice.php        # the declaration
  bin/
    demo.php           # compile → runtime → API, in one script
    server.php         # HTTP front controller for the React renderer
  web/                 # the React UI (added in step 8)
```

## 4. Declare the Invoice entity

`entities/Invoice.php` returns exactly one immutable `EntityDefinition`. Fields,
actions, projections, and authorization are all data. Guards use **primitive
operators only** (`eq` / `lt` / `not`): the runtime denies on any unresolved fact.

```php
<?php
use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('invoice', true)            // tenant-scoped
    ->field('number', FieldType::String)
    ->field('customer', FieldType::String)
    ->field('issueDate', FieldType::Date)
    ->field('dueDate', FieldType::Date)
    ->field('status', FieldType::Enum, [
        'default' => 'draft',
        'writeProtected' => true,                   // only transitions change it
        'typeOptions' => ['values' => ['draft', 'paid', 'cancelled']],
    ])
    ->field('total', FieldType::Decimal)

    ->action('create', ActionKind::Create, [
        'inputs' => ['number', 'customer', 'issueDate', 'dueDate', 'total'],
        'guard' => Expr::eq(Expr::actor('type'), 'user'),
    ])
    ->action('update', ActionKind::Update, [
        'inputs' => ['customer', 'dueDate', 'total'],
        'guard' => Expr::eq(Expr::actor('type'), 'user'),
    ])
    ->action('pay', ActionKind::Transition, [
        'guard' => Expr::not(Expr::lt(Expr::subject('total'), 1)),   // total >= 1
        'transition' => ['field' => 'status', 'from' => 'draft', 'to' => 'paid'],
    ])
    ->action('cancel', ActionKind::Transition, [
        'guard' => Expr::eq(Expr::actor('type'), 'user'),
        'transition' => ['field' => 'status', 'from' => 'draft', 'to' => 'cancelled'],
    ])

    ->projection('board', ['fields' => [
        ['field' => 'number'], ['field' => 'customer'], ['field' => 'status'], ['field' => 'total'],
    ]])
    ->projection('detail', ['fields' => [
        ['field' => 'number'], ['field' => 'customer'], ['field' => 'issueDate'],
        ['field' => 'dueDate'], ['field' => 'status'], ['field' => 'total'],
    ]])
    ->build();
```

## 5. Compilation

The **Compiler** turns the declaration into a content-addressed `EntitySchema`
(canonical normal form + SHA-256 hash). Same semantics ⇒ same hash; the runtime
never recompiles.

```php
use Ausus\Engine\Compile\Compiler;
use Ausus\Engine\Repository\InMemorySchemaRepository;

$invoice = require __DIR__ . '/../entities/Invoice.php';   // EntityDefinition
$graph   = (new Compiler())->compile([$invoice]);          // immutable CompiledGraph

$repo = new InMemorySchemaRepository();
foreach ($graph->schemas as $schema) {
    $repo->putByHash($schema);                             // content-addressed store
}
```

## 6. Start the runtime

Bind the schema to a driver and invoke actions. The runtime depends only on the
`PersistenceDriver` contract; here we use the reference in-memory driver.

```php
use Ausus\Engine\Runtime\DefaultEntityEngine;
use Ausus\Engine\Runtime\DefaultAuthorizationEvaluator;
use Ausus\Persistence\Memory\MemoryDriver;
use Ausus\Api\Runtime\Http\RequestContextFactory;

$engine  = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$driver  = new MemoryDriver();
$ctx     = (new RequestContextFactory(new DateTimeImmutable()))
    ->fromHeaders(['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user']);

$invoice = $engine->bind($repo->resolve('invoice'), $driver);
$created = $invoice->invoke('create', [
    'number' => 'INV-001', 'customer' => 'Globex',
    'issueDate' => '2025-01-10', 'dueDate' => '2025-02-10', 'total' => 1500,
], $ctx);
$id = $created->reference->identityHandle;

$invoice->invoke('update', ['id' => $id, 'total' => 1800], $ctx);  // patch fields
$invoice->invoke('pay', ['id' => $id], $ctx);                      // draft → paid
```

A `guest` actor calling `create` is **denied** — the guard `actor.type = user`
fails closed. Run the whole thing with `php bin/demo.php`.

## 7. Start the HTTP API

The API Runtime exposes the same domain over a framework-agnostic contract:
`dispatch(method, path, headers, body)` returns `{ status, body }`. Routes:
`GET /api/entities/{entity}`, `GET …/projections/{projection}`,
`POST …/actions/{action}`.

```php
use Ausus\Api\Runtime\Http\RuntimeApi;

$api = new RuntimeApi($repo, $engine, $driver, new RequestContextFactory(new DateTimeImmutable()));
$res = $api->dispatch('GET', '/api/entities/invoice/projections/board',
    ['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user']);
// $res === ['status' => 200, 'body' => ['rows' => [ … ]]]
```

`bin/server.php` wires this into a front controller; serve it:

```bash
php -S 127.0.0.1:8080 bin/server.php
curl http://127.0.0.1:8080/api/entities/invoice/projections/board
```

## 8. Connect the React Renderer

The renderer speaks the HTTP contract **only** — give it a base URL and it
discovers the entity, projections, and actions. Install the public npm package:

```bash
cd web
npm install @ausus/react-renderer react react-dom
```

`web/src/App.tsx`:

```tsx
import { RuntimeClient, RendererApp } from '@ausus/react-renderer';

const client = new RuntimeClient({ baseUrl: 'http://127.0.0.1:8080' });

export default function App() {
  return <RendererApp client={client} entities={['invoice']} />;
}
```

```bash
npm run dev      # open the printed URL with the API server running
```

## 9. The result

You have a working invoice manager: a list of invoices (the `board` projection),
a details view (`detail`), auto-generated forms for `create` / `update` / `pay` /
`cancel`, tenant isolation on every read and write, and authorization enforced
before anything changes — none of which you wrote by hand. Adding a field or an
action to `entities/Invoice.php` and recompiling makes it appear in the API and
the UI with no other code change.

## 10. What you just learned

- An application is **data**: one immutable `EntityDefinition`.
- The **Compiler** freezes it into a content-addressed `EntitySchema`.
- The **Runtime** binds that schema to a driver and runs actions, with
  **data-aware, fail-closed authorization** and structural multi-tenancy.
- The **API Runtime** exposes it as `{ status, body }`, and the **React Renderer**
  draws it from that contract alone.
- The whole thing runs on **public packages only** — the exact experience an
  external developer gets.

## Limitations

The reference `ausus/persistence-memory` driver lives for one process. Under
`php -S`, each request runs the router fresh, so writes do not persist across
requests; `bin/server.php` seeds a couple of invoices at boot so reads always
show data. For a persistent server, bind a persistent `PersistenceDriver` in
place of `MemoryDriver` — the rest of the application is unchanged.
