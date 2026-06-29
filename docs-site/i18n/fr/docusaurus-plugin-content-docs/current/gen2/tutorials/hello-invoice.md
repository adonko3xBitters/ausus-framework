---
id: hello-invoice
title: "Tutoriel — Hello Invoice"
sidebar_label: Hello Invoice
description: Construisez votre première application AUSUS 2.0 — un petit gestionnaire de factures — uniquement avec les packages publics, de la déclaration DSL jusqu'à l'interface React.
---

# Hello Invoice

La première application officielle d'AUSUS 2.0. C'est un petit gestionnaire de
factures qui parcourt **l'intégralité du pipeline Gen2** — Authoring → Compiler →
graphe immuable → Runtime → API HTTP → React Renderer — en n'utilisant **que les
packages publics**. C'est exactement le projet que vous téléchargeriez depuis
GitHub : aucun monorepo, aucun path repository, aucun code interne.

## 1. Introduction

Vous déclarez une entité `Invoice` sous forme de données. L'Entity Engine compile
cette déclaration en un schéma figé, adressé par contenu, puis l'exécute :
stockage, autorisation pilotée par la donnée, API HTTP et interface React — le
tout dérivé de l'unique déclaration. Comptez environ quinze minutes pour obtenir
une application fonctionnelle.

## 2. Installation

