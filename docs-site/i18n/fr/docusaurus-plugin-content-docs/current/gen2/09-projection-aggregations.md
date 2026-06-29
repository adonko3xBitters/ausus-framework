---
id: projection-aggregations
title: "9. Agrégations de projection (KPI / tableaux de bord)"
sidebar_label: Agrégations de projection
description: Le contrat de statistiques public du modèle de lecture AUSUS 2.0 — count, sum, avg, min, max sur les lignes d'une projection, pour les dashboards et cartes KPI, sans modifier le modèle d'écriture.
---

# 9. Agrégations de projection (KPI / tableaux de bord)

Les **agrégations de projection** (L4) sont le **contrat de statistiques**
officiel et public du modèle de lecture d'AUSUS Gen2. Elles permettent à une
projection de renvoyer `count`, `sum`, `avg`, `min` et `max` sur ses lignes — la
fondation des tableaux de bord, cartes KPI, badges et du reporting simple — *sans
modifier le modèle métier (écriture)*.

Elles s'appuient directement sur le
[langage de requête de projection](./08-projection-queries.md) (L3) et sont
**purement additives** : le Kernel, l'Authoring, l'EntitySchema, le Compiler et
le runtime d'écriture sont inchangés ; une lecture existante sans paramètre
`aggregate` se comporte exactement comme avant.

> **Portée de la v1.** `count` / `sum` / `avg` / `min` / `max` sur les champs
> scalaires exposés d'une projection. N'inclut volontairement **pas** le
> regroupement (group-by), les champs calculés/dérivés, les jointures, les
> relations inverses ni le reporting. Ces capacités s'appuieront sur cette
> fondation dans des couches ultérieures.

---

## 1. Sa place dans le pipeline

```
findAll(tenant) → WHERE (filtre L3)
   → AGGREGATE (L4 : count/sum/avg/min/max sur l'ensemble filtré et visible)
   → ORDER BY → LIMIT/OFFSET → rendu (visibilité + expand)
```

- Les agrégats sont calculés **après** le filtre `where` et **après** la
  visibilité par champ, mais **indépendamment de `limit`/`offset`** : une carte
  KPI somme *toutes* les lignes correspondantes, pas seulement la page courante.
  Le tableau `rows` reste paginé.
- **Sûr pour le tenant et la visibilité.** L'agrégation s'exécute dans la portée
  tenant, sur des lignes dont la visibilité par champ a déjà été appliquée — une
  valeur masquée pour l'acteur courant ne contribue jamais (traitée comme
  absente, comme un `NULL` SQL).
- **Agnostique au driver.** Appliqué dans le runtime aujourd'hui ; un futur
  driver SQLite/Postgres peut pousser le contrat identique en `COUNT/SUM/AVG/MIN/MAX`.

---

## 2. Le contrat

Les agrégations sont demandées via une nouvelle clé optionnelle `aggregate` sur
le même argument `params` que L3. La réponse gagne une map `aggregates` ; **le
format des lignes `rows` ne change jamais**, et `aggregates` n'est présent que si
demandé.

```php
$params = [
    'where'     => [ ['field' => 'status', 'op' => 'eq', 'value' => 'available'] ],
    'aggregate' => [
        ['op' => 'count',                    'as' => 'rooms'],
        ['op' => 'sum', 'field' => 'total',  'as' => 'revenue'],
        ['op' => 'avg', 'field' => 'price',  'as' => 'averagePrice'],
    ],
];
```

Réponse :

```json
{
  "rows": [ ... ],
  "aggregates": { "rooms": 42, "revenue": 580000, "averagePrice": 13500 }
}
```

### Opérateurs

| Opérateur | `field`    | Résultat                                                     |
|-----------|------------|--------------------------------------------------------------|
| `count`   | optionnel  | nombre de lignes ; avec un champ, nombre de valeurs non nulles |
| `sum`     | requis     | somme des valeurs numériques (ensemble vide → `0`)          |
| `avg`     | requis     | moyenne des valeurs numériques (ensemble vide → `null`)     |
| `min`     | requis     | plus petite valeur (numérique ou lexicographique ; vide → `null`) |
| `max`     | requis     | plus grande valeur (numérique ou lexicographique ; vide → `null`) |

