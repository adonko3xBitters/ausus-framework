---
id: index
title: RFC
sidebar_label: Aperçu des RFC
slug: /rfc/
description: Les RFC architecturaux derrière AUSUS.
---

# RFC

AUSUS est conçu à travers des **RFC** — des documents de conception
architecturale, un par sous-système. Les RFC constituent le raisonnement
derrière le framework ; le code en est une implémentation.

:::info Where the RFCs live
Les textes complets des RFC se trouvent dans le répertoire `rfcs/` du
[dépôt](https://github.com/adonko3xBitters/ausus-framework/tree/main/rfcs).
Cette section en est une carte et un relevé de **quels RFC la v0.1.0 implémente
réellement** — dans la plupart des cas comme un sous-ensemble délibéré.
:::

## Comment lire cette section {#how-to-read-this-section}

- **[Implémentés dans la v0.1.0](implemented.md)** — les RFC que le code de la
  v0.1.0 réalise, avec une note sur le sous-ensemble livré.
- **[Prévus / reportés](planned.md)** — les RFC pas encore réalisés, ou
  réalisés seulement en partie, et ce qui manque.

L'apparition d'un sous-système dans un RFC ne signifie **pas** qu'il existe
dans la v0.1.0. Vérifiez toujours la répartition implémenté/prévu avant de vous
appuyer sur une capacité.

## Catalogue des RFC {#rfc-catalogue}

| RFC | Sujet | v0.1.0 |
|---|---|---|
| RFC-000 | Premières passes d'implémentation réelle / tranche verticale | base de la v0.1.0 |
| RFC-001 | Kernel — contrats et objets-valeurs | implémenté (sous-ensemble) |
| RFC-002 | Driver de persistance | implémenté (sous-ensemble SQLite) |
| RFC-003 | Multi-tenant (tenancy) | partiel — voir ci-dessous |
| RFC-004 | ViewSchema | implémenté (sous-ensemble) |
| RFC-005 | Moteur de politiques | implémenté (sous-ensemble) |
| RFC-006 | Runtime de workflow | implémenté (sous-ensemble) |
| RFC-007 | Audit | implémenté (sous-ensemble) |
| RFC-010 | Reporting et maintenance | prévu |
| RFC-011 | DSL | implémenté (sous-ensemble minimal) |
| RFC-012 | Pile standard | implémenté |
| RFC-013 | Action / Effect | implémenté (effets intégrés) |
| RFC-014 | Autorisation | partiel — contrats uniquement |

Plusieurs RFC comportent des amendements et des revues
(`RFC-001-amendment-01`, `RFC-006-amendment-01`, `RFC-007-amendment-01`, …) ;
ceux-ci affinent le RFC parent et se lisent en parallèle de celui-ci.

## Pourquoi des sous-ensembles {#why-subsets}

La v0.1.0 implémente des **sous-ensembles** de la plupart des RFC à dessein.
Les RFC décrivent l'état final visé ; la v0.1.0 est une tranche verticale qui
prouve l'architecture de bout en bout avec la plus petite surface qui
fonctionne. Les pages de cette section rendent explicite l'écart entre
« conçu » et « livré ».

## Voir aussi {#related}

- [RFC implémentés](implemented.md) · [RFC prévus](planned.md)
- [Concepts fondamentaux](../concepts/metadata-graph.md) — le modèle implémenté.
- [Notes de version v0.1.0](../releases/v0.1.0.md) — la surface livrée.
