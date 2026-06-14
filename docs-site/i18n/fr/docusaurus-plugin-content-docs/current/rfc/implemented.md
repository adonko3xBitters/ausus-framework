---
id: implemented
title: RFC implémentés
sidebar_label: Implémentés
description: Les RFC réalisés par le code d'AUSUS v1.1.0.
---

# RFC implémentés

Ces RFC sont réalisés par la v1.1.0 — dans la plupart des cas comme un
sous-ensemble délibéré. Chaque entrée note ce qui a été livré et renvoie à la
documentation correspondante.

## RFC-001 — Kernel {#rfc-001--kernel}

La couche des contrats et objets-valeurs. La v0.1.0 livre les types de nœuds du
[graphe de métadonnées](../concepts/metadata-graph.md), le contrat `Plugin`, le
`Compiler`, les objets-valeurs (`Tenant`, `Reference`, `Actor`, `Context`, …),
`Ulid`, et la [taxonomie d'exceptions](../reference/errors.md).

**Note sur le sous-ensemble :** le kernel de la v0.1.0 utilise un espace de
noms `Ausus\` plat pour sa surface publique. Le README du paquet décrit une
réorganisation prévue en une arborescence d'espaces de noms
`Ausus\Kernel\Contracts\…` — cette réorganisation n'est **pas** dans la
v0.1.0.

→ [ausus/kernel](../packages/index.md#aususkernel)

## RFC-002 — Driver de persistance {#rfc-002--persistence-driver}

Les contrats `PersistenceDriver` / `Repository` et un driver concret. La
AUSUS livre **deux** drivers derrière le contrat — `persistence-sql` (SQLite,
référence) et `persistence-postgres` (PostgreSQL, production) — avec `find` /
`create` / `update` / `findAll` / `findPaged`, la dérivation de schéma, la
concurrence optimiste, le scoping par tenant et l'intégrité référentielle.

**Note sur le sous-ensemble :** pas de `delete`. La parité comportementale entre
les deux drivers est assurée par un gate de compatibilité inter-drivers.

→ [Persistance SQL](../backend/sql-persistence.md)

## RFC-004 — ViewSchema {#rfc-004--viewschema}

Le format JSON sur le fil entre le backend et le moteur de rendu. AUSUS livre
`schemaVersion 1.2.0`, le profil `react.web.v1`, et les formes de données
liste/détail avec pagination, filtrage et tri.

**Note sur le sous-ensemble :** locale fixe.

→ [ViewSchema](../frontend/viewschema.md)

## RFC-005 — Moteur de politiques {#rfc-005--policy-engine}

Autorisation des actions. AUSUS livre le `PolicyEngine` avec une sémantique de
refus par défaut et de fermeture en cas d'échec (fail-closed), la politique
`RoleRequired`, et — via RFC-018 — des gardes dépendants des données.

**Note sur le sous-ensemble :** pas de classe de politique distincte basée sur
les permissions.

→ [Politiques](../concepts/policies.md)

## RFC-006 — Runtime de workflow {#rfc-006--workflow-runtime}

Des machines à états sur les entités. La v0.1.0 livre l'inférence de workflow à
partir d'un champ enum, les gardes de transition, les sources joker
(wildcard), et les transitions multi-sources.

**Note sur le sous-ensemble :** les politiques de garde par transition ne sont
pas évaluées dans la v0.1.0.

→ [Workflows](../concepts/workflows.md)

## RFC-007 — Audit {#rfc-007--audit}

Une piste d'audit transactionnelle. La v0.1.0 livre `DefaultAuditor` et
`DatabaseAuditSink`, écrivant une entrée d'audit par action à l'intérieur de la
transaction de l'action, dans une table `kernel_audit_log`.

**Note sur le sous-ensemble :** le puits (sink) d'audit fait partie de
`persistence-sql` ; le paquet dédié `ausus/audit-database` est réservé et ne
livre aucun code. Le compteur de séquence par processus n'est pas durable d'un
redémarrage à l'autre.

→ [Le runtime](../backend/runtime.md) · [Persistance SQL](../backend/sql-persistence.md#the-audit-log)

## RFC-011 — DSL {#rfc-011--dsl}

L'API fluide de déclaration de domaine. La v0.1.0 livre le sous-ensemble
**minimal** de RFC-011 : `DslPlugin`, `Dsl`, `Field`, `Action`, et les
builders entité/champ/action/workflow/projection.

**Note sur le sous-ensemble :** les classes de politique/effet résolues par
convention, la visibilité au niveau du champ, et les diagnostics du DSL sont
reportés.

→ [Le DSL PHP](../backend/php-dsl.md)

## RFC-012 — Pile standard {#rfc-012--standard-stack}

L'ensemble de paquets organisé. La v0.1.0 livre `ausus/standard-stack` comme
métapaquet épinglant `kernel`, `persistence-sql`, `runtime-default`, et
`api-http`.

→ [Paquets](../packages/index.md)

## RFC-013 — Action / Effect {#rfc-013--action--effect}

Le contrat action-effet. La v0.1.0 livre les contrats `Effect` /
`EffectContext` et deux effets intégrés — `kernel.builtin.create` et
`kernel.builtin.transition` — dispatchés par `EffectDispatcher`.

**Note sur le sous-ensemble :** les classes `Effect` personnalisées sont
dispatchables mais ne sont pas exercées par le domaine d'exemple de la v0.1.0.

→ [Le runtime](../backend/runtime.md) · [Entités, champs et actions](../concepts/entities-fields-actions.md)

## RFC-015 — Relations et intégrité référentielle {#rfc-015--relations--referential-integrity}

Des références étrangères typées entre entités. AUSUS livre
`Field::reference(...)`, le rejet à la compilation des références orphelines
(`DanglingRelation`), l'application à l'écriture (`ReferentialIntegrityViolation`),
et le `expand` de projection pour replier le champ d'affichage d'une ligne
référencée. L'objet-valeur d'identité `Subject` est unifié dans `Reference`.

→ [Persistance SQL](../backend/sql-persistence.md)

## RFC-018 — Autorisation dépendante des données {#rfc-018--data-dependent-authorization}

Une autorisation qui lit les valeurs de champs du sujet et des attributs d'acteur
structurés. AUSUS livre `Action::…->requireThat(Cond)`, la surface `Fact` /
`Cond`, les `actorAttributes(...)` au niveau du plugin, la fermeture à la
compilation (`DanglingFactReference`), et l'évaluation dans la transaction, en
mode fermé (`PolicyDenied`).

→ [Politiques](../concepts/policies.md#data-dependent-authorization)

## Voir aussi {#related}

- [RFC prévus](planned.md) — ce qui n'est pas encore implémenté.
- [Notes de version v1.1.0](../releases/v1.1.0.md)
