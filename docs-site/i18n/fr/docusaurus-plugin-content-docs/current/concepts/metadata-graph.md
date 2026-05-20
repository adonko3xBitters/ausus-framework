---
id: metadata-graph
title: Le graphe de métadonnées
sidebar_label: Le graphe de métadonnées
description: La description compilée et immuable d'une application AUSUS.
---

# Le graphe de métadonnées

Le `MetadataGraph` est l'objet central d'AUSUS. C'est la **description compilée
et immuable d'une application** — chaque entité, action, politique, workflow et
projection, validée et figée en une seule valeur.

Tout ce qui se trouve en aval — le schéma de persistance, le runtime, l'API
HTTP, l'interface rendue — est dérivé de ce graphe unique.

## Ce que contient le graphe {#what-is-in-the-graph}

Un `MetadataGraph` (dans `ausus/kernel`) contient :

| Champ | Contenu |
|---|---|
| `hash` | un hash de contenu SHA-256 du graphe canonique |
| `kernelVersion` | la version du contrat du kernel contre laquelle le graphe a été compilé |
| `entities` | table de correspondance FQN → `EntityNode` |
| `actions` | table de correspondance FQN → `ActionNode` |
| `policies` | table de correspondance FQN → `PolicyNode` |
| `workflows` | table de correspondance FQN → `WorkflowNode` |
| `projections` | table de correspondance FQN → `ProjectionNode` |

Chaque nœud est un objet valeur `final readonly`. Le graphe n'a aucune méthode
qui le modifie — une fois compilé, il ne change pas.

## Compilation {#compilation}

Le `Compiler` prend une liste de [plugins](plugins.md) et produit le graphe :

```php
use Ausus\Compiler;

$graph = (new Compiler())->compile([new HelloInvoiceDsl()], kernelVersion: '1.0.0');
```

La compilation effectue trois opérations :

1. **Collecte** — la sortie de `describe()` de chaque plugin contribue des nœuds
   d'entité, d'action, de politique, de workflow et de projection.
2. **Validation** — voir ci-dessous.
3. **Canonicalisation et hachage** — les tables de nœuds sont triées par clé,
   sérialisées dans une forme JSON canonique, puis hachées avec SHA-256.

## Validation {#validation}

Le compilateur rejette un graphe incohérent au moment de la compilation, et non
à l'exécution :

- **DuplicateRegistration** — deux plugins déclarent le même FQN d'action.
- **DanglingReference** — une action pointe vers une entité ou une politique qui
  n'est pas enregistrée ; un workflow pointe vers une entité manquante ; une
  transition pointe vers une action manquante.
- **WorkflowCoherence** — le champ d'état d'un workflow n'est pas présent sur
  son entité propriétaire, ou le `source`/`target` d'une transition ne fait pas
  partie des états déclarés du workflow.

Si la validation échoue, `compile()` lève une exception — vous ne pouvez pas
construire un graphe invalide.

## Hachage adressable par contenu {#content-addressable-hashing}

Le `hash` du graphe est déterministe : les **mêmes plugins compilent toujours
vers le même hash**. Cela sert d'identité et de contrôle d'intégrité.

Une conséquence concrète, vérifiée dans le playground : le domaine
`HelloInvoice` écrit avec le [DSL](../backend/php-dsl.md) et le même domaine
écrit sous forme de tableaux de descripteurs construits à la main compilent vers
un **hash identique octet par octet**. Le DSL est du sucre syntaxique pur sur la
forme descripteur — il n'ajoute aucune sémantique.

:::note Ce que couvre le hash de la v0.1.0
La forme canonique de la v0.1.0 hache l'**ensemble des FQN de nœuds** (noms
d'entité, d'action, de politique, de workflow, de projection) plus la version du
kernel. C'est une identité forte pour la *forme* du graphe. Une révision future
pourra étendre la forme canonique pour couvrir le contenu complet des nœuds.
:::

## Pourquoi un graphe {#why-a-graph}

Parce que l'application est une unique valeur déclarative, la même description
de domaine pilote chaque couche sans redite :

- `SchemaDeriver` lit `entities` pour émettre des instructions `CREATE TABLE`.
- L'`Invoker` lit `actions`, `policies` et `workflows` pour exécuter les appels.
- `ProjectionRenderer` lit `projections` pour produire des ViewSchemas.

Aucune couche ne redécrit le domaine. Elles lisent toutes le même graphe.

## Limites actuelles de la v0.1.0 {#current-v010-limitations}

- Le graphe est compilé **en mémoire au démarrage**. Il n'existe ni artefact
  compilé sur disque ni cache de graphe en v0.1.0.
- Le hash canonique couvre les FQN de nœuds et la version du kernel (voir la
  note ci-dessus), et non le corps complet des nœuds.
- Il n'existe aucun outillage de comparaison ou de migration de graphe.

## Voir aussi {#related}

- [Plugins](plugins.md) — l'entrée du compilateur.
- [Entités, champs et actions](entities-fields-actions.md) — les types de nœuds.
- [Persistance SQL](../backend/sql-persistence.md) — le schéma dérivé du graphe.