Chaque entrée requiert un alias `as` ; les alias doivent être **uniques**. Le
`field` doit être un champ scalaire **exposé** par la projection (la même liste
d'autorisation que L3).

---

## 3. Exemple PHP (intégré / tests)

```php
use Ausus\Engine\Runtime\AggregatingRuntimeEntity;

$runtime = $engine->bind($schema, $driver);   // DefaultRuntimeEntity

if ($runtime instanceof AggregatingRuntimeEntity) {
    $result = $runtime->readWithAggregates('board', [
        'where'     => [ ['field' => 'status', 'op' => 'eq', 'value' => 'available'] ],
        'aggregate' => [
            ['op' => 'count',                   'as' => 'rooms'],
            ['op' => 'sum', 'field' => 'price', 'as' => 'revenue'],
        ],
    ], $context);

    $result['rows'];        // lignes paginées (forme inchangée)
    $result['aggregates'];  // ['rooms' => 42, 'revenue' => 580000]
}
```

`read()` lui-même est inchangé — il renvoie toujours une `list<row>` brute.
L'enveloppe enrichie `{ rows, aggregates }` est exposée par l'interface additive
`AggregatingRuntimeEntity` (dans `ausus/entity-engine`, **pas** le kernel gelé).
Toute agrégation malformée lève `Ausus\Engine\Query\QueryError`.

---

## 4. En HTTP

`GET /api/entities/{entity}/projections/{projection}` accepte une clause
`aggregate` aux côtés des paramètres de requête L3.

```
# op:as (count) | op:field:as (sum/avg/min/max, count-de-champ) ; virgule = liste
GET …/board?aggregate=count:rooms,sum:total:revenue,avg:price:averagePrice

# combiné avec where (et orderBy / limit / offset)
GET …/board?where=status:eq:available&aggregate=count:rooms,sum:price:revenue
```

Enveloppe de réponse :

```json
{ "rows": [ ... ], "aggregates": { "rooms": 42, "revenue": 580000 } }
```

Sans `aggregate`, la réponse est l'enveloppe inchangée `{ "rows": [...] }` —
aucune clé `aggregates` n'est ajoutée.

### Codes de statut

| Statut | Quand                                                                  |
|--------|------------------------------------------------------------------------|
| `200`  | succès                                                                 |
| `400`  | **agrégat malformé** — opérateur inconnu, champ inexistant/non exposé, alias manquant, alias dupliqué, valeur de type incompatible (ex. `sum` sur un champ texte) |

Un agrégat malformé ne **retombe jamais** sur un résultat silencieux : il échoue
fermé (fail-closed).

---

## 5. Depuis le renderer React

Le constructeur typé encode la clause `aggregate` exacte, et
`ProjectionResponse.aggregates` est typé :

```ts
import { RuntimeClient, buildProjectionParams } from '@ausus/react-renderer';

const params = buildProjectionParams({
  where: [{ field: 'status', op: 'eq', value: 'available' }],
  aggregate: [
    { op: 'count', as: 'rooms' },
    { op: 'sum', field: 'price', as: 'revenue' },
  ],
});

const { body } = await client.readProjection('room', 'board', params);
//  → GET …/board?where=status:eq:available&aggregate=count:rooms,sum:price:revenue
body.aggregates?.rooms;    // 42
body.aggregates?.revenue;  // 580000
```

`buildProjectionParams` est un pur encodeur — il ne valide jamais. Un champ ou un
opérateur inconnu apparaît comme un `400` venu du serveur.

---

## 6. Garanties de conception

- **Additif & sûr vis-à-vis du gel.** Aucun type kernel/contrat/modèle
  d'écriture n'a changé ; `read()` continue de renvoyer `list<row>`. L'agrégation
  est exposée via l'interface additive `AggregatingRuntimeEntity`.
- **Fail closed.** Opérateur inconnu, champ inexistant/non exposé, alias
  manquant/dupliqué ou valeur de type incompatible est rejeté — jamais coercé,
  jamais de résultat silencieux.
- **Sûr pour la visibilité.** Les valeurs masquées ne contribuent jamais à un
  agrégat ; un champ masqué pour l'acteur s'agrège comme s'il était absent.
- **Indépendant de la pagination.** Les agrégats couvrent l'ensemble tenant
  filtré par WHERE quel que soit `limit`/`offset` ; les `rows` restent paginées.
- **Agnostique au driver.** Réutilise le contrat identique via un futur driver SQL.

Voir aussi les références **Langage de requête de projection** et **Limites
connues** (barre latérale → *Reference*).
