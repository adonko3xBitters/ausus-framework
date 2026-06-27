# ausus/cli

AUSUS **2.0** — CLI (L6). The authoring frontend for the Entity Engine: it
discovers closed-DSL definition files, scans them for forbidden symbols,
compiles them to **content-addressed** `EntitySchema`, and persists them through
a `FileSchemaRepository`. PHP-native: no container, no auto-discovery.

## Installation

```bash
composer require ausus/cli:^2.0
```

## Dependencies

- PHP 8.3+
- `ausus/kernel`, `ausus/authoring`, `ausus/entity-engine` (resolved automatically)

## Public surface

- `Ausus\Cli\Authoring\DslFrontend` — `discover(string $root): EntityDefinition[]`
  (loads `*.php` definition files; one-shot evaluation, forbidden-symbol scan).
- `Ausus\Cli\Command\CompileEntitiesCommand` — `run(string $entitiesDir, string
  $aususRoot, $stdout = null, $stderr = null): int`. Discovers + compiles in
  memory, then writes **atomically** (`.ausus/schemas/<hash>.json` + `index.json`);
  on any error nothing is written.
- `Ausus\Cli\Repository\FileSchemaRepository` — `resolve(string $entityId):
  EntitySchema`, `getByHash()`, `putByHash()`. Reads/writes the content-addressed
  store; **never recompiles**.

## Minimal example

`compile.php` in your project root:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Ausus\Cli\Command\CompileEntitiesCommand;

// entities/*.php each `return Definition::make(...)->build();`
exit((new CompileEntitiesCommand())->run(__DIR__ . '/entities', __DIR__ . '/.ausus'));
```

```bash
php compile.php
# → writes .ausus/schemas/<hash>.json + .ausus/index.json
```

## Documentation

See the canonical reference [`docs/v2/`](../../docs/v2/README.md) and the
[Quick Start](../../docs/v2/QUICKSTART.md) (EE-RFC-011 / EE-RFC-012).
