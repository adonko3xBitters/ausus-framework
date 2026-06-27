# 7. Lessons learned (CRM, PMS, SGH)

Three independent reference applications were built **only** from the DSL and
ViewDefinition, with no change to the Entity Engine framework. They exist to
validate the model on real domains and to record, reproducibly, what it does and
where it stops. This is a summary of evidence — it proposes nothing new.

| App | Entities | Domain | Result |
|-----|---------:|--------|--------|
| **CRM** | 5 | customers, opportunities, tasks, activities, users | full pipeline + single-hop expand + visibility |
| **Teranga PMS** | 10 | hotel: reservation → stay → invoice → payment | 7 state machines, six expand chains, full booking-to-payment workflow |
| **SGH (Hospital)** | 12 | patient → appointment → consultation → prescription → admission → invoice → payment → record | four-dimension authorization (actor/tenant/subject/input) |

## What was validated (held across all three)

- The whole pipeline — **DSL → compile → `.ausus` → resolve → bind →
  invoke/read → API → React/View** — works end to end, with the framework
  unmodified.
- **State machines** via `Transition` actions; **references** and **single-hop
  expand**; **per-field visibility**; **data-aware, fail-closed authorization**
  on `actor`, `tenant`, `subject`, and `input`.
- **Reload without recompilation**: a fresh repository over the same `.ausus`
  binds and runs.
- **Generic React**: a newly compiled entity becomes visible by adding its name
  to the renderer's entity list — no UI code change.

## Limits objectively observed (reproduced by the apps)

These are stated as observations of the current model, with no proposed fix:

1. **Expand depth = 1.** Nested chains (e.g. *Payment → Invoice → Stay →
   Reservation → Guest*) cannot be one read; the compiler rejects depth-2 expand.
2. **No cross-entity invariants.** A guard sees only its own subject fields, so
   rules like *"admit only if the bed is free"* are inexpressible
   (`subject('bed.status')` fails compilation).
3. **Single-field transitions.** A transition flips one state field; it cannot
   also stamp a timestamp/actor (e.g. `actualCheckOut`).
4. **No aggregation / computed fields.** Projections expose stored fields only
   (an invoice `total` cannot be summed from line items).
5. **Deferred `read()` selection.** `params` are ignored — no server-side filter,
   sort, or pagination yet.
6. **Limited runtime integrity validation.** Enum inputs and required references
   are not enforced at `create`/`update`.
7. **Limited actor attributes.** Guards can read only `actor.type/id/homeTenant`;
   a domain `User.role` is not available to guards.

## The single implementation defect found — and fixed

The three validations surfaced exactly **one implementation bug** (as opposed to
a model limit): expression **sugar operators** (`gt/gte/lte/ne/in/or`) in guards
were silently denied at runtime because the evaluator only handled the primitives,
while the canonical (hashed) form used the full set. **RELEASE-001** fixed it by
making the runtime evaluate the same EE-RFC-012 §Q5 reductions used for the hash. No
contract, RFC, or API changed.

## Takeaway

Within its boundary — *one entity plus its raw foreign keys, no derived data* —
the Entity Engine model held on three independent, increasingly demanding
domains. The boundary itself (graph-depth-1, no cross-entity rules, no
aggregation) is now documented and reproducible. These are observations for the
historical record, not a feature backlog.
