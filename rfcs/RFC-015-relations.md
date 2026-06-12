# RFC-015 — Entity Relations & Referential Integrity

| Field         | Value                                                          |
|---------------|----------------------------------------------------------------|
| Status        | **Implemented (v1.0.x)** — landed in kernel / persistence-sql / runtime-default |
| Authors       | architect, kernel                                              |
| Date          | 2026-06-08                                                     |
| Depends on    | RFC-001 (Kernel, esp. §2.1.1 identity tuple, §5.8 DSL invariants), RFC-002 (Persistence), RFC-004 (ViewSchema), RFC-011 (DSL) |
| Supersedes    | —                                                              |
| Stability     | Foundational. Changes after acceptance require a follow-up RFC. |
| Hard rule     | No new kernel *primitive*. A `reference` is a typed Field whose value is the target's identity handle; relations compose the existing tuple `(tenant_id, entity_fqn, identity_handle)`. The DSL only sugars (RFC-001 §5.8). |

---

## 0. Problem statement

AUSUS had no way to declare that one Entity's Field points at another Entity —
the **#1 HIGH-priority finding** from the framework's own validation sample
(`apps/issue-tracker/FRAMEWORK-FINDINGS.md §1`): foreign keys were declared as
`Field::string()->max(26)`, the runtime accepted any value, and dangling "ghost"
references were stored and rendered without complaint. Two consequences:
no referential integrity, and no relation surface in projections (lists showed
raw 26-char ULIDs instead of the parent's display field).

This RFC closes the gap. It is fully implemented and validated by
`apps/playground/relations-test.php` (19/19) with the entire existing suite green.

---

## 1. Reference / Subject unification (prerequisite — DONE)

`Subject` was a byte-identical twin of `Reference` referenced only by the Policy
contract. RFC-015 makes **`Reference` the single canonical identity value
object** and exposes `Ausus\Subject` as a `class_alias` of it
(`packages/kernel/src/kernel.php`). Fully backward-compatible — the alias
resolves to the same class, so `new Subject(...)`, `Subject::fromReference(...)`,
`?Subject` type hints and `instanceof Subject` all keep working unchanged. New
code SHOULD use `Reference`.

---

## 2. What shipped

| # | Decision | Resolution |
|---|---|---|
| **D-1** | Canonical instance identity | `Reference` is canonical; `Subject` is a BC alias (§1). |
| **D-2** | `Field::reference` validation timing | **Write-time, in the effect transaction.** The repository validates on `create` *and* `update` (repoint), inside the Invoker txn, so it observes parents created earlier in the same action. |
| **D-3** | Compiler relation validation | `Compiler::compile()` rejects a `reference` whose target entity is not registered (`DanglingRelation`) and a reference with no target FQN (`RelationTargetMissing`). |
| **D-4** | Projection `expand` grammar + fold | `->projection(..., expand: ['ref_field' => 'target_display_field'])`. The `ProjectionRenderer` folds a `{ref}_label` column into each row. **One read per expanded reference** (target loaded once, indexed by id — no N+1). |
| **D-5** | ViewSchema wire | The reference `FieldDescriptor` exposes `typeOptions.targetEntityFqn`; the expanded column is advertised as a `{ref}_label` field descriptor carrying `expandedFrom` / `targetEntityFqn` / `displayField`. `schemaVersion` unchanged (additive). |
| **D-6** | Tenant-boundary enforcement | The existence check pins `tenant_id`; a parent in another tenant is indistinguishable from a non-existent one. References never cross tenants. |

**Mechanics.** A `reference` value is a `TEXT` column holding the target ULID —
no schema or persistence redesign. `Field::reference('<plugin>.<entity>')` stores
the target in `FieldNode.typeOptions['targetEntityFqn']`; everything downstream
reads from there.

**Files touched:** `packages/kernel/src/dsl.php` (`Field::reference`,
`FieldBuilder::_referenceTarget`, projection `expand`),
`packages/kernel/src/kernel.php` (`Reference::fromReference` + `class_alias`,
`ProjectionNode.expand`, Compiler relation check, `ReferentialIntegrityViolation`),
`packages/persistence-sql/src/persistence.php` (`assertReferenceExists` in
`create`/`update`), `packages/runtime-default/src/runtime.php`
(`ProjectionRenderer` expansion).

---

## 3. Non-goals (unchanged)

Many-to-many / join entities; cascade delete (depends on RFC-002 `delete`,
unshipped); cross-tenant references (forbidden by the identity model); reverse
collections on the parent. All deferred to future RFCs.

---

## 4. Acceptance criteria — MET

- A ghost reference (`create`/`update` with an id that does not resolve to a
  target row in the tenant) is **rejected** with `ReferentialIntegrityViolation`
  (HTTP 400), and the write rolls back cleanly. ✅
- A list/detail projection renders the parent's display field (`{ref}_label`),
  with a bounded query count. ✅
- The Compiler rejects a `reference` to an undeclared entity at compile time. ✅
- `Field::reference()` rejects a non-qualified target up front. ✅
- No new kernel primitive; all constructs sugar the identity tuple and DSL. ✅
- `Reference`/`Subject` unified, fully backward-compatible. ✅
- The entire pre-existing test suite remains green. ✅

---

## 5. Adoption note

The framework capability is complete. `apps/issue-tracker` still declares
`project_id` as a plain `Field::string()` (its smoke deliberately stores a ghost
to document the pre-RFC-015 gap). Migrating that field to
`Field::reference('tracker.project')` and adding
`expand: ['project_id' => 'name']` to its board projection — and flipping the
smoke's ghost assertion from "stored" to "rejected" — closes
`FRAMEWORK-FINDINGS §1` end-to-end. That is an application-level adoption, left
out of this framework RFC to keep the existing smoke green.
