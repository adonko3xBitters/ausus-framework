---
id: domain
title: 'Part 2 — The Domain'
sidebar_label: 2. The domain
description: Bootstrap the Application and declare the ticket entity, its fields, actions and workflow.
---

# Part 2 — The Domain

**Why this step exists:** in AUSUS you do not write models, controllers or
migrations. You write a **plugin** — a single class that *describes* your
domain. The framework compiles that description and runs it. This part writes
the entire Ticket System domain in one file.

This part covers five things at once, because they all live in the same file:
bootstrapping the `Application`, creating the entity, adding fields, adding
actions, and declaring the workflow.

## 2.1 The Application and the plugin {#21-the-application-and-the-plugin}

`Ausus\Application` is the object that runs your domain. Its lifecycle is four
calls:

```php
$app = Application::create([...])   // configure tenant, actor, database
    ->register(new TicketSystem())  // hand it your plugin(s)
    ->boot();                       // compile + wire the runtime
```

`register()` takes a **plugin**. A plugin is any class extending
`Ausus\DslPlugin` that implements three methods: `name()`, `phpNamespace()`
and `dsl()`. The `dsl()` method is where the domain is described.

Create the file `src/TicketSystem.php` with the empty skeleton:

```php
<?php
declare(strict_types=1);

namespace Helpdesk;

use Ausus\{DslPlugin, Dsl, Field, Action};

final class TicketSystem extends DslPlugin
{
    /** The plugin name — becomes the first segment of every FQN. */
    public function name(): string { return 'helpdesk'; }

    /** The PHP namespace this plugin's code lives in. */
    public function phpNamespace(): string { return 'Helpdesk'; }

    /** Describe the domain. AUSUS calls this when the Application boots. */
    public function dsl(Dsl $dsl): void
    {
        // entity, fields, actions and workflow go here
    }
}
```

**Why `name()` matters:** it is the first segment of every *fully-qualified
name* (FQN). With `name()` returning `helpdesk`, the entity you create next is
`helpdesk.ticket` and its create action is `helpdesk.ticket.create`. FQNs are
how every layer — runtime, HTTP, renderer — refers to your domain.

The rest of this part fills in the body of `dsl()`.

## 2.2 Create the entity {#22-create-the-entity}

An **entity** is a kind of record your application stores. The Ticket System
has exactly one: a ticket.

```php
$dsl->entity('ticket')
    // ->fields([...])  — next
;
```

`entity('ticket')` declares an entity with the FQN `helpdesk.ticket`. It
returns an `EntityBuilder` that you keep chaining calls onto. **Why a builder:**
fields, actions and the workflow are all declared by chaining off this one
call, so the whole entity reads as a single expression.

## 2.3 Add fields {#23-add-fields}

**Fields** are the columns of the entity. You declare each one with the
`Field` builder. The Ticket System needs five:

```php
->fields([
    'title'     => Field::string()->max(200),
    'requester'   => Field::string()->max(120),
    'priority'    => Field::enum('LOW', 'NORMAL', 'HIGH')->default('NORMAL'),
    'status'      => Field::enum('OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED')->default('OPEN'),
    'resolved_at' => Field::datetime()->nullable(),
])
```

Each line, and **why**:

| Field | Builder | Why |
|---|---|---|
| `title` | `Field::string()->max(200)` | The ticket title. `max()` caps the length. |
| `requester` | `Field::string()->max(120)` | Who reported it. |
| `priority` | `Field::enum(...)->default('NORMAL')` | A fixed choice. `default()` is used when no value is supplied. |
| `status` | `Field::enum(...)->default('OPEN')` | The lifecycle state — the workflow will be attached to this field. |
| `resolved_at` | `Field::datetime()->nullable()` | Set only when the ticket is resolved, so it must be `nullable()`. |

**Why you do not declare an `id`, timestamps, or a tenant column:** AUSUS adds
five **system fields** to every entity automatically — `id`, `tenant_id`,
`_version`, `created_at`, `updated_at`. You never manage them by hand.

The available field types in v0.1.x are `string`, `integer`, `datetime`,
`money` and `enum`. Builder methods include `->max()`, `->nullable()`,
`->default()`, `->unique()` and `->currency()` (for money).

