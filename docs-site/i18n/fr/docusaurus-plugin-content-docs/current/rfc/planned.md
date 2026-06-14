---
id: planned
title: RFC prévus
sidebar_label: Prévus / Reportés
description: Les RFC pas encore réalisés, ou réalisés seulement en partie, par la v1.1.0.
---

# RFC prévus / reportés

Ces RFC ne sont **pas** réalisés par la v1.1.0, ou ne le sont qu'en partie. Ils
décrivent la direction visée du framework.

:::caution Conception, pas engagement
Les éléments ci-dessous sont une conception et une direction architecturale
documentées. Ils ne sont **pas** des garanties de feuille de route, des dates
ni des promesses. Considérez-les comme « la direction vers laquelle pointe
l'architecture », et non comme « ce qui sera livré et quand ».
:::

## RFC-003 — Multi-tenant (tenancy) {#rfc-003--tenancy}

Conçoit un driver de **multi-tenant au niveau ligne** (row-level tenancy)
dédié et un modèle de résolution de tenant.

**État actuel — partiel.** Le scoping par tenant *existe* — chaque entité est
scopée par tenant et le driver SQLite applique les frontières de tenant (voir
[Persistance SQL](../backend/sql-persistence.md)). Ce qui n'est **pas** dans la
v0.1.0, c'est le paquet de driver dédié `ausus/tenancy-row` ; ce nom est
réservé et ne livre aucun code. Il n'y a pas de *runtime* multi-tenant — un
`Invoker` est lié à un seul tenant.

## RFC-007 (paquet d'audit dédié) — Base de données d'audit {#rfc-007-dedicated-audit-package--audit-database}

RFC-007 lui-même est [implémenté en sous-ensemble](implemented.md#rfc-007--audit) —
la piste d'audit fonctionne. Ce qui est reporté, c'est le **paquet dédié
`ausus/audit-database`** : un driver d'audit autonome séparé de
`persistence-sql`. Ce nom est réservé et ne livre aucun code dans la v0.1.0.

## RFC-010 — Reporting et maintenance {#rfc-010--reporting--maintenance}

Conçoit un sous-système de reporting/requête et des opérations de classe
maintenance (`ReportingDriver`, invocations de classe maintenance).

**État actuel — non implémenté.** Il n'y a pas de driver de reporting. Le
kernel distingue une classe d'invocation `Maintenance` dans l'enregistrement
d'audit, mais aucun sous-système de reporting ou de maintenance n'est livré.

## RFC-014 — Autorisation {#rfc-014--authorization}

Conçoit le modèle d'autorisation complet — résolution d'acteur, un pont
d'authentification, et une composition de politiques plus riche.

**État actuel — partiel.** Les contrats `Actor` / `ActorRef` existent dans le
kernel, et `StubActor` fournit un acteur fixe en mémoire. Ce qui n'est **pas**
dans la v0.1.0 : toute authentification, la résolution d'acteur à partir
d'identifiants, ou le paquet `ausus/auth-bridge` (réservé, sans code). Tout ce
qui expose le runtime doit fournir sa propre authentification — voir
[L'API HTTP](../backend/http-api.md).

## Récapitulatif des paquets réservés {#reserved-packages-summary}

Quatre noms de paquets sont réservés et liés aux RFC ci-dessus :

| Paquet | RFC | Statut |
|---|---|---|
| `ausus/tenancy-row` | RFC-003 | nom réservé, sans code |
| `ausus/audit-database` | RFC-007 | nom réservé, sans code |
| `ausus/auth-bridge` | RFC-014 | nom réservé, sans code |
| `ausus/presentation-default` | présentation (L5) | nom réservé, sans code |

Voir [Paquets](../packages/index.md) pour le catalogue complet.

## Autres éléments reportés {#other-deferred-items}

Non liés à un RFC unique, mais documentés comme reportés :

- **Driver de persistance MySQL** — la conception SQL le permet ; SQLite et
  PostgreSQL sont tous deux implémentés aujourd'hui.
- **Attestation de la chaîne d'approvisionnement** — la provenance npm, les
  tags signés GPG et un SBOM sont reportés à la v0.2.0 (voir
  [Intégrité des paquets](../operations/package-integrity.md)).
- **Enrichissements du DSL** — classes de politique/effet résolues par
  convention, visibilité au niveau du champ, diagnostics du DSL (surface
  reportée de RFC-011).

## Voir aussi {#related}

- [RFC implémentés](implemented.md)
- [Notes de version v1.1.0](../releases/v1.1.0.md) — la version courante.
