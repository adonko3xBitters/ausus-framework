# Changelog — ausus/persistence-sql

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

## [0.2.0-rc.1] — 2026-05-29

### Changed
- Release-candidate cut of v0.2.0-beta.1 with zero runtime change.
  `SqliteRepository` API, the `Ausus\PagedRepository` contract, and
  the WHERE / ORDER BY translation stay bit-identical to beta.1.

## [0.2.0-beta.1] — 2026-05-29

### Added
- `SqliteRepository` implements the new `Ausus\PagedRepository` interface
  with full filtering + sorting pushdown:
  - **Pagination** — `SELECT … LIMIT ? OFFSET ?` bound as `PDO::PARAM_INT`,
    plus a separate `COUNT(*)` under the same WHERE for the un-windowed total.
  - **Filtering** — `eq` / `in` / `contains` translated to parameterised
    WHERE conjuncts. Every value travels through `bindValue` with explicit
    PDO type; LIKE metacharacters in `contains` are escaped so a user-
    supplied `%` cannot widen the match. Three SQL-injection vectors
    (`'OR 1=1`, `DROP TABLE`, backslash-escape) are pinned by
    `apps/playground/filtering-test.php`.
  - **Sorting** — `ORDER BY "col" ASC|DESC` clauses appended in caller order;
    a deterministic `id ASC` tie-breaker is appended unconditionally when
    the caller's sort list does not already pin id. Duplicate sort columns
    in the input are rejected.
  - **Defence in depth** — `resolveColumn()` rejects any field name not
    declared on the entity's metadata, even though the HTTP/renderer layer
    already validates. Errors throw `InvalidArgumentException` with the
    explicit column name.
- `findAll()` is unchanged.

## [0.2.0-alpha.5] — 2026-05-28

### Changed
- No runtime changes in v0.2.0-alpha.5.
- Documentation and release validation workflows were synchronized with
  the alpha release process.

## [0.2.0-alpha.4] — 2026-05-27

### Release engineering
- **No SQL, schema, or driver change.** Zero code changes vs
  `v0.2.0-alpha.3`. `SqliteRepository`, `SqliteContext`,
  `SqliteTransactionHandle`, `SqlitePersistenceDriver`, `SchemaDeriver`,
  and `DatabaseAuditSink` are bit-identical. No table layout change,
  no migration, no on-disk format change.
- **Release validation compatibility.** The package manifest is
  validated by the repo-level `scripts/release-gate.sh` with
  `composer validate --strict` (Composer 2.x compliant — the deprecated
  `--no-check-version` flag is no longer used).
- **Public install validation.** Each tagged release of this package
  is now exercised end-to-end by `scripts/release-gate.sh` live mode
  (CI workflow `packagist-validation.yml`), which runs
  `composer create-project ausus/starter` against Packagist live and
  verifies the resulting install reaches the SQLite-backed runtime.

## [Unreleased] — v0.1.x stabilisation

### Documentation
- **API stability sweep.** `SqliteTransactionHandle`, `SqliteContext`,
  and `SqliteRepository` now carry `@internal` PHPDoc — consumers MUST
  depend on the kernel interfaces (`TransactionHandle`,
  `PersistenceContext`, `Repository`), not on the concrete classes.

### Fixed
- **Nullable-column serialisation (high severity).**
  `serializeField()` used to route PHP null through `json_encode(null)`
  (because `is_scalar(null)` is `false`), storing the literal
  4-character string `"null"` on disk. Symmetric corruption hit
  `integer` (stored `0`), `datetime` (stored `""`), and `money`
  (stored `""` on disk; read back as `{amount: '', currency: '…'}`).
  Fixed by a single null-guard at the top of `serializeField()` plus a
  matching guard in `unwrapFields()` — every nullable type now
  round-trips as a real PHP null / SQL NULL.
  Regression-guarded by `apps/playground/null-roundtrip-test.php` (30
  assertions, CI step `4h`).
- **`SqliteRepository::findAll()`** added (kernel contract addition).
  The projection renderer no longer reads the driver's private PDO via
  `\ReflectionProperty` — list rendering walks the repository contract.

### Notes
- No SQL schema change. Existing rows on disk that were corrupted by
  the pre-fix `serializeField()` are not rewritten; backfill is the
  consumer's responsibility.

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
