# Changelog — ausus/starter

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

## [Unreleased] — v0.1.x stabilisation

### Changed
- **`bin/boot.php` refactored onto `Ausus\Application`.** Removes the
  10-import, 30-line manual `Invoker` wiring used in v0.1.0; the boot
  script is now `Application::create()->register()->boot()` followed
  by a couple of `$app->invoke()` calls. CI step `5` continues to run
  `composer boot` on every push.
- **`src/HelloInvoiceDsl.php`** now uses the explicit
  `->workflow(field: 'status', initial: 'DRAFT')` form. The manual
  `HelloInvoicePlugin.php` shape is unchanged; both still compile to a
  byte-identical `MetadataGraph` hash (playground test 10).
- **`composer.json`** adds `ausus/standard-stack` to `require` so a
  fresh `composer create-project ausus/starter` provides
  `Ausus\Application` out of the box.

## [0.1.0] — 2026-05-19

First public release. Project template that boots a working AUSUS app
in three commands (post-Packagist) or 3 commands with one env var
(clean-room / pre-Packagist).

### Added
- **`composer create-project ausus/starter myapp`** scaffolding.
- **`src/HelloInvoice.php`** — demo Plugin in manual descriptor-array
  form. Shows full Field / Action / Policy / Workflow / Transition /
  Projection / Entity surfaces.
- **`src/HelloInvoiceDsl.php`** — same demo authored in the RFC-011 DSL.
  Produces a byte-identical `MetadataGraph` hash.
- **`bin/boot.php`** — one-command end-to-end smoke. Compiles the graph,
  applies the schema, creates an invoice, issues it, renders the
  summary Projection. Exits 0 cleanly when `vendor/` is absent
  (e.g. under `composer create-project --no-install`).
- **`bin/configure-repo.php`** — clean-room artifact-registry configurator.
  Fires from `post-root-package-install`; reads `AUSUS_LOCAL_REGISTRY`
  env var; writes a `repositories` block into the new project's
  `composer.json` so the cascading install resolves locally.
- **`composer boot`** script alias.

### Validated
- Clean-room install end-to-end (3 commands, < 0.5 s composer CPU)
  using only `composer create-project + composer install + composer boot`.

### Dependencies
- PHP ≥ 8.3
- `ext-pdo`, `ext-pdo_sqlite`
- `ausus/kernel` 0.1.*
- `ausus/persistence-sql` 0.1.*
- `ausus/runtime-default` 0.1.*

### License
MIT — see `LICENSE`.

[0.1.0]: https://github.com/ausus-framework/ausus/releases/tag/starter-v0.1.0
