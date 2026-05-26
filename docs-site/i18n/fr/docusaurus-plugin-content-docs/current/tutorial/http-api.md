---
id: http-api
title: 'Partie 4 — API HTTP'
sidebar_label: 4. API HTTP
description: Exposez le système de tickets via HTTP et testez-le avec curl.
---

# Partie 4 — API HTTP

**Pourquoi cette étape existe :** le lanceur CLI a prouvé que le domaine
fonctionne, mais une véritable application est atteinte par le réseau. Cette
partie place le système de tickets derrière une API HTTP — sans écrire la
moindre route ni le moindre contrôleur.

## Comment fonctionne la couche HTTP {#how-the-http-layer-works}

`ausus/api-http` fournit un `Router` — un seul gestionnaire PSR-15 qui mappe
trois routes HTTP sur le runtime que vous avez déjà :

| Méthode et chemin | Ce qu'il fait |
|---|---|
| `GET /api/_health` | Sonde de vivacité ; renvoie le hash du graphe. |
| `GET /api/projections/{fqn}` | Rend une projection en JSON ViewSchema. |
| `POST /api/actions/{fqn}` | Invoque une action. |

Le Router n'invente aucun comportement — il analyse la requête, appelle le même
`Invoker` que la CLI a utilisé et sérialise le résultat. **Pourquoi c'est
important :** l'API HTTP et la CLI ne peuvent pas diverger, car elles exécutent
un code identique.

## Ajouter la dépendance du serveur HTTP {#add-the-http-server-dependency}

