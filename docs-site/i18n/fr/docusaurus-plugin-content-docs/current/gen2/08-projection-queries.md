---
id: projection-queries
title: "8. Langage de requête de projection (filtres, tri et pagination)"
sidebar_label: Requêtes de projection
description: Le contrat de lecture public et stable d'AUSUS 2.0 — filtrer, trier et paginer les lignes d'une projection (where / orderBy / limit / offset), de la déclaration jusqu'au rendu React.
---

# 8. Langage de requête de projection (filtres, tri et pagination)

Le **langage de requête de projection** (L3) est le **contrat de lecture**
officiel et public d'AUSUS 2.0. C'est la manière stable de demander à une
projection *un sous-ensemble* de ses lignes — filtré, trié et paginé — sans
jamais exposer le moindre détail de stockage.

Il est **purement additif** : le modèle d'écriture (Kernel, EntityDefinition,
EntitySchema, Engine, l'API d'écriture du Runtime) est inchangé, tous les tests
existants passent encore, et une lecture de projection sans paramètre de requête
renvoie exactement ce qu'elle renvoyait avant.

> **Portée de la v1.** Ce contrat couvre `where` / `orderBy` / `limit` /
> `offset` sur les champs scalaires exposés d'une projection. Il ne couvre
> volontairement **pas** les jointures, les relations inverses, les agrégations,
> les champs calculés, le reporting, la disponibilité ni les anti-jointures. Ces
> capacités s'appuieront sur cette fondation dans des couches ultérieures.

---

## 1. Sa place dans le pipeline

```
findAll(tenant)  →  WHERE  →  ORDER BY  →  LIMIT/OFFSET  →  visibilité + expand  →  lignes
```

La requête est analysée et **validée avant toute E/S**, puis appliquée dans le
runtime sur les lignes renvoyées par le driver. Cela la rend **agnostique au
driver** : le driver mémoire fonctionne sans changement, et un futur driver
SQLite/Postgres pourra pousser le *même* contrat jusqu'au SQL via le SPI
existant `Repository::findPaged(limit, offset, filters, sort)` — sans changer un
seul octet du contrat public.

Le filtrage et le tri opèrent sur les **champs scalaires exposés** de la
projection. Les gardes de `visibility` par champ s'appliquent toujours à la
sortie rendue : un champ masqué pour l'acteur courant est omis de chaque ligne,
exactement comme avant.

---

## 2. L'objet requête

Le contrat canonique est un simple tableau associatif passé comme argument
`params` de `RuntimeEntity::read($projection, $params, $context)`. Il possède
exactement quatre clés optionnelles :

| Clé       | Forme                                              | Sens                    |
|-----------|----------------------------------------------------|-------------------------|
| `where`   | un **nœud de filtre** (voir ci-dessous)            | prédicat de ligne       |
| `orderBy` | liste de `{ field, dir }` (`dir` = `asc`\|`desc`)  | tri, première clé prioritaire |
| `limit`   | entier `0 … 200`                                   | taille de page (max **200**) |
| `offset`  | entier `≥ 0`                                        | lignes à ignorer        |

Toute **autre** clé de premier niveau est rejetée (fail-closed). Un tableau vide
`[]` est sans effet — la valeur par défaut, sûre pour la non-régression.

### Nœuds de filtre

Un `where` est un arbre booléen récursif :

- **Condition** — `['field' => 'status', 'op' => 'eq', 'value' => 'open']`
- **Groupe ET** — `['and' => [ nœud, nœud, … ]]`
- **Groupe OU** — `['or' => [ nœud, nœud, … ]]`
- **Liste simple** — `[ nœud, nœud, … ]` est un **ET** implicite

Les groupes s'imbriquent librement, donc `(status = open ET (priority >= 3 OU
assignee est null))` est exprimable.

### Opérateurs

| Opérateur                          | S'applique à        | Valeur     |
|------------------------------------|---------------------|------------|
| `eq`, `ne`                         | tout scalaire       | requise    |
| `lt`, `lte`, `gt`, `gte`           | nombres / chaînes   | requise    |
| `contains`, `startsWith`, `endsWith` | chaînes           | requise    |
| `isNull`, `isNotNull`              | tout champ nullable | **omettre**|

Les comparaisons sont numériques quand les deux côtés sont numériques, sinon
lexicographiques. Les valeurs `null` ne correspondent jamais à un opérateur
d'ordre/relationnel et **sont triées en dernier**, quel que soit le sens.

---

## 3. Exemple PHP (intégré / tests)

```php
use Ausus\Engine\Runtime\DefaultEntityEngine;

