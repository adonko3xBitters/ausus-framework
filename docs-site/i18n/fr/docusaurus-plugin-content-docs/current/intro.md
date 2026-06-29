---
id: intro
title: AUSUS 2.0
sidebar_label: Vue d'ensemble
slug: /
description: Un framework PHP metadata-first — déclarez entités, champs, actions et autorisations comme des données, et l'Entity Engine les compile en un backend, une API HTTP et une interface React.
---

# AUSUS 2.0

**Compilez des métadonnées immuables en applications fonctionnelles.**

AUSUS est un framework PHP **metadata-first**. Vous déclarez une application sous
forme de données — entités, champs, actions, projections et règles d'autorisation —
et l'**Entity Engine** compile cette déclaration en un schéma figé, adressé par
contenu, puis l'exécute : stockage, autorisation pilotée par la donnée, API HTTP et
interface React. Vous décrivez *ce qu'est* l'application ; le moteur fournit le
*comment*, une seule fois, de façon centralisée.

## Commencer ici

- **[Quick Start](gen2/QUICKSTART.md)** — de `composer require` à une interface rendue, en moins de cinq minutes.
- **[Hello Invoice](gen2/tutorials/hello-invoice.md)** — construisez votre première application de bout en bout, avec les seuls packages publics.
- **[Introduction](gen2/01-introduction.md)** — ce qu'est AUSUS, pourquoi il existe, ses principes.
- **[Architecture](gen2/02-architecture.md)** — le découpage L0 → L6 et le pipeline compile→run.

## Explorer

- [Pipeline](gen2/03-pipeline.md) — DSL → Compiler → EntitySchema → Runtime → API → React, étape par étape.
- [Capacités](gen2/06-capabilities.md) — actions, guards, expand, vues, API, React.
- [Applications de référence](gen2/05-reference-apps.md) — CRM, Teranga PMS, SGH.
- [Limites connues](gen2/07-known-limits.md) — les frontières du modèle, documentées ouvertement.

:::note Vous cherchez AUSUS 1.x ?
La lignée `standard-stack` antérieure est conservée sous **AUSUS 1.x (Legacy)** dans
la barre latérale. AUSUS 2.0 (l'Entity Engine) est la ligne actuelle.
:::
