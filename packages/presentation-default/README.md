# ausus/presentation-default

L5 — Presentation layer + L3 ReportingDriver + 11 standard Field Types + `react.web.v1` profile registration (composer half).

Consolidates four RFC-012 components for V1: presentation generator, reporting driver, field type library, renderer profile registration. The npm half of `react.web.v1` lives in `renderer/react/` (separate Node toolchain).

## Owned RFC surfaces

- **RFC-004** — ViewSchema wire format, envelope, projection-to-schema transformation, locale handling, capability negotiation, caching.
- **RFC-010** — `ReportingDriver` SQL implementation, query grammar, projection/aggregation/pagination, audit emission for `audited_reads`.
- **RFC-011 §8.1** Standard Field Types: string, integer, decimal, boolean, date, datetime, time, enum, money, json, reference (RFC-012 §7).
- **RFC-004 §10** — `react.web.v1` profile registration in the Presentation layer.

## Public surface

- `Ausus\Presentation\PresentationServiceProvider` (Laravel service provider; binds L5 + L3 reporting).
- HTTP endpoint contract for ViewSchema GET / Reporting POST (consumed by L4 API Surface — out of V1 first slice; minimal route registration only).

## Allowed dependencies

- `ausus/kernel`
- `illuminate/http` (HTTP transport for ViewSchema endpoint)
- `illuminate/database` (for ReportingDriver's SQL query execution)

## Forbidden

- Any UI-tier dependency (React component, Vue, etc.) — the npm half lives in `renderer/react/`.
- Eloquent return types in ReportingDriver output.
- Cross-package AUSUS dependencies beyond `ausus/kernel`.
