---
id: viewschema
title: ViewSchema
sidebar_label: ViewSchema
description: Le format de transmission JSON entre le backend et le moteur de rendu.
---

# ViewSchema

Un **ViewSchema** est le format de transmission JSON qu'AUSUS utilise pour décrire un écran. Le
backend rend une [projection](../concepts/projections.md) en un ViewSchema ;
le [moteur de rendu React](react-renderer.md) le consomme. Aucun des deux côtés ne code
le domaine en dur — le ViewSchema le transporte.

ViewSchema est défini par la RFC-004. La v0.1.0 en implémente un sous-ensemble.

## Forme {#shape}

```json
{
  "schemaVersion": "1.0.0",
  "targetProfile": "react.web.v1",
  "metadata": {
    "projection": "billing.invoice.summary",
    "entity": "billing.invoice",
    "tenant": "acme",
    "locale": "en-US",
    "generatedAt": "2026-05-20T00:00:00Z"
  },
  "fields":  [ /* FieldDescriptor[] */ ],
  "actions": [ /* ActionDescriptor[] */ ],
  "filters": [],
  "data":    { "items": [ /* ... */ ], "pagination": { "nextCursor": null, "pageSize": 1 } }
}
```

| Clé | Signification |
|---|---|
| `schemaVersion` | la version du format ViewSchema — `1.0.0` dans la v0.1.0 |
| `targetProfile` | le profil de rendu — `react.web.v1` dans la v0.1.0 |
| `metadata` | projection / entité / tenant / locale / heure de génération |
| `fields` | les colonnes ou lignes de détail à rendre |
| `actions` | les actions disponibles sur cette vue |
| `filters` | descripteurs de filtres — toujours vide dans la v0.1.0 |
| `data` | les lignes elles-mêmes (voir ci-dessous) |

## `data` — liste vs détail {#data--list-vs-detail}

Le membre `data` indique au consommateur quelle vue dessiner :

```ts
// list form
{ items: Record<string, unknown>[], pagination?: { nextCursor: string | null, pageSize: number } }

// detail form
{ item: Record<string, unknown> | null }

// or null
```

`data.items` → rend une liste. `data.item` → rend un détail. Le `ViewSchemaConsumer`
du moteur de rendu dispatche exactement là-dessus.

## `FieldDescriptor` {#fielddescriptor}

```ts
interface FieldDescriptor {
  name: string;
  type: "string" | "integer" | "datetime" | "enum" | "money"
      | "identity" | "version" | "system_string";
  label: string;
  typeOptions?: { maxLength?: number; currency?: string; options?: string[] };
}
```

Le `type` détermine comment une cellule est rendue — `money` est formaté avec sa
devise, un `enum` nommé `status` devient un badge de workflow, et ainsi de suite.

## `ActionDescriptor` {#actiondescriptor}

```ts
interface ActionDescriptor {
  fqn: string;
  name: string;
  label: string;
  subjectRequired: boolean;
  inputs?: FieldDescriptor[];
  confirmation?: { required: boolean; prompt?: string };
}
```

`subjectRequired` sépare les **actions de liste** (par ex. `create`) des **actions
d'élément** (par ex. `issue`, `cancel`).

## Versionnage du schéma {#schema-versioning}

Le moteur de rendu vérifie `schemaVersion` : il accepte `1.0.x` et signale une erreur
pour toute autre valeur. `schemaVersion` est le moyen pour une révision future de ViewSchema
de rester rétrocompatible.

## Limites actuelles de la v0.1.0 {#current-v010-limitations}

- `filters` est toujours vide — il n'y a pas de filtrage dans la v0.1.0.
- `pagination.nextCursor` est toujours `null` — le rendu de liste retourne toutes les lignes
  du tenant.
- `targetProfile` est fixé à `react.web.v1` ; `locale` est fixé à `en-US`.
- `confirmation` fait partie du type `ActionDescriptor` mais n'est pas renseigné par
  le moteur de rendu backend de la v0.1.0.

## Voir aussi {#related}

- [Projections](../concepts/projections.md) — ce qui est rendu en un ViewSchema.
- [L'API HTTP](../backend/http-api.md) — sert les ViewSchemas.
- [Le moteur de rendu React](react-renderer.md) — les consomme.
