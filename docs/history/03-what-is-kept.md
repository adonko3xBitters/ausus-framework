# 3. What is kept

The Entity Engine is a new generation of the **same** framework. The following
foundations are common to both generations and are deliberately unchanged.

## Philosophy

- **Metadata-first.** In both generations you declare *what* the application is
  (entities, fields, actions, projections, authorization) and the framework runs
  it. You do not hand-write controllers, ORM models, or templates.
- **Domain-first, not UI-first.** The model is the data domain; the UI is a
  generic renderer over it. React is a rendering engine, not the source of truth.
- **Tenant-first.** Multi-tenancy is structural: every entity is tenant-scoped and
  the platform carries the tenant through reads and writes.
- **Authorization as data.** Permissions are declared, data-aware rules — not
  custom code scattered across the app.

## Engineering structure

- **Monorepo of small packages.** Both generations are a single repository of
  focused `ausus/*` packages with explicit roles.
- **Layered architecture (L0 → L6).** Each package declares a layer
  (`extra.ausus.layer`) and depends only on lower layers — no upward or sideways
  dependencies. The Entity Engine keeps the exact same layering discipline (L0
  kernel, L1 engine/authoring, L3 persistence driver, L4 API, L5 presentation, L6
  CLI/tooling).
- **PHP 8.3+ and a closed PHP DSL.** Both generations author the model in PHP and
  treat the DSL as a notation that produces metadata — never arbitrary business
  logic.
- **A swappable persistence driver behind a kernel contract.** Both rely on the
  `PersistenceDriver` / `Repository` contract so storage is interchangeable
  (SQLite/PostgreSQL in Legacy; an in-memory reference driver in the Entity
  Engine), with the engine never depending on a concrete driver.
- **A typed kernel of value objects.** Both generations keep the cross-cutting
  value objects (`Tenant`, `TenantId`, `ActorRef`, `Reference`, `Version`,
  `Decision`, `Entity`) in `ausus/kernel`. The Entity Engine **reuses** several of
  these unchanged.

## Concrete reuse

The Entity Engine literally reuses, without modification, the existing kernel
contracts and value objects: `PersistenceDriver`, `Repository`,
`PersistenceContext`, `TransactionHandle`, `Entity`, `Reference`, `Version`,
`Tenant`/`TenantId`, `ActorRef`, and the `Decision` enum. The new model is added
**alongside** them (in `kernel/src/{Definition,Contracts,Compiled}`), not in their
place.

In short: the *values* AUSUS was built on — metadata-first, tenant-first,
layered, plugin/driver-swappable, PHP DSL — are unchanged. Only the *shape of the
model and the pipeline* is re-derived.
