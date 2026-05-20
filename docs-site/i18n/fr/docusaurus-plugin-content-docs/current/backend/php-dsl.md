---
id: php-dsl
title: Le DSL PHP
sidebar_label: Le DSL PHP
description: L'API de construction fluide pour déclarer les domaines AUSUS.
---

# Le DSL PHP

Le DSL est l'API PHP fluide pour déclarer un domaine. C'est un constructeur léger
au-dessus des types de nœuds du [graphe de métadonnées](../concepts/metadata-graph.md) — un plugin DSL
et un plugin descripteur écrit à la main équivalent compilent vers un **hash de
graphe identique octet par octet**.

Cette page est la référence du constructeur. Pour les concepts qui la sous-tendent, voir
[Concepts fondamentaux](../concepts/metadata-graph.md). Une fiche condensée se trouve dans
[Référence → DSL](../reference/dsl.md).

## `DslPlugin` {#dslplugin}

Étendez `DslPlugin` et implémentez trois méthodes :

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

## `Dsl` — déclarer une entité {#dsl--declaring-an-entity}

Le constructeur `Dsl` a un seul point d'entrée, `entity()` :

```php
$dsl->entity('invoice')   // -> EntityBuilder for 'billing.invoice'
```

Le nom local est préfixé par le nom du plugin pour former le FQN de l'entité.

## `EntityBuilder` {#entitybuilder}

`EntityBuilder` est fluide — chaînez les appels :

| Méthode | Rôle |
|---|---|
| `->fields([...])` | déclare les champs du domaine (`name => FieldBuilder`) |
| `->actions([...])` | déclare les actions (`name => ActionBuilder`) |
| `->workflow($fieldName)` | marque un champ `enum` comme champ d'état du workflow |
| `->projection($name, fields:, actions:, role:)` | déclare une projection en lecture |

```php
$dsl->entity('invoice')
    ->fields([ /* ... */ ])
    ->actions([ /* ... */ ])
    ->workflow('status')
    ->projection('summary', fields: ['id', 'number', 'status'], role: 'invoice.viewer');
```

## `Field` — constructeurs de champs {#field--field-builders}

`Field` est une façade statique qui retourne un `FieldBuilder` :

| Constructeur | Type de champ |
|---|---|
| `Field::string()` | `string` |
| `Field::integer()` | `integer` |
| `Field::datetime()` | `datetime` |
| `Field::money()` | `money` |
| `Field::enum('A', 'B', ...)` | `enum` avec les options données |

### Modificateurs de `FieldBuilder` {#fieldbuilder-modifiers}

| Méthode | Effet |
|---|---|
| `->nullable()` | le champ peut être null |
| `->default($value)` | une valeur par défaut |
| `->unique()` | enregistré comme unique au sein du tenant (voir les limites) |
| `->max($n)` | longueur maximale de la chaîne |
| `->currency($code)` | code de devise pour `money` |
| `->options([...])` | options d'enum (généralement définies via `Field::enum(...)`) |

```php
'number'    => Field::string()->unique()->max(32),
'amount'    => Field::money()->currency('USD'),
'status'    => Field::enum('DRAFT', 'ISSUED')->default('DRAFT'),
'issued_at' => Field::datetime()->nullable(),
```

Vous déclarez uniquement les champs du domaine. Les cinq
[champs système](../concepts/entities-fields-actions.md#system-fields) sont
injectés par le kernel.

## `Action` — constructeurs d'actions {#action--action-builders}

`Action` est une façade statique qui retourne un `ActionBuilder` :

| Constructeur | Genre d'action |
|---|---|
| `Action::create('field', ...)` | une action **create** ; les arguments sont des noms de champs d'entrée |
| `Action::transition('field', from:, to:)` | une action **transition** |

### Modificateurs de `ActionBuilder` {#actionbuilder-modifiers}

| Méthode | Effet |
|---|---|
| `->requireRole($role)` | attache une politique `RoleRequired` pour `$role` |
| `->stamp($field)` | (transition) écrit l'horodatage courant dans `$field` |
| `->andTransition($field, from:, to:)` | (transition) ajoute une autre paire source→cible |

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

:::note `andTransition`, et non `transition`
La méthode d'instance se nomme `andTransition()` parce que `transition()` est le
constructeur statique de la même classe — PHP n'autorise pas une méthode statique et une
méthode d'instance à partager un nom.
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

Si `actions` est omis, la projection expose toutes les actions de l'entité.

## Limites actuelles de la v0.1.0 {#current-v010-limitations}

Le DSL est le sous-ensemble minimal de la RFC-011. **Reporté** aux versions ultérieures :

- Classes de politiques et d'effets résolues par convention — la v0.1.0 utilise des
  intégrés explicites (`RoleRequired`, `kernel.builtin.create`, `kernel.builtin.transition`).
- Politiques de visibilité au niveau du champ.
- Diagnostics du DSL avec attribution fichier/ligne.
- Enregistrement des surcharges ajoutées par le tenant.
- `->unique()` et `->max()` sont stockés dans le graphe mais **non appliqués** comme
  validation à l'exécution dans la v0.1.0.

## Voir aussi {#related}

- [Référence → DSL](../reference/dsl.md) — la fiche condensée.
- [Plugins](../concepts/plugins.md) — ce qu'est un plugin DSL.
- [Le graphe de métadonnées](../concepts/metadata-graph.md) — ce vers quoi le DSL compile.
