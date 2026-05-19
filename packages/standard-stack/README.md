# ausus/standard-stack

Meta-package. Empty `src/`. Pure version coordination per RFC-012 §16.

## What it does

- Requires every L0–L5 package at compatible versions.
- Pins kernel major; component packages track per their own SemVer.
- Single-line install for plugin authors: `composer require ausus/standard-stack`.

## Required packages

| Package                       | Layer | Role                                                       |
|-------------------------------|-------|------------------------------------------------------------|
| `ausus/kernel`                | L0    | Contracts                                                  |
| `ausus/runtime-default`       | L2    | Invoker + Policy Engine + Workflow runtime + Effect dispatch |
| `ausus/persistence-sql`       | L3    | SQL PersistenceDriver                                      |
| `ausus/tenancy-row`           | L3    | Row Tenant isolation + resolvers + catalog                 |
| `ausus/audit-database`        | L3    | Database TransactionalSink                                 |
| `ausus/auth-bridge`           | L7-ish| Authorization plugin (stub + Laravel bridge)               |
| `ausus/presentation-default`  | L5+L3 | ViewSchema generator + ReportingDriver + field types + react.web.v1 profile |

The npm half of `react.web.v1` is in `renderer/react/`; install via `npm install @ausus/renderer-react` in the frontend.

## Version policy

Standard Stack version tracks kernel major. Component packages SemVer independently; the meta-package's `require` enforces compatible ranges.
