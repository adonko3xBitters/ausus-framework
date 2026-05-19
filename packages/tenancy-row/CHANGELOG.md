# Changelog — ausus/tenancy-row

## [0.1.0] — 2026-05-19

**Name reservation release.** No source code shipped.

This package reserves the `ausus/tenancy-row` name on Packagist while the
RFC-003 row-level `TenantIsolationStrategy` implementation is being
finalized. For V0, the row strategy is enforced directly inside
`ausus/persistence-sql`'s `SqliteContext` via mandatory `WHERE tenant_id = ?`
filtering — see `ausus/persistence-sql` CHANGELOG.

Consumers should not require this package until v0.2.0.

### License
MIT — see `LICENSE`.

[0.1.0]: https://github.com/ausus-framework/ausus/releases/tag/tenancy-row-v0.1.0
