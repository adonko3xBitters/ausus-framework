# RFC-001-amendment-02

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Proposed                                               |
| Authors       | architect, kernel, challenger                          |
| Date          | 2026-05-18                                             |
| Amends        | RFC-001 Draft-03 (which already incorporates Amendment-01) |
| Scope         | F-1A2-1, F-1A2-2, F-1A2-3, F-1A2-4 (RFC-010 kernel-surface gaps only) |

Strictly scoped to the four findings the brief lists. No other RFC-001 clauses are touched. Section numbers continue from Amendment-01 (A-1.1 … A-1.10 → A-1.11 … A-1.14).

---

## A-1.11 F-1A2-1 — `kernel.reporting.query` ActionFqn legality

### Restatement

RFC-010 §7.2 emits Audit Entries with `actionFqn = "kernel.reporting.query"`. Amendment-01 §A-1.2 reserved the `kernel.*` Action namespace in the context of Policy evaluation only:

> "Policies attached as `FieldDescriptor.visibility` MUST accept the sentinel Action FQN `kernel.field.read`. The kernel reserves the `kernel.*` Action namespace for sentinels; plugins MUST NOT register Actions in this namespace."

The reservation was authored to cover the `kernel.field.read` sentinel used in Policy `Action` parameters. It does not explicitly authorize synthetic `actionFqn` values in the AuditEntry shape (§A-1.8). RFC-007 Amendment-01 §A-7.4 enumerated such synthetics from RFC-007's side, but the kernel surface itself does not acknowledge audit-side sentinels.

Strict-reader risk: a plugin author observing only RFC-001 cannot determine whether `kernel.reporting.query`, `kernel.entity.read`, `kernel.tenant.elevate`, `kernel.audit.dead_letter`, etc., are legal AuditEntry `actionFqn` values. The kernel must say.

### Resolution

Clarify §A-1.2's namespace reservation to explicitly cover both forms of kernel-owned sentinels: Policy-evaluation sentinels AND audit-side synthetic ActionFqn values. One sentence appended; no namespace expansion (kernel.* was already reserved); no new error type.

### Sections amended

- §A-1.2 (Amendment-01, §2.5 amendment): extend the reservation clause.

### Replacement normative text

**Replace the §2.5 append clause from Amendment-01 §A-1.2 with:**

> Policies attached as `FieldDescriptor.visibility` MUST accept the sentinel Action FQN `kernel.field.read`. The kernel reserves the `kernel.*` Action namespace for kernel-owned sentinels of two kinds: (a) Policy-evaluation sentinels supplied as the `Action` argument to a Policy (e.g., `kernel.field.read`), and (b) synthetic ActionFqn values emitted on AuditEntries by kernel-internal subsystems for operations that have no registered Action (e.g., reporting queries, read audits, lifecycle events). Plugins MUST NOT register Actions in this namespace, and plugin code MUST NOT construct AuditEntries with `actionFqn` values in this namespace. Kernel subsystems (the Reporting subsystem, the Audit subsystem, the Tenancy subsystem) are the only authorized producers.

### Downstream RFCs impacted

- RFC-010 §7.2: ratified at the kernel-surface level. `kernel.reporting.query` is now explicitly legal.
- RFC-007 §15.1 (`kernel.entity.read`) and RFC-007 §11.4 / §12.5 (`kernel.audit.dead_letter`, `kernel.audit.confirm_lost`, etc.): retroactively ratified.
- RFC-003 §10.2 (`kernel.tenant.elevate`, `kernel.tenant.elevate_close`): retroactively ratified.
- RFC-007 Amendment-01 §A-7.4: the enumerated sentinel list is consistent; no further change required.

### Challenger attack

- **Layer violations:** none. Clarifies an existing reservation; no new layer.
- **Hidden runtime coupling:** none. No new component; no new construction path. Kernel subsystems were already producing these sentinels; the amendment names them as authorized.
- **SemVer surface expansion:** zero new public surface. The reservation already covered `kernel.*`; this clause makes the two-form interpretation explicit. Plugins that previously did not register or emit `kernel.*` are unaffected; plugins that did were already non-conforming under A-1.2's "plugins MUST NOT register Actions in this namespace."
- **Tenancy bypass:** none. AuditEntries carrying sentinel `actionFqn` values still carry the full Tenant context (§A-1.8 + RFC-003 §10.5).
- **Audit bypass:** strengthens. Without this clarification, a plugin could ambiguously claim it was emitting `kernel.*` `actionFqn` values for legitimate internal reasons; the explicit prohibition closes that door. Synthetic identities for plugin-defined Actions become impossible (constraint satisfied).