Prérequis : **PHP 8.3+** et (pour l'interface) **Node 18+**. Créez un dossier de
projet et installez les packages Composer publics depuis Packagist :

```bash
mkdir hello-invoice && cd hello-invoice
composer require ausus/authoring:^2.0 ausus/entity-engine:^2.0 \
                 ausus/persistence-memory:^2.0 ausus/api-runtime:^2.0
```

`ausus/authoring` fournit le DSL, `ausus/entity-engine` le Compiler et le runtime,
`ausus/persistence-memory` le driver de référence, et `ausus/api-runtime` la
surface HTTP. `ausus/kernel` est tiré automatiquement.

## 3. Créer le projet

```
hello-invoice/
  composer.json
  entities/
    Invoice.php        # la déclaration
  bin/
    demo.php           # compile → runtime → API, en un seul script
    server.php         # contrôleur HTTP pour le renderer React
  web/                 # l'interface React (étape 8)
```

## 4. Déclarer l'entité Invoice

`entities/Invoice.php` retourne exactement une `EntityDefinition` immuable. Champs,
actions, projections et autorisations sont des données. Les guards utilisent
**uniquement des opérateurs primitifs** (`eq` / `lt` / `not`) : le runtime refuse
dès qu'un fait n'est pas résolu.

```php
<?php
use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('invoice', true)            // multi-tenant
    ->field('number', FieldType::String)
    ->field('customer', FieldType::String)
    ->field('issueDate', FieldType::Date)
    ->field('dueDate', FieldType::Date)
    ->field('status', FieldType::Enum, [
        'default' => 'draft',
        'writeProtected' => true,                   // seules les transitions le changent
        'typeOptions' => ['values' => ['draft', 'paid', 'cancelled']],
    ])
    ->field('total', FieldType::Decimal)

    ->action('create', ActionKind::Create, [
        'inputs' => ['number', 'customer', 'issueDate', 'dueDate', 'total'],
        'guard' => Expr::eq(Expr::actor('type'), 'user'),
    ])
    ->action('update', ActionKind::Update, [
        'inputs' => ['customer', 'dueDate', 'total'],
        'guard' => Expr::eq(Expr::actor('type'), 'user'),
    ])
    ->action('pay', ActionKind::Transition, [
        'guard' => Expr::not(Expr::lt(Expr::subject('total'), 1)),   // total >= 1
        'transition' => ['field' => 'status', 'from' => 'draft', 'to' => 'paid'],
    ])
    ->action('cancel', ActionKind::Transition, [
        'guard' => Expr::eq(Expr::actor('type'), 'user'),
        'transition' => ['field' => 'status', 'from' => 'draft', 'to' => 'cancelled'],
    ])

    ->projection('board', ['fields' => [
        ['field' => 'number'], ['field' => 'customer'], ['field' => 'status'], ['field' => 'total'],
    ]])
    ->projection('detail', ['fields' => [
        ['field' => 'number'], ['field' => 'customer'], ['field' => 'issueDate'],
        ['field' => 'dueDate'], ['field' => 'status'], ['field' => 'total'],
    ]])
    ->build();
```

## 5. Compilation

Le **Compiler** transforme la déclaration en un `EntitySchema` adressé par contenu
(forme normale canonique + hash SHA-256). Mêmes sémantiques ⇒ même hash ; le
runtime ne recompile jamais.

```php
use Ausus\Engine\Compile\Compiler;
use Ausus\Engine\Repository\InMemorySchemaRepository;

$invoice = require __DIR__ . '/../entities/Invoice.php';   // EntityDefinition
$graph   = (new Compiler())->compile([$invoice]);          // CompiledGraph immuable

$repo = new InMemorySchemaRepository();
foreach ($graph->schemas as $schema) {
    $repo->putByHash($schema);                             // store adressé par contenu
}
```

## 6. Lancer le runtime

Liez le schéma à un driver et invoquez des actions. Le runtime ne dépend que du
contrat `PersistenceDriver` ; on utilise ici le driver en mémoire de référence.

```php
use Ausus\Engine\Runtime\DefaultEntityEngine;
use Ausus\Engine\Runtime\DefaultAuthorizationEvaluator;
use Ausus\Persistence\Memory\MemoryDriver;
use Ausus\Api\Runtime\Http\RequestContextFactory;

$engine  = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$driver  = new MemoryDriver();
$ctx     = (new RequestContextFactory(new DateTimeImmutable()))
    ->fromHeaders(['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user']);

$invoice = $engine->bind($repo->resolve('invoice'), $driver);
$created = $invoice->invoke('create', [
    'number' => 'INV-001', 'customer' => 'Globex',
    'issueDate' => '2025-01-10', 'dueDate' => '2025-02-10', 'total' => 1500,
], $ctx);
$id = $created->reference->identityHandle;

$invoice->invoke('update', ['id' => $id, 'total' => 1800], $ctx);  // mise à jour
$invoice->invoke('pay', ['id' => $id], $ctx);                      // draft → paid
```

Un acteur `guest` appelant `create` est **refusé** — le guard `actor.type = user`
échoue en mode fermé. Exécutez l'ensemble avec `php bin/demo.php`.

## 7. Démarrer l'API HTTP

L'API Runtime expose le même domaine via un contrat agnostique du framework :
`dispatch(method, path, headers, body)` retourne `{ status, body }`. Routes :
`GET /api/entities/{entity}`, `GET …/projections/{projection}`,
`POST …/actions/{action}`.

```php
use Ausus\Api\Runtime\Http\RuntimeApi;

$api = new RuntimeApi($repo, $engine, $driver, new RequestContextFactory(new DateTimeImmutable()));
$res = $api->dispatch('GET', '/api/entities/invoice/projections/board',
    ['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user']);
// $res === ['status' => 200, 'body' => ['rows' => [ … ]]]
```

`bin/server.php` branche cela dans un contrôleur frontal ; servez-le :

```bash
php -S 127.0.0.1:8080 bin/server.php
curl http://127.0.0.1:8080/api/entities/invoice/projections/board
```

## 8. Connecter le React Renderer

Le renderer ne parle **que** le contrat HTTP — donnez-lui une URL de base et il
découvre l'entité, les projections et les actions. Installez le package npm
public :

```bash
cd web
npm install @ausus/react-renderer react react-dom
```

`web/src/App.tsx` :

```tsx
import { RuntimeClient, RendererApp } from '@ausus/react-renderer';

const client = new RuntimeClient({ baseUrl: 'http://127.0.0.1:8080' });

export default function App() {
  return <RendererApp client={client} entities={['invoice']} />;
}
```

```bash
npm run dev      # ouvrez l'URL affichée, le serveur d'API étant lancé
```

## 9. Le résultat obtenu

Vous obtenez un gestionnaire de factures fonctionnel : une liste de factures
(projection `board`), une vue détaillée (`detail`), des formulaires générés
automatiquement pour `create` / `update` / `pay` / `cancel`, l'isolation par
tenant sur chaque lecture et écriture, et l'autorisation appliquée avant toute
modification — sans rien écrire à la main. Ajouter un champ ou une action à
`entities/Invoice.php` puis recompiler les fait apparaître dans l'API et
l'interface sans autre changement de code.

## 10. Ce que vous venez d'apprendre

- Une application est une **donnée** : une `EntityDefinition` immuable.
- Le **Compiler** la fige en un `EntitySchema` adressé par contenu.
- Le **Runtime** lie ce schéma à un driver et exécute les actions, avec une
  **autorisation pilotée par la donnée et fermée par défaut**, et un
  multi-tenant structurel.
- L'**API Runtime** l'expose en `{ status, body }`, et le **React Renderer** la
  dessine à partir de ce seul contrat.
- Le tout fonctionne **uniquement avec des packages publics** — exactement
  l'expérience d'un développeur externe.

## Limitations

Le driver de référence `ausus/persistence-memory` vit le temps d'un processus.
Sous `php -S`, chaque requête réexécute le routeur à neuf : les écritures ne
persistent donc pas entre les requêtes ; `bin/server.php` amorce quelques factures
au démarrage pour que les lectures affichent toujours des données. Pour un serveur
persistant, liez un `PersistenceDriver` persistant à la place de `MemoryDriver` —
le reste de l'application est inchangé.
