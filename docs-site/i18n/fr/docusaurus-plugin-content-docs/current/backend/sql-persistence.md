---
id: sql-persistence
title: Persistance SQL
sidebar_label: Persistance SQL
description: Le driver de persistance basé sur SQLite et la dérivation de schéma.
---

# Persistance SQL

`ausus/persistence-sql` (couche L3) est le driver de persistance SQLite. Il est
basé sur SQLite, dérive son schéma du [graphe de métadonnées](../concepts/metadata-graph.md),
et applique l'isolation des tenants et la concurrence optimiste. C'est l'une des
deux implémentations du contrat `PersistenceDriver` du kernel — voir
[le contrat partagé & PostgreSQL](#shared-contract-postgresql) ci-dessous.

## Dériver le schéma {#deriving-the-schema}

`SchemaDeriver` transforme un graphe compilé en instructions `CREATE TABLE` — une
table par entité, plus la table du journal d'audit :

```php
use Ausus\Persistence\Sql\SchemaDeriver;

foreach (SchemaDeriver::deriveAll($graph) as $stmt) {
    $pdo->exec($stmt);
}
```

- Le nom de la table est le FQN de l'entité avec les points remplacés par des underscores —
  `billing.invoice` → `billing_invoice`.
- Les types de colonnes correspondent aux types de champs : `string`/`datetime` → `TEXT`,
  `integer` → `INTEGER`, `money` → `NUMERIC`, `identity`/`version` →
  `TEXT NOT NULL`.
- `id` est la clé primaire. Les champs non nullables reçoivent `NOT NULL` ; les valeurs par
  défaut des champs deviennent les `DEFAULT` des colonnes.

## Le driver {#the-driver}

`SqlitePersistenceDriver` implémente le contrat `PersistenceDriver` du kernel :

```php
use Ausus\Persistence\Sql\SqlitePersistenceDriver;

$driver = new SqlitePersistenceDriver($pdo, $graph);

$tx   = $driver->beginTransaction($tenant);
$ctx  = $driver->context($tenant, $tx);
$repo = $ctx->repository('billing.invoice');
// ... use the repository ...
$driver->commit($tx);
```

Un `PersistenceContext` est toujours lié à un `Tenant` ; demander un contexte avec
un tenant qui ne correspond pas au handle de transaction lève
`TenantBoundaryViolation`.

## Le repository {#the-repository}

`SqliteRepository` est l'API de données par entité. Ses opérations :

| Méthode | Comportement |
|---|---|
| `find(Reference $ref): ?Entity` | lit une ligne par id, limitée au tenant |
| `create(array $payload, ?string $identity = null): Entity` | insère une ligne, en générant un id ULID et `_version` |
| `update(Reference $ref, array $patch, Version $expected): Entity` | met à jour une ligne si `$expected` correspond à la `_version` courante |
| `findAll(): list<Entity>` | lit toutes les lignes du tenant actif, triées par id |
| `findPaged(int $limit, int $offset, array $filters, array $sort): array` | une page déterministe plus le total, avec filtres optionnels (`eq` / `contains` / `in`) et tri |

```php
$entity = $repo->find($ref);
$entity = $repo->create(['number' => 'INV-1', /* ... */]);
$entity = $repo->update($ref, ['customer_name' => 'New'], $entity->version);
```

## Isolation des tenants {#tenant-isolation}

Chaque table possède une colonne `tenant_id`. Chaque requête est filtrée par elle, et une
`Reference` dont le `tenantId` ne correspond pas au tenant actif est rejetée avec
`TenantBoundaryViolation` **avant** l'exécution de tout SQL. Le cantonnement par tenant est
appliqué dans le driver, et non laissé à l'appelant.

## Concurrence optimiste {#optimistic-concurrency}

Chaque ligne porte une colonne `_version` — un ULID régénéré à chaque écriture.
`update()` inclut `_version = :expected` dans sa clause `WHERE` :

- Si la ligne est mise à jour, la version correspondait.
- Si zéro ligne est affectée, le driver vérifie si la ligne existe :
  absente → `NotFound` ; présente mais avec une version différente → `ConcurrencyConflict`.

C'est ainsi qu'une écriture obsolète est détectée — il n'y a pas de verrouillage de ligne.

## Le journal d'audit {#the-audit-log}

`SchemaDeriver` émet aussi une table `kernel_audit_log`. `DatabaseAuditSink`
implémente le contrat `AuditSink` du kernel et écrit une ligne par action réussie —
acteur, tenant, FQN de l'action, sujet, entrées, sorties, horodatage,
identifiant de corrélation et numéro de séquence. L'écriture a lieu **à l'intérieur de la
transaction de l'action**, de sorte que l'entrée d'audit et le changement de données sont
validés ou annulés ensemble. Voir [Le Runtime](runtime.md).

## Le contrat `PersistenceDriver` partagé & PostgreSQL {#shared-contract-postgresql}

`PersistenceDriver` est un **contrat**, pas une implémentation unique. AUSUS
livre deux drivers derrière lui :

- **`ausus/persistence-sql`** — le driver SQLite de **référence** décrit ci-dessus
  (zéro-configuration ; idéal pour le développement et les tests).
- **`ausus/persistence-postgres`** — le driver PostgreSQL de **production**
  (`PostgresPersistenceDriver`, `PostgresRepository`, `PostgresSchemaDeriver`,
  `PostgresAuditSink`).

Les deux implémentent les mêmes opérations — dérivation de schéma, isolation des
tenants, concurrence optimiste, `find` / `create` / `update` / `findAll` /
`findPaged`, intégrité référentielle, et le sink d'audit dans la transaction. Ils
sont **compatibles en comportement** : les mêmes opérations produisent les mêmes
résultats et lèvent des exceptions identiques au message près
(`TenantBoundaryViolation`, `ConcurrencyConflict`,
`ReferentialIntegrityViolation`, …). Un gate de compatibilité inter-drivers
continu exécute les deux drivers sur la même suite à chaque changement.

Comme le contrat est partagé, une application passe de SQLite (développement) à
PostgreSQL (production) en configurant un driver différent — sans changement de
domaine :

```bash
composer require ausus/persistence-postgres:^1.1
```

## Limites actuelles {#current-v010-limitations}

- **SQLite et PostgreSQL** sont tous deux implémentés derrière le contrat partagé
  (ci-dessus). **MySQL** est un objectif de conception mais n'est pas implémenté.
- Le repository n'a **pas de `delete`**. Le listage, le filtrage, le tri et la
  pagination sont disponibles via `findAll` / `findPaged` (voir
  [Projections](../concepts/projections.md)).
- Il n'y a pas de migrations — `SchemaDeriver` utilise `CREATE TABLE IF NOT EXISTS`.
  Changer les champs d'une entité ne modifie pas une table existante.
- `_version` est régénéré comme un ULID ; c'est un jeton de changement, pas un compteur.

## Voir aussi {#related}

- [Le Runtime](runtime.md) — écrit via ce driver.
- [Le graphe de métadonnées](../concepts/metadata-graph.md) — la source du schéma.
- [Référence des erreurs](../reference/errors.md) — `NotFound`, `ConcurrencyConflict`.