---

## A-1.12 F-1A2-2 — `audited_reads` Entity declaration

### Restatement

RFC-001 §8.3 (Amendment-01 §A-1.6 incorporated) states:

> "Reads are not audited by default. An Entity may opt into read auditing."

RFC-007 §15 and RFC-010 §7 build on this with a concrete `audited_reads: true` flag declared on the Entity. The flag's existence is implied by §8.3's opt-in language, but §2.1 (Entity) does not enumerate it. Plugin authors writing Entity descriptors against RFC-001 alone cannot know how to opt in. The Compiler validating Entity descriptors against RFC-001 has no rule for the flag's shape or invariants.

Strict-reader risk: read-audit emission depends on a flag the canonical Entity spec does not name. Either RFC-001 acknowledges the flag, or RFC-010 / RFC-007 are emitting audit on the basis of an unspecified Entity property.

### Resolution

Add a normative `audited_reads: bool` declaration to the Entity descriptor in §2.1. Default `false`. The declaration is the sole mechanism for Entity-level read-audit opt-in. Mutation-audit semantics remain strictly stronger (mutation is unconditional, read is opt-in).

### Sections amended

- §2.1 (Entity): add §2.1.3 (Read-audit opt-in invariant).

### Replacement normative text

**Append new §2.1.3 to §2.1 (after the existing §2.1.1 identity invariants and §2.1.2 tenant binding invariants):**

> **§2.1.3 Read-audit opt-in (normative).**
>
> 1. An Entity descriptor MAY declare an optional `audited_reads` attribute of type boolean. Default: `false`.
> 2. When `audited_reads: true`, every read operation on the Entity (find, findMany, iterate, fetchRelated, and inclusion as a touched Entity in a ReportingDriver query under RFC-010) MUST emit an AuditEntry. The emission contract is specified by RFC-007 §15 for Repository reads and RFC-010 §7 for reporting reads.
> 3. Read-audit emission is **strictly weaker** than mutation-audit emission:
>    - Mutation audit is unconditional and cannot be opted out (RFC-001 §8.3, Amendment-01 §A-1.6). Read audit is per-Entity opt-in.
>    - Mutation audit carries a real instance Subject (SingleSubject per §A-1.8) or a real BulkSubject. Read audit MAY carry a synthetic Subject when no instance is meaningful (§A-1.13 / §2.1.1.6).
>    - Primary audit sink failure aborts the operation in both cases (RFC-001 §A-1.6, RFC-007 §15.3), so the failure surface is symmetric; the opt-in distinction is the only asymmetry.
> 4. Changing an Entity's `audited_reads` value is a kernel-graph change subject to the standard compile + cache invalidation lifecycle (§4.2, §4.3). It is NOT a Tenant override (§A-1.3 forbids changing existing Field semantics; same logic applies — Entity-level read-audit policy is a base-graph property).

### Downstream RFCs impacted

- RFC-007 §15.1 (typo in the original draft references "FieldDescriptor" where "EntityDescriptor" was intended): retroactively ratified as Entity-level. RFC-007 Amendment-01 should clarify in its next iteration; no normative change required to RFC-007 itself.
- RFC-010 §7.1, §7.2: ratified — the `audited_reads: true` flag now has a normative kernel declaration to reference.

### Challenger attack

- **Layer violations:** none. The flag lives on the EntityDescriptor (L0); enforcement lives at L2 (Repository, ReportingDriver via Auditor). Standard layering.
- **Hidden runtime coupling:** none new. The opt-in mechanism was already implied by §8.3; this amendment formalizes the property declaration. Existing Entity descriptors omitting the flag default to `false` — backward-compatible.
- **SemVer surface expansion:** one new optional boolean attribute on EntityDescriptor with a `false` default. Existing plugins do not need recompilation; no existing field semantics change. **Minimal additive expansion.**
- **Tenancy bypass:** the flag is per-Entity, not per-Tenant. Tenant overrides cannot toggle it (§2.1.3.4). This prevents a Tenant from silently disabling its own read auditing — a compliance-positive invariant.
- **Audit bypass:** strengthens by formalizing the opt-in path. Without this clause, the flag's existence was inferential; with it, the Compiler can validate the declaration and `ausus:doctor` can list audited-reads Entities. Mutation-audit guarantees remain strictly stronger (mutation: unconditional, real Subject; read: opt-in, synthetic Subject allowed).

