# ausus/tenancy-row

L3 — row-level Tenant isolation + Tenant catalog + per-context resolvers.

## Owned RFC surfaces

- **RFC-003 §4** — `TenantIsolationStrategy` (row strategy only in V1).
- **RFC-003 §3, §11** — `TenantResolver` for HTTP (subdomain / header / JWT composite), CLI (`--tenant` flag), QUEUE (`__ausus_tenant` payload), SCHEDULED (static binding).
- **RFC-003 §5** — `TenantCatalog` backed by `ausus/persistence-sql`.
- **RFC-003 §8** — `OverrideStore` for additive Tenant overrides.
- **RFC-003 §6, §7, §13** — Bootstrap, archival, deletion, strategy migration Actions (`kernel.tenant.*`).

## Allowed dependencies

- `ausus/kernel`
- `illuminate/http` (subdomain resolution from `Host` header)

## Forbidden

- Cross-Tenant transactions of any kind.
- Schema-per-tenant or db-per-tenant strategies (out of V1 — would be separate packages `ausus/tenancy-schema`, `ausus/tenancy-database`).
