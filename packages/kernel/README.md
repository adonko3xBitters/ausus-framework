# ausus/kernel

AUSUS **2.0** — Kernel (L0). Contracts and value objects only; **zero runtime
side effects, zero external dependencies**. It is the frozen foundation of the
Entity Engine and is shared by every higher layer.

## Installation

```bash
composer require ausus/kernel:^2.0
```

## Dependencies

- PHP 8.3+
- none

## Public surface

- **Entity Engine model** (`Ausus\Definition\*`) — `EntityDefinition`,
  `FieldDefinition`, `ActionDefinition`, `ProjectionDefinition`, the `Expression`
  sub-language, and the `Enum\*` families (EE-RFC-012).
- **Compiled form** (`Ausus\Compiled\*`) — `EntitySchema`, `SchemaIndex`,
  `SchemaVersion`: the content-addressed output of compilation.
- **Runtime contracts** (`Ausus\Contracts\*`) — `EntityEngine` (bind),
  `RuntimeEntity` (invoke/read), `SchemaRepository`, `AuthorizationEvaluator`,
  `Context` (EE-RFC-011).
- **Shared value objects** (`Ausus\*`) — `Reference`, `Tenant`, `TenantId`,
  `ActorRef`, `Entity`, `Decision`, and the `PersistenceDriver` / `Repository`
  storage contracts.

## Minimal example

```php
<?php
use Ausus\Definition\Enum\FieldType;
use Ausus\Contracts\EntityEngine;   // implemented by ausus/entity-engine

// The kernel declares the contracts; concrete engines/drivers live in their
// own packages and are bound at runtime:  $engine->bind($schema, $driver).
```

## Documentation

See the canonical reference [`docs/v2/`](../../docs/v2/README.md) and the
[Quick Start](../../docs/v2/QUICKSTART.md).
