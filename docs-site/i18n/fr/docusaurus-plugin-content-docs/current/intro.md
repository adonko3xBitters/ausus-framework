---
id: intro
title: AUSUS
sidebar_label: Vue d'ensemble
slug: /
description: Plateforme Laravel-native metadata-first, plugin-first et tenant-first pour les applications d'entreprise — CRUD, workflows ERP, SaaS multi-tenant et outils internes.
---

# AUSUS

**Livrez des applications d'entreprise en quelques jours, pas en trimestres.**
AUSUS est une plateforme Laravel-native pour construire des applications CRUD, des
workflows ERP, du SaaS multi-tenant et des outils internes — où la multi-tenancy,
l'autorisation, les pistes d'audit et les workflows d'approbation sont **intégrés**,
et non rapportés après coup.

Vous décrivez **ce qu'est** l'application — ses enregistrements, ses actions, ses
workflows et ses permissions — et AUSUS la compile en un système opérationnel :
schéma de base de données, règles métier, API HTTP et interface React, le tout à
partir d'une seule source de vérité.

## Que pouvez-vous construire ? {#what-can-you-build}

| Vous devez livrer | Ce qu'AUSUS fournit, prêt à l'emploi |
|---|---|
| **Une application back-office / admin** | Enregistrements, formulaires, listes et vues de détail générés depuis votre modèle de données |
| **Un workflow ERP / d'approbation** | Des machines à états de première classe (`draft → review → approved → paid`) avec transitions gardées par rôle-et-état et une piste d'audit complète |
| **Un SaaS multi-tenant** | Isolation des tenants appliquée à chaque lecture et écriture, pour qu'un client ne voie jamais les données d'un autre |
| **Un outil de gouvernance / sinistres / KYC** | **Une autorisation qui lit les données** — « un gestionnaire ne peut approuver un sinistre que jusqu'à sa limite » est une règle, pas du code sur mesure |
| **Un outil interne** | Un domaine typé, plus une API HTTP prête et une interface React, assemblés à partir de plugins réutilisables |

## Pourquoi AUSUS est différent {#why-different}

- **Metadata-first.** Vous déclarez votre domaine sous forme de métadonnées ;
  AUSUS le transforme en application opérationnelle au lieu de milliers de lignes
  de code de liaison.
- **Plugin-first.** Chaque capacité est un plugin interchangeable — changez la
  base de données (SQLite ↔ PostgreSQL) ou ajoutez un module métier sans toucher
  au moteur.
- **Tenant-first.** La multi-tenancy est structurelle : chaque enregistrement
  porte son tenant et la plateforme refuse de franchir cette frontière.
- **Sûr par construction.** Quatre garanties ne peuvent jamais être contournées
  par aucune API — **isolation des tenants, autorisation, audit et workflow**. La
  conformité devient une propriété de la plateforme, et non une liste de
  vérification en revue de code.

## Ce qui est livré aujourd'hui (v1.1.0) {#what-ships-today}

Une pile complète, installable en production, sur Packagist :

- **Moteur central** — `ausus/kernel`, `ausus/runtime-default` — compile votre
  application et exécute chaque action dans une seule transaction gardée et
  auditée.
- **Bases de données** — `ausus/persistence-sql` (SQLite, zéro-configuration pour
  le développement) et **`ausus/persistence-postgres` (PostgreSQL, pour la
  production)** — interchangeables derrière un seul contrat.
- **API HTTP** — `ausus/api-http` — une surface d'API PSR-7/15 prête, générée
  depuis votre modèle.
- **Frontend** — `@ausus/renderer-react` — un moteur de rendu React 18 / 19 qui
  transforme votre application en interface.
- **Démarrer vite** — `ausus/standard-stack` (le bundle organisé) et
  `ausus/starter` (`composer create-project`).

## Capacités livrées en v1.1 {#capabilities-v11}

- **PostgreSQL, prêt pour la production.** `ausus/persistence-postgres` amène
  AUSUS sur la base de données sur laquelle tournent réellement les systèmes
  d'entreprise — écritures concurrentes, durabilité, montée en charge — derrière
  le même contrat que le driver SQLite. Le passage du développement à la
  production ne demande **aucun changement de votre code applicatif**.
- **Relations et intégrité des données (RFC-015).** Reliez des enregistrements
  entre eux (un sinistre appartient à une police, une preuve à un sinistre). Les
  mauvais liens sont détectés à la construction de l'application, et les
  références cassées sont rejetées à l'écriture.
- **Autorisation dépendante des données (RFC-018).** Les permissions peuvent lire
  l'enregistrement et l'utilisateur : *approuver seulement si `montant ≤ la limite
  d'autorité de l'approbateur`*. Ces règles sont vérifiées avant tout changement
  et défaillent en mode fermé, exprimées en configuration plutôt qu'enfouies dans
  la logique métier.

