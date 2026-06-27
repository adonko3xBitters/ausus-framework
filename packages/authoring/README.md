# ausus/authoring

AUSUS **2.0** — Authoring (L1). A **closed PHP DSL** whose only product is a
frozen `EntityDefinition` (EE-RFC-012). It introduces no concept and depends only
on the kernel; it has no side effects and reads no external state.

## Installation

```bash
composer require ausus/authoring:^2.0
```

## Dependencies

- PHP 8.3+
- `ausus/kernel`

## Public surface

- `Ausus\Authoring\Dsl\Definition` — `Definition::make(string $identity, bool
  $tenantScoped)`, then `->field()`, `->action()`, `->projection()`, `->build()`.
- `Ausus\Authoring\Dsl\Expr` — authorization expression builder (`Expr::eq`,
  `Expr::actor`, `Expr::subject`, `Expr::and`, `Expr::not`, …).

## Minimal example

```php
<?php
use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\Enum\ActionKind;

return Definition::make('customer', true)
    ->field('name', FieldType::String)
    ->field('status', FieldType::Enum, [
        'default' => 'inactive',
        'typeOptions' => ['values' => ['active', 'inactive']],
    ])
    ->action('create', ActionKind::Create, [
        'inputs' => ['name'],
        'guard'  => Expr::eq(Expr::actor('type'), 'user'),
    ])
    ->action('activate', ActionKind::Transition, [
        'transition' => ['field' => 'status', 'from' => 'inactive', 'to' => 'active'],
    ])
    ->projection('board', ['fields' => [['field' => 'name'], ['field' => 'status']]])
    ->build();
```

## Documentation

See the canonical reference [`docs/v2/`](../../docs/v2/README.md) and the
[Quick Start](../../docs/v2/QUICKSTART.md).
