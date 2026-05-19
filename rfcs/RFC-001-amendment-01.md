# RFC-001-amendment-01

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Proposed                                               |
| Authors       | architect, domain, kernel, challenger                  |
| Date          | 2026-05-17                                             |
| Amends        | RFC-001 Draft-02                                       |
| Scope         | F-D4, F-D9, F-D18, F-D20, F-D24, F-D28, F-D31, F-D34, F-D37 |

This amendment is strictly scoped to the nine findings listed. All other Appendix D findings remain open. RFC-001 §0–§12 and Appendices A–D are unmodified except where this document explicitly replaces normative text.

---

## A-1.1 F-D4 — Plugin provider vs Laravel service provider

**Restatement.** §6.1 names a `provider` that "must implement `Plugin` and `PluginLifecycle`." §5.1 says each plugin "ships its own service provider, which calls into the Registry during `register()`." Relationship between the two objects is unspecified.

**Resolution.** A plugin's `provider` is a single Laravel `ServiceProvider` subclass that additionally implements the kernel contracts `Plugin` and `PluginLifecycle`. One class, three interfaces. No second object exists.

**Sections amended.** §5.1, §6.1.

**Replacement normative text.**

§5.1 (final bullet replaced):

> Each Plugin ships exactly one provider class. The provider class MUST extend `Illuminate\Support\ServiceProvider` and MUST implement both `Ausus\Kernel\Contracts\Plugin` and `Ausus\Kernel\Contracts\PluginLifecycle`. Laravel's `register()` and `boot()` methods on the provider are bound to the AUSUS plugin lifecycle as follows: Laravel `register()` MAY perform container bindings only; AUSUS descriptor registration MUST occur in the `Plugin::boot()` method defined by the AUSUS contract, invoked by the kernel after Laravel boot completes.

§6.1 (sentence following the JSON example replaced):

> The `provider` value MUST name a class satisfying §5.1's three-interface requirement. The kernel rejects any plugin whose `provider` class does not implement all three interfaces at discovery time (§6.2).

**Downstream RFCs impacted.** RFC-011 (DSL surface) — DSL declarations occur inside `Plugin::boot()`, not inside Laravel `register()`.

**Challenger attack.**
- Layer violations: none. Provider is L7; lifecycle calls are inbound from L2.
- Hidden runtime coupling: L7 plugins are coupled to a Laravel framework class (`ServiceProvider`), not only to Laravel contracts. §3.2.4 limits **the Kernel** to Laravel contracts; it does not constrain L7. Acceptable.
- SemVer surface expansion: nil. Clarifies existing surface.
- Tenancy bypass: none.
- Audit bypass: none.

---

## A-1.2 F-D9 — Field-level Policies undefined

**Restatement.** "Field-level Policies" are referenced by §1.1.9, §3.2.6, and §4.5. No primitive in §2 defines them. Plugin authors cannot declare them; ReportingDrivers and the Presentation layer cannot enforce them.

**Resolution.** Add one optional attribute to `FieldDescriptor`: `visibility`, a Policy FQN. The kernel defines one sentinel Action FQN `kernel.field.read`. A Field with a `visibility` Policy is filtered from any read surface (Projection output, ReportingDriver result) whenever the Policy, evaluated with `(Actor, kernel.field.read, Subject, Context)`, returns `Deny` or fails to return `Permit`. Fields without `visibility` are unrestricted at the field level (Projection-level and Tenant-level Policies still apply).

**Sections amended.** §2.2, §2.5, §1.1.9, §3.2.6, §4.5.

**Replacement normative text.**

§2.2 (append two clauses):

> A Field descriptor MAY declare an optional `visibility` attribute. The value MUST be a Policy FQN. When present, the Policy is evaluated for every read surface that includes the Field, with the Action argument set to the kernel-defined sentinel FQN `kernel.field.read` and the Subject argument set to the Entity instance the Field belongs to (or `null` when the Field is read in a metadata-only context).
>
> A Field without a `visibility` attribute is not filtered at the field level. Projection-level Policies (§2.7) and Tenant-level Policies (§8.2) apply regardless of field-level visibility.

