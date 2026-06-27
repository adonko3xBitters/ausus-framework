# 5. Reference applications

Three independent applications are the official demonstrators of 2.0. Each is
built **only** from the DSL + ViewDefinition — no framework code was modified —
and each ships a full validation test (PHP + a React/View test). They exist to
prove the model on real domains and to establish, reproducibly, where it holds
and where it stops.

| App | Path | Entities | Highlights | Tests |
|-----|------|---------:|-----------|-------|
| **CRM** | `apps/crm` | 5 | customers, opportunities (pipeline), tasks, activities, users | 18 PHP + 4 JS |
| **Teranga PMS** | `apps/teranga-pms` | 10 | hotel property management; reservation → stay → invoice → payment | 28 PHP + 4 JS |
| **SGH (Hospital)** | `apps/sgh` | 12 | patient → appointment → consultation → prescription → admission → invoice → payment → medical record | 31 PHP + 4 JS |

## What each demonstrates

- **CRM** — the baseline: entities, create/transition actions, projections,
  single-hop expand (opportunity → customer), visibility-gated fields, and a
  generated React navigation.
- **Teranga PMS** — scale and chains: 7 state machines, a full booking-to-payment
  workflow, six expand chains, and the design pattern that every entity exposes a
  **flat `board`** projection (the only legal single-hop expand target) plus a
  richer `detail` that carries the expands.
- **SGH** — the most demanding: a clinical workflow across 12 entities and
  **four-dimension authorization** (guards on `actor`, `tenant`, `subject`, and
  `input`, all expressed with primitive operators).

## Running a reference app

```bash
# PHP validation (compiles to a temp .ausus, runs the full workflow, writes test fixtures)
php apps/sgh/tests/sgh-validation-test.php

# React/View layer (consumes the fixtures the PHP test just generated)
cd apps/sgh/tests && node --test --experimental-strip-types 'sgh-renderer.test.ts'
```

> The `*-renderer.test.ts` suites depend on the fixtures produced by the matching
> `*-validation-test.php`; run the PHP test first. Generated `.ausus/` and
> `.fixtures/` are not versioned (see `.gitignore`).

The three apps are also the empirical basis for the
[known limits](07-known-limits.md): each limit listed there is reproduced by a
concrete scenario in one of them.
