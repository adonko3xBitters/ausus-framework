---
id: persistence
title: 'Partie 3 — Persistance'
sidebar_label: 3. Persistance
description: Exécutez le système de tickets en ligne de commande et laissez AUSUS dériver le schéma SQLite.
---

# Partie 3 — Persistance

**Pourquoi cette étape existe :** un domaine qui ne peut pas stocker de données
n'est qu'une description. Cette partie donne une base de données à l'application
et l'exécute pour la première fois. Vous n'écrirez **pas** de migration ni
d'instruction `CREATE TABLE` — AUSUS dérive le schéma à partir de l'entité que
vous avez déclarée.

## Comment fonctionne la persistance {#how-persistence-works}

Lorsque `Application::boot()` s'exécute, il :

1. compile votre plugin en un **MetadataGraph** ;
2. dérive un schéma SQL à partir de ce graphe — une table par entité, plus une
   table interne `kernel_audit_log` ;
3. applique le schéma à la base de données (`CREATE TABLE IF NOT EXISTS`, son
   exécution répétée est donc sans danger).

En v0.1.x, le pilote de persistance est **SQLite**. Vous indiquez à
l'`Application` un chemin de fichier et AUSUS gère le reste. La table de
`helpdesk.ticket` est nommée `helpdesk_ticket` — les points deviennent des
tirets bas.

## Écrire le lanceur CLI {#write-the-cli-runner}

Créez `tickets.php` à la racine du projet. Ce script amorce l'application, crée
quelques tickets, pilote le workflow et affiche le résultat :

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Ausus\Application;
use Helpdesk\TicketSystem;

// Start from a clean database every run so the script is repeatable.
$dbPath = __DIR__ . '/tickets.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

// Bootstrap: compile the plugin, open the SQLite file, apply the schema.
$app = Application::create([
    'tenant'   => 'helpdesk',
    'roles'    => ['ticket.agent', 'ticket.viewer'],
    'database' => $dbPath,
])->register(new TicketSystem())->boot();

echo "AUSUS Ticket System — CLI run\n";
echo "database: {$dbPath}\n\n";

/** Create one ticket and return its id. */
function newTicket(Application $app, string $title, string $requester, string $priority): string
{
    $result = $app->invoke('helpdesk.ticket.create', null, [
        'title'     => $title,
        'requester' => $requester,
        'priority'  => $priority,
    ]);
    echo "  created {$result['id']}  [{$priority}]  {$title}\n";
    return $result['id'];
}

echo "creating tickets:\n";
$a = newTicket($app, 'Printer on floor 3 is offline', 'dana@example.com', 'HIGH');
$b = newTicket($app, 'VPN drops every hour',          'sam@example.com',  'NORMAL');
$c = newTicket($app, 'Request a second monitor',      'lee@example.com',  'LOW');

// Drive the workflow. invoke() returns the updated fields.
echo "\ndriving the workflow:\n";
$app->invoke('helpdesk.ticket.start',   $app->reference('helpdesk.ticket', $b));
echo "  ticket {$b} → IN_PROGRESS\n";

$app->invoke('helpdesk.ticket.start',   $app->reference('helpdesk.ticket', $c));
$resolved = $app->invoke('helpdesk.ticket.resolve', $app->reference('helpdesk.ticket', $c));
echo "  ticket {$c} → RESOLVED at {$resolved['resolved_at']}\n";

// The workflow guard rejects an illegal transition.
echo "\nworkflow guard:\n";
try {
    $app->invoke('helpdesk.ticket.resolve', $app->reference('helpdesk.ticket', $a));
} catch (\Ausus\WorkflowStateMismatch $e) {
    echo "  rejected resolve on an OPEN ticket — as expected\n";
}

// Render the summary projection — the same data the HTTP API will serve.
$view  = $app->render('helpdesk.ticket.summary');
$count = count($view['data']['items']);
echo "\nsummary projection lists {$count} tickets.\n";
echo "done.\n";
```

## Exécuter {#run-it}

```bash
php tickets.php
```

Sortie attendue :

```
AUSUS Ticket System — CLI run
database: /…/ticket-system/tickets.sqlite

creating tickets:
  created 01J… [HIGH]   Printer on floor 3 is offline
  created 01J… [NORMAL] VPN drops every hour
  created 01J… [LOW]    Request a second monitor

driving the workflow:
  ticket 01J… → IN_PROGRESS
  ticket 01J… → RESOLVED at 2026-05-21T…Z

workflow guard:
  rejected resolve on an OPEN ticket — as expected

summary projection lists 3 tickets.
done.
```

Les valeurs `01J…` sont des [ULID](https://github.com/ulid/spec) — l'identité
qu'AUSUS génère pour chaque enregistrement. Les vôtres seront différentes.

:::tip Pourquoi le rejet du garde est un succès
`resolve` est déclaré `from: 'IN_PROGRESS'`. Le ticket `a` est encore `OPEN`, il
n'y a donc aucune transition correspondante et le runtime lève
`WorkflowStateMismatch` **avant** de toucher la base de données. Ce rejet, c'est
le workflow qui fait son travail.
:::

## Regarder à l'intérieur de la base de données {#look-inside-the-database}

AUSUS a créé un véritable fichier SQLite. Si vous disposez de la CLI `sqlite3`,
inspectez-le :

```bash
sqlite3 tickets.sqlite '.tables'
```

```
helpdesk_ticket  kernel_audit_log
```

```bash
sqlite3 tickets.sqlite 'SELECT id, title, priority, status FROM helpdesk_ticket;'
```

```
01J…|Printer on floor 3 is offline|HIGH|OPEN
01J…|VPN drops every hour|NORMAL|IN_PROGRESS
01J…|Request a second monitor|LOW|RESOLVED
```

Deux choses méritent d'être remarquées, et **pourquoi** :

- **`helpdesk_ticket`** — vous n'avez jamais écrit cette table. `SchemaDeriver`
  l'a construite à partir de l'entité, y compris les colonnes système `id`,
  `tenant_id`, `_version`, `created_at`, `updated_at`.
- **`kernel_audit_log`** — chaque action que vous avez invoquée y a ajouté une
  entrée. L'audit est automatique ; le runtime l'écrit dans la même transaction
  que la modification.

![Sortie du terminal lors de `php tickets.php` — trois tickets créés, la garde de workflow refusant un `resolve` illégal et la projection de synthèse listant trois tickets.](/img/tutorial/cli-run.svg)

## Ce que vous avez maintenant {#what-you-have-now}

```
ticket-system/
├── composer.json
├── src/TicketSystem.php
├── tickets.php          ← CLI runner
├── tickets.sqlite       ← created at runtime, holds 3 tickets
└── vendor/
```

L'application fonctionne de bout en bout en ligne de commande. Ensuite, vous la
placez derrière HTTP afin que d'autres programmes — y compris un navigateur —
puissent l'atteindre.

**Suivant : [Partie 4 — API HTTP](http-api.md).**