§2.5 (append one clause):

> Policies attached as `FieldDescriptor.visibility` MUST accept the sentinel Action FQN `kernel.field.read`. The kernel reserves the `kernel.*` Action namespace for sentinels; plugins MUST NOT register Actions in this namespace.

§1.1.9 (replace text after the contract name):

> The `ReportingDriver` contract — a read-only, cross-Entity query interface that respects (a) `FieldDescriptor.visibility` Policies for every Field included in a query result and (b) Tenant scope. The Kernel owns the contract; implementations ship as plugins.

§3.2.6 (replace):

> Reporting drivers (L3) are read-only. They MUST enforce `FieldDescriptor.visibility` Policies for every Field they expose in a query result, and they MUST enforce Tenant scope. They MUST NOT provide a path to mutation.

§4.5 (replace third bullet):

> The Presentation layer is responsible for: resolving the named Projection, applying UI hints, evaluating Tenant overrides, evaluating each exposed Field's `visibility` Policy (where declared) against the requesting Actor, and emitting JSON conforming to the ViewSchema wire format. Fields whose `visibility` Policy does not return `Permit` MUST be omitted from the emitted ViewSchema.

**Downstream RFCs impacted.** RFC-004 (ViewSchema wire format must define omission semantics for filtered Fields), RFC-005 (Policy combinator must define behavior for the `kernel.field.read` sentinel), RFC-010 (ReportingDriver contract specification).

**Challenger attack.**
- Layer violations: `visibility` lives on FieldDescriptor (L0); evaluated by L3 and L5 against L0 contracts. None.
- Hidden runtime coupling: every read of a `visibility`-bearing Field triggers a Policy evaluation. Fields without the attribute incur zero cost. Acceptable.
- SemVer surface expansion: one new optional attribute on `FieldDescriptor`; one reserved Action namespace (`kernel.*`); one sentinel FQN. Bounded.
- Tenancy bypass: none. Policy receives Tenant in Context (§2.5).
- Audit bypass: none. Reads are not audited by default (§8.3); this amendment does not change that.

---

## A-1.3 F-D18 — Tenant override scope undefined

**Restatement.** §4.4 and §7.2 reference Tenant overrides on "Fields, Policies, Workflows, Projections" without constraining what may be overridden. The Compiler's coherence validation (§4.2.3) cannot be preserved under arbitrary overrides.

**Resolution.** Tenant overrides are restricted to **additive** and **strictly-narrowing** operations against the compiled base graph. The set of permitted operations is enumerated and exhaustive. Any operation outside the set is rejected at override-application time.

**Sections amended.** §4.4 (insert §4.4.1 immediately after the existing bullets), §7.2.

**Replacement normative text.**

§4.4.1 (new subsection, normative):

> **Tenant override scope.** A Tenant override MAY perform only the following operations against the compiled base graph:
>
> 1. Add a new Field to an Entity. The added Field MUST satisfy §5.8 invariants and MUST declare a Field Type registered in the base graph.
> 2. Add a new Projection to an Entity.
> 3. Add a new Policy descriptor and attach it to an existing Action, Projection, or Field `visibility` slot. Additional Policies compose with base Policies under §2.5's combinator. The combinator's `Deny > Permit > Abstain` precedence guarantees that added Policies can only narrow access.
> 4. Add a new Workflow transition whose source state already exists in the base Workflow.
>
> A Tenant override MUST NOT:
>
> 1. Remove or rename any Field, Action, Relation, Policy, Workflow, Workflow state, or Projection declared in the base graph.
> 2. Change the type, persistence binding, nullability, or uniqueness of an existing Field.
> 3. Detach a Policy from an Action, Projection, or Field.
> 4. Add a Relation to an Entity in a different Tenant unless both endpoints are `system` (§2.1.2.3).
>
> Field-level visibility narrowing per Tenant is expressed by adding a Policy to the existing Field's `visibility` slot (operation 3 above), not by removing the Field.

