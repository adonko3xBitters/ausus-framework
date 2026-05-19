# ausus/persistence-sql

L3 — SQL `PersistenceDriver` implementation.

## Owned RFC surfaces

- **RFC-002** — full PersistenceDriver, PersistenceContext, Repository, Identity, Transactions, Optimistic locking, Relations, Filter grammar, Bulk operations, Error taxonomy.
- Schema derivation from Metadata Graph per RFC-012 §3.4.

## Capabilities advertised

```
supportedTenancyStrategies() -> ['row']     # V1 ships row-only
supportsSnapshotReads()      -> true        # Postgres MVCC / MySQL InnoDB
maxNestedSavepoints()        -> 8           # RFC-002 §7.4 minimum
maxBulkTransactionSize()     -> 100000      # configurable
identityShape()              -> 'ulid'      # configurable
```

## Allowed dependencies

- `ausus/kernel`
- `illuminate/database` (Eloquent connection only — never exposed across the contract boundary)

## Forbidden

- Eloquent return types in any public method.
- `Illuminate\Database\Query\Builder` exposed to plugins.
- Direct SQL strings accepted as input.
