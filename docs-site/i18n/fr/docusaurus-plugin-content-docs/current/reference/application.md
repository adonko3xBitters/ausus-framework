---
id: application
title: Application & ApplicationConfig
sidebar_label: Application
description: Reference for Ausus\Application and Ausus\ApplicationConfig — the bootstrap facade and its typed config builder.
---

# `Ausus\Application` & `Ausus\ApplicationConfig`

The two classes a v0.1.x consumer touches every time: `Application`
composes the compiler, the SQLite driver and the runtime; `ApplicationConfig`
is the typed, immutable builder you usually hand to `Application::create()`.

Both ship in the `ausus/standard-stack` package.

## Lifecycle at a glance {#lifecycle}

```php
use Ausus\{Application, ApplicationConfig};

$app = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')
            ->roles(['issue.author', 'issue.maintainer'])
            ->sqlite(__DIR__ . '/app.sqlite')
    )
    ->register(new HelloInvoiceDsl())
    ->boot();

$result  = $app->run('billing.invoice.create', null, [...]);   // typed
$view    = $app->render('billing.invoice.summary');             // ViewSchema array
$response = $app->http($psrRequest);                            // PSR-7 → PSR-7
```

The four-call shape `create → register → boot → invoke` is the
recommended consumer surface. Every other method (the accessors,
`run()`, `http()`, `router()`, …) is additive sugar.

## `Application::create(ApplicationConfig|array): self` {#create}

Builds an unbooted `Application` from a config.

The argument may be either:

- an **`ApplicationConfig`** (recommended — typed, IDE-discoverable, fluent), or
- a plain **`array<string,mixed>`** with the keys catalogued in
  [Configuration reference](configuration.md).

Both forms are bit-for-bit equivalent — an `ApplicationConfig` is
converted to its array form internally before the resolution path runs.

Throws `InvalidArgumentException` on:

- an unknown array key (the message lists every recognised key);
- a config value with the wrong type (`'driver'` must implement
  `Ausus\PersistenceDriver`, `'database'` must be a `PDO` or a string
  path, `'apiPrefix'` must start with `/`, …).

## `register(Plugin ...$plugins): self` {#register}

Appends one or more plugins. Throws `RuntimeException` if called after
`boot()`. Idempotent across registrations of distinct plugins.

```php
$app->register(new BillingPlugin(), new ShippingPlugin());
```

## `boot(): self` {#boot}

Compiles every registered plugin into a single `MetadataGraph`, wires
the SQLite driver and the default runtime, and applies the derived
schema (`CREATE TABLE IF NOT EXISTS` per entity + the
`kernel_audit_log` table).

Idempotent: calling `boot()` twice is a no-op. Throws
`RuntimeException` if no plugin has been registered. **Lazy** —
calling any accessor or `invoke()` / `run()` / `http()` on an unbooted
Application boots it first.

## Action invocation {#invocation}

### `invoke(string $fqn, ?Reference $subject = null, array $inputs = []): array<string,mixed>` {#invoke}

Runs the full Invoker chain — preflight → policy → workflow guard →
effect → audit — inside one database transaction. Returns the raw
effect outputs as an associative array.

```php
$outputs = $app->invoke('billing.invoice.create', null, [
    'number' => 'INV-001', 'customer_name' => 'ACME', 'amount' => ['amount' => '10', 'currency' => 'USD'],
]);
```

### `run(string $fqn, ?Reference $subject = null, array $inputs = []): InvocationResult` {#run}

Same chain as `invoke()`, with a typed return:

```php
final readonly class InvocationResult {
    public string     $actionFqn;
    public ?Reference $subject;        // post-action subject — new ref for create, input subject for transition/update
    public array      $outputs;
    public function id(): ?string;
    public function output(string $key): mixed;
}
```

Use `run()` when you want IDE discoverability on the result, or when
you need the post-action `Reference` (e.g. to chain the next invocation
in a CLI script).

