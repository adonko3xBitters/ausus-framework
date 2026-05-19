# Changelog — ausus/persistence-sql

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

## [0.1.0] — 2026-05-19

First public release. L3 driver: SQL-backed `PersistenceDriver` over PDO.
**No Laravel/Eloquent dependency** — pure PHP + PDO.

### Added
- **`SqlitePersistenceDriver`** — implements RFC-002 contract on PDO.
  Per-tenant transaction handles, row-level read filtering, optimistic
  locking via `_version` column.
- **`SqliteContext`** — bound `(Tenant, TransactionHandle)` pair; every
  read enforces `WHERE tenant_id = ?` at the SQL surface (RFC-003 row
  strategy).
- **`SqliteRepository`** — `find`, `create`, `update` with optimistic
  lock check (`WHERE id = ? AND _version = ?`). Raises
  `ConcurrencyConflict` on stale write.
- **`SchemaDeriver`** — translates `MetadataGraph` into `CREATE TABLE`
  statements (Entity table + per-Entity `_audit` table). Idempotent.
- **`DatabaseAuditSink`** — writes audit rows in the same DB transaction
  as the Effect's data writes (RFC-007 Amendment-01 in-transaction sink).
  Architecturally precludes orphan audit entries.

### Compatibility
- **Tested driver:** PDO SQLite (in-memory + file-backed).
- **Designed for:** PDO MySQL, PDO Postgres. Schema deriver emits ANSI
  DDL; full multi-engine validation deferred to a later release.

### Dependencies
- PHP ≥ 8.3
- `ext-pdo`
- `ausus/kernel` 0.1.*

### License
MIT — see `LICENSE`.

[0.1.0]: https://github.com/ausus-framework/ausus/releases/tag/persistence-sql-v0.1.0
