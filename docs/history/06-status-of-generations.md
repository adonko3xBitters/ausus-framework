# 6. Status of the two generations

This describes the **current, factual** status of each generation in the
repository. It does not announce a roadmap, dates, or deprecations.

## Legacy (Generation 1) — `MetadataGraph` / `standard-stack`

- **Role.** The published, released AUSUS. It is what `v0.1.0` … `v1.1.0` shipped
  and what the README badge, `CHANGELOG.md`, and `artifacts/releases/` describe.
- **State.** Complete and intact in the tree: `kernel` (legacy classes),
  `standard-stack`, `runtime-default`, `api-http`, `presentation-default`,
  `persistence-sql`, `persistence-postgres`, `tenancy-row`, `audit-database`,
  `auth-bridge`, `starter`, and `@ausus/renderer-react`; RFCs `RFC-001`…`RFC-018`.
- **Maintenance.** It remains the source of truth for everything already
  published. Per-package CHANGELOGs and `docs/VERSIONING.md` continue to govern
  its release lines.
- **Compatibility.** Anything depending on the published v1.x packages continues
  to work unchanged; nothing in the Entity Engine alters Legacy code or contracts.

## Entity Engine (Generation 2) — `EntityDefinition` / `Compiler` / `RuntimeEntity`

- **Role.** The new metadata-first core: closed DSL → `EntityDefinition` →
  `Compiler` → `EntitySchema` → repository → `EntityEngine::bind` → `RuntimeEntity`
  → API Runtime → React Renderer → View System.
- **State.** Built and **validated** (three reference applications: CRM, Teranga
  PMS, SGH) but **not yet published** — no tag, no release, no commit; its
  packages are untracked in the working tree.
- **Quality evidence.** All of its test suites are green (kernel/engine/authoring/
  CLI/persistence-memory/api-runtime/view-system suites, plus the CRM/PMS/SGH
  validations and the React/View suites).
- **Direction (factual, not a roadmap).** It is the architecture intended for the
  next AUSUS publication, expected to be a new generation (major) of the line.
  This document deliberately stops there: no feature plan, no timeline, no
  deprecation schedule for Legacy is stated or implied.

## Coexistence

Both generations are present at once. They share `ausus/kernel`'s value objects
and the `PersistenceDriver` contract, while keeping distinct models and
namespaces. The Legacy generation is the published reality; the Entity Engine is
the validated, unpublished next generation. Their boundary is clean: the Entity
Engine adds, it does not remove.