### `render(string $projectionFqn, ?Reference $subject = null): array<string,mixed>` {#render}

Renders a projection to a ViewSchema array. With a subject → detail
shape (`data.item`); without → list shape (`data.items`).

```php
$viewSchema = $app->render('billing.invoice.summary');
$detail     = $app->render('billing.invoice.detail', $invoiceRef);
```

## `http(ServerRequestInterface $request): ResponseInterface` {#http}

One-call HTTP entry point. Internally builds a `Router` once (lazy,
cached) and dispatches every request through it.

```php
use Ausus\Api\Http\Emitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

$factory = new Psr17Factory();
$creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
Emitter::emit($app->http($creator->fromGlobals()));
```

PSR-17 factory resolution, in order:

1. `ApplicationConfig::responseFactory()` / `streamFactory()` /
   `psr17(...)` if configured;
2. `Nyholm\Psr7\Factory\Psr17Factory` autodetected when on the
   autoloader (the path every documented example takes);
3. otherwise an `InvalidArgumentException` with a fix-it message.

The mount prefix comes from `ApplicationConfig::apiPrefix('/api')`
(default `/api`).

## `router(ResponseFactoryInterface, StreamFactoryInterface, string $prefix = '/api'): Router` {#router}

Lower-level factory that builds a fresh `Router` against the booted
graph/driver/audit-sink. Useful when you want to manipulate the Router
directly (custom middleware, multiple mount points). Each call returns
a new instance; the Router used by `http()` is cached separately and
not affected.

## Accessors {#accessors}

| Method | Returns | Notes |
|---|---|---|
| `graph()` | `MetadataGraph` | The compiled graph. Reads boot-cached value. |
| `invoker()` | `Ausus\Runtime\Invoker` | Drop down to the low-level Invoker. |
| `driver()` | `Ausus\PersistenceDriver` | The persistence driver — `SqlitePersistenceDriver` unless `ApplicationConfig::driver()` overrode it. |
| `renderer()` | `Ausus\Runtime\ProjectionRenderer` | The projection renderer used by `render()`. |
| `auditSink()` | `Ausus\AuditSink` | The audit sink the runtime writes through. |
| `pdo()` | `?\PDO` | The underlying PDO connection when the built-in SQLite path is used (`null` only if you supplied a fully-custom `driver` + `auditSink`). |
| `tenant()` | `Ausus\Tenant` | Available before boot. |
| `actor()` | `Ausus\Actor` | Available before boot. |
| `isBooted()` | `bool` | Whether `boot()` has run. |
| `reference(string $entityFqn, string $id): Reference` | `Reference` | Builds a `Reference` scoped to this Application's tenant — convenience for chaining. |

Every accessor that returns a runtime service boots the Application
lazily on first call.

## Errors {#errors}

`Application::create()` throws only `InvalidArgumentException`. Runtime
errors during `invoke()` / `run()` / `http()` are the kernel taxonomy
described in [Error Reference](errors.md): `PolicyDenied`,
`WorkflowStateMismatch`, `ConcurrencyConflict`, `NotFound`,
`UnknownAction`, `TenantBoundaryViolation`, etc. `http()` maps each to
the documented HTTP status via `ErrorMapper`.

## Scope notes {#scope-notes}

- **One tenant + one actor per Application.** Multi-tenant request
  handling means one Application per tenant. `http()` *also* respects
  the per-request `X-Tenant-ID` / `X-Actor-*` headers that the Router
  reads; the Application's `tenant`/`actor` are used by `invoke()` /
  `run()` (process-level).
- **Not a DI container.** `Application` does not register your services
  or resolve them; it composes the framework's own services only.

---

## `Ausus\ApplicationConfig` {#applicationconfig}

A fluent, immutable typed builder. Every setter returns a new instance
with the value applied; the receiver is never mutated. Pass the result
of any chain into `Application::create(...)`.

