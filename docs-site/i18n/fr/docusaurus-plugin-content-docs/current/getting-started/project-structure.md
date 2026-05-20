---
id: project-structure
title: Structure du projet
sidebar_label: Structure du projet
description: Comment sont organisés le monorepo AUSUS et une application générée.
---

# Structure du projet

Il existe deux organisations qu'il est utile de connaître : le **monorepo** (où AUSUS lui-même
est développé) et une **application consommatrice** (ce que vous construisez).

## Le monorepo {#the-monorepo}

Le framework est développé comme un dépôt unique avec un paquet publiable
par répertoire.

```
ausus-framework/
├── packages/                 one Composer package per directory
│   ├── kernel/               L0 — contracts, value objects, DSL, Compiler
│   ├── persistence-sql/      L3 — SQLite PersistenceDriver
│   ├── runtime-default/      L2 — Invoker chain
│   ├── api-http/             L4 — PSR-7/15 HTTP API
│   ├── standard-stack/       metapackage — pins the v0.1.0 set
│   ├── starter/              project template
│   ├── tenancy-row/          reserved name — no code in v0.1.0
│   ├── audit-database/       reserved name — no code in v0.1.0
│   ├── auth-bridge/          reserved name — no code in v0.1.0
│   └── presentation-default/ reserved name — no code in v0.1.0
├── renderer/
│   └── react/                @ausus/renderer-react (npm package)
├── apps/
│   └── playground/           end-to-end runner + live HTTP trace
├── docs/                     design docs + operational runbooks
├── docs-site/                this documentation site (Docusaurus)
├── rfcs/                     architectural RFCs
└── scripts/                  ci.sh, clean-room.sh, integration-http.sh
```

Chaque répertoire de paquet contient `composer.json`, `src/`, `README.md`,
`CHANGELOG.md` et `LICENSE`. Les quatre paquets réservés contiennent un
`composer.json` et un `README.md` mais **aucun code PHP `src/`**.

### Portes de validation {#validation-gates}

| Script | Ce qu'il exécute |
|---|---|
| `scripts/ci.sh` | porte en 10 étapes : validate, install, playground, boot, build, trace, pack, intégration HTTP |
| `scripts/clean-room.sh` | reconstruction isolée en 8 étapes dans un répertoire temporaire |
| `scripts/integration-http.sh` | 12 assertions HTTP en direct contre `php -S` + le moteur de rendu |
| `apps/playground/run.php` | 36 assertions de bout en bout |

## Une application consommatrice {#a-consuming-application}

Un projet créé à partir de `ausus/starter` est un projet Composer normal :

```
myapp/
├── composer.json             requires ausus/kernel, persistence-sql, runtime-default
├── bin/
│   └── boot.php              the `composer boot` end-to-end smoke script
├── src/
│   ├── HelloInvoice.php      sample plugin — hand-written descriptor form
│   └── HelloInvoiceDsl.php   sample plugin — DSL form
└── vendor/
```

Votre propre application remplace les plugins d'exemple par vos plugins de domaine et
remplace `bin/boot.php` par votre véritable point d'entrée (une commande CLI, un contrôleur
frontal HTTP utilisant [ausus/api-http](../backend/http-api.md), etc.).

## Où se trouve le code de domaine {#where-domain-code-lives}

AUSUS n'impose aucune convention de répertoire pour votre code de domaine. Un plugin est
une classe PHP ordinaire qui étend `DslPlugin` (ou implémente `Plugin`). Placez-le
là où votre autoloader peut le trouver. La seule règle stricte est qu'un plugin
déclare un `phpNamespace()` et un `name()` — consultez [Plugins](../concepts/plugins.md).

## Conventions de nommage {#naming-conventions}

- **FQN d'entité** — `{plugin name}.{entity local name}`, par exemple `billing.invoice`.
- **FQN d'action** — `{entity FQN}.{action local name}`, par exemple `billing.invoice.issue`.
- **FQN de projection** — `{entity FQN}.{projection local name}`, par exemple
  `billing.invoice.summary`.
- **Nom de table SQL** — le FQN d'entité avec les points remplacés par des tirets bas, par
  exemple `billing_invoice`.

## Étapes suivantes {#next}

- [Plugins](../concepts/plugins.md) — l'unité de code de domaine.
- [Paquets](../packages/index.md) — ce que contient chaque paquet.
