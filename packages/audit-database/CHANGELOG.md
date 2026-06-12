# Changelog — ausus/audit-database

## [1.1.0] — 2026-06-12

### Changed
- Version alignment release. No package-specific code changes.

### Note
- This package was previously released as part of the coordinated 1.0.x
  distribution but those alignment releases were not recorded in this
  changelog. This entry restores changelog/version alignment.

## [0.1.0] — 2026-05-19

**Name reservation release.** No source code shipped.

This package reserves the `ausus/audit-database` name on Packagist while
the standalone RFC-007 `TransactionalSink` driver is being finalized.
For V0, the database audit sink is shipped inline by
`ausus/persistence-sql` as `DatabaseAuditSink` — see that package's
CHANGELOG.

Consumers should not require this package until v0.2.0.

### License
MIT — see `LICENSE`.

[0.1.0]: https://github.com/ausus-framework/ausus/releases/tag/audit-database-v0.1.0
