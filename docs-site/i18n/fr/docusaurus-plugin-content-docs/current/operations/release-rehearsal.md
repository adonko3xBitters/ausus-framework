---
id: release-rehearsal
title: Répétition de publication
sidebar_label: Répétition de publication
description: La simulation (dry-run) de publication qui vérifie que la version est prête.
---

# Répétition de publication

Une **répétition de publication** est une simulation (dry-run) du
[Runbook de publication](publication-runbook.md) : elle exécute chaque contrôle
préalable sûr et vérifie les prérequis des étapes dangereuses **sans rien
publier, pousser, taguer ou soumettre**.

C'est ainsi que le projet confirme qu'une version est réellement prête avant
que toute opération irréversible ne soit lancée.

:::info Source
Les résultats de la répétition se trouvent dans
`docs/RELEASE-REHEARSAL-v0.1.0.md` dans le dépôt. Cette page résume ce
document.
:::

## Ce que vérifie une répétition {#what-a-rehearsal-checks}

Une répétition parcourt le §2 des contrôles préalables du runbook de haut en
bas :

- **Chaîne d'outils** — versions de PHP, Composer, Node, npm, GitHub CLI.
- **P0-A** — arbre de travail propre, sur `main`, synchronisé avec `origin`.
- **P0-B** — CI vert sur le commit exact de la version.
- **P0-C** — les 10 dépôts de publication par paquet existent et sont vides.
- **P0-D** — aucun tag `v0.1.0` n'existe encore sur un dépôt de publication ou le monorepo.
- **Identité npm** — `npm whoami`, `npm org ls @ausus`, et la disponibilité de la 2FA.
- **Artefacts** — `composer validate` sur tous les manifestes, `composer archive` sur
  chaque paquet, et `npm pack --dry-run` pour le moteur de rendu.
- **Contrôles locaux** — `scripts/ci.sh`, `scripts/clean-room.sh`,
  `scripts/integration-http.sh`.

Chaque commande qui modifie un registre est remplacée par un équivalent en
lecture seule.

## Détermination {#determination}

Une répétition se termine par l'une de deux déterminations :

- **HOLD** — un ou plusieurs contrôles P0 ont échoué ; la publication ne doit pas se poursuivre.
- **READY TO PUBLISH** — tous les contrôles P0 sont passés ; l'opérateur peut
  commencer la procédure par phases du runbook.

La répétition de la v0.1.0 a été exécutée de manière itérative. Les premières
exécutions ont renvoyé **HOLD** tant que l'infrastructure de publication était
encore en cours de mise en place (la branche du runbook devait être fusionnée,
le GitHub CLI devait être authentifié, les 10 dépôts de publication devaient
être créés, l'organisation npm `@ausus` devait être créée). Une fois cette
infrastructure en place, la répétition a renvoyé **READY TO PUBLISH** avec les
14 contrôles au vert.

## Pourquoi répéter {#why-rehearse}

La répétition existe parce que l'alternative — découvrir un dépôt de
publication manquant ou un CLI non authentifié **en pleine publication** —
risque de laisser l'écosystème dans un état à moitié publié et partiellement
irréversible. Une répétition déplace chaque échec détectable *avant* la
première opération irréversible.

## Règle de re-vérification {#re-verification-rule}

Une répétition vérifie le runbook par rapport à **un commit spécifique**. Si un
nouveau commit arrive sur `main` par la suite, les contrôles préalables — en
particulier P0-A et P0-B — doivent être ré-exécutés par rapport au nouveau
`HEAD` avant de publier.

## Voir aussi {#related}

- [Runbook de publication](publication-runbook.md) — la procédure répétée.
- [Intégrité des paquets](package-integrity.md) — détail de la vérification des artefacts.
