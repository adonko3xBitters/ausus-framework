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

## Champs réservés {#reserved-fields}

Certains champs du wire format apparaissent dans le schéma pour la compatibilité
ascendante — ils sont émis aujourd'hui avec une valeur v0.1.x fixe, mais leur
valeur deviendra **dynamique** dans une future version mineure. Les consommateurs
DOIVENT tolérer la valeur v0.1.x documentée, NE DOIVENT PAS épingler d'assertions
sur cette valeur, et DEVRAIENT rendre la forme future sans changement de code.

| Champ | Valeur v0.1.x | Portera dans une version ultérieure |
|---|---|---|
| `targetProfile` | exactement `"react.web.v1"` | D'autres profils de rendu (par ex. `react.web.v2`, `react.native.v1`). |
| `metadata.locale` | exactement `"en-US"` | La locale négociée par requête depuis `Accept-Language`. |
| `filters` | toujours `[]` | Une liste d'éléments `FilterDescriptor` une fois le filtrage livré. |
| `data.pagination.nextCursor` | toujours `null` (quand `pagination` est présent) | Une chaîne curseur opaque quand une page suivante existe ; `null` quand la page courante est la dernière. |
| `ActionDescriptor.confirmation` | déclaré dans le type TS, jamais émis par le moteur de rendu backend v0.1.x | `{ required: boolean, prompt?: string }` quand l'action est déclarée comme nécessitant une confirmation. |

Contrat de compatibilité ascendante :

- Code lecteur : traitez `targetProfile` et `metadata.locale` comme des chaînes
  opaques ; ne branchez pas sur la valeur exacte v0.1.x au-delà d'une seule
  porte "est-ce que je supporte ce profil ?" à la frontière du consommateur.
- Code lecteur : traitez `filters: []` et `nextCursor: null` comme le cas vide
  de la forme future — rendez le cas vide aujourd'hui, rendez le cas peuplé
  quand il sera livré.
- Code lecteur : traitez l'**absence** de `ActionDescriptor.confirmation` et un
  `confirmation.required: false` peuplé comme équivalents ("aucune confirmation
  requise"). Un `confirmation.required: true` peuplé signifiera ce que le type
  TS dit déjà.
- Code producteur : en dehors du framework, vous NE DEVRIEZ PAS émettre de
  valeurs non par défaut pour ces champs en v0.1.x — le moteur de rendu n'agit
  pas encore dessus et ils sont réservés pour le runtime.

## Voir aussi {#related}

- [Projections](../concepts/projections.md) — ce qui est rendu en un ViewSchema.
- [L'API HTTP](../backend/http-api.md) — sert les ViewSchemas.
- [Le moteur de rendu React](react-renderer.md) — les consomme.
