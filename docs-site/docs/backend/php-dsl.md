---
id: php-dsl
title: The PHP DSL
sidebar_label: The PHP DSL
description: The fluent builder API for declaring AUSUS domains.
---

# The PHP DSL

The DSL is the fluent PHP API for declaring a domain. It is a thin builder over
the [metadata graph](../concepts/metadata-graph.md) node types — a DSL plugin
and an equivalent hand-written descriptor plugin compile to a **byte-identical
graph hash**.

This page is the builder reference. For the concepts behind it, see
[Core Concepts](../concepts/metadata-graph.md). A condensed cheat sheet is in
[Reference → DSL](../reference/dsl.md).

## `DslPlugin` {#dslplugin}

Extend `DslPlugin` and implement three methods:

```php
use Ausus\{DslPlugin, Dsl};

final class BillingPlugin extends DslPlugin
{
    public function name(): string        { return 'billing'; }
    public function phpNamespace(): string { return 'Acme\\Billing'; }

    public function dsl(Dsl $dsl): void
    {
        // declare entities here
    }
}
```

## `Dsl` — declaring an entity {#dsl--declaring-an-entity}

The `Dsl` builder has one entry point, `entity()`:

```php
$dsl->entity('invoice')   // -> EntityBuilder for 'billing.invoice'
```

The local name is prefixed with the plugin name to form the entity FQN.

## `EntityBuilder` {#entitybuilder}

`EntityBuilder` is fluent — chain the calls:

| Method | Purpose |
|---|---|
| `->fields([...])` | declare domain fields (`name => FieldBuilder`) |
| `->actions([...])` | declare actions (`name => ActionBuilder`) |
| `->workflow($fieldName)` | mark an `enum` field as the workflow state field |
| `->projection($name, fields:, actions:, role:)` | declare a read projection |

```php
$dsl->entity('invoice')
    ->fields([ /* ... */ ])
    ->actions([ /* ... */ ])
    ->workflow('status')
    ->projection('summary', fields: ['id', 'number', 'status'], role: 'invoice.viewer');
```

## `Field` — field builders {#field--field-builders}

`Field` is a static facade returning a `FieldBuilder`:

| Constructor | Field type |
|---|---|
| `Field::string()` | `string` |
| `Field::integer()` | `integer` |
| `Field::datetime()` | `datetime` |
| `Field::money()` | `money` |
| `Field::enum('A', 'B', ...)` | `enum` with the given options |

### `FieldBuilder` modifiers {#fieldbuilder-modifiers}

| Method | Effect |
|---|---|
| `->nullable()` | the field may be null |
| `->default($value)` | a default value |
| `->unique()` | recorded as unique-within-tenant (see limitations) |
| `->max($n)` | string max length |
| `->currency($code)` | money currency code |
| `->options([...])` | enum options (usually set via `Field::enum(...)`) |

```php
'number'    => Field::string()->unique()->max(32),
'amount'    => Field::money()->currency('USD'),
'status'    => Field::enum('DRAFT', 'ISSUED')->default('DRAFT'),
'issued_at' => Field::datetime()->nullable(),
```

You declare only domain fields. The five
[system fields](../concepts/entities-fields-actions.md#system-fields) are
injected by the kernel.

## `Action` — action builders {#action--action-builders}

`Action` is a static facade returning an `ActionBuilder`:

| Constructor | Action kind |
|---|---|
| `Action::create('field', ...)` | a **create** action; arguments are input field names |
| `Action::transition('field', from:, to:)` | a **transition** action |

### `ActionBuilder` modifiers {#actionbuilder-modifiers}

| Method | Effect |
|---|---|
| `->requireRole($role)` | attaches a `RoleRequired` policy for `$role` |
| `->stamp($field)` | (transition) writes the current timestamp to `$field` |
| `->andTransition($field, from:, to:)` | (transition) adds another source→target pair |

```php
'create' => Action::create('number', 'customer_name', 'amount')
              ->requireRole('invoice.creator'),

'issue'  => Action::transition('status', from: 'DRAFT', to: 'ISSUED')
              ->stamp('issued_at')
              ->requireRole('invoice.issuer'),

'cancel' => Action::transition('status', from: 'DRAFT',  to: 'CANCELLED')
              ->andTransition('status', from: 'ISSUED', to: 'CANCELLED')
              ->requireRole('invoice.canceler'),
```

:::note `andTransition`, not `transition`
The instance method is named `andTransition()` because `transition()` is the
static constructor on the same class — PHP does not allow a static and an
instance method to share a name.
:::

## Projections {#projections}

```php
->projection(
    'summary',                                       // local name
    fields:  ['id', 'number', 'customer_name'],      // field names to expose
    actions: ['create', 'cancel'],                   // action local names (default: all)
    role:    'invoice.viewer',                       // optional read policy role
)
```

If `actions` is omitted the projection exposes all of the entity's actions.

## Current v0.1.0 limitations {#current-v010-limitations}

The DSL is the minimal RFC-011 subset. **Deferred** to later versions:

- Convention-resolved policy and effect classes — v0.1.0 uses explicit
  built-ins (`RoleRequired`, `kernel.builtin.create`, `kernel.builtin.transition`).
- Field-level visibility policies.
- DSL diagnostics with file/line attribution.
- Tenant-added override registration.
- `->unique()` and `->max()` are stored in the graph but **not enforced** as
  runtime validation in v0.1.0.

## Related {#related}

- [Reference → DSL](../reference/dsl.md) — the condensed cheat sheet.
- [Plugins](../concepts/plugins.md) — what a DSL plugin is.
- [The Metadata Graph](../concepts/metadata-graph.md) — what the DSL compiles to.