Le Router parle PSR-7, il lui faut donc une implémentation de message HTTP.
Installez [nyholm/psr7](https://github.com/Nyholm/psr7) — léger et sans
dépendances :

```bash
composer require nyholm/psr7 nyholm/psr7-server
```

## Écrire le contrôleur frontal {#write-the-front-controller}

Créez `server.php` à la racine du projet. Il amorce l'application exactement
comme `tickets.php` le faisait, puis transmet le graphe et le pilote au
`Router` :

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Ausus\Application;
use Ausus\Persistence\Sql\DatabaseAuditSink;
use Ausus\Api\Http\{Router, Emitter};
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Helpdesk\TicketSystem;

// Open the same SQLite file the CLI runner created.
$pdo = new PDO('sqlite:' . __DIR__ . '/tickets.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// The audit sink is shared: the runtime writes through it, and the Router
// is handed the same instance.
$sink = new DatabaseAuditSink($pdo);

$app = Application::create([
    'tenant'    => 'helpdesk',
    'roles'     => ['ticket.agent', 'ticket.viewer'],
    'database'  => $pdo,
    'auditSink' => $sink,
])->register(new TicketSystem())->boot();

// PSR-7 plumbing — nyholm provides the HTTP message factories.
$factory = new Psr17Factory();
$creator = new ServerRequestCreator($factory, $factory, $factory, $factory);

// The Router maps HTTP requests onto runtime invocations under the /api prefix.
$router = new Router($app->graph(), $app->driver(), $sink, $factory, $factory, '/api');

// Handle the current request and emit the response.
Emitter::emit($router->handle($creator->fromGlobals()));
```

## Lancer le serveur {#run-the-server}

Assurez-vous d'abord que la base de données contient des données — exécutez le
lanceur CLI de la partie 3 si ce n'est pas déjà fait :

```bash
php tickets.php
```

Démarrez ensuite le serveur web intégré de PHP avec `server.php` comme
contrôleur frontal :

```bash
php -S localhost:8080 server.php
```

Laissez-le tourner. Ouvrez un **second terminal** pour les requêtes
ci-dessous.

## Exercer l'API {#exercise-the-api}

### Contrôle de santé {#health-check}

```bash
curl -s http://localhost:8080/api/_health
```

```json
{"ok":true,"service":"ausus/api-http","graphHash":"7c1e9b3a…"}
```

### Lister les tickets {#list-tickets}

Les routes de projection et d'action exigent un en-tête **`X-Tenant-ID`** — il
indique à AUSUS sur les données de quel tenant agir. Votre tenant est
`helpdesk` :

```bash
curl -s -H 'X-Tenant-ID: helpdesk' \
  http://localhost:8080/api/projections/helpdesk.ticket.summary
```

La réponse est un **ViewSchema** — un document JSON décrivant les champs, les
actions disponibles et les données :

```json
{
  "schemaVersion": "1.0.0",
  "targetProfile": "react.web.v1",
  "fields": [
    {"name": "id",       "type": "identity", "label": "Id"},
    {"name": "title",    "type": "string",   "label": "Title"},
    {"name": "requester","type": "string",   "label": "Requester"},
    {"name": "priority", "type": "enum",     "label": "Priority"},
    {"name": "status",   "type": "enum",     "label": "Status"}
  ],
  "actions": [ ... ],
  "data": {
    "items": [
      {"id": "01J…", "title": "Printer on floor 3 is offline", "priority": "HIGH", "status": "OPEN"},
      ...
    ]
  }
}
```

Le moteur de rendu React de la partie suivante consomme ce document
directement.

### Créer un ticket via HTTP {#create-a-ticket-over-http}

Les routes d'action sont des requêtes `POST`. Le corps comporte deux clés :

- **`subject`** — l'enregistrement ciblé par l'action, sous forme de référence.
  Pour une action de *création*, il n'y a aucun enregistrement existant, c'est
  donc `null`.
- **`inputs`** — les valeurs des champs.

Les actions sont également soumises à un contrôle de politique, vous devez donc
indiquer quels rôles détient l'appelant avec **`X-Actor-Roles`**. L'action
`create` exige `ticket.agent` :

```bash
curl -s -X POST http://localhost:8080/api/actions/helpdesk.ticket.create \
  -H 'X-Tenant-ID: helpdesk' \
  -H 'X-Actor-Roles: ticket.agent' \
  -H 'Content-Type: application/json' \
  -d '{
        "subject": null,
        "inputs": {
          "title": "Laptop will not boot",
          "requester": "max@example.com",
          "priority": "HIGH"
        }
      }'
```

```json
{"ok":true,"outputs":{"id":"01J…","title":"Laptop will not boot","status":"OPEN","priority":"HIGH",...}}
```

### Piloter une transition {#drive-a-transition}

Une action de transition cible un ticket existant, `subject` est donc une
référence. Copiez un `id` de la réponse de liste et lancez-le avec `start` :

```bash
curl -s -X POST http://localhost:8080/api/actions/helpdesk.ticket.start \
  -H 'X-Tenant-ID: helpdesk' \
  -H 'X-Actor-Roles: ticket.agent' \
  -H 'Content-Type: application/json' \
  -d '{
        "subject": {
          "tenantId": "helpdesk",
          "entityFqn": "helpdesk.ticket",
          "identityHandle": "PASTE_AN_OPEN_TICKET_ID"
        },
        "inputs": {}
      }'
```

```json
{"ok":true,"outputs":{"status":"IN_PROGRESS","_version":"01J…"}}
```

Si vous lancez `start` sur un ticket qui n'est pas `OPEN`, l'API répond
`409 Conflict` avec `{"ok":false,"error":{"kind":"WorkflowStateMismatch",...}}`
— le même garde que vous avez vu en CLI, désormais via HTTP.

:::warning Les rôles par défaut ne sont pas les vôtres
Si vous omettez `X-Actor-Roles`, le Router v0.1.0 retombe sur un jeu de rôles de
démonstration intégré qui n'inclut **pas** `ticket.agent` — chaque action de
ticket serait refusée avec `403 PolicyDenied`. Envoyez toujours `X-Actor-Roles`
pour ce tutoriel. Il n'y a aucune couche d'authentification en v0.1.0 ; un
déploiement en production place un véritable middleware d'authentification
devant le Router.
:::

![Session de terminal montrant des appels curl vers /_health, /projections/helpdesk.ticket.summary et /actions/helpdesk.ticket.create — ce dernier renvoyant ok:true avec un ULID généré.](/img/tutorial/curl-session.svg)

## Ce que vous avez maintenant {#what-you-have-now}

Le système de tickets répond aux requêtes HTTP : il liste les tickets, les crée
et pilote le workflow — le tout sur le réseau. La partie suivante place une
véritable interface utilisateur par-dessus.

**Suivant : [Partie 5 — Interface React](react-ui.md).**
