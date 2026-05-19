# ausus/audit-database

L3 — `TransactionalSink` implementation writing audit entries to the same database as `ausus/persistence-sql`.

## Owned RFC surfaces

- **RFC-007 Draft-02 + Amendment-01** — Auditor implementation, `TransactionalSink` for the `kernel_audit_log` table.
- Per RFC-007 §5.3: recommended default. No orphans architecturally possible because audit writes share the active transaction with data writes.

## Capabilities advertised

```
supportsDedupByEntryId()  -> true          # UNIQUE on entry_id
maxSampleHandles()        -> 100           # RFC-007 §13.1 default
preservesInsertionOrder() -> true          # sequence per correlation_id
preservesElevation()      -> true          # Amendment-01 §A-7.2 column
```

## Schema (created via package migration)

`kernel_audit_log`:
`entry_id` (PK, ULID) · `sequence` · `actor_*` · `tenant_id` · `action_fqn` · `subject_kind` · `subject_*` · `inputs` (jsonb) · `outputs` (jsonb) · `timestamp` · `correlation_id` · `trace_id` · `invocation_class` · `elevation` (jsonb nullable) · `emitter_version`.

`outputs.bulk_entities` lives inside the `outputs` JSONB per RFC-007 Amendment-01 §A-7.1.

Append-only enforced via database-role grant: `INSERT` only.
