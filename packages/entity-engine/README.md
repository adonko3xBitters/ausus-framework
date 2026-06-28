# ausus/entity-engine

AUSUS **2.0** — Entity Engine (L1). The two halves of EE-RFC-011: the
**content-addressed compile pipeline** (Canonicalizer → Hasher → ClosureValidator
→ `EntitySchema`) and the **runtime** (`bind` → `RuntimeEntity`, with fail-closed
authorization). Same semantics ⇒ same hash; binding never recompiles.

## Installation

```bash
composer require ausus/entity-engine:^2.0
```

## Dependencies

- PHP 8.3+
- `ausus/kernel`

## Public surface

- `Ausus\Engine\Compile\Compiler` — `compile(EntityDefinition[]): CompiledGraph`
  (`EntitySchema[]` + `SchemaIndex`); atomic, any error produces nothing.
- `Ausus\Engine\Compile\{Canonicalizer, Hasher, ClosureValidator}` — semantic
  normal form, SHA-256 content hash, the 16 closure invariants.
- `Ausus\Engine\Runtime\DefaultEntityEngine` — `bind(EntitySchema,
  PersistenceDriver): RuntimeEntity`.
- `Ausus\Engine\Runtime\DefaultAuthorizationEvaluator` — fail-closed evaluation of
  the embedded `Expression`.

## Minimal example

```php
<?php
use Ausus\Engine\Runtime\DefaultEntityEngine;
use Ausus\Engine\Runtime\DefaultAuthorizationEvaluator;

$engine  = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repository);
$runtime = $engine->bind($repository->resolve('customer'), $driver);
$runtime->invoke('create', ['name' => 'Globex'], $context);
```

## Documentation

See the canonical reference [`docs/v2/`](../../docs/v2/README.md) and the
[Quick Start](../../docs/v2/QUICKSTART.md).
