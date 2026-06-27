# 4. Your first project

This walkthrough uses the exact APIs shipped in v1.0. It mirrors the reference
applications under `apps/`.

## 0. Prerequisites

PHP 8.3+. In the monorepo, the packages are resolved via Composer path
repositories (`composer install`). Node 18+ only if you render the React UI.

## 1. Define an entity (DSL)

Create `entities/Customer.php` and `entities/Invoice.php`. The DSL is closed: a
file returns exactly one `EntityDefinition`.

```php
<?php // entities/Customer.php
use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\{FieldType, ActionKind};

return Definition::make('customer', true)            // identity, tenantScoped
    ->field('name', FieldType::String)
    ->field('status', FieldType::Enum, [
        'default' => 'inactive', 'writeProtected' => true,
        'typeOptions' => ['values' => ['active', 'inactive']],
    ])
    ->action('create', ActionKind::Create, ['inputs' => ['name']])
    ->action('activate', ActionKind::Transition, [
        'transition' => ['field' => 'status', 'from' => 'inactive', 'to' => 'active'],
    ])
    ->projection('board', ['fields' => [['field' => 'name'], ['field' => 'status']]])
    ->build();
```

```php
<?php // entities/Invoice.php
use Ausus\Authoring\Dsl\{Definition, Expr};
use Ausus\Definition\Enum\{FieldType, ActionKind};

return Definition::make('invoice', true)
    ->field('amount', FieldType::Decimal)
    ->field('customer', FieldType::Reference, ['nullable' => true, 'typeOptions' => ['target' => 'customer']])
    // guard: reject absurd amounts (input fact)
    ->action('create', ActionKind::Create, [
        'inputs' => ['amount', 'customer'],
        'guard'  => Expr::lt(Expr::input('amount'), 1000000),
    ])
    ->projection('board', [
        'fields' => [['field' => 'amount']],
        'expand' => [['via' => 'customer', 'projection' => 'board']],   // single-hop
    ])
    ->build();
```

## 2. Compile

```php
use Ausus\Cli\Command\CompileEntitiesCommand;

$code = (new CompileEntitiesCommand())->run('entities', '.ausus');
// → "Compiled: definitions: 2 / schemas: 2"  → writes .ausus/schemas/<hash>.json + index.json
```

## 3. Run (resolve → bind → invoke / read)

```php
use Ausus\Cli\Repository\FileSchemaRepository;
use Ausus\Engine\Runtime\{DefaultEntityEngine, DefaultAuthorizationEvaluator};
use Ausus\Persistence\Memory\MemoryDriver;
use Ausus\Api\Runtime\Http\RequestContextFactory;

$repo    = new FileSchemaRepository('.ausus');                 // no recompilation
$engine  = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$driver  = new MemoryDriver();                                 // any PersistenceDriver
$ctx     = (new RequestContextFactory())->fromHeaders(['X-Tenant-ID' => 'demo', 'X-Actor-Type' => 'user']);

$cust = $engine->bind($repo->resolve('customer'), $driver)->invoke('create', ['name' => 'Globex'], $ctx);
$inv  = $engine->bind($repo->resolve('invoice'),  $driver);
$inv->invoke('create', ['amount' => 1200, 'customer' => $cust->reference->identityHandle], $ctx);

$rows = $inv->read('board', [], $ctx);
// → [ ['amount' => 1200, 'customer' => ['name' => 'Globex', 'status' => 'inactive']] ]
```

## 4. Serve the HTTP API

```php
use Ausus\Api\Runtime\Http\RuntimeApi;

$api = new RuntimeApi($repo, $engine, $driver, new RequestContextFactory());
$api->dispatch('GET',  '/api/entities/invoice',                     $headers);
$api->dispatch('POST', '/api/entities/invoice/actions/create',      $headers, ['inputs' => ['amount' => 99]]);
$api->dispatch('GET',  '/api/entities/invoice/projections/board',   $headers);
// each returns ['status' => int, 'body' => array]
```

## 5. Add a view (View System)

```php
use Ausus\View\{ViewDefinition, PageDefinition, SectionDefinition, ViewRegistry};

$views = new ViewRegistry();
$views->register(new ViewDefinition('billing', 'Billing', [
    new PageDefinition('billing', 'Billing', [
        SectionDefinition::projection('Invoices', 'invoice', 'board'),   // a projection section
        SectionDefinition::action('Create invoice', 'invoice', 'create'),// OR an action section
    ]),
]));
$json = $views->toArray();   // serialise for the renderer
```

## 6. Render in React

```tsx
import { RuntimeClient, RendererApp } from '@ausus/react-renderer';

const client = new RuntimeClient({ baseUrl: '/', headers: { 'X-Tenant-ID': 'demo', 'X-Actor-Type': 'user' } });
// auto-discovers entities, builds navigation, tables, and forms from the API
<RendererApp client={client} entities={['customer', 'invoice']} />
```

To drive views instead of raw entities, feed a serialized `ViewDefinition` to the
View System's `ViewRenderer` (`packages/view-system/ui`), which delegates each
section to the renderer's `ProjectionPage` / `ActionForm`.
