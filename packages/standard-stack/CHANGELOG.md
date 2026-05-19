# Changelog — ausus/standard-stack

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

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

[0.1.0]: https://github.com/ausus-framework/ausus/releases/tag/standard-stack-v0.1.0