§7.2 (replace third bullet):

> Per-Tenant overrides apply at resolution time, not compile time. Override descriptors are validated against the compiled base graph at the time of override installation (not at resolution time). An override that violates §4.4.1 is rejected at installation; the resolver never sees an invalid override.

**Downstream RFCs impacted.** RFC-003 (Tenant isolation strategies must specify override storage and the installation-time validator), RFC-010 (ReportingDriver must respect added Field-level visibility Policies per A-1.2).

**Challenger attack.**
- Layer violations: override storage lives at L3 (Tenancy plugin); override descriptors are L0-shaped. None.
- Hidden runtime coupling: validation runs at installation, not on every resolve. Resolve-time cost is the merge only.
- SemVer surface expansion: introduces the term "override operation" with four allowed and four forbidden cases. Bounded.
- Tenancy bypass: forbidden operation set includes cross-Tenant Relation creation (operation 4 of the negative list).
- Audit bypass: override installation is itself a domain operation; per §8.3 it is auditable as an Action invoked through the Tenancy plugin. Specified by RFC-003.

---

## A-1.4 F-D20 — Action executor unnamed

**Restatement.** §8.2 describes the Tenant-check → Policy-chain → Workflow-guard → Action-effect → Audit-emit sequence but assigns ownership to no component. §1.1 does not name an executor. §5.2 has no contract for it.

**Resolution.** Add `Invoker` to the kernel contracts. The Invoker is the sole component permitted to execute the §8.2 chain. It lives at L2 (Runtime).

**Sections amended.** §1.1 (add item 10), §5.2 (add to headline contracts), §8.2 (insert §8.2.1).

**Replacement normative text.**

§1.1 (new item 10):

> 10. **The `Invoker`** — the L2 Runtime component that executes the §8.2 chain. The Invoker is the sole authorized path for invoking any Action.

§5.2 (add to headline contracts list):

> - `Invoker`

§8.2.1 (new subsection, normative):

> **Invoker contract.** Every Action invocation MUST be executed by the `Invoker`. The Invoker's signature is `invoke(Actor, ActionFqn, Subject | null, inputs) → output`. The Invoker performs, in order: (1) Tenant Context check, (2) Policy chain (§8.2), (3) Workflow guard (§8.2; skipped only for `MaintenanceAction` per §2.4.1), (4) Action effect (delegated to the registered Action implementation), (5) Audit emission (§8.3; subject to A-1.6 sink-failure semantics).
>
> The API Surface (L4) MUST invoke Actions only through the `Invoker`. L7 plugins MUST NOT call the `Invoker` directly; plugins declare Actions and Actions are invoked on their behalf by L4 or by another Action's effect. Action effects MAY invoke other Actions through the `Invoker`; recursive invocation is subject to the full §8.2 chain on every call.

**Downstream RFCs impacted.** RFC-002 (PersistenceDriver contract must specify how Action effects acquire repositories from inside the Invoker), RFC-006 (Workflow execution must specify how transitions are dispatched through the Invoker), RFC-010 (`MaintenanceAction` execution path uses the same Invoker).

**Challenger attack.**
- Layer violations: Invoker at L2; called by L4 and by Action effects (which run inside the Invoker, therefore at L2 for the duration of execution). Consistent with §3.2.8.
- Hidden runtime coupling: the Invoker concentrates the §8.2 chain in one place. Coupling is the design intent.
- SemVer surface expansion: one new contract. Bounded.
- Tenancy bypass: impossible — Tenant check is step 1 of the chain.
- Audit bypass: impossible — Audit emission is step 5; sink-failure semantics governed by A-1.6.

---

## A-1.5 F-D24 — Field-level Policy evaluation contract unspecified

