# Changelog — ausus/standard-stack

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

## [0.2.0-beta.1] — 2026-05-29

### Added
- `Ausus\Application::renderProjection(string $fqn, ?Reference $subject, int
  $limit = 50, int $offset = 0, list<Filter> = [], list<Sort> = []): array`
  — convenience wrapper around the underlying `ProjectionRenderer::render()`
  that mirrors the HTTP API surface defaults. Lets non-HTTP callers
  paginate / filter / sort without manually grabbing the renderer via
  `$app->renderer()`.

## [0.2.0-alpha.5] — 2026-05-28

### Changed
- No runtime changes in v0.2.0-alpha.5.
- Alpha installation documentation and release procedures were clarified.

## [0.2.0-alpha.4] — 2026-05-27

### Release engineering — install stability hotfix
- **No `Application` / `ApplicationConfig` runtime change.** The
  bootstrap facade is bit-identical to `v0.2.0-alpha.3`. Consumers
  using `Application::create(...)` see no behaviour change.
- **Starter install fixed (BUG #1 from QA report).**
  `packages/starter/composer.json` now declares
  `"minimum-stability": "alpha"`. When `composer create-project
  ausus/starter myapp` scaffolds a user project, the root
  `composer.json` inherits this and the transitive `ausus/*
  ^0.2@alpha` chain resolves cleanly. Previously the scaffold inherited
  `minimum-stability: stable`, which rejected the alpha transitive
  constraints and broke the official quickstart on every fresh machine.
- **`post-root-package-install` hook removed from starter.** That hook
  ran `bin/configure-repo.php` BEFORE dependency resolution; when
  resolution failed (the v0.2.0-alpha.3 scenario), the user was left
  with a half-populated `myapp/` and configure-repo side effects
  already applied. The companion hook `post-create-project-cmd` (which
  runs `bin/boot.php` AFTER successful install) is preserved.
- **Packagist alignment.** Each of the 10 `ausus/*` packages now
  declares its dedicated `rel-*` repository in the `homepage` field
  (`https://github.com/adonko3xBitters/<package>`). The standard-stack
  metapackage's `homepage` points at
  `https://github.com/adonko3xBitters/standard-stack` rather than the
  monorepo URL, matching the actual subtree-split distribution
  architecture.
- **Alpha dependency resolution documented.** Consumers who scaffold
  manually (not via `composer create-project`) MUST declare alpha
  stability at their root. See the troubleshooting entry
  `alpha-resolution-failure` and the
  `installation.md#alpha-installation-requirements` section in the
  docs site for the full explanation of why Composer's `@alpha` flag
  does not propagate to transitive dependencies.
- **Public install gate.** `scripts/release-gate.sh` live mode runs
  `composer create-project ausus/starter:$VERSION` from a fresh tmp
  directory against Packagist on every tag. The previous BUG #1
  scenario would now fail the gate before tag promotion.

## [Unreleased] — v0.1.x stabilisation

### Changed
- Package `type` is now `library` (was `metapackage`) so it can ship code.
- `require` now includes `ausus/api-http` alongside kernel, runtime-default
  and persistence-sql — the four implemented core packages.

### Added
- **`Ausus\Application`** — a high-level bootstrap facade with a four-call
  lifecycle (`create → register → boot → invoke`). Composes the kernel
  compiler, the SQLite persistence driver and the default runtime,
  eliminating the manual `Invoker` wiring previously repeated across every
  entry point. Purely additive — every low-level service it builds
  remains directly constructable. Public surface: `create`, `register`,
  `boot`, `invoke`, `run`, `http`, `router`, `render`, plus typed
  accessors (`graph`, `invoker`, `driver`, `renderer`, `auditSink`, `pdo`,
  `tenant`, `actor`, `isBooted`, `reference`).
- **`Ausus\ApplicationConfig`** — a typed, immutable, fluent builder for
  `Application::create()`. Every setter returns a new instance; the
  receiver is never mutated. `Application::create()` accepts either an
  `ApplicationConfig` or the legacy associative array (both bit-for-bit
  equivalent). 14 setters: tenant, actor / actorId, roles, permissions,
  sqlite / pdo / driver / auditSink / migrate, kernelVersion, apiPrefix,
  psr17 / responseFactory / streamFactory.
- **`Application::http(ServerRequest): Response`** — one-call PSR-7
  entry point. Lazily builds and caches a `Router` against the booted
  graph/driver/audit-sink, autodetects `Nyholm\Psr7\Factory\Psr17Factory`
  when none is configured, mounts at `ApplicationConfig::apiPrefix()`
  (default `/api`). Collapses typical front controllers to ≈ 10 lines.
- **`Application::run(...): InvocationResult`** — typed wrapper around
  `invoke()`'s loose array return; carries the post-action `Reference`,
  the action FQN, and the raw outputs.

### Documentation
- Reference page added at `docs-site/docs/reference/application.md`.

## [0.1.0] — 2026-05-19

## [0.1.0] — 2026-05-19

First public release. Composer **metapackage** that pins the V0 Standard
Stack: the three implemented core packages.

### Added
- **Meta-requires** at 0.1.*:
  - `ausus/kernel`
  - `ausus/persistence-sql`
  - `ausus/runtime-default`

### Deferred to a later release
The following packages have reserved names and skeleton manifests but
are NOT included in this metapackage's `require` until they ship real
code:
- `ausus/tenancy-row`
- `ausus/audit-database`
- `ausus/auth-bridge`
- `ausus/presentation-default`

These appear under `extra.ausus.v0-scope` as a forward-marker.

### License
MIT — see `LICENSE`.

[Unreleased]: https://github.com/adonko3xBitters/ausus-framework
[0.1.0]: https://github.com/ausus-framework/ausus/releases/tag/standard-stack-v0.1.0