---

## A-1.13 F-1A2-3 — synthetic `identity_handle` for kernel-internal Subjects

### Restatement

§2.1.1.1 requires every Entity instance to have an opaque, immutable identity handle. §2.1.1.2 states: "The identity handle is produced by the active Persistence Driver or by the application, never by the Kernel itself." §2.1.1.4 makes the identity handle a required component of the canonical cross-Entity reference (`(tenant_id, entity_fqn, identity_handle)`), and §A-1.8 requires every SingleSubject to carry one.

RFC-010 §7.2 emits AuditEntries whose `subject.identity_handle = "kernel.reporting.aggregate"` — a kernel-produced synthetic identifier for queries that have no single instance Subject. This **directly contradicts** §2.1.1.2's "never by the Kernel itself" clause.

Two paths to resolution: (a) permit kernel-produced synthetic identity handles in a narrowly-scoped exception, or (b) extend the AuditEntry Subject shape to allow `null` identity handles. Path (b) is structurally larger (changes the §A-1.8 shape, breaks consumers that assume non-null identity_handle in SingleSubject). Path (a) is narrower (one exception clause; existing SingleSubject shape preserved; consumers see a string-typed identity_handle whose value is recognizably kernel-owned).

### Resolution

Adopt path (a). Add a narrowly-scoped exception to §2.1.1.2 permitting kernel-reserved synthetic identity handle values in the `kernel.*` namespace, **only** in AuditEntry Subjects where no instance Subject exists. Reserve the `kernel.*` namespace for identity handles symmetrically with §A-1.2's Action namespace reservation. Plugins cannot produce or claim synthetic identities in this namespace.

### Sections amended

- §2.1.1 (Entity identity invariants): replace §2.1.1.2; add §2.1.1.6 (synthetic-identity exception).

### Replacement normative text

**Replace §2.1.1.2 (the current text reading "The identity handle is produced by the active Persistence Driver or by the application, never by the Kernel itself...") with:**

> 2. The identity handle is produced by the active Persistence Driver or by the application, never by the Kernel itself. The sole exception is the narrow case in §2.1.1.6.

**Append new §2.1.1.6 (after the existing five identity invariants):**

> 6. **Synthetic identity-handle exception (normative).** Kernel-internal subsystems (Reporting, Audit, Tenancy) MAY emit AuditEntries whose `subject.identity_handle` is a kernel-reserved synthetic value in the `kernel.*` namespace, **only** when the operation has no real instance Subject (e.g., a reporting query aggregating across rows). Such synthetic values:
>    - MUST conform to §2.1.1.3 (serializable, string-encoded).
>    - MUST be enumerated in the kernel surface (Appendix A of RFC-007 lists them).
>    - MUST NOT appear in any persistence-side context (Persistence Driver inputs/outputs, Relation endpoints, Action subject arguments). Their sole authorized use site is the AuditEntry Subject.
>    - MUST NOT be produced by plugin code. The `kernel.*` namespace is reserved for kernel-produced synthetic identity handles symmetrically with the §A-1.2 ActionFqn reservation. Plugins constructing identity handles in this namespace are non-conforming and rejected at registration / at runtime by the bound Persistence Driver (which knows it did not produce these handles).
>    - Cross-Entity references (§2.1.1.4) carrying a synthetic identity handle are valid only inside an AuditEntry Subject. Persistence Drivers MUST reject such references in any other context with `InvalidIdentity` (RFC-002 §12.1).

### Downstream RFCs impacted

- RFC-010 §7.2 (`kernel.reporting.aggregate` synthetic handle): retroactively ratified.
- RFC-007 §2.2.7 (RFC-007 Amendment-01 §A-7.4 reserved synthetic `identity_handle` values): retroactively ratified at kernel surface.
- RFC-002 §6 (Identity): no change required — `generateIdentity` continues to be driver-produced. The synthetic exception lives outside Persistence Driver's scope.

### Challenger attack

