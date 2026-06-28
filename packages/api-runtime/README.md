# ausus/api-runtime

AUSUS **2.0** — HTTP API Runtime (L4). A framework-agnostic HTTP surface over a
compiled domain: `resolve → bind → invoke/read`. It consumes only the
`SchemaRepository` / `EntityEngine` / `RuntimeEntity` contracts — it never
compiles, canonicalises, hashes, or loads the DSL. No PSR-7 dependency; requests
and responses are plain arrays.

## Installation

```bash
composer require ausus/api-runtime:^2.0
```

## Dependencies

- PHP 8.3+
- `ausus/kernel`, `ausus/entity-engine`

## Public surface

- `Ausus\Api\Runtime\Http\RuntimeApi` — `new RuntimeApi($repo, $engine, $driver,
  $contextFactory)` and `dispatch(string $method, string $path, array $headers =
  [], array $body = []): array{status:int, body:array}`.
- `Ausus\Api\Runtime\Http\RequestContextFactory` — builds a `Context` from HTTP
  headers (`X-Tenant-ID`, `X-Actor-Type`, …).

Routes: `GET /api/entities/{entity}`, `GET …/projections/{projection}`,
`POST …/actions/{action}`.

## Minimal example

```php
<?php
use Ausus\Api\Runtime\Http\RuntimeApi;
use Ausus\Api\Runtime\Http\RequestContextFactory;

$api = new RuntimeApi($repository, $engine, $driver, new RequestContextFactory(new DateTimeImmutable()));
$res = $api->dispatch('GET', '/api/entities/customer/projections/board',
    ['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user']);
// $res === ['status' => 200, 'body' => [...]]
```

## Documentation

See the canonical reference [`docs/v2/`](../../docs/v2/README.md) and the
[Quick Start](../../docs/v2/QUICKSTART.md).
