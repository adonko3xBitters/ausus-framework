# RFC-007-amendment-01

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Proposed                                               |
| Authors       | architect, kernel, challenger                          |
| Date          | 2026-05-18                                             |
| Amends        | RFC-007 Draft                                          |
| Scope         | F-7A1, F-7A2, F-7A3, F-7A4 (RFC-010 incompatibilities only) |

This amendment is strictly scoped to the four findings the brief lists. No other RFC-007 clauses are touched.

---

## A-7.1 F-7A1 — multi-Entity `bulk_entities` payload

### Restatement

RFC-010 §9.6 and §10.2 introduce a multi-Entity MaintenanceAction audit shape carrying `outputs.bulk_entities`. RFC-007 §2.1 defines `BulkSubject` as single-Entity (`entity_fqn`, `affected_count`, `sample_handles`). RFC-007 §2.1 permits arbitrary JSON in `outputs`, so the field is *encodable* in the existing wire format, but the **shape, ordering, dedup semantics, and replay byte-stability** are not normative anywhere. A sink would have to guess the structure. Conformance cannot be verified.

### Resolution

Normatively define `outputs.bulk_entities` as a sorted list of fixed-key records. Require its presence on **every** MaintenanceAction audit entry (including single-Entity ones, which become a one-element list). The pre-existing `BulkSubject` continues to refer to the **primary** Entity unchanged; `outputs.bulk_entities` carries the full breakdown including the primary. Existing sinks that did not index `outputs.bulk_entities` keep working — they see the additional key and either ignore it or index it.

### Sections amended

- §2.1 (AuditEntry wire shape): add `outputs.bulk_entities` shape.
- §13 (BulkSubject serialization): add §13.6 (multi-Entity audit output).

### Replacement normative text

**Append to §2.1, immediately after the existing `BulkSubject` block:**