- **Layer violations:** none. The exception is narrowly scoped to AuditEntry Subjects. Persistence Driver layer (L3) is explicitly forbidden from accepting synthetic handles in non-audit contexts (§2.1.1.6 bullet 4). Relation endpoints, Action arguments, Repository `Reference` instances cannot carry synthetics.
- **Hidden runtime coupling:** none. No new runtime component; the exception authorizes existing kernel-subsystem behavior that was previously contradictory with §2.1.1.2.
- **SemVer surface expansion:** one new normative exception clause (§2.1.1.6). One namespace reservation (`kernel.*` for identity handles) added symmetrically with the existing §A-1.2 reservation. Existing plugins do not produce synthetic identity handles; backward-compatible. Existing AuditEntry consumers reading `subject.identity_handle` as an opaque string continue to do so — the string is now allowed to be a `kernel.*` sentinel, but the type and round-trip semantics are unchanged.
- **Tenancy bypass:** none. Synthetic-handle AuditEntries still carry `subject.tenant_id` (per §A-1.8); the Tenant binding is unaffected. Synthetic handles cannot be used to misrepresent cross-Tenant operations because the Tenant slot is independent.
- **Audit bypass:** strengthens. Without this clause, RFC-010's reporting audit emissions were silently non-conforming; this amendment makes them conforming and bounds the synthetic surface narrowly enough that plugins cannot impersonate kernel subsystems (constraint satisfied: "Synthetic identities must be impossible for plugin-defined Actions unless explicitly kernel-owned").

---

## A-1.14 F-1A2-4 — `BulkSubject.affected_count` semantics under multi-Entity expansion

### Restatement

§A-1.8 defines `BulkSubject` as:

```
BulkSubject := (tenant_id, entity_fqn, affected_count, sample_handles)
```

and requires: "`affected_count` MUST be the exact number of Subjects mutated by the invocation."

For single-Entity MaintenanceActions, "the invocation" mutates Subjects of exactly one Entity, so `affected_count` is unambiguous: it is both "the Entity's count" and "the invocation's total count" — the two are equal.

For multi-Entity MaintenanceActions (RFC-010 §9.6, RFC-007 Amendment-01 §A-7.1), the two interpretations diverge:

- **Reading A**: `affected_count` = total Subjects mutated across all Entities = sum of `outputs.bulk_entities[*].affected_count`.
- **Reading B**: `affected_count` = the primary Entity's count = `outputs.bulk_entities[<primary>].affected_count`.

RFC-007 Amendment-01 §A-7.1 commits to Reading B: "The primary entry's `affected_count` MUST equal `subject.affected_count`." RFC-001 §A-1.8's "exact number of Subjects mutated by the invocation" is ambiguous between A and B.

Ambiguity proven. Amendment required.

### Resolution

Clarify §A-1.8 to fix Reading B at the kernel surface: `subject.affected_count` refers to the primary Entity (the one whose FQN equals `subject.entity_fqn`); the total across all affected Entities for multi-Entity MaintenanceActions is computed by consumers via `outputs.bulk_entities[*].affected_count` sum, per RFC-007 Amendment-01 §A-7.1.

This aligns RFC-001 with RFC-007 Amendment-01 without changing BulkSubject's shape, without breaking single-Entity consumers (for whom the primary's count equals the total), and without requiring any consumer migration for legitimate uses (no consumer of pre-RFC-010 AuditEntries observed multi-Entity MaintenanceActions, because they did not exist).

### Sections amended

- §A-1.8 (Amendment-01, §8.3 Audit Entry shape): clarify `BulkSubject.affected_count` semantics.

### Replacement normative text

**Replace the §8.3 paragraph in Amendment-01 §A-1.8 that reads "MaintenanceActions (§2.4.1) MUST emit `Subject = BulkSubject` and `InvocationClass = Maintenance`. `affected_count` MUST be the exact number of Subjects mutated by the invocation; a partial-failure count (per F-D35, out of scope of this amendment) is governed by a follow-up RFC." with:**

