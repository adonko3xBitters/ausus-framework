# Changelog — ausus/standard-stack

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

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
