# 7. Known limits of 2.0

These are documented honestly and **without proposed solutions**. Each was
reproduced by a concrete scenario in the CRM, PMS, or SGH reference apps. They
describe the current state only.

## 1. Expand depth = 1

A projection can expand one hop, to a target projection that itself has no
expand. Nested chains (e.g. *Payment → Invoice → Stay → Reservation → Guest*, or
*Payment → Invoice → Admission → Patient*) are **not** expressible as a single
read; the compiler rejects a depth-2 expand
(`[14] … expands into '…' which itself expands (depth > 1)`).
**Layer:** entity-engine (ClosureValidator / RuntimeEntity read).

## 2. Cross-entity invariants are not supported

A guard sees only `{actor, tenant, subject(own fields), input}`. A reference
field holds the foreign-key id, never the related entity, and there is no fact
source for a related entity. So rules like *"admit a patient only if the chosen
bed is free"* or *"discharge only if the invoice is paid"* cannot be expressed;
attempting `subject('bed.status')` fails compilation
(`[8] … subject fact 'subject.bed.status' resolves to no field`).
**Layer:** kernel (FactSource) / entity-engine (AuthorizationEvaluator).

## 3. Transitions are single-field

A `Transition` flips exactly one enum state field (`{field, from, to}`). It
cannot stamp other fields at the same time — e.g. `Stay.checkOut` sets
`status = checked_out` but cannot record `actualCheckOut`; `Consultation.close`
cannot record `closedAt`. **Layer:** kernel (TransitionSpec) / runtime.

## 4. Aggregations (count/sum/avg/min/max) only — no grouping or computed fields

**Resolved in part.** A projection read now applies server-side **aggregations**
— `count`, `sum`, `avg`, `min`, `max` over the exposed scalar fields, computed on
the WHERE-filtered, visible set (see the *Projection Aggregations* reference).
KPI cards and dashboard badges are now expressible.

Still deferred at this layer: **grouping (group-by), computed/derived fields**
(e.g. an invoice `total` summed from line items, or a patient `age` derived from
`dob`), and full reporting. `sum`/`avg` operate on stored numeric values only.
**Layer:** projection / runtime read.

## 5. `read()` selection — basic filter/sort/pagination only (L3)

**Resolved in part.** `read(projection, params, context)` now applies a
server-side **Projection Query** — `where` (filter), `orderBy` (sort), and
`limit`/`offset` (pagination) over the projection's exposed scalar fields (see
the *Projection Query Language* reference). Distinct boards that differ only by a
filter (e.g. "Arrivals" vs "Departures", "today's appointments", "active
admissions") are now expressible.

Aggregations (count/sum/avg/min/max) are also available — see limit #4 and the
*Projection Aggregations* reference. Still deferred at this layer: **joins,
reverse relations, grouping, computed/derived fields, reporting, availability,
and anti-joins**. Filtering and sorting are restricted to the projection's own
exposed scalar fields (not expand targets). **Layer:** runtime read.

## 6. Limited runtime integrity validation

The compiler validates the declared model (the 16 closure invariants), but the
runtime does **not** validate instance data on `create`/`update`:

- An enum **input** value is not checked against the enum members
  (`payment.method = 'BOGUS'` is stored).
- A required (`nullable: false`) field — including a required reference — is not
  enforced (a record can be created with a missing reference, i.e. a dangling
  foreign key).

**Layer:** runtime create/update.

## 7. Actor attributes are limited

Guards can reference only `actor.type`, `actor.id`, `actor.homeTenant` (the
`ActorRef` shape). A domain `User.role` is stored data but is **not** available to
guards; richer role/attribute-based rules are approximated through `actor.type`.
**Layer:** kernel (ActorRef) / Context.

## Minor

- The subject of a `Transition`/`Update` is identified by the implicit
  `inputs['id']` convention (not surfaced in the schema metadata).
- There is no "list entities" endpoint; the renderer is given the entity list as
  configuration.

---

These limits mark the boundary of 2.0: AUSUS reasons about **one entity and its
raw foreign keys**, not the entity graph, and it does not compute derived data.
Within that boundary the model holds, as demonstrated by three independent
applications.