## Installer {#install}

Créez un nouveau projet depuis le modèle starter :

```bash
composer create-project ausus/starter myapp
cd myapp
composer boot      # construit l'application, crée le schéma, exécute une démo de bout en bout
composer serve     # API HTTP en direct sur http://127.0.0.1:8080
```

Elle utilise SQLite par défaut. Passer en production sur PostgreSQL est une
dépendance d'une ligne, sans réécriture de votre domaine :

```bash
composer require ausus/persistence-postgres:^1.1
```

Ajoutez le moteur de rendu React à un projet frontend :

```bash
npm install @ausus/renderer-react react@18 react-dom@18
```

Voir [Installation](getting-started/installation.md) pour le chemin depuis les
sources et les versions requises.

## Un domaine minimal {#a-minimal-domain}

Voici un plugin de domaine complet — une entité `invoice` avec trois actions et un
workflow de statut — écrit dans le DSL AUSUS :

```php
use Ausus\{DslPlugin, Dsl, Field, Action};

final class HelloInvoiceDsl extends DslPlugin
{
    public function name(): string        { return 'billing'; }
    public function phpNamespace(): string { return 'Acme\\Billing'; }

    public function dsl(Dsl $dsl): void
    {
        $dsl->entity('invoice')
            ->fields([
                'number'        => Field::string()->unique()->max(32),
                'customer_name' => Field::string()->max(200),
                'amount'        => Field::money()->currency('USD'),
                'status'        => Field::enum('DRAFT', 'ISSUED', 'CANCELLED')->default('DRAFT'),
                'issued_at'     => Field::datetime()->nullable(),
            ])
            ->actions([
                'create' => Action::create('number', 'customer_name', 'amount')
                              ->requireRole('invoice.creator'),
                'issue'  => Action::transition('status', from: 'DRAFT', to: 'ISSUED')
                              ->stamp('issued_at')
                              ->requireRole('invoice.issuer'),
                'cancel' => Action::transition('status', from: 'DRAFT',  to: 'CANCELLED')
                              ->andTransition('status', from: 'ISSUED', to: 'CANCELLED')
                              ->requireRole('invoice.canceler'),
            ])
            ->workflow(field: 'status', initial: 'DRAFT')
            ->projection('summary',
                fields:  ['id', 'number', 'customer_name', 'status', 'amount'],
                actions: ['create', 'cancel'],
                role:    'invoice.viewer');
    }
}
```

Parcourez-le de bout en bout dans le [tutoriel HelloInvoice](getting-started/hello-invoice.md).

## Comment tout s'articule {#how-it-fits-together}

AUSUS maintient une séparation nette entre le domaine, le moteur et la
présentation :

- **Le domaine** décrit le métier — entités, actions, workflows, permissions — et
  ne connaît ni HTTP ni l'interface.
- **Le moteur** compile cette description et exécute chaque action dans un seul
  chemin transactionnel qui applique la tenancy, les permissions, le workflow et
  l'audit.
- **La présentation** — l'API HTTP et le moteur de rendu React — est générée à
  partir de cette même description. React est purement une couche de rendu.

![Architecture AUSUS : les plugins écrits par l'utilisateur sont compilés en un seul graphe de métadonnées ; le runtime pilote des pilotes de persistance interchangeables ; l'API HTTP et la couche de présentation exposent les données au moteur de rendu React.](/img/diagrams/architecture.svg)

Parce que la base de données, l'API et l'interface dérivent toutes d'une seule
source de vérité, elles ne peuvent pas diverger — et remplacer une partie (par
exemple SQLite par PostgreSQL) laisse le reste inchangé. Le pipeline d'invocation
du runtime est détaillé dans [Le Runtime](backend/runtime.md) ; le cycle de vie du
modèle est dans [Le graphe de métadonnées](concepts/metadata-graph.md).

## Liens de l'écosystème {#ecosystem-links}

- **GitHub** — [adonko3xBitters/ausus-framework](https://github.com/adonko3xBitters/ausus-framework)
- **Packagist** — [paquets `ausus/*`](https://packagist.org/search/?query=ausus)
- **npm** — [`@ausus/renderer-react`](https://www.npmjs.com/package/@ausus/renderer-react)

## Où aller ensuite {#where-to-go-next}

| Si vous voulez… | Commencez ici |
|---|---|
| Installer AUSUS | [Pour commencer → Installation](getting-started/installation.md) |
| Comprendre le modèle | [Concepts → Le graphe de métadonnées](concepts/metadata-graph.md) |
| Construire quelque chose | [Tutoriel HelloInvoice](getting-started/hello-invoice.md) |
| Voir les paquets | [Paquets](packages/index.md) |
| Lire les notes de version | [Versions sur GitHub](https://github.com/adonko3xBitters/ausus-framework/releases) |
