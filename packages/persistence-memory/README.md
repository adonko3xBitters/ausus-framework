# ausus/persistence-memory

AUSUS **2.0** — in-memory `PersistenceDriver` (L3). The reference conformance
driver: a complete, dependency-free implementation of the kernel storage contract
(transactions with rollback, tenant-scoped repositories). Intended for tests and
the vertical slice, not production storage.

## Installation

```bash
composer require ausus/persistence-memory:^2.0
```

## Dependencies

- PHP 8.3+
- `ausus/kernel`

## Public surface

- `Ausus\Persistence\Memory\MemoryDriver` — implements `Ausus\PersistenceDriver`
  (`beginTransaction`, `commit`, `rollback`, `context`, `generateIdentity`).
- `Ausus\Persistence\Memory\MemoryRepository` — implements `Ausus\Repository`
  (`find`, `create`, `update`, `findAll`).

## Minimal example

```php
<?php
use Ausus\Persistence\Memory\MemoryDriver;
use Ausus\Engine\Runtime\DefaultEntityEngine;

$driver  = new MemoryDriver();
$runtime = $engine->bind($repository->resolve('customer'), $driver); // driver injected
```

## Documentation

See the canonical reference [`docs/v2/`](../../docs/v2/README.md) and the
[Quick Start](../../docs/v2/QUICKSTART.md).