$runtime = $engine->bind($schema, $driver);

$rows = $runtime->read('list', [
    'where' => ['and' => [
        ['field' => 'status',   'op' => 'eq',  'value' => 'open'],
        ['field' => 'priority', 'op' => 'gte', 'value' => 3],
    ]],
    'orderBy' => [['field' => 'priority', 'dir' => 'desc']],
    'limit'   => 20,
    'offset'  => 0,
], $context);
```

`null` / OU / groupes imbriqués sont entièrement disponibles ici. Toute requête
malformée lève `Ausus\Engine\Query\QueryError` — elle n'est jamais silencieusement
coercée.

---

## 4. En HTTP

`GET /api/entities/{entity}/projections/{projection}` accepte la requête sous
forme de paramètres plats de chaîne de requête. L'api-runtime les traduit dans
le contrat structuré ci-dessus ; le runtime reste l'unique autorité fail-closed.

```
# raccourci : toute clé non réservée ⇒ eq
GET /api/entities/task/projections/list?status=open

# filtres explicites — virgule = ET
GET …/list?where=status:eq:open,priority:gte:3

# opérateurs sans valeur
GET …/list?where=assignee:isNull

# tri (alias : sort) + pagination
GET …/list?orderBy=priority:desc,title:asc&limit=20&offset=40
```

Clés réservées : `where`, `orderBy`, `sort`, `limit`, `offset`. Toute autre clé
est traitée comme un filtre `eq` raccourci et fusionnée dans une seule liste ET.
**Le OU et les groupes imbriqués ne sont volontairement pas exprimables dans le
raccourci HTTP** (il est ET-seulement par conception) — utilisez les `params`
structurés pour cela ; le contrat de lecture public reste volontairement petit.

L'enveloppe de réponse est inchangée : `{ "rows": [ … ] }`.

### Codes de statut

| Statut | Quand                                                                |
|--------|----------------------------------------------------------------------|
| `200`  | succès (y compris une liste `rows` vide)                            |
| `400`  | **requête malformée** — champ inconnu, opérateur inconnu, valeur manquante, sens de tri invalide, `limit`/`offset` hors bornes, paramètre inconnu |
| `403` / `404` / `422` | erreurs inchangées d'autorisation / résolution / transition |

Une requête malformée ne **retombe jamais** sur « toutes les lignes » : elle
échoue fermée (fail-closed).

---

## 5. Depuis le renderer React

`RuntimeClient.readProjection(entity, projection, params)` transmet déjà une map
de paramètres à la chaîne de requête : le contrat est donc opt-in et rétro-
compatible. Un constructeur typé produit l'encodage exact :

```ts
import { RuntimeClient, buildProjectionParams } from '@ausus/react-renderer';

const params = buildProjectionParams({
  where: [
    { field: 'status', op: 'eq', value: 'open' },
    { field: 'priority', op: 'gte', value: 3 },
  ],
  orderBy: [{ field: 'priority', dir: 'desc' }],
  limit: 20,
});

const { body } = await client.readProjection('task', 'list', params);
//  → GET …/list?where=status:eq:open,priority:gte:3&orderBy=priority:desc&limit=20
```

`buildProjectionParams` est un pur encodeur — il ne valide jamais. Un champ ou un
opérateur inconnu apparaît comme un `400` venu du serveur, pas comme une clause
silencieusement ignorée.

---

## 6. Garanties de conception

- **Additif & sûr vis-à-vis du gel.** Aucun type kernel/contrat/modèle
  d'écriture n'a changé ; le tableau `params` *est* le contrat, donc rien de
  nouveau n'est ajouté à la surface gelée.
- **Fail closed.** Champ/opérateur/paramètre inconnu, valeur manquante, sens
  invalide ou pagination hors bornes est rejeté — jamais coercé, jamais élargi à
  « toutes les lignes ».
- **Agnostique au driver.** Appliqué dans le runtime aujourd'hui ; un futur
  driver SQL réutilise le contrat identique via `findPaged`.
- **Sûr pour le tenant & la visibilité.** La requête s'exécute *à l'intérieur*
  de la portée tenant et *avant* le rendu ; les gardes de visibilité par champ
  sont inchangés.
- **Borné.** `limit` est plafonné à **200**, de sorte qu'un balayage non borné
  n'est jamais à une requête de distance.

Voir aussi les références **Capacités** et **Limites connues** (barre latérale →
*Concepts* / *Reference*).
