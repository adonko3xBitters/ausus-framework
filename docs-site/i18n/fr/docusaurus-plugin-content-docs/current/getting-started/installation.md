---
id: installation
title: Installation
sidebar_label: Installation
description: Prérequis et procédures d'installation pour AUSUS v0.1.0.
---

# Installation

AUSUS v0.1.0 comporte deux moitiés que vous pouvez installer indépendamment : les paquets
du **backend PHP** et le paquet npm du **moteur de rendu React**.

## Prérequis {#requirements}

| Couche | Outil | Minimum | Testé avec |
|---|---|---|---|
| Runtime (PHP) | `php` | 8.3 | 8.4.18 |
| Runtime (PHP) | `ext-pdo`, `ext-pdo_sqlite` | bundled | bundled |
| Outillage (PHP) | `composer` | 2.0 | 2.9.5 |
| Runtime (JS) | `node` | 18 | 22.x |
| Outillage (JS) | `npm` | 8 | 10.x |
| Runtime (JS) | `react`, `react-dom` | ^18 \|\| ^19 | 18.3.1 |

AUSUS ne nécessite **pas** le framework Laravel, Eloquent, Filament,
Tailwind, un bundler ou une bibliothèque de composants UI. La persistance dans la v0.1.0
utilise le pilote PDO SQLite intégré.

## Option A — partir du modèle de projet {#option-a--start-from-the-project-template}

La voie la plus rapide. `ausus/starter` est un projet prêt à l'emploi qui câble déjà
ensemble le kernel, la persistance, le runtime et un domaine d'exemple.

```bash
composer create-project ausus/starter myapp
cd myapp
composer boot
```

Sortie attendue :

```
ausus/starter boot
  ✓ compiled graph (hash …)
  ✓ schema applied
  ✓ created invoice id=…
  ✓ issued invoice (DRAFT → ISSUED)
  ✓ rendered summary projection (items=1)
OK — ausus/starter boots cleanly.
```

Si vous avez utilisé `--no-install`, terminez avec `composer install && composer boot`.

## Option B — ajouter des paquets à un projet existant {#option-b--add-packages-to-an-existing-project}

N'installez que les paquets dont vous avez besoin. L'ordre des dépendances est ascendant :

```bash
composer require ausus/kernel
composer require ausus/runtime-default
composer require ausus/persistence-sql
composer require ausus/api-http        # optional — HTTP API surface
```

Ou figez l'ensemble validé de la v0.1.0 avec le métapaquet :

```bash
composer require ausus/standard-stack
```

## Option C — compiler depuis les sources (monorepo) {#option-c--build-from-source-monorepo}

Utilisez cette voie pour lire le code, exécuter les portes de validation ou contribuer.

```bash
git clone https://github.com/adonko3xBitters/ausus-framework.git
cd ausus-framework
composer install     # workspace install via path repositories
npm install           # workspace install
bash scripts/ci.sh    # full validation gate
```

`scripts/ci.sh` exécute une porte en 10 étapes et se termine par :

```
[ci] DONE — all 10 steps passed
```

## Le moteur de rendu React {#the-react-renderer}

Le moteur de rendu est un paquet npm distinct. `react` et `react-dom` sont des **peer
dependencies** — vous les installez vous-même.

```bash
npm install @ausus/renderer-react react@18 react-dom@18
# React 19 also works:
# npm install @ausus/renderer-react react@^19 react-dom@^19
```

Le paquet est **ESM uniquement** (`"type": "module"`, résolution NodeNext). Il ne livre
aucune dépendance intégrée ni aucun fichier CSS — consultez
[Le moteur de rendu React](../frontend/react-renderer.md).

## Limites actuelles de la v0.1.0 {#current-v010-limitations}

- **La persistance est validée uniquement sur SQLite.** Les pilotes MySQL/PostgreSQL
  sont un objectif de conception, pas une capacité testée de la v0.1.0.
- `composer create-project ausus/starter` est un flux en 2 commandes
  (`create-project` puis `composer boot`) ; avec `--no-install` il devient un
  flux en 3 commandes.
- Les paquets réservés (`tenancy-row`, `audit-database`, `auth-bridge`,
  `presentation-default`) ne sont **pas installables en tant que code fonctionnel** — ce sont
  des réservations de noms. Consultez [Paquets](../packages/index.md).

## Étapes suivantes {#next}

- [Votre première application](first-app.md) — câblez les couches ensemble à la main.
- [Tutoriel HelloInvoice](hello-invoice.md) — un domaine complet, de bout en bout.
