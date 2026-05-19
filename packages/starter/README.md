# ausus/starter

Project template that boots a working AUSUS application end-to-end.

## Quickstart (post-Packagist publication)

```bash
composer create-project ausus/starter myapp
cd myapp && composer boot
# expected: "OK — ausus/starter boots cleanly."
```

## Quickstart (clean-room / pre-Packagist publication)

If `ausus/*` packages are not yet on Packagist, point Composer at a local
artifact registry (a directory of `composer archive` `.tar` outputs):

```bash
AUSUS_LOCAL_REGISTRY=/path/to/registry \
  composer create-project ausus/starter myapp \
    --no-install \
    --repository='{"type":"artifact","url":"/path/to/registry"}' \
    --repository='{"packagist.org":false}'

cd myapp
composer install
composer boot
```

The `--no-install` flag tells create-project to skip its cascading dependency
resolution; the starter's `post-root-package-install` hook
(`bin/configure-repo.php`) reads `AUSUS_LOCAL_REGISTRY` and writes a
`repositories` field into `myapp/composer.json`. The subsequent
`composer install` resolves all `ausus/*` deps from the artifact registry.

Total wall time on a 2025-era machine: **< 0.5 s of composer CPU**, 0 LOC
authored by the consumer.

## What gets installed

| Package | Role |
|---|---|
| `ausus/kernel` | metadata graph, value objects, DSL facade (L0) |
| `ausus/persistence-sql` | SQLite/MySQL/Postgres PersistenceDriver (L3) |
| `ausus/runtime-default` | Invoker, Policy Engine, Workflow runtime (L2) |

## What's in the project after `composer create-project`

```
myapp/
├── bin/
│   ├── boot.php              # end-to-end smoke (exit 0 if vendor/ missing)
│   └── configure-repo.php    # clean-room artifact-repo configurator
├── src/
│   ├── HelloInvoice.php      # demo Plugin (manual descriptor-array form)
│   └── HelloInvoiceDsl.php   # same demo, written in the RFC-011 DSL
├── composer.json
└── README.md
```

## RFC ownership

- **RFC-012 §12** — starter project template
- **RFC-011 §2.1** — DSL plugin worked example (HelloInvoiceDsl.php)
