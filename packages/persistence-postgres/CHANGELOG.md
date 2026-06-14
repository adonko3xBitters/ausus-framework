# Changelog — ausus/persistence-postgres

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

## [1.1.0] — 2026-06-14

### Added
- First release. PostgreSQL `PersistenceDriver` implementation reaching
  behavioural parity with the reference `ausus/persistence-sql` (SQLite)
  driver across the full kernel persistence contract:
  - `PostgresPersistenceDriver`, `PostgresContext`, `PostgresRepository`
    (`Repository` + `PagedRepository`), `PostgresTransactionHandle`,
    `PostgresAuditSink`, `PostgresSchemaDeriver`.
  - Per-tenant transactions and row-level `WHERE tenant_id = ?` isolation.
  - Optimistic locking → `ConcurrencyConflict` (message-identical to SQLite).
  - Filter grammar `eq` / `contains` (LIKE-escaped) / `in`; sorting with
    deterministic `id ASC` tie-breaker and duplicate-column rejection;
    undeclared-column rejection.
  - Referential-integrity enforcement on reference fields (RFC-015) →
    `ReferentialIntegrityViolation`, message-identical to SQLite.
  - In-transaction audit sink (17-column `kernel_audit_log`).
- Cross-driver compatibility harness (`tests/compat.php`) asserting
  SQLite ↔ PostgreSQL parity. Covers defaults, required/nullable, money,
  integer, datetime, enum, references, tenant isolation, update/
  concurrency, `findAll`/`findPaged`, filters (`eq`/`contains`/`in`),
  sorting (asc/desc/id-tiebreak/duplicate), `generateIdentity`,
  invalid-filter and invalid-sort guards, audit (nominal / metadata /
  null-trace / isolation / multi-row / rollback / structure), and RFC-015
  reference enforcement.
- Anti-false-positive CI guard: with `AUSUS_PG_REQUIRED=1` the suite refuses
  to report success unless the PostgreSQL driver actually participated.

### Dependencies
- PHP ≥ 8.3
- `ext-pdo`, `ext-pdo_pgsql`
- `ausus/kernel` ^1.0
- (dev) `ausus/persistence-sql` ^1.0 — reference driver for the parity harness.

### Compatibility
- **Tested engine:** PDO PostgreSQL 16 (Docker `postgres:16`).
- Parity validated against PDO SQLite (in-memory) on every CI run.

### License
MIT — see `LICENSE`.