> **`outputs.bulk_entities` (normative for `InvocationClass: "Maintenance"`).** Every Audit Entry whose `invocationClass` is `Maintenance` MUST carry an `outputs.bulk_entities` field with the following shape:
>
> ```
> outputs.bulk_entities := EntityBreakdown[]                  // length ≥ 1
>
> EntityBreakdown := {
>   "entity_fqn":     string,
>   "affected_count": int,
>   "sample_handles": string[]                                 // [] permitted; non-empty only for the primary
> }
> ```
>
> Constraints:
>
> 1. The list MUST contain exactly one entry whose `entity_fqn` equals the entry's `subject.entity_fqn`. This entry is the **primary**.
> 2. The primary entry's `affected_count` MUST equal `subject.affected_count` and its `sample_handles` MUST equal `subject.sample_handles`.
> 3. Secondary entries (all entries other than the primary) MUST have `sample_handles == []`. Sample selection for secondaries is deferred to a future RFC; V1 emits empty arrays.
> 4. The list MUST be sorted ascending by `entity_fqn` (lexicographic, UTF-8 codepoint order). Sinks MUST NOT reorder.
> 5. Each `EntityBreakdown` object's keys MUST be serialized in the order `entity_fqn`, `affected_count`, `sample_handles`. This is required for byte-identity across replay.
> 6. Single-Entity MaintenanceActions produce a one-element list whose only entry is the primary. The list is never empty.
> 7. The sum of all `affected_count` values across `bulk_entities` MAY exceed `subject.affected_count` (the primary's count) when secondary Entities are also mutated. Sinks computing totals MUST sum across the list, not read `subject.affected_count` alone.

**Append new §13.6 (after §13.5):**

> **§13.6 Multi-Entity audit output.** A MaintenanceAction whose effect mutates more than one Entity FQN MUST produce a single Audit Entry. The entry's `subject` (BulkSubject per §13.1–§13.5) reports the primary Entity, conventionally the largest-affected. The full per-Entity breakdown lives in `outputs.bulk_entities` per §2.1.
>
> Selection of the primary is the effect's responsibility (typically the largest-affected by row count, deterministically tie-broken by `entity_fqn` lex order). The Auditor does not select; it serializes what the effect declares.
>
> Sinks that index audit by `subject.entity_fqn` MUST treat that field as the primary indexing key; they MUST NOT treat it as the exclusive Entity affected. The authoritative full picture is `outputs.bulk_entities`.

### Downstream RFCs impacted

- RFC-010 §9.6 / §10.2: ratified by this amendment. The "pragmatic V1 convention" of RFC-010 §9.6 is now normative wire format.
- No change required to RFC-001, RFC-002, RFC-003, RFC-004.

### Challenger attack

- **Layer violations:** none. `outputs.bulk_entities` is a payload field on an L0 value object. No new layer.
- **Hidden runtime coupling:** none. The list is computed by the MaintenanceAction effect from the `BulkResult`(s) it receives from RFC-002 §11.2; no new runtime channel.
- **SemVer surface expansion:** one new normative key (`outputs.bulk_entities`) with fixed shape. Required for every Maintenance entry; previously-emitted entries without it remain valid historical records (RFC-007 §2.3 immutability). Existing sinks that did not index this key see no behavioral change. **Bounded.**
- **Tenancy bypass:** every entry in `bulk_entities` is implicitly within the entry's `subject.tenant_id`; cross-Tenant entries are rejected upstream by RFC-002 §13.1 (bulk operations cannot span Tenants in a single Invoker transaction). **No bypass.**
- **Audit bypass:** strengthens. Previously, a multi-Entity MaintenanceAction's full effect was visible only by inspecting the Action's effect code; now it is mandatorily logged. **No bypass; closes a visibility gap.**

---

## A-7.2 F-7A2 — Elevation slot preservation across sink lifecycle

### Restatement

RFC-003 §10.5 added the `elevation` slot to the Audit Entry shape. RFC-007 §2.1 carries the slot. RFC-007 §10 documents the elevation chain. RFC-007 does NOT explicitly guarantee that the slot is preserved byte-identically across:

- Primary sink write.
- Secondary sink fan-out.
- Retry queue enqueue/dequeue.
- Dead-letter transition.
- Manual or automatic replay.

A sink whose underlying store has no column for the slot could silently drop it. A retry queue serialization that uses a subset schema could lose it. Dead-letter could persist an attenuated form. None of this is currently forbidden.

### Resolution

Add a normative preservation guarantee covering every sink lifecycle path. Add a boot-time capability check: sinks that cannot persist the `elevation` slot byte-identically MUST advertise non-support and be rejected at boot. Add one new error type to the §12.1 error taxonomy. No change to the elevation shape itself.

### Sections amended

- §4.1 (`SinkCapabilities`): add `preservesElevation(): bool`.
- §4 (sink contract): add §4.7 (elevation preservation MUST).
- §11.1 (`AuditRetryQueue`): add normative clause to `QueuedEntry::entry()`.
- §11.4 (dead-letter): add normative preservation clause.
- §16.2 (`ausus:doctor`): add elevation-support check.
- §12 (errors, by reference): introduce `ElevationStorageUnsupported`.

### Replacement normative text

**Replace §4.1 `SinkCapabilities` definition with:**

```
final class SinkCapabilities
{
  function supportsDedupByEntryId(): bool;
  function maxSampleHandles(): int;
  function maxInputsBytes(): int;
  function maxOutputsBytes(): int;
  function preservesInsertionOrder(): bool;
  function preservesElevation(): bool;        // §4.7
}
```

**Append new §4.7 (after §4.6):**

> **§4.7 Elevation preservation (normative).** Every AuditSink MUST preserve the `elevation` slot byte-identically across every write path it implements. Specifically:
>
> 1. For `TransactionalSink`: the storage row MUST carry the full serialized elevation object (or `null`) exactly as received from the Auditor. Sinks that flatten or transform the elevation slot in any way are non-conforming.
> 2. For `ExternalSink`: `prepare`, `confirm`, and `cancel` MUST round-trip the elevation slot unchanged.
> 3. Sink-internal indexes derived from elevation (e.g., a `from_tenant` index) MAY be added; they MUST NOT replace the slot.
>
> A sink that cannot guarantee §4.7.1–§4.7.3 MUST return `preservesElevation(): false`. The kernel rejects any configuration whose `primary_sink` or `secondary_sinks` includes a sink returning `false`. Detection occurs at boot via `ausus:doctor` (§16.2); registering an elevation-unsupporting sink raises `ElevationStorageUnsupported`.
>
> This guarantee is independent of redaction (§14). Elevation is never redacted in V1; the slot is part of the audit-spine commitment, not of the inputs/outputs payload.

**Replace §11.1 `QueuedEntry::entry()` description with:**

> `entry(): AuditEntry` — returns the full Audit Entry as originally constructed by the Auditor, including every field of §2.1 (notably `elevation`, `entryId`, `sequence`, `emitterVersion`). The retry queue's persistence MUST preserve every field byte-identically. Serialization formats that drop unknown fields are non-conforming.

**Append to §11.4 (after the final sentence "...the only escape from dead-letter."):**

> A dead-lettered `QueuedEntry` MUST retain the full Audit Entry exactly as enqueued, including the `elevation` slot. Dead-letter is a state transition on the queue row, not a re-serialization of the entry. Manual or automatic replay (`kernel.audit.retry_dead_letter`) re-submits the byte-identical entry to the sink; the sink's dedup-by-entryId mechanism (when supported) prevents duplication.

**Append to §16.2 (after the existing `ausus:doctor` MUST list):**

> - For each configured sink, `capabilities().preservesElevation()` MUST return `true`. If any returns `false`, boot fails with `ElevationStorageUnsupported(sink_name)`.

**Add to the error type list in §12.1 (or wherever the §12 taxonomy resides; appendix A inherits):**

```
ElevationStorageUnsupported(string $sinkName)
```

### Downstream RFCs impacted

- RFC-003 §10.5: elevation slot preservation guarantee strengthened from implicit to normative. RFC-003 acceptance gate §16.1 is satisfied by this amendment.
- No change to RFC-001, RFC-002, RFC-004, RFC-010.

### Challenger attack

- **Layer violations:** none. Elevation lives on the L0 value object; sinks are L3; the boot-time check is L0/L2 (kernel) consulting an L0 capability advertised by L3.
- **Hidden runtime coupling:** introduces a new MUST on every sink. **Mitigated by boot-time detection** — the failure cannot surprise at runtime. Sinks that previously emitted elevation correctly (the typical case) are unaffected operationally; they just need to flip `preservesElevation()` to `true`. Sinks that did not actually preserve elevation were already non-conforming under RFC-003 §10.5; this amendment makes the failure detectable.
- **SemVer surface expansion:** one new method on `SinkCapabilities`; one new error type; one new normative clause. Sinks built against the previous `SinkCapabilities` interface need recompilation. **Bounded; documented as a boot-time gate, not a runtime surprise.**
- **Tenancy bypass:** the elevation slot is the audit primitive that proves cross-Tenant operations were authorized. Strengthening its preservation **closes** a latent bypass (a sink that silently dropped elevation could obscure cross-Tenant access in the audit trail). **No bypass; closes one.**
- **Audit bypass:** strengthens. The guarantee makes "elevation occurred but not auditable" impossible on conforming sinks.

---

## A-7.3 F-7A3 — paginated reporting audit distinguishability

### Restatement

RFC-010 §7.4 specifies that each page of a paginated reporting query emits its own Audit Entry. RFC-007 must guarantee that, under replay (re-delivery of the same Audit Entry to a sink) and idempotency (sink dedup), page N and page N+1 remain distinguishable from each other and that replay of page N does not collide with page N+1.

### Resolution

RFC-007's existing primitives already satisfy this:

- Each page emits an entry with a unique `entryId` (UUID v7, §2.2.1).
- Sink dedup-by-`entryId` (§7.2) eliminates within-page duplication on replay.
- Per RFC-007 §9.1, each `ReportingDriver::execute` call is a separate top-level Action invocation and starts a new `correlationId`. Pages of the same query therefore have different `correlationId` values when issued as separate top-level calls. Pages issued from within a single parent Action's effect share the parent's `correlationId` but receive distinct `sequence` values.
- Per RFC-010 §7.2, `inputs.queryFingerprint` is identical across pages of the same query, providing the cross-page correlation key.

The amendment is a **normative clarification**: state these guarantees explicitly in a new §15.6, so sinks implementing reporting-audit indexing have a defined contract to verify against. No new field, no new error, no new shape.

### Sections amended

- §15 (read-side opt-in): add §15.6 (paginated reporting audit semantics).

### Replacement normative text

**Append new §15.6 (after §15.5):**

> **§15.6 Paginated reporting audit (normative).** Each page of a paginated ReportingDriver query (RFC-010 §3.9 cursor pagination, §7.4) emitting an `audited_reads` audit entry produces a distinct Audit Entry. The following properties are guaranteed:
>
> 1. **Distinct `entryId`.** Page N and page N+1 have different `entryId` values (UUID v7 per §2.2.1). Distinguishability at the sink does not depend on any other field.
> 2. **Page correlation.** All pages of the same query share an identical `inputs.queryFingerprint` (RFC-010 §7.2). Sinks indexing reporting audit by query MUST use `queryFingerprint`, not `correlationId`, because pages may originate from different top-level invocations (and therefore different correlations).
> 3. **In-page idempotent replay.** Replay of page N (re-delivery of the same `entryId` to a sink) on sinks advertising `supportsDedupByEntryId: true` is a no-op. On sinks without dedup, replay produces a duplicate row carrying the same `entryId`; downstream readers MUST dedup by `entryId`.
> 4. **Cross-page non-collision.** Replay of page N cannot be confused with page N+1: their `entryId` values differ, their `outputs.rowCount` may differ, and their `inputs.query.pagination.cursor` differ (page N+1's input cursor is page N's output cursor). The cursor in `inputs` is the only field that uniquely identifies the page within a query; sinks reconstructing page order MUST sort by `inputs.query.pagination.cursor` only when the cursor format is sortable (driver-defined), and otherwise by `timestamp` micro-precision (§2.2.3).
> 5. **No page-sequence field.** This RFC does NOT introduce a `page_number` field. Page identity is `(queryFingerprint, inputs.query.pagination.cursor)`; numerical page indices are not part of the audit shape because cursors are opaque and pages may be skipped.
>
> This clause clarifies the existing primitives; it adds no new field and no new error.

### Downstream RFCs impacted

- RFC-010 §7.4: ratified. The "each page emits its own audit entry" claim now has explicit sink-level distinguishability and idempotency guarantees.
- No change to other RFCs.

### Challenger attack

- **Layer violations:** none. Clarification only.
- **Hidden runtime coupling:** none. No new primitive introduced.
- **SemVer surface expansion:** zero new public surface. Documentation of existing surface.
- **Tenancy bypass:** each page audit is Tenant-bound by the entry's `tenant` field (§3.3 Auditor tenant verification). **No bypass.**
- **Audit bypass:** strengthens by removing the ambiguity that a sink might wrongly conflate pages.

---

## A-7.4 F-7A4 — synthetic `actionFqn`, synthetic Subject handles, entity lists

### Restatement

RFC-010 §7.2 introduces three synthetic constructs the audit subsystem must carry:

- A synthetic `actionFqn` value `kernel.reporting.query` that is not a registered Action.
- A synthetic `identity_handle` value `kernel.reporting.aggregate` that is not produced by `PersistenceDriver::generateIdentity`.
- An `inputs.entities` array enumerating the Entities touched by the query.

RFC-007 §2.1 types `actionFqn` and `identity_handle` as `string` and types `inputs` as an arbitrary object, so the synthetics are *encodable*. But RFC-007 does not authorize them: a strict sink could reject `actionFqn` values not present in the Action registry, or reject `identity_handle` values not produced by the driver, or reject `inputs.entities` as an unknown payload. RFC-015 §15.1 already uses one synthetic (`kernel.entity.read`) without RFC-007 explicit authorization. The pattern is uncodified.

### Resolution

Add a normative clause to §2.2 authorizing synthetic sentinels in the `kernel.*` namespace for both `actionFqn` and `identity_handle`. Forbid sinks from validating these against the Action registry or the Entity registry. Enumerate the V1 reserved synthetic sentinels and reserve the `kernel.*` namespace for future kernel additions. Document `inputs.entities` as a permitted payload key whose shape is normative.

### Sections amended

- §2.2 (invariants): add §2.2.7 and §2.2.8 (synthetic sentinels).
- §4 (sink contract): add §4.8 (sinks MUST accept synthetics).
- Appendix A (V1 public surface enumeration): extend the sentinels list.

### Replacement normative text

**Append to §2.2 (after invariant 6):**

> 7. **Synthetic sentinels.** `actionFqn` MAY refer to a kernel-reserved sentinel FQN in the `kernel.*` namespace. Sentinels do not correspond to registered Actions; they identify kernel-internal audit categories (reads, reports, lifecycle events). Similarly, `subject.identity_handle` MAY refer to a kernel-reserved sentinel value in the `kernel.*` namespace, used when an audit entry has no single instance Subject (e.g., aggregate reports). Sinks MUST treat such values as opaque strings and MUST NOT validate them against the Action registry or against `PersistenceDriver::generateIdentity` outputs.
>
> The V1 reserved sentinels are enumerated in Appendix A. The `kernel.*` namespace is reserved for future kernel additions; plugins MUST NOT register `actionFqn` or produce `identity_handle` values in this namespace.
>
> 8. **`inputs.entities` (normative when present).** When the audit entry's `actionFqn` is a kernel sentinel for a cross-Entity operation (currently `kernel.reporting.query`), the `inputs` object MUST include an `entities` field with the following shape:
>
> ```
> inputs.entities := string[]                        // list of Entity FQNs
> ```
>
> The list MUST be sorted ascending (lexicographic, UTF-8 codepoint order). It MUST include every Entity FQN referenced by the operation (e.g., `from` + every join target in a ReportingQuery). It MUST NOT include synthetic Entity FQNs from the `kernel.*` namespace unless the kernel-internal operation legitimately reads kernel-managed Entities (rare; documented case-by-case).

**Append new §4.8 (after §4.7):**

> **§4.8 Synthetic sentinel acceptance (normative).** Sinks MUST accept Audit Entries whose `actionFqn` is in the `kernel.*` namespace and whose `subject.identity_handle` is in the `kernel.*` namespace. Sinks MUST NOT:
>
> 1. Reject the entry on the grounds that the FQN is not a registered Action.
> 2. Reject the entry on the grounds that the handle was not produced by the bound PersistenceDriver.
> 3. Attempt to dereference the handle as a real instance reference.
> 4. Strip or rewrite either field.
>
> Sinks MAY index by `actionFqn` and `subject.identity_handle` for downstream querying; sentinels are valid index keys.

**Extend Appendix A "Reserved synthetic sentinels" (or add the subsection if absent):**

```
Reserved synthetic actionFqn values (kernel.*):
  kernel.entity.read                  (§15; reads against audited_reads Entities)
  kernel.reporting.query              (RFC-010 §7.2; reporting query audit)
  kernel.tenant.elevate               (RFC-003 §10.2; elevation grant)
  kernel.tenant.elevate_close         (RFC-003 §10.2; elevation close)
  kernel.audit.dead_letter            (§11.4; dead-letter emission)
  kernel.audit.confirm_lost           (§12.5; orphan reconciliation loss)
  kernel.tenant.elevate_close_late    (§12.5; late close from reconciler)

Reserved synthetic identity_handle values (kernel.*):
  kernel.reporting.aggregate          (RFC-010 §7.2; queries with no instance subject)

Reserved namespace:
  kernel.*                            (no plugin may register actionFqn or produce identity_handle values in this namespace)
```

### Downstream RFCs impacted

- RFC-010 §7.2: ratified. Synthetic FQN + synthetic handle + entities list now have normative sink-acceptance guarantees.
- RFC-007 internal: brings §15.1's existing `kernel.entity.read` sentinel under the same explicit policy (previously implicit).
- RFC-003: previously emitted `kernel.tenant.elevate*` sentinels are now in the enumerated reserved list.
- No change to RFC-001, RFC-002, RFC-004.

### Challenger attack

- **Layer violations:** none. Sentinels are L0 contract surface; sink acceptance is L3 conformance against the L0 contract.
- **Hidden runtime coupling:** none. No new runtime path; clarifies an existing payload-shape contract.
- **SemVer surface expansion:** one new normative clause; one new payload-shape requirement (`inputs.entities` ordering); one reserved namespace declaration; the V1 sentinel list. **Bounded.** New sentinels added later are minor (additive); the namespace is reserved up front so future additions do not collide with plugin Action FQNs.
- **Tenancy bypass:** synthetic entries still carry `tenant`, `subject.tenant_id`, and (when elevated) `elevation`. The Auditor §3.3 tenant verification still applies. Sentinels do not unlock cross-Tenant emission. **No bypass.**
- **Audit bypass:** strengthens. Plugins cannot disguise activity as kernel sentinels (reserved namespace prevents registration). Sinks indexing by `actionFqn` get a stable, normative vocabulary for kernel-internal categories. **No bypass.**

---

## Determination

ACCEPT.

All four findings resolve with additive, normative changes within the existing RFC-007 envelope:

| Finding | New runtime components | New layers | Schema redesign | Replay-stable | Backward compat |
|---------|----------------------|------------|-----------------|---------------|-----------------|
| F-7A1   | 0                    | 0          | No (additive `outputs.bulk_entities`) | Yes (lex-sorted list, fixed key order) | Yes (new field; existing sinks ignore or index) |
| F-7A2   | 0                    | 0          | No (one new `SinkCapabilities` method, one new error) | Yes (preservation guarantee covers replay) | Conditional — sinks must declare `preservesElevation: true`; non-declaring sinks were already violating RFC-003 §10.5 implicitly |
| F-7A3   | 0                    | 0          | No (clarification only) | Yes (existing entryId mechanism) | Yes (zero new surface) |
| F-7A4   | 0                    | 0          | No (additive sentinel authorization + `inputs.entities` shape) | Yes (sorted list, opaque sentinels) | Yes (sinks already accepted arbitrary strings; now MUST) |

No new runtime components. No new layers. No schema redesign. Every new field has deterministic serialization order and deterministic replay semantics. Every new field survives dead-letter and replay unchanged (per A-7.2's explicit preservation guarantee, which covers all subsequent additive fields by extension).

The exact normative replacement text for required sections is the text quoted in each finding above. No further replacement text is required at this level; folding the per-finding text into the next RFC-007 draft produces RFC-007 Draft-02.
