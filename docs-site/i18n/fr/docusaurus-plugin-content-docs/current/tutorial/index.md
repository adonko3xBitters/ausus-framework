---
id: index
title: 'Tutoriel : construire un système de tickets'
sidebar_label: Vue d'ensemble
description: Un tutoriel pas à pas, depuis zéro, qui construit une petite application AUSUS complète.
---

# Tutoriel : construire un système de tickets

Ce tutoriel construit une application complète et fonctionnelle **depuis zéro** — un
**système de tickets** de support minimal. Contrairement aux pages de concepts, qui
expliquent *comment AUSUS est conçu*, ce tutoriel vous apprend *comment assembler une
vraie application*, une étape exécutable à la fois.

À la fin, vous aurez :

- un plugin de domaine décrivant une entité `ticket`, ses champs, ses actions et son cycle de vie ;
- une base de données SQLite dont AUSUS a dérivé le schéma pour vous ;
- une API HTTP fonctionnelle ;
- une interface utilisateur React qui liste les tickets et pilote leur workflow.

Tout ici utilise uniquement les **capacités implémentées en v0.1.x**. Rien n'est
simulé ni hypothétique — chaque bloc de code s'exécute.

## Ce que vous allez construire {#what-you-will-build}

Un ticket parcourt un cycle de vie fixe :

```
OPEN  ──start──▶  IN_PROGRESS  ──resolve──▶  RESOLVED  ──close──▶  CLOSED
```

Chaque flèche est une **action** protégée par un **workflow**. Un agent ne peut
`resolve` qu'un ticket qui est `IN_PROGRESS` ; appeler `resolve` sur un ticket
`OPEN` est rejeté par le runtime, avant que quoi que ce soit ne soit écrit.

## Comment AUSUS s'articule {#how-ausus-fits-together}

Vous toucherez quatre couches. Gardez ce schéma en tête — chaque partie du
tutoriel en complète une pièce :

```
  Votre plugin (le domaine comme données)
        │  compilé en
        ▼
  MetadataGraph  ──▶  Runtime (Invoker : policy → workflow → effect → audit)
        │                     │
        │                     ▼
        │              Persistance SQLite
        ▼
  API HTTP  ──▶  JSON ViewSchema  ──▶  Renderer React
```

Vous n'écrivez jamais de contrôleur, de migration, de requête SQL ni de composant
de formulaire. Vous décrivez le domaine ; AUSUS le compile et l'exécute.

## Prérequis {#prerequisites}

| Outil | Version | Vérifié avec |
|---|---|---|
| PHP | 8.3+ | `php --version` |
| Extensions PHP | `pdo`, `pdo_sqlite` | `php -m` |
| Composer | 2.0+ | `composer --version` |
| Node.js | 18+ | `node --version` |
| npm | 8+ | `npm --version` |

Vous devez être à l'aise avec PHP et l'usage basique de la ligne de commande. Aucune
connaissance préalable d'AUSUS n'est requise. Vous n'avez **pas** besoin de Laravel —
AUSUS est natif Laravel mais ne nécessite pas le framework pour fonctionner.

## Le parcours d'apprentissage {#the-learning-path}

Suivez les sept parties dans l'ordre. Chacune s'appuie directement sur la précédente.

1. **[Installation](installation.md)** — créer le projet et installer AUSUS.
2. **[Le domaine](domain.md)** — amorcer l'`Application` ; déclarer l'entité,
   les champs, les actions et le workflow.
3. **[Persistance](persistence.md)** — exécuter l'application en ligne de commande ;
   AUSUS dérive et applique le schéma SQLite.
4. **[API HTTP](http-api.md)** — exposer le domaine via HTTP et l'éprouver
   avec `curl`.
5. **[Interface React](react-ui.md)** — afficher les tickets dans le navigateur et
   piloter le workflow.
6. **[Dépannage et récapitulatif](troubleshooting.md)** — erreurs courantes,
   conseils de débogage et récapitulatif final de l'architecture.

Durée totale : environ 30 à 45 minutes.

:::tip Où aller ensuite
Une fois le tutoriel assimilé, la section [Concepts fondamentaux](../concepts/metadata-graph.md)
explique le modèle en profondeur, et [Le DSL PHP](../backend/php-dsl.md)
est la référence complète du builder.
:::

Prêt ? Commencez par **[Partie 1 — Installation](installation.md)**.
