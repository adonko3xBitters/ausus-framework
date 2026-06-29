---
id: sql-persistence
title: "10. Persistance SQL (le driver SQLite)"
sidebar_label: Persistance SQL
description: Le premier PersistenceDriver SQL public d'AUSUS 2.0 — une implémentation PDO SQLite du SPI de persistance gelé, totalement interchangeable avec persistence-memory, sans changer une ligne de code métier.
---

# 10. Persistance SQL (le driver SQLite)

`ausus/persistence-sqlite` est le premier **`PersistenceDriver` SQL public**
d'AUSUS 2.0 — une implémentation PDO SQLite du SPI de persistance gelé du kernel.
Il est **interchangeable** avec `ausus/persistence-memory` : le Entity Engine, le
Runtime, les requêtes de projection L3, les agrégations L4, l'API Runtime, le
View System et le React Renderer fonctionnent sans changement. Le seul changement
applicatif est le driver utilisé.

```php
// avant — en mémoire, éphémère
$driver = new Ausus\Persistence\Memory\MemoryDriver();
// après — SQLite, durable
$driver = new Ausus\Persistence\Sqlite\SqliteDriver(__DIR__ . '/var/app.db');
```

> **Pourquoi c'est additif.** Le SPI de persistance était déjà complet :
> CRUD/actions/transitions/guards et L3/L4 s'exécutent tous **dans le runtime
> au-dessus de `Repository::findAll()`**, donc un driver n'implémente que
> `find` / `create` / `update` / `findAll` plus les transactions, le versioning
> et la portée tenant. Aucun changement de kernel, de runtime ni de contrat
> public — un nouveau package a été ajouté, exactement comme `persistence-memory`.

---

## 1. Où se situe le driver

```
RuntimeEntity (read/invoke)  →  PersistenceDriver  →  PersistenceContext
                                      │                      │
                                      │                      └→ Repository (find/create/update/findAll)
                                      └→ TransactionHandle (begin/commit/rollback)
```

Le runtime possède L3/L4, la visibilité et l'expand ; le **driver ne possède que
le stockage**. C'est cette séparation qui permet à un seul SPI de servir Memory
et SQL — et qui fait qu'un futur passage SQLite → Postgres ne demande aucun
changement de contrat.

## 2. Le SPI implémenté (inchangé)

| Contrat | Opérations |
|---------|------------|
| `PersistenceDriver` | `beginTransaction`, `commit`, `rollback`, `context`, `generateIdentity` |
| `PersistenceContext` | `repository(fqn)`, `tenant()` |
| `Repository` | `find`, `create`, `update`, `findAll` |
| `TransactionHandle` | `tenant()` |

`PagedRepository::findPaged` (pushdown du filtre/tri/pagination vers SQL) est une
optimisation future **optionnelle** — comme Memory, le driver SQLite ne
l'implémente pas, car L3/L4 produisent déjà des résultats corrects au-dessus de
`findAll`.

## 3. Architecture interne

```
SqliteDriver        — PersistenceDriver ; possède connexions, transactions, identité
 ├─ SqliteConnection — fabrique PDO (DSN, réécriture :memory: → shared-cache)
 ├─ SchemaManager    — CREATE TABLE IF NOT EXISTS + index, idempotent
 ├─ Dialect (SPI)    — couture moteur ; SqliteDialect = quoting, DDL, PRAGMAs
 └─ SqliteRepository — Repository ; SQL paramétré, portée tenant, payload JSON
```

| Composant | Visibilité | Stabilité |
|-----------|------------|-----------|
| `SqliteDriver`, `SqliteRepository` | public | stable |
| interface `Dialect` | SPI public | stable (la couture multi-moteurs) |
| `SqliteDialect`, `SchemaManager`, `SqliteConnection` | public, interne par convention | stable |
| `MigrationPlanner`, pushdown `findPaged` | absents pour l'instant | expérimental / futur |

### Modèle de stockage — neutre vis-à-vis du moteur

Une seule table contient chaque entité ; le payload métier est en JSON, donc
**aucun DDL par entité et aucune migration** :

