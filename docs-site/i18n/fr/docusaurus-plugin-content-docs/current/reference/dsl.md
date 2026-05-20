---
id: dsl
title: Référence du DSL
sidebar_label: Référence du DSL
description: Aide-mémoire condensé pour le DSL PHP d'AUSUS.
---

# Référence du DSL

Un aide-mémoire condensé pour le DSL d'AUSUS. Pour la version expliquée, voir
[Le DSL PHP](../backend/php-dsl.md).

## Squelette de plugin {#plugin-skeleton}

```php
use Ausus\{DslPlugin, Dsl, Field, Action};

final class MyPlugin extends DslPlugin
{
    public function name(): string        { return 'myplugin'; }
    public function phpNamespace(): string { return 'Acme\\MyPlugin'; }

    public function dsl(Dsl $dsl): void { /* declare entities */ }
}
```

## `Dsl` {#dsl}

| Appel | Renvoie |
|---|---|
| `$dsl->entity('local')` | `EntityBuilder` pour `{plugin}.local` |

## `EntityBuilder` {#entitybuilder}

| Appel | Objectif |
|---|---|
| `->fields(['name' => FieldBuilder, ...])` | déclarer les champs du domaine |
| `->actions(['name' => ActionBuilder, ...])` | déclarer les actions |
| `->workflow('fieldName')` | marquer un champ `enum` comme état de workflow |
| `->projection('name', fields: [...], actions: [...], role: '...')` | déclarer une projection |

## `Field` {#field}

| Appel | Type |
|---|---|
| `Field::string()` | `string` |
| `Field::integer()` | `integer` |
| `Field::datetime()` | `datetime` |
| `Field::money()` | `money` |
| `Field::enum('A', 'B', ...)` | `enum` |

### Modificateurs de `FieldBuilder` {#fieldbuilder-modifiers}

| Appel | Effet |
|---|---|
| `->nullable()` | autorise null |
| `->default($v)` | valeur par défaut |
| `->unique()` | unique au sein du tenant (enregistré, non appliqué en v0.1.0) |
| `->max($n)` | longueur maximale de chaîne (enregistrée, non appliquée en v0.1.0) |
| `->currency('USD')` | devise du champ money |
| `->options([...])` | options de l'enum |

## `Action` {#action}

| Appel | Genre | `subjectRequired` |
|---|---|---|
| `Action::create('f1', 'f2', ...)` | create | `false` |
| `Action::transition('field', from: 'A', to: 'B')` | transition | `true` |

### Modificateurs de `ActionBuilder` {#actionbuilder-modifiers}

| Appel | Effet |
|---|---|
| `->requireRole('role')` | attache une politique `RoleRequired` |
| `->stamp('field')` | (transition) écrit l'horodatage courant dans `field` |
| `->andTransition('field', from: 'B', to: 'C')` | (transition) ajoute une paire source→cible |

## Types de champs {#field-types}

`string` · `integer` · `enum` · `money` · `datetime` — déclarables.
`identity` · `version` · `system_string` — types système, injectés par le kernel.

## Champs système (auto-injectés) {#system-fields-auto-injected}

`id` (identity) · `tenant_id` (system_string) · `_version` (version) ·
`created_at` (datetime) · `updated_at` (datetime).

## Nommage des FQN {#fqn-naming}

| Élément | Modèle | Exemple |
|---|---|---|
| Entité | `{plugin}.{entity}` | `billing.invoice` |
| Action | `{entity}.{action}` | `billing.invoice.issue` |
| Projection | `{entity}.{projection}` | `billing.invoice.summary` |
| Workflow | `{entity}.lifecycle` | `billing.invoice.lifecycle` |
| Table SQL | `{entity}` avec `.` → `_` | `billing_invoice` |

## Voir aussi {#related}

- [Le DSL PHP](../backend/php-dsl.md) — explication complète.
- [Référence des erreurs](errors.md) — la taxonomie des exceptions.