```php
use Ausus\ApplicationConfig;

$config = ApplicationConfig::make()
    ->tenant('acme')
    ->actor('user42')
    ->roles(['invoice.creator', 'invoice.issuer'])
    ->sqlite(__DIR__ . '/app.sqlite')
    ->apiPrefix('/api');
```

`make()` is the only entry point — the constructor is private.

### Setters {#setters}

| Method | Argument | Default | Notes |
|---|---|---|---|
| `tenant(string\|Tenant)` | non-empty id, or a `Tenant` | `'default'` | Empty string is rejected. |
| `actor(string\|Actor)` | string → actor id; `Actor` → full actor (overrides `actorId`/`roles`/`permissions`) | — | Overloaded for convenience. |
| `actorId(string)` | non-empty id | `'app'` | Explicit alias of `actor(string)`. |
| `roles(string[])` | list of role names | `[]` | Replaces; non-string / empty-string entries throw `InvalidArgumentException`. |
| `permissions(string[])` | list | `[]` | Same validation as `roles()`. |
| `sqlite(string)` | non-empty path; `':memory:'` valid | — | **Mutually exclusive** with `pdo()` — the second call throws. |
| `pdo(\PDO)` | live connection | — | **Mutually exclusive** with `sqlite()`. |
| `migrate(bool = true)` | toggle | `true` | Whether `boot()` runs `SchemaDeriver` against the chosen database. |
| `driver(PersistenceDriver)` | advanced | — | Replace the SQLite driver entirely. |
| `auditSink(AuditSink)` | advanced | — | Replace the database audit sink. |
| `kernelVersion(string)` | non-empty | `'1.0.0'` | Recorded in the graph; used only by hash determinism. |
| `apiPrefix(string)` | non-empty, starts with `/`, no trailing `/` | uses Router default (`/api`) | URL prefix the Router mounts under. |
| `psr17(object)` | a factory implementing both `ResponseFactoryInterface` and `StreamFactoryInterface` (e.g. nyholm's `Psr17Factory`) | autodetect nyholm | Convenience setter — assigns both factory slots at once. |
| `responseFactory(ResponseFactoryInterface)` | — | autodetect nyholm | Split form when your library ships separate factories. |
| `streamFactory(StreamFactoryInterface)` | — | autodetect nyholm | Same. |

### Output {#applicationconfig-output}

`toArray(): array<string,mixed>` returns the canonical array form
`Application::create()` consumes. Only non-default values appear in
the output — the result is the minimal representation of the choices
the caller made.

```php
$config = ApplicationConfig::make()->tenant('acme');
$config->toArray();
// → ['tenant' => Ausus\Tenant{...}]
```

### Property access {#applicationconfig-properties}

Every accepted value is exposed as a `public readonly` property:
`$config->tenant`, `$config->actor`, `$config->actorId`,
`$config->roles`, `$config->permissions`, `$config->pdo`,
`$config->sqlitePath`, `$config->migrate`, `$config->kernelVersion`,
`$config->driver`, `$config->auditSink`, `$config->apiPrefix`,
`$config->responseFactory`, `$config->streamFactory`. Read-only.

### Validation timing {#validation-timing}

Every setter validates at the call that introduced the value —
unknown keys, empty strings, conflicting persistence config, wrongly-typed
factories. There is **no** boot-time validation deferral: a misconfigured
chain throws before the chain ends.

```php
// Throws at .sqlite(), before boot, with a clear message:
ApplicationConfig::make()->pdo($pdo)->sqlite('/tmp/x.sqlite');
// InvalidArgumentException: ApplicationConfig::sqlite(): cannot set a sqlite
// path when pdo() has already been set — they are mutually exclusive.
```

## Related {#related}

- [Configuration reference](configuration.md) — every config key + env var on one page.
- [HTTP routes reference](http-routes.md) — routes that `http()` serves.
- [DSL reference](dsl.md) — what you register on the Application.
- [Error reference](errors.md) — the kernel taxonomy raised by `invoke()` / `run()`.