```sql
CREATE TABLE ausus_entities (
  tenant_id   TEXT NOT NULL,
  entity_fqn  TEXT NOT NULL,
  identity    TEXT NOT NULL,
  version     TEXT NOT NULL,
  fields_json TEXT NOT NULL,
  PRIMARY KEY (tenant_id, entity_fqn, identity)
);
```

La même forme se porte sur PostgreSQL, MySQL, MariaDB, SQL Server, CockroachDB,
PlanetScale et Turso — chacun est un nouveau `Dialect`, pas un nouveau driver.

## 4. Cycle de vie & transactions

- `beginTransaction(tenant)` ouvre une **nouvelle connexion** et `BEGIN` une vraie
  transaction SQLite. Chaque handle est indépendant.
- Les écritures via le repository du handle sont visibles pour ce handle
  (read-your-writes) mais **invisibles aux autres handles jusqu'au commit** —
  isolation snapshot WAL, la même garantie que l'overlay committed/staging de
  Memory.
- `commit()` / `rollback()` finalisent et libèrent la connexion.
- Le runtime lit dans une transaction qu'il annule ensuite (lecture seule), et
  encapsule chaque mutation dans `begin … commit`, avec rollback sur erreur.

**Concurrence optimiste.** `create` écrit `version = "1"` ; `update` l'incrémente
et refuse une `expected` périmée (`… not found` / `… version conflict`, alignés
sur Memory pour un mapping de statut HTTP identique). **Isolation tenant :** chaque
requête est filtrée par `tenant_id`. **Identité :** UUID v4.

## 5. Choix de conception (et pourquoi)

- **Payload JSON, table unique** — l'universalité avant la micro-optimisation ;
  le contrat doit survivre à n'importe quel moteur.
- **Connexion par transaction** — la seule façon fidèle de reproduire
  l'invisibilité inter-transactions de Memory sur SQLite ; un pool de connexions
  est une optimisation future additive.
- **WAL + busy timeout** — les lecteurs concurrents ne bloquent jamais le
  rédacteur ; durable.
- **Couture `Dialect`** — le driver/repository ne nomment jamais SQLite
  directement, donc les nouveaux moteurs se branchent sans toucher la logique
  agnostique.
- **Parité comportementale avec Memory** — mêmes incréments de version, mêmes
  sémantiques conflit/not-found et même ordre `findAll`, donc les applications ne
  peuvent pas distinguer les drivers.

## 6. Migration depuis le driver Memory

1. `composer require ausus/persistence-sqlite`.
2. Remplacer `new MemoryDriver()` par `new SqliteDriver('/chemin/app.db')`.
3. Rien d'autre ne change — mêmes entités, actions, projections, requêtes,
   agrégations, API et renderer.

```php
// Hello Invoice / Teranga PMS — l'entité, le compiler, l'engine et l'API sont identiques
$engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$driver = new SqliteDriver(__DIR__ . '/var/hello-invoice.db');   // ← seule ligne qui diffère
$engine->bind($repo->resolve('invoice'), $driver)
       ->invoke('create', ['number' => 'INV-001', /* … */ 'total' => 1500], $user);
```

La suite de tests de référence le prouve : la **vraie entité Hello Invoice**
exécutée via `MemoryDriver` puis `SqliteDriver` produit des lignes de projection
et des agrégats L4 byte-identiques, les guards refusent de façon identique, et les
données SQLite survivent à la destruction du driver et à sa réouverture sur le
même fichier (redémarrage de processus) — ce que le driver Memory ne peut pas
faire.

## 7. Limites

- **Pas de pushdown** pour l'instant : L3/L4 s'exécutent dans le runtime au-dessus
  de `findAll` ; les grands jeux de données voudront un pushdown SQL `findPaged`
  (futur, additif — le SPI `PagedRepository` existe déjà).
- **Pas de migrations de schéma** : la table JSON unique est fixe ; un
  `MigrationPlanner` est un travail futur.
- **Concurrence d'écriture SQLite** : un seul rédacteur à la fois (WAL) ; adapté
  à l'embarqué / mono-nœud, un moteur serveur (Postgres) est le prochain dialecte.
- **Typage des champs JSON** : les valeurs transitent par JSON (scalaires
  préservés) ; pas encore de typage ni d'indexation au niveau colonne.

Voir aussi les références **Capacités** et **Limites connues** (barre latérale →
*Concepts* / *Reference*).
