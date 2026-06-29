# Hello Invoice — AUSUS 2.0 official example

A small invoice manager that demonstrates the **entire AUSUS 2.0 pipeline** —
Authoring → Compiler → immutable graph → Runtime → HTTP API → React Renderer —
using **only public packages**. No monorepo, no path repositories, no internal
code: this is exactly what you download from GitHub.

> Full walkthrough: **[Hello Invoice tutorial](https://ausus-framework.pages.dev/gen2/tutorials/hello-invoice)** (EN / FR).

## Requirements

PHP 8.3+ ; Node 18+ (for the React UI).

## Run it

```bash
# 1. install the public Composer packages (from Packagist)
composer install

# 2. the whole pipeline in one script: compile → runtime → API
php bin/demo.php

# 3. serve the HTTP API (seeded with two invoices)
php -S 127.0.0.1:8080 bin/server.php
#    → http://127.0.0.1:8080/api/entities/invoice/projections/board

# 4. the React UI (in another terminal, with the API running)
cd web
npm install
npm run dev
```

## What's inside

| Path | Role |
|---|---|
| `entities/Invoice.php` | the `Invoice` declaration (Authoring DSL) — fields, actions, projections, guards |
| `bin/demo.php` | compile → runtime → API, with assertions |
| `bin/server.php` | HTTP front controller for `php -S` (so the renderer can connect) |
| `web/` | the React UI using `@ausus/react-renderer` |

## Public packages only

Composer: `ausus/authoring`, `ausus/entity-engine`, `ausus/persistence-memory`,
`ausus/api-runtime` (and `ausus/kernel`, pulled in) — all `^2.0` from Packagist.
npm: `@ausus/react-renderer` `^2.0`. No internal paths, no monorepo dependency.

## Limitations

The reference `ausus/persistence-memory` driver lives for one process; under
`php -S` writes do not persist across requests, so `bin/server.php` seeds a couple
of invoices at boot. For a persistent server, bind a persistent `PersistenceDriver`
in place of `MemoryDriver` — the rest of the application is unchanged.

## License

MIT.
