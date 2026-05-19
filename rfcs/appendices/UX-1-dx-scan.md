# Appendix UX-1 — Developer Ergonomics Scan against RFC-000

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Audit report                                           |
| Authors       | architect, challenger                                  |
| Date          | 2026-05-18                                             |
| Target        | RFC-000 (V0 Vertical Slice Validation)                 |
| Scope         | Developer ergonomics ONLY — no fixes, no recommendations |

This appendix audits exactly the eight measurements the brief specifies. Each measurement is taken from RFC-000's worked slice (`acme/billing` against the accepted RFC stack). Each is classified into one of four bands: **Excellent**, **Acceptable**, **Dangerous**, **Adoption blocker**.

No remediation is proposed. The scan reports the present state.

---

## Classification bands

| Band              | Operational meaning                                                                          |
|-------------------|----------------------------------------------------------------------------------------------|
| Excellent         | Better than industry norm for comparable platforms. DX is a competitive advantage.           |
| Acceptable        | Within industry norm. Neither attracts nor repels developers on this dimension.              |
| Dangerous         | Worse than industry norm. Adoption proceeds only with deliberate developer commitment.       |
| Adoption blocker  | Outside the range a developer will tolerate during evaluation. First impression kills adoption. |

---

## Measurement 1 — Time-to-first-screen

### Number

**~8 hours** from `composer create-project` to a working `billing.invoice.summary` ViewSchema rendered in the browser.

### Evidence (RFC-000 §9)

| Phase                                              | Estimated time |
|----------------------------------------------------|----------------|
| Setup steps 1–9 (install, compile, doctor)         | 90 minutes     |
| React mount, blank screen                          | 30 minutes     |
| curl POST to create                                | 15 minutes     |
| UI shows invoice; issue button works               | 45 minutes     |
| Reporting query response                           | 30 minutes     |
| Reading documentation + first DSL trial            | 180 minutes    |
| Debugging typo / missing config                    | 90 minutes     |
| **Total**                                          | **~480 minutes** |

### Comparison

| Platform   | Time-to-first-screen | Ratio vs AUSUS V0 |
|------------|----------------------|--------------------|
| Retool     | 10 minutes           | 48× faster         |
| Filament   | 30 minutes           | 16× faster         |
| Nova       | 45 minutes           | ~11× faster        |
| AUSUS V0   | 480 minutes          | baseline           |
| Odoo (new) | days                 | comparable or slower |

### Classification

**Dangerous.**