## 2.4 Add actions {#24-add-actions}

Records do not change by themselves. Every change goes through an **action**.
There are two kinds, and the Ticket System uses both.

A **create action** brings a new record into existence:

```php
'create' => Action::create('title', 'requester', 'priority')
                ->requireRole('ticket.agent'),
```

The arguments to `Action::create()` are the field names the caller must
supply. `status` is not listed — the workflow seeds it. `resolved_at` is not
listed — it is empty until the ticket is resolved.

A **transition action** moves a record from one state to another:

```php
'start'   => Action::transition('status', from: 'OPEN', to: 'IN_PROGRESS')
                ->requireRole('ticket.agent'),
'resolve' => Action::transition('status', from: 'IN_PROGRESS', to: 'RESOLVED')
                ->stamp('resolved_at')
                ->requireRole('ticket.agent'),
'close'   => Action::transition('status', from: 'RESOLVED', to: 'CLOSED')
                ->requireRole('ticket.agent'),
```

`transition('status', from:, to:)` says "this action changes `status` from one
value to another." Notes, with **why**:

- **`->stamp('resolved_at')`** on `resolve` writes the current timestamp into
  `resolved_at` as part of the transition. That is why the field exists.
- **`->requireRole('ticket.agent')`** attaches a policy: only an actor holding
  the `ticket.agent` role may invoke the action. Without a matching role the
  runtime rejects the call before any work happens.

Put together, the `->actions([...])` block is:

```php
->actions([
    'create'  => Action::create('title', 'requester', 'priority')
                    ->requireRole('ticket.agent'),
    'start'   => Action::transition('status', from: 'OPEN', to: 'IN_PROGRESS')
                    ->requireRole('ticket.agent'),
    'resolve' => Action::transition('status', from: 'IN_PROGRESS', to: 'RESOLVED')
                    ->stamp('resolved_at')
                    ->requireRole('ticket.agent'),
    'close'   => Action::transition('status', from: 'RESOLVED', to: 'CLOSED')
                    ->requireRole('ticket.agent'),
])
```

## 2.5 Declare the workflow {#25-declare-the-workflow}

The transition actions describe individual moves. The **workflow** ties them
into one state machine and tells AUSUS where a new ticket starts:

![Ticket workflow state machine: a new ticket starts in OPEN; start moves it to IN_PROGRESS; resolve moves it to RESOLVED and stamps resolved_at; close moves it to CLOSED.](/img/diagrams/ticket-workflow.svg)

The runtime enforces this diagram: calling `start` on a `RESOLVED` ticket, or
`resolve` on an `OPEN` ticket, raises `WorkflowStateMismatch` before any data
is written.

```php
->workflow(field: 'status', initial: 'OPEN')
```

- **`field: 'status'`** — the enum field that holds the state. Its options
  (`OPEN`, `IN_PROGRESS`, `RESOLVED`, `CLOSED`) become the workflow's states.
- **`initial: 'OPEN'`** — the state a freshly created ticket starts in.

**Why declare it explicitly:** the workflow is what makes the runtime *guard*
transitions. With the workflow in place, calling `resolve` on an `OPEN` ticket
fails with a `WorkflowStateMismatch` — there is no `OPEN → RESOLVED`
transition. Always pass `initial` explicitly; omitting it triggers a
deprecation notice.

Finally, add two **projections** — named, read-shaped views of the entity that
the HTTP API and the React UI consume:

```php
->projection('summary',
    fields:  ['id', 'title', 'requester', 'priority', 'status'],
    actions: ['create', 'start', 'resolve', 'close'],
    role:    'ticket.viewer')
->projection('detail',
    fields:  ['id', 'title', 'requester', 'priority', 'status', 'resolved_at', 'created_at', 'updated_at'],
    actions: ['start', 'resolve', 'close'],
    role:    'ticket.viewer');
```

`summary` is a list view; `detail` is a single-record view. The `fields` list
chooses which columns appear — including system fields like `id` and
`created_at`.

## The complete plugin {#the-complete-plugin}

Here is `src/TicketSystem.php` in full. This is the entire domain — every
later part of the tutorial just runs it:

