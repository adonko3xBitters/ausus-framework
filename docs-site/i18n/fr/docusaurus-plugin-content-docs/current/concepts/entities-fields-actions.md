---
id: entities-fields-actions
title: Entités, champs et actions
sidebar_label: Entités, champs et actions
description: Les nœuds fondamentaux de données et de comportement du graphe de métadonnées.
---

# Entités, champs et actions

Les entités, les champs et les actions constituent le cœur de données et de
comportement d'un domaine AUSUS. Cette page les décrit en tant que concepts du
graphe ; les méthodes du builder qui les déclarent figurent dans
[Le DSL PHP](../backend/php-dsl.md).

## Entités {#entities}

Une **entité** est un type d'enregistrement de domaine — `invoice`, `customer`,
`order`. Dans le graphe, c'est un `EntityNode` avec :

- `fqn` — `{plugin}.{nom local}`, par exemple `billing.invoice`.
- `tenantScoped` — toujours `true` en v0.1.0 ; chaque entité est limitée à un
  tenant.
- `fields` — la liste des champs (champs système + champs utilisateur).
- `actionFqns`, `projectionFqns`, `workflowFqns` — ce qui y est rattaché.

Une entité correspond à une table SQL ; voir
[Persistance SQL](../backend/sql-persistence.md).

## Champs {#fields}

Un **champ** (`FieldNode`) possède un `name`, un `type`, un indicateur
`nullable`, des `typeOptions` facultatives et une valeur `default` facultative.

### Types de champs {#field-types}

La v0.1.0 prend en charge huit types de champs :

| Type | Objet | Options |
|---|---|---|
| `string` | texte | `maxLength` |
| `integer` | nombres entiers | — |
| `enum` | un ensemble fixe de valeurs de chaîne | `options` |
| `money` | un montant + une devise | `currency` |
| `datetime` | un horodatage | — |
| `identity` | l'identifiant de l'enregistrement (système) | — |
| `version` | le jeton de verrou optimiste (système) | — |
| `system_string` | colonne de chaîne interne (système) | — |

`identity`, `version` et `system_string` sont des **types système** — vous ne
les déclarez pas ; le kernel les injecte.

### Champs système {#system-fields}

Chaque entité reçoit automatiquement cinq champs système, dans cet ordre :

| Champ | Type | Rôle |
|---|---|---|
| `id` | `identity` | clé primaire — un ULID de 26 caractères |
| `tenant_id` | `system_string` | le tenant propriétaire |
| `_version` | `version` | jeton de concurrence optimiste (un ULID) |
| `created_at` | `datetime` | horodatage de création |
| `updated_at` | `datetime` | horodatage de dernière mise à jour |

Vous ne déclarez que vos champs de **domaine**. Le type `money` stocke le
montant dans la colonne et résout la devise à partir des `typeOptions` du champ.

## Actions {#actions}

Une **action** est la seule façon de modifier des données. Dans le graphe, un
`ActionNode` possède un `fqn`, le `entityFqn` auquel il appartient, un
`policyFqn`, un `effectClass`, une liste d'entrées, un indicateur
`subjectRequired` et un `kind`.

La v0.1.0 dispose de deux types d'actions, tous deux soutenus par des **effets
intégrés** :

### Actions de création {#create-actions}

Une action de création insère un nouvel enregistrement. Elle déclare quels
champs sont des entrées :

```php
'create' => Action::create('number', 'customer_name', 'amount')
              ->requireRole('invoice.creator'),
```

- `subjectRequired` est `false` — il n'y a aucun enregistrement existant sur
  lequel agir.
- L'effet est `kernel.builtin.create`.
- Si l'entité possède un champ enum avec une valeur par défaut (un champ d'état
  de workflow), l'effet de création applique cette valeur par défaut
  automatiquement.

### Actions de transition {#transition-actions}

Une action de transition déplace un enregistrement entre des états de
workflow :

```php
'issue' => Action::transition('status', from: 'DRAFT', to: 'ISSUED')
             ->stamp('issued_at')
             ->requireRole('invoice.issuer'),
```

- `subjectRequired` est `true` — vous devez passer une `Reference` vers
  l'enregistrement.
- L'effet est `kernel.builtin.transition`.
- `->stamp('issued_at')` écrit l'horodatage courant dans un champ dans le cadre
  de la transition.
- `->andTransition(...)` ajoute une autre paire `(from, to)` à la même action —
  ainsi une seule action peut être légale depuis plusieurs états sources.

Les actions de transition sont ce à partir de quoi les
[workflows](workflows.md) sont construits.

## Limites actuelles de la v0.1.0 {#current-v010-limitations}

- Les seuls types d'actions sont **create** et **transition**, tous deux
  intégrés. Il n'existe aucune action `update` ou `delete` intégrée, et les
  actions de classe `Effect` personnalisée, bien que prises en charge par le
  dispatcher, ne sont pas exercées par le domaine d'exemple de la v0.1.0.
- La validation des champs se limite à la **coercition de type et à la
  présence**. `maxLength` et `unique` sont enregistrés dans le graphe mais ne
  sont pas appliqués comme règles de validation à l'exécution en v0.1.0.
- Toutes les entités sont limitées à un tenant ; il n'existe aucune entité
  globale (non limitée).

## Voir aussi {#related}

- [Workflows](workflows.md) — ce que pilotent les actions de transition.
- [Politiques](policies.md) — l'autorisation sur chaque action.
- [Le DSL PHP](../backend/php-dsl.md) — la référence du builder.
