---
id: projections
title: Projections
sidebar_label: Projections
description: Vues en lecture sur une entité.
---

# Projections

Une **projection** est une vue en lecture sur une entité — les champs et les
actions dont un écran particulier a besoin. Les projections sont la façon dont
AUSUS transforme un domaine en quelque chose qu'une interface peut rendre sans
que l'interface ne connaisse le domaine.

## Déclarer une projection {#declaring-a-projection}

Une projection est déclarée sur l'entité, en nommant les champs et les actions
qu'elle expose :

```php
$dsl->entity('invoice')
    ->fields([ /* ... */ ])
    ->actions([ /* ... */ ])
    ->projection('summary',
        fields:  ['id', 'number', 'customer_name', 'status', 'amount'],
        actions: ['create', 'cancel'],
        role:    'invoice.viewer')
    ->projection('detail',
        fields:  ['id', 'number', 'customer_name', 'status', 'amount', 'issued_at', 'created_at', 'updated_at'],
        actions: ['issue', 'cancel'],
        role:    'invoice.viewer');
```

Chaque projection devient un `ProjectionNode` dans le graphe, avec un FQN de la
forme `{entity}.{nom de projection}`, par exemple `billing.invoice.summary`. Le
`role` facultatif attache une politique de lecture.

## Rendre une projection {#rendering-a-projection}

`ProjectionRenderer` transforme une projection en un
[ViewSchema](../frontend/viewschema.md) — une description au format JSON des
champs, des actions et des données :

```php
use Ausus\Runtime\ProjectionRenderer;

$renderer = new ProjectionRenderer($graph, $driver, $tenant);

// List form — no subject:
$list = $renderer->render('billing.invoice.summary');
// $list['data']['items'] -> array of rows for the tenant

// Detail form — with a subject Reference:
$detail = $renderer->render('billing.invoice.detail', $invoiceRef);
// $detail['data']['item'] -> a single row
```

La forme de `data` indique au consommateur quelle vue dessiner :

- `data.items` présent → une vue **liste**.
- `data.item` présent → une vue **détail**.

Le `ViewSchemaConsumer` du [moteur de rendu React](../frontend/react-renderer.md)
fait sa répartition exactement sur cela.

## Liste vs détail {#list-vs-detail}

| | Liste | Détail |
|---|---|---|
| Appel | `render($fqn)` | `render($fqn, $subjectRef)` |
| `data` | `{ items: [...], pagination }` | `{ item: {...} \| null }` |
| Actions typiques | au niveau liste (par ex. `create`) | au niveau item (par ex. `issue`, `cancel`) |

Le moteur de rendu sépare automatiquement les **actions de liste** (aucun sujet
requis) des **actions d'item** (sujet requis) en fonction de l'indicateur
`subjectRequired` de chaque action.

## Limites actuelles de la v0.1.0 {#current-v010-limitations}

- **Le rendu de liste retourne toutes les lignes du tenant.** Il n'existe ni
  filtrage, ni tri, ni pagination réelle — le tableau `filters` du ViewSchema
  est vide et `pagination.nextCursor` est toujours `null`.
- Le chemin de requête de liste lit les lignes directement plutôt que via le
  contrat `Repository` (le `Repository` de la v0.1.0 n'a pas de `findMany`).
  C'est un raccourci interne connu, documenté comme un constat de la v0.1.0.
- Les libellés de champs sont dérivés mécaniquement des noms de champs
  (`customer_name` → « Customer name »). Il n'existe ni localisation ni
  surcharge des libellés en v0.1.0.

## Voir aussi {#related}

- [ViewSchema](../frontend/viewschema.md) — le format de transport vers lequel une projection rend.
- [Le moteur de rendu React](../frontend/react-renderer.md) — le client qui le consomme.
- [L'API HTTP](../backend/http-api.md) — sert les projections via HTTP.
