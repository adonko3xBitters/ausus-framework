---
id: persistence
title: 'Part 3 — Persistence'
sidebar_label: 3. Persistence
description: Run the Ticket System on the command line and let AUSUS derive the SQLite schema.
---

# Part 3 — Persistence

**Why this step exists:** a domain that cannot store data is just a
description. This part gives the application a database and runs it for the
first time. You will **not** write a migration or a `CREATE TABLE` statement —
AUSUS derives the schema from the entity you declared.

## How persistence works {#how-persistence-works}

When `Application::boot()` runs, it:

1. compiles your plugin into a **MetadataGraph**;
2. derives a SQL schema from that graph — one table per entity, plus an
   internal `kernel_audit_log` table;
3. applies the schema to the database (`CREATE TABLE IF NOT EXISTS`, so it is
   safe to run repeatedly).

In v0.1.x the persistence driver is **SQLite**. You point the `Application` at
a file path and AUSUS manages the rest. The table for `helpdesk.ticket` is
named `helpdesk_ticket` — dots become underscores.

## Write the CLI runner {#write-the-cli-runner}

Create `tickets.php` in the project root. This script bootstraps the
application, creates a few tickets, drives the workflow, and prints the result:

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

## Run it {#run-it}

```bash
php tickets.php
```

Expected output:

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

The `01J…` values are [ULIDs](https://github.com/ulid/spec) — the identity
AUSUS generates for every record. Yours will differ.

:::tip Why the guard rejection is a success
`resolve` is declared `from: 'IN_PROGRESS'`. Ticket `a` is still `OPEN`, so
there is no matching transition and the runtime throws `WorkflowStateMismatch`
**before** touching the database. That rejection is the workflow doing its job.
:::

## Look inside the database {#look-inside-the-database}

AUSUS created a real SQLite file. If you have the `sqlite3` CLI, inspect it:

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

Two things worth noticing, and **why**:

- **`helpdesk_ticket`** — you never wrote this table. `SchemaDeriver` built it
  from the entity, including the system columns `id`, `tenant_id`, `_version`,
  `created_at`, `updated_at`.
- **`kernel_audit_log`** — every action you invoked appended an entry here.
  Auditing is automatic; the runtime writes it inside the same transaction as
  the change.

![Terminal output of running php tickets.php — three tickets created, the workflow guard rejecting an illegal resolve, and the summary projection listing three tickets.](/img/tutorial/cli-run.svg)

## What you have now {#what-you-have-now}

```
ticket-system/
├── composer.json
├── src/TicketSystem.php
├── tickets.php          ← CLI runner
├── tickets.sqlite       ← created at runtime, holds 3 tickets
└── vendor/
```

The application works end to end on the command line. Next, you put it behind
HTTP so other programs — including a browser — can reach it.

**Next: [Part 4 — HTTP API](http-api.md).**