```php
<?php
declare(strict_types=1);

namespace Helpdesk;

use Ausus\{DslPlugin, Dsl, Field, Action};

/**
 * TicketSystem — the AUSUS tutorial domain.
 *
 * One entity (helpdesk.ticket) with a four-state lifecycle:
 *   OPEN → IN_PROGRESS → RESOLVED → CLOSED
 */
final class TicketSystem extends DslPlugin
{
    public function name(): string         { return 'helpdesk'; }
    public function phpNamespace(): string { return 'Helpdesk'; }

    public function dsl(Dsl $dsl): void
    {
        $dsl->entity('ticket')
            ->fields([
                'title'     => Field::string()->max(200),
                'requester'   => Field::string()->max(120),
                'priority'    => Field::enum('LOW', 'NORMAL', 'HIGH')->default('NORMAL'),
                'status'      => Field::enum('OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED')->default('OPEN'),
                'resolved_at' => Field::datetime()->nullable(),
            ])
            ->actions([
                'create'  => Action::create('title', 'requester', 'priority')
                                ->requireRole('ticket.agent'),
                'start'   => Action::transition('status', from: 'OPEN', to: 'IN_PROGRESS')
                                ->requireRole('ticket.agent'),
                'resolve' => Action::transition('status', from: 'IN_PROGRESS', to: 'RESOLVED')
                                ->stamp('resolved_at')
                                ->requireRole('ticket.agent'),
                'close'   => Action::transition('status', from: 'RESOLVED', to: 'CLOSED')
                                ->requireRole('ticket.agent'),
            ])
            ->workflow(field: 'status', initial: 'OPEN')
            ->projection('summary',
                fields:  ['id', 'title', 'requester', 'priority', 'status'],
                actions: ['create', 'start', 'resolve', 'close'],
                role:    'ticket.viewer')
            ->projection('detail',
                fields:  ['id', 'title', 'requester', 'priority', 'status', 'resolved_at', 'created_at', 'updated_at'],
                actions: ['start', 'resolve', 'close'],
                role:    'ticket.viewer');
    }
}
```

## Verify it compiles {#verify-it-compiles}

Before persisting anything, confirm the plugin compiles cleanly. Create
`compile-check.php` in the project root:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Ausus\Application;
use Helpdesk\TicketSystem;

// boot() compiles the plugin into a MetadataGraph and wires the runtime.
$app = Application::create([
    'tenant' => 'helpdesk',
    'roles'  => ['ticket.agent', 'ticket.viewer'],
])->register(new TicketSystem())->boot();

$graph = $app->graph();
echo 'graph hash : ', substr($graph->hash, 0, 16), "…\n";
echo 'entities   : ', count($graph->entities), "\n";
echo 'actions    : ', count($graph->actions), "\n";
echo 'workflows  : ', count($graph->workflows), "\n";
echo "plugin compiles cleanly.\n";
```

:::tip Prefer the typed config builder
The configuration above can also be written with the fluent
[`ApplicationConfig`](../getting-started/first-app.md#typed-config-builder)
builder — typed, IDE-discoverable, and equivalent end-to-end:

```php
use Ausus\{Application, ApplicationConfig};

$app = Application::create(
        ApplicationConfig::make()
            ->tenant('helpdesk')
            ->roles(['ticket.agent', 'ticket.viewer'])
    )
    ->register(new TicketSystem())
    ->boot();
```
:::

Run it:

```bash
php compile-check.php
```

Expected output:

```
graph hash : 7c1e9b3a0f4d2e88…
entities   : 1
actions    : 4
workflows  : 1
plugin compiles cleanly.
```

The exact hash will differ; the **counts** must match. If you see a
`RuntimeException` instead, read its message — the DSL validates your
declaration and names the problem (a typo'd field, a bad workflow state, …).
You can delete `compile-check.php` once it passes.

## What you have now {#what-you-have-now}

```
ticket-system/
├── composer.json
├── src/
│   └── TicketSystem.php   ← the whole domain
└── vendor/
```

The domain exists, but no data does. In the next part you give the application
a database.

**Next: [Part 3 — Persistence](persistence.md).**
