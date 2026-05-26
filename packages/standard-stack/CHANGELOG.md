# Changelog — ausus/standard-stack

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

## [Unreleased]

### Changed
- Package `type` is now `library` (was `metapackage`) so it can ship code.
- `require` now includes `ausus/api-http` alongside kernel, runtime-default and
  persistence-sql — the four implemented core packages.

### Added
- **`Ausus\Application`** — a high-level bootstrap facade with a four-call
  lifecycle (`create → register → boot → invoke`). It composes the kernel
  compiler, the SQLite persistence driver and the default runtime, eliminating
  the manual `Invoker` wiring previously repeated across every entry point.
  It is purely additive: the low-level `Invoker` API is unchanged and every
  object `Application` builds remains directly constructable.

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