**Restatement.** Restates F-D9. §4.5 invokes "Field-level Policies"; §2.5's Policy signature includes an `Action` argument; the value of that argument for Field reads is unspecified.

**Resolution.** Resolved by A-1.2. The `Action` argument for Field-level visibility evaluation is the kernel sentinel FQN `kernel.field.read`.

**Sections amended.** None beyond A-1.2.

**Replacement normative text.** None beyond A-1.2.

**Downstream RFCs impacted.** As A-1.2.

**Challenger attack.** As A-1.2. No additional surface.

---

## A-1.6 F-D28 — Audit sink failure behaviour unspecified

**Restatement.** §8.3 enforces Audit Entry emission but does not specify behaviour when a sink write fails. Mutation success under audit failure is undefined.

**Resolution.** Audit sinks are an ordered list with exactly one **primary** sink and zero or more **secondary** sinks. Primary sink failure aborts the mutation and rolls back the transaction. Secondary sink failure does not abort the mutation; the failure is queued for retry on the secondary sink only.

**Sections amended.** §5.4, §8.3.

**Replacement normative text.**

§5.4 (replace the `audit:` block):

```
audit:
  primary_sink: ausus.audit.database
  secondary_sinks: []
  redact: []
```

§8.3 (append four clauses):

> Audit sinks are ordered. Exactly one sink MUST be designated `primary`; zero or more sinks MAY be designated `secondary`.
>
> If the primary sink fails to acknowledge the Audit Entry write, the Invoker (§8.2.1) MUST abort the Action: the Action's effect is rolled back through the Persistence Driver's transaction contract, and the Invoker returns an error. No mutation is observable.
>
> If a secondary sink fails to acknowledge the write, the mutation MUST succeed; the failed write MUST be queued for retry on the same secondary sink. Secondary-sink retry semantics are owned by the sink implementation.
>
> A configuration with no primary sink is a boot-time error; `ausus:doctor` MUST detect and report it.

**Downstream RFCs impacted.** RFC-002 (Persistence Driver must expose a transaction contract that the Invoker can roll back when primary audit fails), RFC-007 (Audit sink contract must specify acknowledgement semantics and retry queue interface).

**Challenger attack.**
- Layer violations: none. Sink ordering is config-level; enforcement is in the Invoker.
- Hidden runtime coupling: every mutating Action now depends on the primary sink's availability. Intentional and required for compliance.
- SemVer surface expansion: audit sink contract gains an acknowledgement requirement and a `primary | secondary` distinction. Bounded.
- Tenancy bypass: none.
- Audit bypass: strengthens guarantees; rules out "mutation without audit" entirely for the primary sink.

---

## A-1.7 F-D31 — Tenant override coherence validation unspecified

**Restatement.** Restates F-D18. RFC-001 does not specify who validates a per-Tenant merged graph or when validation runs.

**Resolution.** Resolved by A-1.3. Validation runs at override-installation time against the compiled base graph; the resolver never sees an invalid override.

**Sections amended.** None beyond A-1.3.

**Replacement normative text.** None beyond A-1.3.

**Downstream RFCs impacted.** As A-1.3 (RFC-003 owns the installation-time validator implementation).

**Challenger attack.** As A-1.3. No additional surface.

---

## A-1.8 F-D34 — Audit Entry shape contradicts MaintenanceAction count

**Restatement.** §8.3 specifies Audit Entry with a singular "canonical Subject reference per §2.1.1.4." §2.4.1 and §8.3 specify that `MaintenanceAction` entries include "the count of affected Subjects." A scalar and a count are incompatible shapes.

**Resolution.** The Audit Entry's Subject slot is a sum type: `SingleSubject | BulkSubject`. Standard Actions emit `SingleSubject`. MaintenanceActions emit `BulkSubject`.

**Sections amended.** §8.3.

**Replacement normative text.**

§8.3 (replace the opening bullet through the MaintenanceAction bullet with the following):