> MaintenanceActions (§2.4.1) MUST emit `Subject = BulkSubject` and `InvocationClass = Maintenance`. The `subject.entity_fqn` identifies the **primary** Entity affected (conventionally the largest-affected); `subject.affected_count` MUST be the exact number of Subjects of the primary Entity mutated by the invocation; `subject.sample_handles` MUST be drawn from the primary Entity's mutated Subjects.
>
> For multi-Entity MaintenanceActions whose effect mutates more than one Entity FQN, the full per-Entity breakdown lives in `outputs.bulk_entities` per RFC-007 Amendment-01 §A-7.1. The list includes the primary as one entry among possibly several. Consumers computing "total Subjects mutated by the invocation" MUST sum across `outputs.bulk_entities[*].affected_count`; they MUST NOT use `subject.affected_count` as the total.
>
> For single-Entity MaintenanceActions, `subject.affected_count` equals the invocation's total, and `outputs.bulk_entities` is a one-element list whose only entry matches the Subject.
>
> A partial-failure count (per F-D35) is governed by a follow-up RFC.

### Downstream RFCs impacted

- RFC-007 Amendment-01 §A-7.1: ratified at the kernel surface. The previously-ambiguous interpretation is now locked to Reading B.
- RFC-010 §9.6, §10.2: ratified.
- No other downstream RFCs affected.

### Challenger attack

- **Layer violations:** none. The clause is a clarification of an existing payload-shape semantic.
- **Hidden runtime coupling:** none. The Auditor and the MaintenanceAction effect were already producing payloads consistent with Reading B (per RFC-007 Amendment-01); this clarification documents kernel intent.
- **SemVer surface expansion:** zero new public surface. Pre-RFC-010 single-Entity MaintenanceActions continue to behave identically (primary = only Entity = total). Post-RFC-010 multi-Entity MaintenanceActions are unlocked under Reading B without ambiguity. **No expansion; closes ambiguity.**
- **Tenancy bypass:** none. All entries in `outputs.bulk_entities` are within `subject.tenant_id` per RFC-002 §13.1 (bulk operations cannot span Tenants in a single Invoker transaction). No bypass surface.
- **Audit bypass:** strengthens. Previously, a consumer reading `subject.affected_count` on a multi-Entity MaintenanceAction would silently observe an under-count (only the primary's). The clarification + `outputs.bulk_entities` requirement (already normative per RFC-007 Amendment-01) ensures the full picture is auditable. Existing consumers reading only `affected_count` continue to receive a valid count (the primary's); consumers needing totals migrate to summing.

---

## Determination

ACCEPT.

All four findings resolve with minimal, additive, kernel-surface clarifications:

| Finding   | New layers | New runtime components | Namespace redesign | Existing-plugin source compat | Existing-AuditEntry-consumer compat | Mutation > Read audit | Plugin synthetic-identity prevention |
|-----------|------------|------------------------|--------------------|-------------------------------|--------------------------------------|------------------------|--------------------------------------|
| A-1.11    | 0          | 0                      | None (kernel.* already reserved; clause clarified) | Yes | Yes (no shape change) | n/a | Yes (explicit plugin prohibition) |
| A-1.12    | 0          | 0                      | None | Yes (default `false`) | Yes (no shape change) | Yes (read is opt-in, mutation is unconditional) | n/a |
| A-1.13    | 0          | 0                      | Symmetric namespace reservation for identity handles in `kernel.*` (same shape as A-1.2 reservation) | Yes (plugins do not use synthetic handles) | Yes (string remains opaque) | n/a | Yes (kernel-only authorized producers) |
| A-1.14    | 0          | 0                      | None | Yes | Yes (single-Entity unchanged; multi-Entity is new behavior) | n/a | n/a |

No new layers. No new runtime components. No broad namespace redesign — `kernel.*` was already reserved for ActionFqn per A-1.2, and A-1.13 extends the same kernel-ownership pattern symmetrically to identity handles, which is the smallest possible change. Existing plugins remain source-compatible (no plugin in V1 emits `kernel.*` ActionFqn or synthetic handles; no plugin reads `audited_reads` — only the kernel does). Existing AuditEntry consumers remain backward-compatible: A-1.11 changes no shape; A-1.12 changes no shape; A-1.13 keeps `identity_handle` as an opaque string; A-1.14 is a semantic clarification with no shape change.

The amendment satisfies the hard constraints in full: read-audit opt-in is strictly weaker than mutation-audit unconditionality (A-1.12.3); synthetic identities for plugin-defined Actions are explicitly forbidden (A-1.11 final clause; A-1.13 bullet 4); the kernel.* namespace remains kernel-owned across both Actions and identity handles.

Folding the per-finding replacement text into RFC-001 produces RFC-001 Draft-04. No structural redesign; no Amendment-01 clause overturned; no Appendix D finding scope-creep.