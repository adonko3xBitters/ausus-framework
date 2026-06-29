# AUSUS 2.0 — Quick Start

A first project, **outside the monorepo**, from an empty directory to a rendered
UI. Requires PHP 8.3+ (and Node 18+ for the React step). It assumes the `ausus/*`
packages are installed from Packagist and `@ausus/react-renderer` from npm.

---

## 1. Install

```bash
mkdir my-app && cd my-app
composer require ausus/cli:^2.0 ausus/persistence-memory:^2.0 ausus/api-runtime:^2.0
```

`ausus/cli` pulls `ausus/kernel`, `ausus/authoring`, and `ausus/entity-engine`;
`ausus/api-runtime` and `ausus/persistence-memory` add the HTTP runtime and the
reference driver. (Add `ausus/view-system:^2.0` if you assemble views.)

## 2. Author a first `EntityDefinition`

`entities/Customer.php` — a definition file returns exactly one built definition:

```php
<?php
use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\Enum\ActionKind;

return Definition::make('customer', true)
    ->field('name', FieldType::String)
    ->field('email', FieldType::String)
    ->field('status', FieldType::Enum, [
        'default' => 'inactive',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['active', 'inactive']],
    ])
    ->action('create', ActionKind::Create, [
        'inputs' => ['name', 'email'],
        'guard'  => Expr::eq(Expr::actor('type'), 'user'),
    ])
    ->action('activate', ActionKind::Transition, [
        'transition' => ['field' => 'status', 'from' => 'inactive', 'to' => 'active'],
    ])
    ->projection('board', ['fields' => [['field' => 'name'], ['field' => 'status']]])
    ->build();
```

## 3. Compile (content-addressed)

`compile.php`:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Ausus\Cli\Command\CompileEntitiesCommand;

exit((new CompileEntitiesCommand())->run(__DIR__ . '/entities', __DIR__ . '/.ausus'));
```

```bash
php compile.php
# → .ausus/schemas/<hash>.json + .ausus/index.json
```

## 4. Run an entity

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Ausus\Cli\Repository\FileSchemaRepository;
use Ausus\Engine\Runtime\DefaultEntityEngine;
use Ausus\Engine\Runtime\DefaultAuthorizationEvaluator;
use Ausus\Persistence\Memory\MemoryDriver;
use Ausus\Api\Runtime\Http\RequestContextFactory;

$repo    = new FileSchemaRepository(__DIR__ . '/.ausus');   // reads disk; never recompiles
$engine  = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$driver  = new MemoryDriver();
$ctx     = (new RequestContextFactory(new DateTimeImmutable()))
    ->fromHeaders(['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user']);

$customer = $engine->bind($repo->resolve('customer'), $driver);
$created  = $customer->invoke('create', ['name' => 'Globex', 'email' => 'ops@globex.test'], $ctx);
$customer->invoke('activate', ['id' => $created->reference->identityHandle], $ctx);
```

## 5. Expose the HTTP API

```php
<?php
use Ausus\Api\Runtime\Http\RuntimeApi;
use Ausus\Api\Runtime\Http\RequestContextFactory;

$api = new RuntimeApi($repo, $engine, $driver, new RequestContextFactory(new DateTimeImmutable()));

$res = $api->dispatch('GET', '/api/entities/customer/projections/board',
    ['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user']);
// $res === ['status' => 200, 'body' => [...]]
```

Wire `dispatch($method, $path, $headers, $body)` into any HTTP front controller —
it returns `['status' => int, 'body' => array]`. Routes:
`GET /api/entities/{e}`, `GET …/projections/{p}`, `POST …/actions/{a}`.

## 6. Render with React

```bash
npm install @ausus/react-renderer react react-dom
```

```tsx
import { RuntimeClient, RendererApp } from '@ausus/react-renderer';

const client = new RuntimeClient({ baseUrl: 'http://localhost:8080' });

export function App() {
  return <RendererApp client={client} entities={['customer']} />;
}
```

The renderer discovers entities, projections and actions from the HTTP contract
only — point it at your API base URL and it renders.

---

See the full reference under [`docs/v2/`](README.md): architecture, the compile
pipeline, capabilities, and the documented known limits (EE-RFC-011 /
EE-RFC-012).