> Every mutating Action emits an Audit Entry with the following shape:
>
> ```
> AuditEntry := (
>   Actor,
>   Tenant,
>   ActionFqn,
>   Subject := SingleSubject | BulkSubject,
>   Inputs,
>   Outputs,
>   Timestamp,
>   CorrelationId,
>   TraceId,
>   InvocationClass := Standard | Maintenance
> )
> SingleSubject := (tenant_id, entity_fqn, identity_handle)
> BulkSubject   := (tenant_id, entity_fqn, affected_count, sample_handles)
> ```
>
> `sample_handles` is a bounded list of identity handles drawn from the affected set; the bound is sink-configurable and defaults to 100.
>
> Standard Actions (§2.4) MUST emit `Subject = SingleSubject` and `InvocationClass = Standard`.
>
> MaintenanceActions (§2.4.1) MUST emit `Subject = BulkSubject` and `InvocationClass = Maintenance`. `affected_count` MUST be the exact number of Subjects mutated by the invocation; a partial-failure count (per F-D35, out of scope of this amendment) is governed by a follow-up RFC.

**Downstream RFCs impacted.** RFC-007 (Audit sink contract must accept the sum type; sample_handles bound and serialization owned here).

**Challenger attack.**
- Layer violations: none. Schema change is L0.
- Hidden runtime coupling: none beyond the existing audit path.
- SemVer surface expansion: Audit Entry payload schema gains a sum type and an `InvocationClass` discriminator. Necessary to resolve the contradiction.
- Tenancy bypass: `tenant_id` remains required in both arms.
- Audit bypass: schema change closes the contradiction; does not weaken any guarantee.

---

## A-1.9 F-D37 — ReportingDriver bound to undefined primitive

**Restatement.** Restates F-D9. §3.2.6 requires ReportingDriver to enforce a primitive ("Field-level Policies") that §2 does not define.

**Resolution.** Resolved by A-1.2. ReportingDriver enforces `FieldDescriptor.visibility` Policies, evaluated with the sentinel Action FQN `kernel.field.read`.

**Sections amended.** None beyond A-1.2.

**Replacement normative text.** None beyond A-1.2. Note that the §3.2.6 replacement in A-1.2 also satisfies this finding.

**Downstream RFCs impacted.** RFC-010 (ReportingDriver contract specification MUST require enforcement of `FieldDescriptor.visibility` per A-1.2).

**Challenger attack.** As A-1.2. No additional surface.

---

## A-1.10 Determination

ACCEPT.

Rationale (normative): all nine findings are resolved with a total surface expansion of (a) one new contract (`Invoker`), (b) one new optional FieldDescriptor attribute (`visibility`), (c) one reserved Action namespace (`kernel.*`) with one sentinel (`kernel.field.read`), (d) one Audit Entry sum type, (e) one audit-sink ordering rule, (f) one enumerated Tenant-override operation set, (g) one plugin-provider class consolidation. Each addition is within scope of the findings it resolves; no addition introduces a layer violation, a tenancy bypass, or an audit bypass; no addition expands the kernel surface beyond what the finding requires. Two contradictions (F-D4, F-D34) resolved; four major gaps (F-D9 / F-D24 / F-D37, F-D18 / F-D31, F-D20, F-D28) closed.

This amendment is contingent on:

1. RFC-003 owning the override-installation validator (A-1.3 / A-1.7).
2. RFC-002 owning the transaction contract used by primary-audit-failure rollback (A-1.6).
3. RFC-007 owning the audit sink acknowledgement protocol and the `BulkSubject` serialization (A-1.6, A-1.8).
4. RFC-004 owning the ViewSchema omission semantics for `visibility`-filtered Fields (A-1.2).
5. RFC-010 owning the ReportingDriver contract specification with `FieldDescriptor.visibility` enforcement (A-1.2 / A-1.9).

Upon acceptance, this amendment is folded into RFC-001 as Draft-03. The 30 remaining Appendix D findings are unaffected by this amendment and remain open.
