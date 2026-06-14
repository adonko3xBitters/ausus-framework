---
id: index
title: Paquets
sidebar_label: Catalogue des paquets
slug: /packages/
description: Tous les paquets d'AUSUS v1.1.0 — implémentés et réservés.
---

# Paquets

AUSUS v1.1.0 publie **11 paquets Composer** et **1 paquet npm**. Cette
page est la liste de référence de ce qu'est chacun d'eux — et lesquels sont
des **noms réservés sans code à ce jour**.

:::warning Implémentés vs réservés
Quatre paquets Composer sont **uniquement des réservations de nom**. Ils
contiennent un `composer.json` et un `README.md` mais **aucun code source**. Ils
sont listés ici afin que l'espace de noms soit documenté et revendiqué, et
restent réservés en v1.1.0. Ne comptez pas sur eux comme des paquets
fonctionnels.
:::

## Implémentés — Composer {#implemented--composer}

| Paquet | Couche | Rôle |
|---|---|---|
| [`ausus/kernel`](#aususkernel) | L0 | contrats, objets-valeurs, DSL, Compiler |
| [`ausus/runtime-default`](#aususruntime-default) | L2 | la chaîne Invoker |
| [`ausus/persistence-sql`](#aususpersistence-sql) | L3 | pilote de persistance SQLite (référence) |
| [`ausus/persistence-postgres`](#aususpersistence-postgres) | L3 | pilote de persistance PostgreSQL (production) |
| [`ausus/api-http`](#aususapi-http) | L4 | API HTTP PSR-7/15 |

### ausus/kernel {#aususkernel}

Couche L0. Contrats et objets-valeurs uniquement — aucun effet de bord à
l'exécution. Définit les types de nœuds du [graphe de métadonnées](../concepts/metadata-graph.md),
le contrat `Plugin`, le `Compiler`, le [DSL](../backend/php-dsl.md) (`DslPlugin`, `Dsl`,
`Field`, `Action`), les contrats `Policy` / `Effect` / `Repository` / `Auditor`,
les objets-valeurs (`Tenant`, `Reference`, `Actor`, …), `Ulid`, et
la [taxonomie des exceptions](../reference/errors.md). Tous les autres paquets en
dépendent.

### ausus/runtime-default {#aususruntime-default}

Couche L2. Le moteur d'exécution : `Invoker`, `PolicyEngine`, `WorkflowRuntime`,
`EffectDispatcher` et les effets intégrés, `DefaultAuditor`, et
`ProjectionRenderer`. Dépend de `kernel`. Voir [Le runtime](../backend/runtime.md).

### ausus/persistence-sql {#aususpersistence-sql}

Couche L3. Le `PersistenceDriver` de **référence** adossé à SQLite :
`SqlitePersistenceDriver`, `SqliteRepository`, `SchemaDeriver`, et
`DatabaseAuditSink`. Dépend de `kernel`. Voir
[Persistance SQL](../backend/sql-persistence.md).

### ausus/persistence-postgres {#aususpersistence-postgres}

Couche L3. L'implémentation PostgreSQL de **production** du même contrat
`PersistenceDriver` : `PostgresPersistenceDriver`, `PostgresRepository`,
`PostgresSchemaDeriver`, et `PostgresAuditSink`. Compatible en comportement avec
`persistence-sql`, vérifiée par un gate de compatibilité inter-drivers continu.
Dépend de `kernel`. Voir
[Persistance SQL](../backend/sql-persistence.md#shared-contract-postgresql).

### ausus/api-http {#aususapi-http}

Couche L4. Un `Router` PSR-15 exposant les projections et les actions via HTTP,
plus `ErrorMapper` et un `Emitter` minimal. Dépend de `kernel` et
`runtime-default`. Voir [L'API HTTP](../backend/http-api.md).

## Implémentés — modèle de projet et métapaquet {#implemented--template-and-metapackage}

| Paquet | Type | Rôle |
|---|---|---|
| `ausus/starter` | projet | modèle `composer create-project` — câble la pile et fournit l'exemple HelloInvoice |
| `ausus/standard-stack` | métapaquet | épingle l'ensemble de paquets validé pour la v1.1.0 ; dépend de `kernel`, `persistence-sql`, `runtime-default`, `api-http` |

## Implémentés — npm {#implemented--npm}

| Paquet | Rôle |
|---|---|
| `@ausus/renderer-react` | moteur de rendu React 18/19 pour le format de transport [ViewSchema](../frontend/viewschema.md) |

ESM uniquement ; `react`/`react-dom` sont des dépendances paires. Voir
[Le moteur de rendu React](../frontend/react-renderer.md).

## Réservés — nom uniquement, aucun code {#reserved--name-only-no-code-in-v010}

Ces quatre paquets sont des **noms réservés**. Ils sont livrés avec des
métadonnées mais **aucune implémentation**, et restent réservés en v1.1.0.

| Paquet | Réservé pour | Couche prévue |
|---|---|---|
| `ausus/tenancy-row` | un pilote de multi-tenancy au niveau ligne | L3 |
| `ausus/audit-database` | un sink/pilote d'audit dédié en base de données | L3 |
| `ausus/auth-bridge` | le pont d'authentification / résolution d'acteur | L2–L4 |
| `ausus/presentation-default` | la couche de présentation L5 au-delà du moteur de rendu du kernel | L5 |

:::note Ce que cela signifie pour vous
- La **multi-tenancy** est le cloisonnement par tenant intégré aux pilotes de
  persistance ([`persistence-sql`](#aususpersistence-sql) /
  [`persistence-postgres`](#aususpersistence-postgres)) — et non un pilote
  `tenancy-row` distinct.
- L'**audit** est le sink d'audit dans la transaction fourni par les pilotes de
  persistance — et non le paquet `audit-database`.
- L'**authentification** n'est pas fournie — `auth-bridge` n'est pas écrit. Voir la
  note de sécurité dans [L'API HTTP](../backend/http-api.md).
:::

## Ordre des dépendances {#dependency-order}

Lors de l'installation manuelle des paquets, suivez l'ordre des dépendances :

```
kernel
 ├─ runtime-default      (-> kernel)
 ├─ persistence-sql      (-> kernel)
 ├─ persistence-postgres (-> kernel)
 └─ api-http             (-> kernel, runtime-default)
standard-stack           (-> kernel, persistence-sql, runtime-default, api-http)
starter                  (-> kernel, persistence-sql, runtime-default)
```

## Voir aussi {#related}

- [Installation](../getting-started/installation.md) — comment les installer.
- [Notes de version v1.1.0](../releases/v1.1.0.md) — la version courante.
- [Intégrité des paquets](../operations/package-integrity.md) — vérification des artefacts.
