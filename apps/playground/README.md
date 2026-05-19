# apps/playground

End-to-end integration target. Exercises the entire monorepo against a real Postgres/SQLite instance.

NOT a Composer package. NOT published. Used only for monorepo CI and local dev verification.

## Purpose

- Smoke-test that every package wires together correctly.
- Reproduce RFC-000 V0 Real Pass — but successfully this time.
- Validate UX-2 / UX-3 measurements: TTFS, concept count, imports, FQNs.

## Contents (target)

- Tiny Laravel app using `ausus/starter` template inline (via path repo).
- Acceptance test suite that drives the Invoke chain end-to-end (Action invocation → audit emission → ViewSchema rendering).
- No production-grade configuration. SQLite by default; Postgres on demand.

## When to populate

After the first executable milestone (kernel + runtime + persistence-sql + tenancy-row + audit-database can compile + boot in isolation). See `docs/IMPLEMENTATION-PLAN.md`.
