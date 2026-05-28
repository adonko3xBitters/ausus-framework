---
id: troubleshooting
title: 'Part 6 — Troubleshooting & Recap'
sidebar_label: 6. Troubleshooting & recap
description: Common mistakes, debugging tips, and a final architecture recap for the Ticket System.
---

# Part 6 — Troubleshooting & Recap

**Why this step exists:** building the app is one thing; understanding what
breaks and why is what makes you productive. This part collects the errors you
are most likely to hit, how to debug them, and a recap of the whole system.

## Common mistakes {#common-mistakes}

AUSUS exceptions are **named precisely** — the class name is the diagnosis.
Here are the ones a beginner meets most often.

### `FieldRequired` {#fieldrequired}

```
FieldRequired: helpdesk.ticket.title not in payload and no default
```

A `create` was invoked without a value for a non-nullable field that has no
default. **Fix:** include every required field in `inputs`, or give the field
a `->default(...)` or `->nullable()`. The React renderer's create modal
validates required fields on the client and blocks submission before the
server sees the request — see
[Create a ticket from the UI](react-ui.md#create-a-ticket-from-the-ui).

### `PolicyDenied` {#policydenied}

```
PolicyDenied: helpdesk.ticket.start
```

The actor does not hold the role the action requires (`ticket.agent`).
**Fix on the CLI:** pass the role in `Application::create(['roles' => [...]])`.
**Fix over HTTP:** send the `X-Actor-Roles` header. Omitting it yields a
roleless actor — every protected action returns 403 with the role the
action actually requires (no demo fallback).

### `WorkflowStateMismatch` {#workflowstatemismatch}

```
WorkflowStateMismatch: current state 'OPEN' does not match any declared source
```

You invoked a transition that is not legal from the record's current state —
for example `resolve` on an `OPEN` ticket. **This is the workflow guard
working.** Fix the call order, or add the transition you intended.

### `BadRequest: missing required X-Tenant-ID header` {#missing-tenant}

A `projections/*` or `actions/*` request arrived with no `X-Tenant-ID`.
**Fix:** send `-H 'X-Tenant-ID: helpdesk'`. The React renderer sends it for you
from the `AususProvider tenant` prop.

### `TenantBoundaryViolation` {#tenantboundaryviolation}

The `subject` reference names a different tenant than the one the request runs
in. **Fix:** the `tenantId` in a subject reference must match `X-Tenant-ID`
(`helpdesk` throughout this tutorial).

### `AmbiguousWorkflowField` {#ambiguousworkflowfield}

```
AmbiguousWorkflowField: entity 'helpdesk.ticket' has multiple enum fields
with a default (priority, status)
```

The entity has two `enum` fields with defaults and **no `->workflow()` call**,
so AUSUS cannot guess which one is the lifecycle. **Fix:** declare the workflow
explicitly — `->workflow(field: 'status', initial: 'OPEN')` — which this
tutorial does. (If you removed that line, this is the error you would see.)

### `ProjectionNotFound` / `ActionNotFound` {#fqn-typos}

A `404` with one of these kinds means the FQN in the URL is wrong. FQNs are
case-sensitive and fully qualified: it is `helpdesk.ticket.summary`, not
`ticket.summary` or `summary`.

### Class not found {#class-not-found}

```
Error: Class "Helpdesk\TicketSystem" not found
```

Composer's autoloader has not indexed the class. **Fix:** confirm the file is
`src/TicketSystem.php`, the namespace is `Helpdesk`, the PSR-4 rule is in
`composer.json`, then run `composer dump-autoload`.

### SQLite cannot open the database {#sqlite-open}

`unable to open database file` means the **directory** in the `database` path
does not exist — SQLite creates files, not folders. **Fix:** use a path whose
directory exists (this tutorial keeps `tickets.sqlite` in the project root).

## Debugging tips {#debugging-tips}

A short checklist for when something is wrong:

1. **Read the exception class, not just the message.** `WorkflowStateMismatch`,
   `PolicyDenied`, `ConcurrencyConflict` each point at a specific layer.
2. **Isolate with the CLI.** If the browser misbehaves, reproduce it in a small
   PHP script with `$app->invoke(...)`. That removes HTTP and React from the
   picture and tells you whether the bug is in the domain.
3. **Inspect the ViewSchema directly.** Before blaming the renderer, run
   `curl -H 'X-Tenant-ID: helpdesk' …/projections/helpdesk.ticket.summary` and
   read the JSON. If the data is wrong there, the problem is server-side.
4. **Check the graph hash.** `GET /api/_health` returns `graphHash`. If it does
   not change after you edit the plugin, the server is running stale code —
   restart `php -S`.
5. **Look in the database.** `sqlite3 tickets.sqlite 'SELECT * FROM helpdesk_ticket;'`
   shows the real rows. `SELECT action_fqn, timestamp FROM kernel_audit_log;`
   shows every action that ran — the audit trail is your event history.
6. **Watch for deprecation notices.** A line containing `AUSUS deprecation:` on
   `boot()` means you are relying on a legacy fallback (for example an implicit
   workflow). It still works, but fix it before it becomes an error.
7. **Read the server terminal.** `php -S` prints PHP errors and stack traces in
   the terminal where it runs — keep that window visible.

## Architecture recap {#architecture-recap}

You wrote **one** file of domain code — `src/TicketSystem.php` — and got a
persisted, HTTP-exposed, browser-rendered application. Here is why that worked.

### The layers {#the-layers}

```
  TicketSystem plugin        ← you wrote this
        │  compiled by the Compiler
        ▼
  MetadataGraph              ← entities, fields, actions, workflows, projections
        │  consumed by
        ▼
  Runtime / Invoker          ← preflight → policy → workflow guard → effect → audit
        │  reads & writes
        ▼
  SQLite persistence         ← schema derived from the graph; + kernel_audit_log
```

And the outward-facing half:

```
  HTTP Router (ausus/api-http)   ← maps /projections + /actions onto the runtime
        │  emits
        ▼
  ViewSchema JSON                ← fields + actions + data
        │  consumed by
        ▼
  @ausus/renderer-react          ← draws lists, detail views, action dialogs
```

### What happens on one action {#what-happens-on-one-action}

When you clicked **Start** in the browser, this is the full path:

1. The renderer `POST`ed `/api/actions/helpdesk.ticket.start` with the ticket
   reference and the tenant/role headers.
2. The `Router` parsed it and called the `Invoker`.
3. The `Invoker` ran its chain inside one transaction: **preflight** (the
   action exists, the subject is in-tenant) → **policy** (the actor holds
   `ticket.agent`) → **workflow guard** (the ticket is `OPEN`, so `OPEN →
   IN_PROGRESS` is legal) → **effect** (write `status = IN_PROGRESS`) →
   **audit** (append to `kernel_audit_log`).
4. The response went back as JSON; the renderer refetched the projection and
   redrew the row.

Every guarantee — tenancy, authorization, the legal-transition check, the audit
entry — came from the metadata you declared, not from code you wrote per route.

### What you built {#what-you-built}

- A domain plugin: 1 entity, 5 fields, 4 actions, 1 workflow, 2 projections.
- A SQLite-backed application you ran on the CLI.
- An HTTP API with health, projection and action routes.
- A React UI that lists tickets and drives their lifecycle.

## Remaining documentation gaps {#remaining-documentation-gaps}

Honest notes on what this tutorial **could not** show, because the capability
is not in v0.1.x:

- **Authentication.** v0.1.0 has no auth layer; roles are passed as headers /
  config. A real deployment needs an auth middleware in front of the `Router`.
- **Databases other than SQLite.** Persistence is validated on SQLite only.
- **List filtering and pagination.** Projections return all rows for the
  tenant; `pagination.nextCursor` is always `null`.
- **Per-transition guard policies.** Authorization is per action; a guard
  policy attached to an individual transition is designed but not run in v0.1.0.
- **Multiple entities and cross-entity references.** This tutorial uses a
  single entity; relations between entities are not covered.

## `create-project` installs the monorepo instead of the starter {#monorepo-installed-as-starter}

### Symptoms

After running:

```bash
composer create-project ausus/starter myapp
```

the generated directory contains unexpected folders such as:

- `packages/`
- `apps/`
- `docs-site/`
- `renderer/`
- `scripts/`

and Composer output contains lines similar to:

```text
Symlinking from packages/kernel
```

### Cause

Composer defaults to `minimum-stability=stable`. Without explicitly requesting
the alpha channel, Composer resolves the latest stable `0.1.x` release.

### Resolution

Use the documented alpha installation command:

```bash
composer create-project "ausus/starter:^0.2@alpha" myapp --stability=alpha
```

## `composer boot` returns `Command "boot" is not defined` {#composer-boot-not-defined}

### Cause

This usually means the project was scaffolded from the legacy stable starter
instead of the v0.2 alpha channel.

### Resolution

Delete the generated directory and reinstall using:

```bash
composer create-project "ausus/starter:^0.2@alpha" myapp --stability=alpha
```

## Alpha resolution failure {#alpha-resolution-failure}

**Symptom:**

```
Your requirements could not be resolved to an installable set of packages.

  Problem 1
    - Root composer.json requires ausus/standard-stack ^0.2@alpha
    - ausus/standard-stack v0.2.0-alpha.X requires ausus/api-http ^0.2@alpha
      → does not match your minimum-stability.
```

**Cause.** Your root `composer.json` has `minimum-stability: stable`
(Composer default). When `ausus/standard-stack@^0.2@alpha` declares a
transitive `ausus/api-http ^0.2@alpha`, that inner alpha constraint is
evaluated against YOUR root's `minimum-stability`. Stable rejects it.

The `@alpha` flag on a single root `require` does **not** propagate to
transitive dependencies — this is documented Composer behaviour, not
AUSUS-specific.

**Fix.** Declare alpha stability at the root of your `composer.json`:

```json
{
    "minimum-stability": "alpha",
    "prefer-stable": true
}
```

Or via CLI:

```bash
composer config minimum-stability alpha
composer config prefer-stable true
composer update
```

Starting at `v0.2.0-alpha.4`, `composer create-project ausus/starter`
already sets this for you — you only hit this error if you set up the
project manually.

See [Alpha installation requirements](../getting-started/installation.md#alpha-installation-requirements)
for the full explanation.

## Packagist propagation delay {#packagist-propagation-delay}

**Symptom.** A new `ausus/*` version was just tagged on GitHub but
`composer require ausus/standard-stack:^0.2@alpha` still pulls the
previous version.

**Cause.** GitHub → Packagist webhook propagation typically takes
**1–3 minutes** (occasionally up to 15 min on slow days). Composer's
local cache may also serve a stale package list.

**Fix.**

```bash
composer clear-cache
composer require ausus/standard-stack:^0.2@alpha --no-cache
```

If after 15 min the new version still doesn't appear, click the manual
**Update** button on the package's Packagist page
(`https://packagist.org/packages/ausus/<name>`).

## npm dist-tag confusion {#npm-dist-tag}

**Symptom.** `npm install @ausus/renderer-react` returns a version that
doesn't match what's documented as the current alpha.

**Cause.** npm dist-tags. AUSUS publishes pre-releases to `@next` and
also promotes `@latest` to the current pre-release as long as no stable
v1+ exists.

**Fix.** Be explicit:

```bash
# Default (gets @latest = current alpha until a stable v1+ exists)
npm install @ausus/renderer-react

# Always-current pre-release stream
npm install @ausus/renderer-react@next

# Specific version
npm install @ausus/renderer-react@0.2.0-alpha.4
```

See [The React renderer — npm dist-tag policy](../frontend/react-renderer.md#peer-schema-version).

## Where to go next {#where-to-go-next}

- [Core Concepts](../concepts/metadata-graph.md) — the model behind everything
  you just used.
- [The PHP DSL](../backend/php-dsl.md) — every builder method.
- [Workflows](../concepts/workflows.md) — transitions, guards, and the explicit
  workflow API in depth.
- [The HTTP API](../backend/http-api.md) — the full route and error reference.

You have built a complete AUSUS application from zero. Everything larger is the
same four moves: **describe the domain, boot the Application, expose it, render
it.**
