---
id: intro
title: AUSUS
sidebar_label: Vue d'ensemble
slug: /
description: Un framework PHP metadata-first et plugin-first pour les applications d'entreprise.
---

# AUSUS

AUSUS est un framework PHP pour construire des applications d'entreprise — plateformes
CRUD, outils de workflow, outils internes — à partir de **graphes de métadonnées** au lieu
de contrôleurs et de vues écrits à la main.

Vous décrivez votre domaine (entités, champs, actions, workflows, politiques,
projections) sous forme de **plugin**. Un compilateur transforme cette description en un
`MetadataGraph` déterministe et adressable par contenu. Un runtime en couches exécute
les actions sur celui-ci ; une API HTTP l'expose ; un moteur de rendu React le dessine.

## L'architecture d'abord {#architecture-first}

AUSUS est organisé comme une pile de couches dotées de contrats stables et
unidirectionnels. Une couche ne dépend jamais d'une couche située au-dessus d'elle.

```
L7  Plugins              user-authored domain logic
L6  Renderer (React)     @ausus/renderer-react
L5  Presentation         ProjectionRenderer -> ViewSchema (RFC-004)
L4  API Surface          ausus/api-http (PSR-7/15)
L3  Drivers              ausus/persistence-sql (and reserved drivers)
L2  Runtime              ausus/runtime-default (Invoker chain)
L1  Compiler             MetadataGraph synthesis
L0  Kernel               ausus/kernel (contracts, value objects, DSL)
```

C'est l'idée centrale : le **graphe de métadonnées est l'application**. Les backends,
les API et les interfaces utilisateur sont des rendus du même graphe plutôt que du code
maintenu indépendamment.

## Vue d'ensemble de l'architecture {#architecture-overview}

La même image sous forme de flux — votre plugin entre par le haut ; tout ce
qui suit est ce que le framework compile et exécute à partir de lui :

![Pile architecturale AUSUS : les Plugins (L7) écrits par l'utilisateur alimentent le Compiler (L1) qui construit le MetadataGraph (L0) ; le Runtime (L2) lit le graphe et pilote les Drivers (L3) ; l'API HTTP (L4) et la couche Presentation (L5) exposent les données au moteur de rendu React (L6).](/img/diagrams/architecture.svg)

Le pipeline d'invocation du runtime est détaillé dans
[Le runtime](backend/runtime.md) ; le cycle de vie du graphe dans
[Le graphe de métadonnées](concepts/metadata-graph.md) ;
le flux de données du moteur de rendu dans
[Le moteur de rendu React](frontend/react-renderer.md).

## Installation {#install}

Créez un nouveau projet à partir du modèle de démarrage :

```bash
composer create-project ausus/starter myapp
cd myapp && composer boot
# -> OK — ausus/starter boots cleanly.
```

Ajoutez le moteur de rendu React à un projet frontend :

```bash
npm install @ausus/renderer-react react@18 react-dom@18
```

Consultez [Installation](getting-started/installation.md) pour la procédure depuis les
sources et les prérequis de version.

## Un domaine minimal {#a-minimal-domain}

Voici un plugin de domaine complet — une entité `invoice` avec trois actions et
un workflow de statut — écrit dans le DSL AUSUS :

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
            ->workflow('status')
            ->projection('summary',
                fields:  ['id', 'number', 'customer_name', 'status', 'amount'],
                actions: ['create', 'cancel'],
                role:    'invoice.viewer');
    }
}
```

Découvrez ce processus de bout en bout dans le [tutoriel HelloInvoice](getting-started/hello-invoice.md).

## État actuel {#current-status}

:::info v0.1.0 — première version publique

Il s'agit d'une **première version publique**. Elle convient à l'évaluation, aux prototypes
et à l'apprentissage du modèle. Les contrats publics sont encore en cours de
**stabilisation** et peuvent changer avant la v1.0.

**Ce qui est livré et fonctionne aujourd'hui :**

- **4 bibliothèques PHP implémentées** — `kernel`, `persistence-sql`,
  `runtime-default`, `api-http`.
- Un **modèle de démarrage** (`ausus/starter`) et un **métapaquet**
  (`ausus/standard-stack`).
- Un **moteur de rendu React** (`@ausus/renderer-react`) pour le format de transport ViewSchema.

**Ce qui n'est _pas_ encore livré :**

- **4 noms de paquets sont uniquement réservés** — `tenancy-row`, `audit-database`,
  `auth-bridge`, `presentation-default` ne contiennent **aucun code source** dans la v0.1.0.
  Ils sont prévus pour la v0.2.0.
- La persistance est validée sur **SQLite** ; MySQL et PostgreSQL sont prévus dans la
  conception mais ne sont pas validés.
- Le runtime est **mono-processus, mono-tenant, mono-acteur** par invocation.
  Il n'existe ni runtime distribué ni runtime multi-tenant.
- L'authentification se limite à un **acteur stub** ; il n'y a pas de pont d'authentification.

Consultez les [notes de version v0.1.0](releases/v0.1.0.md) pour la matrice de compatibilité
complète et les limites connues.

:::

## Liens de l'écosystème {#ecosystem-links}

- **GitHub** — [adonko3xBitters/ausus-framework](https://github.com/adonko3xBitters/ausus-framework)
- **Packagist** — [paquets `ausus/*`](https://packagist.org/search/?query=ausus)
- **npm** — [`@ausus/renderer-react`](https://www.npmjs.com/package/@ausus/renderer-react)

## Par où continuer {#where-to-go-next}

| Si vous voulez… | Commencez ici |
|---|---|
| Installer AUSUS | [Démarrage → Installation](getting-started/installation.md) |
| Comprendre le modèle | [Concepts fondamentaux → Le graphe de métadonnées](concepts/metadata-graph.md) |
| Construire quelque chose | [Tutoriel HelloInvoice](getting-started/hello-invoice.md) |
| Voir ce qui est réel ou réservé | [Paquets](packages/index.md) |
| Évaluer la maturité de la version | [Notes de version v0.1.0](releases/v0.1.0.md) |