Eight hours is far outside the "evaluate during a coffee break" window every comparable Laravel-ecosystem framework occupies. RFC-000 §10.5.1 explicitly acknowledges this as the V0 architectural-rigor tax and offers the amortization argument (per-Entity cost drops to ~30 minutes for Entity #2). The amortization is unproven; the 8-hour first impression is fact. A determined enterprise developer will push through; a casual evaluator will not.

Not classified as Adoption blocker because the 8-hour figure includes 180 minutes of "reading documentation + first DSL trial" — a developer who pre-reads can compress total time toward 5 hours. Still Dangerous; the first-impression cost is real.

---

## Measurement 2 — Concepts introduced before first render

### Number

**80+ distinct concepts** the plugin author must encounter before rendering the first screen.

### Evidence (enumerated from RFC-000 §7 setup + §2 DSL + cross-references to the RFC stack)

Counted by category (each line is one concept the plugin author has to know exists):

**Plugin and packaging (RFC-001, Amendment-01 §A-1.1)**
1. `Plugin` interface
2. `PluginLifecycle` interface
3. `ServiceProvider` triple-interface unification
4. `composer.json` `extra.ausus` manifest block
5. Plugin discovery via autodiscovery flag
6. Plugin install / boot / upgrade / uninstall lifecycle hooks

**Entity and Fields (RFC-001 §2.1, §2.2, RFC-002 §6, §8, Amendment-02 §A-1.12)**
7. Entity FQN convention `namespace.name`
8. Tenant binding invariants (`tenant_id` immutable)
9. Identity invariants (opaque, immutable)
10. Field types: id, system, string, money, enum, datetime, timestamps, version
11. `uniqueWithinTenant` modifier
12. `Field::version()` mandatory for optimistic locking
13. `audited_reads` opt-in attribute
14. Reserved Field path `_version` (leading underscore prefix reserved)

**Actions (RFC-001 §2.4, RFC-010 §8.1, Amendment-01 §A-1.4)**
15. Action FQN
16. Action `policy` attachment
17. Action `subjectRequired` flag
18. Action `inputs` schema
19. Action `effect` class reference (per RFC-000 invented; F-V0-02)
20. Standard vs Maintenance Action kinds
21. `acknowledges_bulk_lwm` flag (RFC-002 §11.4)

**Policies (RFC-005, Amendment-01 §A-1.2)**
22. `Policy` interface
23. `Decision` enum (Permit / Deny / Abstain)
24. `Subject` value object
25. `Context` value object — 8 keys (tenant, trace, correlation, elevation, resolverContext, clock, requestId, locale)
26. `Actor` interface — `ref()`, `roleHash()`
27. Authorization-plugin-extended `Actor::roles()`, `Actor::permissions()`
28. PolicyDescriptor (fqn, class, cacheable, timeoutMs)
29. Composition combinator `Deny > Permit > Abstain`
30. Empty chain → Abstain → deny-by-default
31. Policy chain segments (9 segments per RFC-005 §4.1)
32. Subject=null semantics (5 documented cases)
33. Policy side-effect prohibition (RFC-005 §10)
34. Policy timeout (RFC-005 §9.2)
35. Cacheable Policy flag
36. `kernel.field.read` sentinel
37. `kernel.entity.read` sentinel
38. Reserved `kernel.*` Action namespace
39. Reserved `kernel.*` identity-handle namespace (Amendment-02 §A-1.13)

**Workflow (RFC-001 §2.6)**
40. `Workflow` descriptor
41. States, initial state, transitions
42. Workflow `via` Action mapping
43. Workflow guard (optional; same Policy semantics)
44. Workflow execution semantics (deferred to RFC-006; V0 shim — F-V0-03)

**Projections (RFC-001 §2.7, RFC-004)**
45. Projection FQN scoped to Entity
46. Projection `fields`, `actions`, `filters`, `policy`
47. Projection-level Policy mandatory
48. UI hints sub-descriptor

**Tenancy (RFC-003)**
49. `TenantId` opaque value
50. Six Tenant states (PROVISIONING / ACTIVE / SUSPENDED / MIGRATING / ARCHIVED / DELETED)
51. Three isolation strategies (row / schema / database)
52. Four resolver contexts (HTTP / CLI / QUEUE / SCHEDULED)
53. Tenant catalog
54. `system` Tenant reservation and `__system__` literal
55. Tenant override scope (additive narrowing only)
56. `overrideVersion` monotonic counter
57. Elevation grant + close lifecycle
58. Cross-Tenant operation prohibition
59. `ausus:tenant:create` command
60. Reserved queue payload key `__ausus_tenant`

**Persistence (RFC-002)**
61. `PersistenceDriver` contract
62. `PersistenceContext` contract
63. `Repository` per-Entity surface
64. `TransactionHandle` opaque
65. `Reference` canonical tuple `(tenant_id, entity_fqn, identity_handle)`
66. `IdentityHandle` opaque
67. `Version` opaque
68. Filter grammar (10 closed node types)
69. Bulk operations (`updateMany`, `deleteMany`, `iterate`)
70. PersistenceError taxonomy (13 types)
71. Driver capabilities advertisement

**Audit (RFC-007 + Amendment-01)**
72. Audit Spine kernel-enforced
73. AuditEntry shape — `entryId`, `sequence`, `correlationId`, `traceId`, `emitterVersion`, `actor`, `tenant`, `actionFqn`, `subject`, `inputs`, `outputs`, `timestamp`, `invocationClass`, `elevation`
74. `SingleSubject` vs `BulkSubject` sum
75. `outputs.bulk_entities` for multi-Entity Maintenance (Amendment-01 §A-7.1)
76. SinkRole (PRIMARY / SECONDARY), SinkKind (TRANSACTIONAL / EXTERNAL)
77. Three-phase External-sink protocol (prepare / confirm / cancel)
78. Retry queue + dead-letter (`kernel.audit.retry_worker`, `kernel.audit.dead_letter`)
79. Orphan reconciliation (`kernel.audit.reconcile_external_primary`)
80. `audit.redact` global glob patterns
81. Per-Field `sensitive: true` annotation
82. Per-Action `audit_inputs_redact` additive redaction
83. Redaction marker literal `"[REDACTED]"`
84. AuditError taxonomy (14+ types)
85. Reserved queue payload key `__ausus_trace`

**Presentation (RFC-004)**
86. ViewSchema wire format
87. Schema version triple `MAJOR.MINOR.PATCH`
88. Renderer profile (`react.web.v1`)
89. Profile capabilities (widgets, operators, embedded relations, etc.)
90. Cache key 8-tuple
91. Downgrade enumeration in `compatibility.downgrades`
92. Strict omission of denied Fields (no `redacted: true` flag)

**Reporting (RFC-010)**
93. `ReportingDriver` contract
94. `ReportingQuery` grammar (from / joins / filter / project / groupBy / having / orderBy / pagination)
95. Six aggregate functions (count / count_distinct / sum / avg / min / max)
96. Loud-failure on denied Field reference (`FieldVisibilityDenied`)
97. `kernel.reporting.query` synthetic ActionFqn
98. `kernel.reporting.aggregate` synthetic identity handle
99. ReportingError taxonomy

**CLI & operational**
100. `ausus:compile`
101. `ausus:cache:clear`
102. `ausus:doctor` (9+ checks across multiple subsystems)
103. `ausus:audit:tail`
104. `config/ausus.php` keys (25+ across kernel, compiler, runtime, tenancy, plugins, audit, reporting, policy_engine, maintenance)

Conservative count: **104 distinct concepts**. RFC-000's §10.10 friction acknowledges 70k words across seven specs as the documentation surface for first-Entity work.

### Comparison

| Platform   | Concepts before first render (rough order of magnitude) |
|------------|---------------------------------------------------------|
| Retool     | <10                                                     |
| Filament   | ~15 (Resource, Form, Table, Page, Action, Filter, …)    |
| Nova       | ~15                                                     |
| Laravel base | ~8 (Model, Migration, Controller, Route, View, Request, Response, Middleware) |
| AUSUS V0   | ~100                                                    |

AUSUS V0 introduces **6–10× more concepts** than peer Laravel frameworks before the first render.

### Classification

**Adoption blocker.**

100+ concepts before first render is incompatible with the standard developer-evaluation pattern (skim docs → write something → see it work within an afternoon). Even with a Plugin Author Handbook (RFC-000 §10.10, unscheduled), the conceptual surface a developer must absorb to write 155 lines of meaningful code is severe. Comparable to learning a new ORM, a new web framework, a new audit subsystem, a new authorization model, and a new build pipeline simultaneously.

This is the strongest DX signal in the audit. The amortization argument (per-Entity #2 onward) does not apply: concept count does not amortize. Every plugin author pays the full conceptual tax once.

---

## Measurement 3 — Lines of DSL

### Number

**85 lines** of DSL declaration for one Entity with 5 declared fields (plus 5 system fields), 3 Actions, 4 Policies, 1 Workflow with 3 transitions, 2 Projections.

### Evidence (RFC-000 §2 worked example, RFC-000 §8 LOC table)

The DSL chain in `BillingPlugin::boot()` is 85 lines of fluent declaration: Entity → Fields → Actions → Policies → Workflows → Projections, all in one chain.

### Comparison

| Platform                         | DSL/declaration lines for similar Entity |
|----------------------------------|------------------------------------------|
| Filament resource (form + table) | ~150 lines                               |
| Nova resource                    | ~200 lines                               |
| Laravel raw (Eloquent + migration + form request + controller) | ~250 lines |
| AUSUS V0 DSL                     | 85 lines                                 |

### Classification

**Acceptable.**

85 lines of DSL is dense but compact for the breadth of subsystems it configures (persistence schema, workflow, authorization attachments, presentation projections, filters, actions). The DSL is **smaller** than comparable per-Entity declarations in Filament or Nova. Per-line value is high.

Caveat: the DSL is illustrative per F-V0-01 (no normative surface yet). Final RFC-011 syntax may shift the count. The estimate stands as a reasonable order of magnitude for any plausible DSL respecting RFC-001 §5.8.

---

## Measurement 4 — Lines of infrastructure code

### Number

**~330 lines** of infrastructure code per plugin (excluding the DSL itself and excluding tests).

### Evidence (RFC-000 §8 LOC table, infrastructure rows only)

| Category                                | Lines  |
|-----------------------------------------|--------|
| `composer.json` (Composer + AUSUS extra)| 30     |
| Plugin/ServiceProvider class            | 60     |
| Policy implementations (4 classes)      | 90     |
| Action effect implementations (3 classes)| 110   |
| Migration (`billing_invoices` table)    | 40     |
| **Subtotal (infrastructure)**           | **330**|

Excludes: 85 lines of DSL (measurement 3), 250 lines of tests (not slice-required), 32 lines of deployment config, 640 lines of frontend (amortized across all Projections).

### Comparison

| Platform                         | Infrastructure lines per Entity |
|----------------------------------|----------------------------------|
| Laravel `make:model -mfsc`       | ~150 lines (model + migration + factory + seeder + controller) |
| Filament resource                | ~150 lines (resource + pages, mostly autogenerated) |
| Nova resource                    | ~200 lines                       |
| AUSUS V0                         | 330 lines                        |

AUSUS V0 is **~2× typical Laravel** infrastructure-line count.

### Classification

**Dangerous.**

330 lines of mandatory boilerplate per Entity (200 of which are Policy + Effect classes — the "real domain logic" but with explicit per-Action and per-attachment class scaffolds) is high. The amortization slope across additional Entities is favorable (RFC-000 §8 estimates per-Entity-after-first ~155 lines), but the initial-setup tax is real.

Most painful concentration: every Projection mandates a Policy (RFC-005 §5.4 empty-chain → deny-by-default), even universal-permit ones (RFC-000 friction 10.06). Every Action mandates an effect class (no inline closures per RFC-001 §5.8.6).

---

## Measurement 5 — Number of mandatory imports

### Number

**38 mandatory `use` statements** across the V0 plugin's files.

### Evidence (counted from RFC-000 §2 worked example)

| File                                        | Imports                                                                                  | Count |
|---------------------------------------------|------------------------------------------------------------------------------------------|-------|
| `BillingPlugin.php`                         | `Entity, Field, Action, Policy, Workflow, Projection, Plugin, PluginLifecycle, ServiceProvider` | 9     |
| Each Policy class (× 4)                     | `Policy, Decision, Subject, Context, Actor`                                              | 5 × 4 = 20 |
| Each Action effect class (× 3)              | `PersistenceContext, Reference, Context` (the policy Context)                            | 3 × 3 = 9 |
| **Total mandatory imports**                 |                                                                                          | **38** |

### Comparison

| Platform        | Mandatory imports for similar Entity      |
|-----------------|-------------------------------------------|
| Filament        | ~10 (one resource file, fewer classes)    |
| Nova            | ~12                                       |
| Laravel raw     | ~15 (Model + Controller + Request + …)    |
| AUSUS V0        | 38                                        |

**~3–4× peer count.** Reflects the per-attachment class proliferation already counted in measurement 4.

### Classification

**Dangerous.**

38 mandatory imports is well above peer norm. IDE auto-import mitigates the typing cost, but the **conceptual import** — knowing which class to import from which namespace — does not amortize. Each import is contract-bound: the Policy interface from `Ausus\Kernel\Contracts\Policy\Policy` is not the same as a generic Policy concept. Plugin authors learn the namespace layout as part of measurement 2's concept count.

---

## Measurement 6 — FQNs written manually

### Number

**19 unique FQNs** written manually by the plugin author, with **~35 textual occurrences** when cross-references are counted.

### Evidence (counted from RFC-000 §2 worked DSL + Effect / Policy class names)

| Category               | FQNs                                                                                                | Count |
|------------------------|-----------------------------------------------------------------------------------------------------|-------|
| Entity FQN             | `billing.invoice`                                                                                   | 1     |
| Action FQNs            | `billing.invoice.create`, `billing.invoice.issue`, `billing.invoice.cancel`                          | 3     |
| Policy FQNs            | `billing.invoice.policy.create`, `.issue`, `.cancel`, `billing.invoice.projection.read`              | 4     |
| Workflow FQN           | `billing.invoice.lifecycle`                                                                          | 1     |
| Projection names       | `summary`, `detail` (locally namespaced to Entity)                                                   | 2     |
| PHP class FQNs (plugin-owned) | `Acme\Billing\BillingPlugin`, three Effects, four Policies                                    | 8     |
| **Unique FQNs**        |                                                                                                     | **19** |

Cross-references (FQNs written more than once): each Action FQN appears in (a) Action declaration, (b) Workflow transition `via`, (c) Projection `actions` list, (d) at least one Policy decision branch ⇒ ~4 occurrences per Action × 3 = 12. Plus Policy FQN cross-refs (each in `policy()` attachment and PolicyDescriptor `make()`): ~2 × 4 = 8. Plus the Entity FQN repeats. Approximate **35 textual FQN occurrences** in 85 lines of DSL.

### Comparison

| Platform   | Manual FQNs for first Entity |
|------------|------------------------------|
| Filament   | ~5 (Model class, Resource class, Page classes)                       |
| Nova       | ~5                            |
| AUSUS V0   | 19 unique / ~35 textual       |

### Classification

**Dangerous.**

19 unique manually-typed FQNs (plus 35 textual occurrences) is a typo surface. The Compiler catches dangling references at compile time (RFC-001 §4.2.3), but each typo triggers a compile-fail loop. Authors who pattern-match FQN segments (`billing.invoice.policy.create` vs `billing.invoice.policy.issue`) are exposed to subtle one-character errors. The slash-separated PHP namespaces (`Acme\Billing\Effects\IssueInvoice`) plus dot-separated AUSUS FQNs (`billing.invoice.issue`) compound the cognitive load: two parallel naming worlds the author maintains by hand.

---

## Measurement 7 — Reserved-kernel concepts exposed to plugin author

### Number

**12 distinct reservation surfaces** the plugin author must know exist to avoid silent collision or registration-time rejection.

### Evidence (enumerated from the accepted RFC stack)

1. **Reserved Action namespace `kernel.*`** for both Policy-evaluation sentinels and audit-side synthetics (Amendment-01 §A-1.2 + Amendment-02 §A-1.11). Plugins MUST NOT register Actions in this namespace.
2. **Reserved identity-handle namespace `kernel.*`** (Amendment-02 §A-1.13). Plugins MUST NOT produce synthetic identities here; PersistenceDrivers must reject in non-audit contexts.
3. **Reserved Field-name prefix `_`** (RFC-004 §8.4). `_version` is one example; plugin Field names starting with underscore are rejected at registration.
4. **Reserved queue payload key `__ausus_tenant`** (RFC-003 §11.3). Plugins MUST NOT use this in job payloads.
5. **Reserved queue payload key `__ausus_trace`** (RFC-007 §9.6). Same.
6. **Reserved Kernel-owned Entity FQNs**: `kernel.tenant`, `kernel.tenant_override`, `kernel.audit_log`, `kernel.audit_retry_queue`, `kernel.audit_pending`. Plugins MUST NOT register Entities in `kernel.*`.
7. **Reserved synthetic ActionFqn list**: `kernel.field.read`, `kernel.entity.read`, `kernel.reporting.query`, `kernel.tenant.elevate`, `kernel.tenant.elevate_close`, `kernel.audit.dead_letter`, `kernel.audit.confirm_lost`, `kernel.tenant.elevate_close_late`, several `kernel.audit.*` Action FQNs (RFC-007 Appendix A, extended by RFC-007 Amendment-01 §A-7.4).
8. **Reserved synthetic identity-handle list**: `kernel.reporting.aggregate` (RFC-010 §7.2).
9. **Reserved Tenant ID literal `__system__`** (RFC-003 §12.1). Plugins MUST NOT construct or impersonate this.
10. **Reserved redaction marker string `"[REDACTED]"`** (RFC-007 §14.4). Plugins MUST NOT emit this string as legitimate data.
11. **Reserved `system` resolution behavior**: TenantResolvers MUST NOT return `system` from external resolution (RFC-003 §12.3).
12. **Reserved `kernel.audit.*` Action namespace** for audit-subsystem-internal Actions (RFC-007 §11.2, §12.5). Plugins MUST NOT register.

### Comparison

| Platform   | Reserved concepts |
|------------|-------------------|
| Filament   | ~3 (resource directory convention, page route prefix, naming conventions) |
| Laravel    | ~10 (kernel.php, console.php, auth.php config keys, reserved trait names, …) |
| AUSUS V0   | 12 hard reservations across 5 namespaces (FQN, identity, field-prefix, payload-key, literal-string) |

### Classification

**Dangerous.**

12 reservation surfaces across 5 different namespace conventions (FQN dot-notation, identity dot-notation, Field-name underscore prefix, payload-key double-underscore prefix, string literal) is a substantial mental model. The reservations are predictably-prefixed (`kernel.*`, `__ausus_*`, `_<name>`, `__system__`), which mitigates collision risk for careful authors, but each prefix is reserved by a different RFC. A plugin author with partial RFC familiarity may inadvertently violate (e.g., naming a custom Field `_audit_id`, or constructing a job payload key `__ausus_audit`).

The Compiler / Repository / Auditor reject at registration or first use, so violations surface loudly — but only after the author has invested in code that must be rewritten. No single consolidated "reserved names" reference exists in the RFC stack as of this scan.

---

## Measurement 8 — Compile-time failures before happy path

### Number

**Estimated 5–12 compile/boot failures** for a first-time plugin author writing toward the V0 slice. **0–3 failures** for an author following RFC-000 §2's worked example exactly.

### Evidence (drawn from error taxonomies across the RFC stack)

Possible compile-/boot-time failures on the V0 path:

| Source RFC        | Error class / failure mode                                                | Likelihood for novice |
|-------------------|---------------------------------------------------------------------------|------------------------|
| RFC-001 §4.2.3    | Dangling Policy FQN in Action `policy` field                              | High (typo)           |
| RFC-001 §4.2.3    | Projection references undeclared Field                                    | High                  |
| RFC-001 §4.2.3    | Workflow `via` Action not registered                                      | Medium                |
| RFC-001 §6.4      | Plugin kernel version range incompatible                                   | Low                   |
| RFC-001 §A-1.1    | Provider class missing one of the three required interfaces               | Medium                |
| RFC-002 §13.2     | Bound driver doesn't support configured tenancy strategy                   | Low                   |
| RFC-002 §12.1     | Missing PersistenceDriver binding                                          | Medium (first install) |
| RFC-003 §4.3      | Configured isolation strategy not advertised by driver                     | Low                   |
| RFC-003 §5.4      | Tenant in PROVISIONING / ARCHIVED state                                    | Low                   |
| RFC-005 §13       | `PolicyContractViolation` (wrong signature on Policy class)                | Medium                |
| RFC-005 §13       | `PolicyClassNotFound` (PolicyDescriptor's class FQN unresolvable)          | High (typo)           |
| RFC-005 §13       | `PolicyDuplicateRegistration` / `PolicyDuplicateAttachment`                | Low                   |
| RFC-005 §13       | `PolicyReservedNamespace` (plugin used `kernel.*`)                         | Low (if aware)        |
| RFC-005 §12       | Doctor: unreachable Policy (warning)                                       | Medium                |
| RFC-007 §16.2     | `primary_sink` unset or unregistered                                       | Medium (first install) |
| RFC-007 Amend-01  | Sink advertising `preservesElevation: false`                               | Low                   |
| RFC-007 §16.2     | Configured but unknown audit sink reference                                | Medium                |
| RFC-010 §17.1     | Missing ReportingDriver binding when ReportingQuery is exercised           | Medium                |
| Config            | `config/ausus.php` malformed / missing required key                        | High                  |
| Config            | Database migration not run before `ausus:tenant:create`                    | Medium                |
| Effect dispatch   | Action `effect` class not found / wrong interface (per F-V0-02; invented in V0) | High             |

Conservative estimate: a novice writing the slice from scratch encounters **5–12** of these on the first pass. The worked example in RFC-000 §2 is carefully constructed; following it verbatim should produce **0–3** failures (primarily configuration and migration-order issues).

### Comparison

| Platform   | Typical first-trial compile failures |
|------------|--------------------------------------|
| Retool     | 0 (visual builder; no compile)       |
| Filament   | 1–3 (`php artisan make:resource` produces working scaffold) |
| Nova       | 1–3                                  |
| AUSUS V0   | 5–12 for novice; 0–3 for guided      |

### Classification

**Dangerous.**

The error surface is wide. Each error is a learning moment IF the error message is clear and actionable — but the RFC stack specifies error TYPES (closed taxonomies in RFC-002 §12.1, RFC-005 §13, RFC-007 §13, RFC-010 §11.1, RFC-003 Appendix A — totaling 60+ error types) without specifying error-message quality. Quality is implementation-dependent.

In the worst realistic case, a developer hits 8 compile failures averaging 10 minutes each to diagnose and fix: 80 minutes of debugging. RFC-000 §9's 90-minute debugging budget covers this but leaves no slack. With ambiguous error messages, the budget overruns.

Not Adoption blocker because failures are signal (the platform refuses to ship broken code); but a first-time author repeatedly hitting compile-fail loops on what feels like trivial work erodes confidence.

---

## Aggregate scorecard

| # | Measurement                                                  | Number              | Classification     |
|---|--------------------------------------------------------------|---------------------|---------------------|
| 1 | Time-to-first-screen                                         | ~8 hours            | **Dangerous**       |
| 2 | Concepts introduced before first render                      | ~100                | **Adoption blocker**|
| 3 | Lines of DSL                                                  | 85                  | **Acceptable**      |
| 4 | Lines of infrastructure code                                  | 330                 | **Dangerous**       |
| 5 | Mandatory imports                                             | 38                  | **Dangerous**       |
| 6 | FQNs written manually (unique / textual)                      | 19 / ~35            | **Dangerous**       |
| 7 | Reserved-kernel concepts exposed to plugin author             | 12                  | **Dangerous**       |
| 8 | Compile-time failures before happy path (novice / guided)     | 5–12 / 0–3          | **Dangerous**       |

### Band distribution

| Band              | Count | Measurements                                  |
|-------------------|-------|-----------------------------------------------|
| Excellent         | 0     | —                                             |
| Acceptable        | 1     | #3 lines of DSL                               |
| Dangerous         | 6     | #1, #4, #5, #6, #7, #8                        |
| Adoption blocker  | 1     | #2 concepts before first render               |

### Single-axis summary

- **Strongest signal**: measurement 2 (~100 concepts before first render) is the only Adoption-blocker-band finding. This is the single most consequential DX measurement in the audit.
- **Six Dangerous-band findings** cluster on per-Entity boilerplate (infrastructure LOC, imports, manual FQNs) and on operational surface (time-to-first-screen, reserved concepts, compile failures). The pattern is consistent: the kernel is rigorously specified at the cost of plugin-author cognitive load.
- **One Acceptable-band finding** (DSL line count) suggests the DSL itself, once authored, is not the bottleneck; the bottleneck is everything around it.
- **Zero Excellent-band findings.** No measurement of V0 DX is better than industry norm. The platform's DX is, on every measured axis, equal to or worse than comparable Laravel-ecosystem platforms.

### Findings (no remediation proposed; per brief)

1. The conceptual load before first render (measurement 2) is the dominant DX risk.
2. Per-Entity infrastructure boilerplate (measurements 4, 5, 6) is consistently 2–4× peer norms; not catastrophic individually but compounding.
3. Operational error surface (measurements 1, 8) is wide enough that the first-screen budget is dominated by environment setup and debugging, not by writing the slice.
4. Reservation surface (measurement 7) is bounded and predictable but spread across multiple namespace conventions and multiple RFCs.
5. DSL itself (measurement 3) is the platform's only DX bright spot in this audit. The fluent declaration succeeds at being compact and expressive.

End of audit.
