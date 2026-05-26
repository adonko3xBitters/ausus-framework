# ausus/standard-stack

The V0 Standard Stack. Bundles the implemented core packages at compatible
versions **and** ships the high-level `Ausus\Application` bootstrap facade.

## What it does

- Requires every implemented core package at a compatible version.
- Pins kernel major; component packages track per their own SemVer.
- Single-line install for plugin authors: `composer require ausus/standard-stack`.
- Provides `Ausus\Application` — a four-call bootstrap facade
  (`create → register → boot → invoke`) that composes the compiler, the SQLite
  persistence driver and the default runtime.

## `Ausus\Application`

```php
use Ausus\Application;

$app = Application::create([
        'tenant' => 'acme',
        'roles'  => ['invoice.creator', 'invoice.issuer'],
    ])
    ->register(new HelloInvoiceDsl())
    ->boot();

$created = $app->invoke('billing.invoice.create', null, [/* inputs */]);
$view    = $app->render('billing.invoice.summary');
```

`Application` adds no behaviour — it is a composition convenience. Every object
it builds (`Invoker`, `SqlitePersistenceDriver`, `PolicyEngine`, …) stays
directly constructable, and the booted services are exposed via `graph()`,
`invoker()`, `driver()`, `renderer()` and `pdo()` for advanced wiring.

## Required packages

| Package                  | Layer | Role                                                         |
|--------------------------|-------|--------------------------------------------------------------|
| `ausus/kernel`           | L0    | Contracts, value objects, DSL facade                         |
| `ausus/runtime-default`  | L2    | Invoker + Policy Engine + Workflow runtime + Effect dispatch  |
| `ausus/persistence-sql`  | L3    | SQL PersistenceDriver + SchemaDeriver + DatabaseAuditSink     |
| `ausus/api-http`         | L4    | PSR-7/15 HTTP API surface                                     |

Skeleton packages (`tenancy-row`, `audit-database`, `auth-bridge`,
`presentation-default`) are reserved names and join `require` when their
RFC-012 §16 implementations land. They are listed under `extra.ausus.v0-scope`.

The npm half of `react.web.v1` is in `renderer/react/`; install via
`npm install @ausus/renderer-react` in the frontend.

## Version policy

Standard Stack version tracks kernel major. Component packages SemVer
independently; this package's `require` enforces compatible ranges.
