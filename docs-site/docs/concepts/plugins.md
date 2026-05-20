---
id: plugins
title: Plugins
sidebar_label: Plugins
description: The unit of domain code in AUSUS.
---

# Plugins

A **plugin** is the unit of domain code in AUSUS. It is a PHP class that
describes a slice of an application — its entities, actions, policies,
workflows, and projections. The [compiler](metadata-graph.md) turns one or more
plugins into a `MetadataGraph`.

AUSUS is "plugin-first": you do not write controllers and models, you write a
plugin and let the framework derive the rest.

## The `Plugin` contract {#the-plugin-contract}

The low-level contract (`ausus/kernel`) is small:

```php
interface Plugin
{
    public function name(): string;        // e.g. 'billing'
    public function phpNamespace(): string; // e.g. 'Acme\\Billing'
    public function describe(): array;       // normalized descriptor arrays
}
```

- `name()` is the plugin's short name. It prefixes every FQN the plugin
  produces — entity `invoice` in plugin `billing` becomes `billing.invoice`.
- `phpNamespace()` declares the PHP namespace the plugin's domain classes live
  under.
- `describe()` returns descriptor arrays the compiler consumes.

## Writing plugins with the DSL {#writing-plugins-with-the-dsl}

You rarely implement `Plugin` directly. Instead, extend `DslPlugin` and
implement `dsl()` — the DSL builds the descriptor arrays for you:

```php
namespace Acme\Billing;

use Ausus\{DslPlugin, Dsl, Field, Action};

final class HelloInvoiceDsl extends DslPlugin
{
    public function name(): string        { return 'billing'; }
    public function phpNamespace(): string { return 'Acme\\Billing'; }

    public function dsl(Dsl $dsl): void
    {
        $dsl->entity('invoice')
            ->fields([ /* ... */ ])
            ->actions([ /* ... */ ])
            ->workflow('status')
            ->projection('summary', fields: [/* ... */]);
    }
}
```

`DslPlugin::describe()` is implemented for you — it runs your `dsl()` method
against a fresh `Dsl` builder and emits the descriptor arrays. The full builder
surface is documented in [The PHP DSL](../backend/php-dsl.md).

## Composing multiple plugins {#composing-multiple-plugins}

`Compiler::compile()` takes an array. Plugins compose by declaration — the
compiler merges their nodes into one graph:

```php
$graph = (new Compiler())->compile([
    new BillingPlugin(),
    new CrmPlugin(),
]);
```

If two plugins declare the same action FQN, the compiler throws a
**DuplicateRegistration** error. Entities and policies with the same FQN are
merged (last-wins); actions are strict.

## Current v0.1.0 limitations {#current-v010-limitations}

- **Cross-plugin references are not specially resolved.** A plugin's action
  may reference another plugin's entity only if both are compiled together and
  the FQNs line up; there is no import/dependency mechanism between plugins.
- The DSL surface is the minimal RFC-011 subset. Convention-resolved policy and
  effect classes, field-level visibility, and DSL diagnostics with file/line
  attribution are **deferred** — see [The PHP DSL](../backend/php-dsl.md).
- There is no plugin discovery or registry. You pass the plugin instances to
  `compile()` explicitly.

## Related {#related}

- [The Metadata Graph](metadata-graph.md) — what plugins compile into.
- [The PHP DSL](../backend/php-dsl.md) — the full builder reference.
- [Entities, Fields & Actions](entities-fields-actions.md) — what a plugin declares.
