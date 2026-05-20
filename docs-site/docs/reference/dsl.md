---
id: dsl
title: DSL Reference
sidebar_label: DSL Reference
description: Condensed cheat sheet for the AUSUS PHP DSL.
---

# DSL Reference

A condensed cheat sheet for the AUSUS DSL. For the explained version, see
[The PHP DSL](../backend/php-dsl.md).

## Plugin skeleton

```php
use Ausus\{DslPlugin, Dsl, Field, Action};

final class MyPlugin extends DslPlugin
{
    public function name(): string        { return 'myplugin'; }
    public function phpNamespace(): string { return 'Acme\\MyPlugin'; }

    public function dsl(Dsl $dsl): void { /* declare entities */ }
}
```

## `Dsl`

| Call | Returns |
|---|---|
| `$dsl->entity('local')` | `EntityBuilder` for `{plugin}.local` |

## `EntityBuilder`

| Call | Purpose |
|---|---|
| `->fields(['name' => FieldBuilder, ...])` | declare domain fields |
| `->actions(['name' => ActionBuilder, ...])` | declare actions |
| `->workflow('fieldName')` | mark an `enum` field as workflow state |
| `->projection('name', fields: [...], actions: [...], role: '...')` | declare a projection |

## `Field`

| Call | Type |
|---|---|
| `Field::string()` | `string` |
| `Field::integer()` | `integer` |
| `Field::datetime()` | `datetime` |
| `Field::money()` | `money` |
| `Field::enum('A', 'B', ...)` | `enum` |

### `FieldBuilder` modifiers

| Call | Effect |
|---|---|
| `->nullable()` | allow null |
| `->default($v)` | default value |
| `->unique()` | unique-within-tenant (recorded, not enforced in v0.1.0) |
| `->max($n)` | string max length (recorded, not enforced in v0.1.0) |
| `->currency('USD')` | money currency |
| `->options([...])` | enum options |

## `Action`

| Call | Kind | `subjectRequired` |
|---|---|---|
| `Action::create('f1', 'f2', ...)` | create | `false` |
| `Action::transition('field', from: 'A', to: 'B')` | transition | `true` |

### `ActionBuilder` modifiers

| Call | Effect |
|---|---|
| `->requireRole('role')` | attach a `RoleRequired` policy |
| `->stamp('field')` | (transition) write current timestamp to `field` |
| `->andTransition('field', from: 'B', to: 'C')` | (transition) add a source→target pair |

## Field types

`string` · `integer` · `enum` · `money` · `datetime` — declarable.
`identity` · `version` · `system_string` — system types, injected by the kernel.

## System fields (auto-injected)

`id` (identity) · `tenant_id` (system_string) · `_version` (version) ·
`created_at` (datetime) · `updated_at` (datetime).

## FQN naming

| Thing | Pattern | Example |
|---|---|---|
| Entity | `{plugin}.{entity}` | `billing.invoice` |
| Action | `{entity}.{action}` | `billing.invoice.issue` |
| Projection | `{entity}.{projection}` | `billing.invoice.summary` |
| Workflow | `{entity}.lifecycle` | `billing.invoice.lifecycle` |
| SQL table | `{entity}` with `.` → `_` | `billing_invoice` |

## Related

- [The PHP DSL](../backend/php-dsl.md) — full explanation.
- [Error Reference](errors.md) — the exception taxonomy.
