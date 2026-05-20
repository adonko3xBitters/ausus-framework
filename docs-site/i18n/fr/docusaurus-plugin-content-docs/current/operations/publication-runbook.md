---
id: publication-runbook
title: Runbook de publication
sidebar_label: Runbook de publication
description: Comment AUSUS v0.1.0 est publié sur Packagist et npm — en toute sécurité.
---

# Runbook de publication

Publier AUSUS consiste à pousser 10 paquets Composer vers Packagist et un
paquet vers npm. Certaines de ces étapes sont **irréversibles**. Cette page
résume la procédure contrôlée utilisée pour le faire en toute sécurité.

:::info Normative source
Le runbook normatif faisant autorité est `docs/PUBLICATION-RUNBOOK.md` dans le
dépôt. Cette page en est un résumé consultable. En cas de divergence entre les
deux, le runbook du dépôt prévaut.
:::

## Opérations irréversibles {#irreversible-operations}

Trois opérations d'une publication ne peuvent pas être proprement annulées —
manipulez-les avec précaution :

| Opération | Réversibilité |
|---|---|
| **`npm publish`** | dépubliable uniquement dans une **fenêtre de 72 heures** ; passé ce délai, seul `npm deprecate` |
| **Soumission Packagist** | **jamais réversible** — une version publiée est définitive |
| **Pousser un tag git** vers un dépôt de publication | récupérable uniquement si Packagist ne l'a pas encore scanné |

À cause de cela, la publication est **contrôlée par phases** (phase-gated) :
chaque phase vérifie avant que la suivante ne commence.

## Contrôles préalables P0 {#p0-pre-flight-gates}

La publication ne démarre pas tant que chaque contrôle **P0** n'est pas passé.
Un contrôle (gate) P0 en échec signifie **STOP** — il est bloquant pour la
publication par définition.

| Contrôle (gate) | Exigence |
|---|---|
| P0-A | arbre de travail propre, sur `main`, synchronisé avec `origin` |
| P0-B | CI vert sur le **commit exact** en cours de tag |
| P0-C | les 10 dépôts de publication par paquet existent **et sont vides** |
| P0-D | aucun tag `v0.1.0` ne préexiste sur un dépôt de publication ou le monorepo |

Plus les vérifications d'identité npm : `npm whoami`, `npm org ls @ausus`, et
la disponibilité de la 2FA.

## Ordre de publication {#publication-order}

Les paquets sont publiés selon un **ordre topologique des dépendances**, afin
que les dépendances de chaque paquet soient déjà sur Packagist au moment de sa
soumission :

```
Phase 1  kernel + the 4 reserved skeletons
Phase 2  persistence-sql, runtime-default        (depend on kernel)
Phase 3  api-http                                (depends on runtime-default)
Phase 4  standard-stack, starter                 (top-level compositions)
Phase 5  @ausus/renderer-react  (npm)
Phase 6  post-publish smoke tests
Phase 7  monorepo tag + GitHub release
```

Tous les paquets Composer sont publiés **avant** npm, de sorte que le framework
ne se retrouve jamais dans un état où le moteur de rendu est en ligne mais pas
son backend.

## Propagation Packagist {#packagist-propagation}

Après la soumission d'un paquet, Packagist met **environ 30 à 120 secondes**
pour l'indexer. Le runbook **interroge** (poll) l'indexation avant de publier
tout paquet qui en dépend — publier un paquet dépendant trop tôt provoque un
échec de résolution lors d'une installation neuve. Le délai de propagation est
un comportement attendu, et non un défaut.

## STOP-si-cela-échoue {#stop-if-this-fails}

Chaque phase se termine par un contrôle (gate). Si un contrôle échoue, la
procédure **s'arrête** au lieu de le contourner — par exemple, si un push d'un
paquet est rejeté comme non-fast-forward, c'est que le dépôt n'était pas vide
(une régression de P0-C) et l'opérateur enquête au lieu de forcer le push.

## Rollback {#rollback}

Il n'existe pas de véritable « dépublication » :

- **npm** — dans les 72 heures, `npm unpublish` est possible ; passé ce délai,
  `npm deprecate`.
- **Packagist** — jamais réversible. Le seul remède est de **rouler en avant**
  vers `0.1.1` : incrémenter chaque manifeste affecté, ré-exécuter la
  publication par phases, et ré-interroger Packagist. Prévoir environ
  30 minutes.

## Voir aussi {#related}

- [Répétition de publication](release-rehearsal.md) — la simulation (dry-run) qui vérifie les contrôles.
- [Intégrité des paquets](package-integrity.md) — vérification des artefacts.
- [Notes de version v0.1.0](../releases/v0.1.0.md)
