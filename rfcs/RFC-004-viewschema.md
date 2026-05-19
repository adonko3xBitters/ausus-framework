# RFC-004 — ViewSchema wire format

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Draft                                                  |
| Authors       | architect, kernel, challenger                          |
| Date          | 2026-05-18                                             |
| Depends on    | RFC-001 Draft-03 (incl. Amendment-01), RFC-002 Draft, RFC-003 Draft |
| Supersedes    | —                                                      |
| Stability     | Foundational. Changes after acceptance require a follow-up RFC. |

---

## 0. Problem statement

RFC-001 places the renderer (L6) at the bottom of the architecture's dependency graph and grants it exactly one inbound contract: "the ViewSchema wire format owned by the Presentation layer" (§3.2.3). That sentence is the entire Kernel commitment about the wire format. Everything else — the JSON shape, the versioning policy, the capability negotiation protocol, the locale handling, the omission semantics for Fields filtered by Amendment-01 §A-1.2 visibility Policies, and the deterministic transformation from a `Projection` descriptor to a JSON document — is unspecified.

This RFC closes that gap. It defines:

- The envelope and versioning rules a ViewSchema document MUST satisfy.
- The closed shape of every descriptor block (fields, actions, filters, data).
- The negotiation protocol by which a Renderer declares its profile and the Presentation layer responds with a compatible schema.
- The omission semantics the Presentation layer applies when evaluating Field `visibility` Policies.
- The locale-resolution model.
- The deterministic transformation `Projection × (Tenant, Actor, locale, profile) → ViewSchema`.
- The backward-compatibility rules under which V1's `1.x` schemas evolve.

The wire format is the single point through which the Renderer learns anything about the domain. It is therefore the single point of failure for the "Renderer never imports backend code" invariant (RFC-001 §3.2.3). The format MUST be self-contained, parseable as JSON without a backend-side codec, and free of any reference to backend internals.

The ten-year horizon and SemVer discipline (RFC-001 §6.4) apply. The schema's `1.x` envelope is part of the V1 public surface.

---

## 1. Scope and inherited constraints

### 1.1 Inherited

1. The Renderer (L6) depends only on the ViewSchema wire format (RFC-001 §3.2.3). It MUST NOT import backend code.
2. The Presentation layer (L5) generates ViewSchemas by consuming `Projection` descriptors (RFC-001 §2.7) and Tenant overrides (RFC-001 §4.4 + RFC-003 §9).
3. Fields whose `visibility` Policy does not return `Permit` MUST be omitted from the emitted ViewSchema (Amendment-01 §A-1.2; RFC-001 §4.5).
4. The presentation request shape is `(Tenant, Actor, Entity FQN, Projection name, locale) → ViewSchema JSON` (RFC-001 §4.5).
5. The Presentation layer is the sole emitter of ViewSchemas. Plugins, Actions, and the Kernel do not emit ViewSchemas.
6. The renderer invokes Actions through the API Surface (L4), not by re-reading the wire format (RFC-001 §4.5).
7. ViewSchema generation MAY be cached keyed by `(graph hash, tenant, projection, actor-role hash, locale)` (RFC-001 §7.2). This RFC specifies the cache key shape (§12) but the caching mechanism is owned by L5.

### 1.2 Out of scope

- The internal AST or intermediate representation the Presentation layer uses before serialization. This RFC commits only to the on-the-wire JSON.
- The Renderer's reactive framework, component library, or state machine. The contract is consumption of JSON; how React turns it into DOM is L6's business.
- The HTTP transport semantics (status codes, headers, caching directives). RFC-005 (API Surface) owns transport.
- The visual design system, theme tokens, or color palettes. Hints in this schema are abstract widget identifiers; concrete styling is L6's responsibility.
- Real-time updates, server-sent events, or WebSocket protocols. The schema is request/response only in V1; live updates are a post-V1 RFC.

---

## 2. Versioning

### 2.1 Schema version triple

Every ViewSchema document carries a `schemaVersion` triple `MAJOR.MINOR.PATCH`.

- **MAJOR**: incompatible changes. Renderers built for `1.x` MUST NOT consume `2.x`. Producers MAY emit multiple majors in parallel via negotiation (§10).
- **MINOR**: additive, backward-compatible changes. New optional fields, new optional descriptor variants, new optional capability flags. Old renderers MUST tolerate unknown fields (§5.1).
- **PATCH**: clarifications. No on-the-wire change. Documentation-only releases.

V1 ships `1.0.0`. The envelope and rules in this RFC are normative for the entire `1.x` line.

### 2.2 Promotion rules

| Change | Required version bump |
|---|---|
| Add an optional top-level field | MINOR |
| Add an optional property inside a descriptor | MINOR |
| Add a new variant to a sealed union (e.g., a new `type` value) | MINOR if marked `extensible: true`; MAJOR otherwise |
| Remove any field | MAJOR |
| Rename any field | MAJOR |
| Change the semantics of an existing field | MAJOR |
| Tighten an existing field's accepted value set | MAJOR |
| Broaden an existing field's accepted value set | MINOR |

This table is the SemVer charter for the wire format. Producers and consumers reference it.

### 2.3 Multi-version emission

The Presentation layer MAY support emitting more than one major version simultaneously. A deployment serving both `react.web.v1` (consuming `1.x`) and `react.mobile.v2` (consuming `2.x`) advertises both via §10's profile registry.

---

## 3. Envelope

### 3.1 Top-level shape

Every ViewSchema document conforms to:

```
{
  "schemaVersion":      "1.0.0",
  "targetProfile":      "<profile FQN>",
  "metadata":           { ... },           // §3.2
  "compatibility":      { ... },           // §3.3
  "fields":             [ ... ],           // §5
  "actions":            [ ... ],           // §6
  "filters":            [ ... ],           // §7
  "data":               null | { ... }     // §8, optional
}
```

Keys are case-sensitive `camelCase`. No null values for required keys (`schemaVersion`, `targetProfile`, `metadata`, `compatibility`, `fields`, `actions`, `filters`). `data` is `null` for metadata-only requests and an object for data-bearing requests.

### 3.2 `metadata`

```
{
  "projection":         "<projection FQN>",       // e.g. "billing.invoice.list"
  "entity":             "<entity FQN>",           // e.g. "billing.invoice"
  "tenant":             "<TenantId.value()>",     // string; opaque
  "locale":             "<BCP 47 tag>",           // e.g. "en-US"
  "generatedAt":        "<RFC 3339 UTC>",
  "cacheKey":           "<opaque string>",        // §12
  "actorRoleHash":      "<opaque string>"         // §12
}
```

All fields are required. `tenant` is the opaque value from `TenantId::value()` (RFC-003 §2.1); the Renderer treats it as a black-box identifier.

### 3.3 `compatibility`

```
{
  "requestedProfile":   "<profile FQN>",
  "negotiatedProfile":  "<profile FQN>",
  "requestedVersions":  ["1.0", "1.0.0"],         // versions the Renderer accepts
  "emittedVersion":     "1.0.0",
  "downgrades":         [ ... ],                  // §10.4
  "rejectedCapabilities": [ ... ]                 // capabilities the Renderer requested but L5 cannot emit
}
```

`compatibility` is the audit of negotiation (§10). The Renderer inspects it on receipt and decides whether to proceed.

### 3.4 No backend internals

The envelope MUST NOT contain:

- PHP class names, namespace strings, or fully-qualified names of backend types.
- Database identifiers (table names, column names, connection names, schema names).
- Persistence cursors in any form other than the opaque pagination cursor (§8.3).
- Identity handles in any form other than the `id` value declared on the Entity (per RFC-001 §2.1.1 these are opaque strings anyway).
- Closures, function references, or executable code in any field.
- Trace identifiers, correlation IDs, or any audit-trail material. Those live in the API Surface response envelope (RFC-005), not in the ViewSchema.

These exclusions are conformance failures, not stylistic preferences. A document emitting any of them is non-conforming.

---

## 4. Reserved value types

Across the schema, values typed as primitives are restricted to:

| Type identifier | JSON representation | Notes |
|---|---|---|
| `string`      | JSON string | UTF-8, unrestricted unless a `maxLength` is declared |
| `integer`     | JSON number | Integral; magnitude bounded by IEEE 754 (`[-(2^53-1), 2^53-1]`) unless `bigint: true` (string-encoded) |
| `decimal`     | JSON string | Decimal serialized as a string to preserve precision (e.g. `"1234.56"`) |
| `money`       | object `{ amount: decimal, currency: string }` | Currency is ISO 4217 three-letter |
| `boolean`     | JSON `true` / `false` | |
| `date`        | JSON string | RFC 3339 calendar date (`YYYY-MM-DD`) |
| `datetime`    | JSON string | RFC 3339 timestamp, UTC, with offset indicator |
| `time`        | JSON string | RFC 3339 partial time |
| `enum`        | JSON string | One of declared option values |
| `json`        | JSON object/array/primitive | Schema-less opaque payload; treated as a black box by the Renderer |
| `reference`   | object `{ entity: string, id: string }` | Reference to another Entity; resolution mode in §5.4 |
| `null`        | JSON `null` | Permitted only where `nullable: true` |

These are the only value types the wire format names. Field Types not on this list cannot be carried by V1 ViewSchemas. A plugin registering a custom Field Type (e.g., `geo.point`) MUST map to one of these for transport (typically `json` with a documented sub-shape) until a future RFC extends the list.

---

## 5. `fields`

### 5.1 Descriptor shape

`fields` is an ordered array. Each element:

```
{
  "name":          "<field FQN local part>",     // e.g. "number"
  "type":          "<value type>",               // §4
  "label":         "<localized string>",
  "help":          "<localized string>" | null,
  "nullable":      true | false,
  "readOnly":      true | false,
  "typeOptions":   { ... },                      // §5.2; type-specific
  "hints":         { ... } | null,               // §5.3
  "validation":    { ... } | null                // §5.5
}
```

The order of the array is the order the Renderer SHOULD render. Reordering is a hint, not a constraint; the Renderer MAY reorder if its layout requires (e.g., for responsive breakpoints), but the default rendering order is the array order.

A consumer MUST tolerate unknown keys inside any descriptor: a `1.1` Presentation layer adding `foo` to the field descriptor MUST NOT break a `1.0` Renderer that ignores `foo`. This is the load-bearing tolerant-reader rule for backward compatibility.

### 5.2 `typeOptions`

Type-specific options. Examples:

- `string`: `{ "minLength": 0, "maxLength": 255 }`
- `decimal`: `{ "precision": 12, "scale": 2 }`
- `money`: `{ "allowedCurrencies": ["USD", "EUR"] }`
- `enum`: `{ "options": [ { "value": "draft", "label": "Draft" }, ... ] }`
- `reference`: `{ "targetEntity": "billing.customer", "mode": "embedded" | "reference-only", "embeddedFields": ["id", "display_name"] }`
- `date`/`datetime`/`time`: `{ "min": "...", "max": "...", "step": "..." }`
- `json`: `{ "schemaDescriptor": null | <opaque JSON Schema fragment> }`

`typeOptions` is required for types that have options; `{}` is permitted for types with no options.

### 5.3 `hints`

UI hints are advisory presentation directives. Per RFC-001 §2.2 they are a distinct sub-descriptor of `Field`; per RFC-001 §2.7 they do not live on the Projection. The Presentation layer attaches them to each emitted field descriptor.

```
{
  "widget":     "<widget identifier>",       // profile-defined; "text", "textarea", "date-picker", "select", "money", ...
  "width":      "small" | "medium" | "large" | "full",
  "placeholder":"<localized string>" | null,
  "group":      "<group identifier>" | null,
  "icon":       "<icon identifier>" | null
}
```

Hints are a sealed set per profile (§10.2). A hint not supported by the negotiated profile is omitted from the emitted descriptor (§10.4 downgrade list records it).

### 5.4 `reference` mode

For fields of type `reference`:

- `mode: "reference-only"` — emits `{ "entity": "billing.customer", "id": "cust_456" }` in data items. The Renderer is responsible for fetching the related Entity via a separate Projection request if it needs more than the id.
- `mode: "embedded"` — emits a nested object containing the fields named in `embeddedFields`. The Renderer renders inline without a follow-up request.

Embedded mode is supported only when the Renderer profile advertises `capabilities.embeddedRelations: true` (§10.2). If the profile does not, the Presentation layer downgrades to `reference-only` and records the downgrade (§10.4).

### 5.5 `validation`

The Presentation layer mirrors the Field's declarative validation rules so that the Renderer can validate on input before the round-trip to L4.

```
{
  "required":    true | false,
  "minLength":   <int> | null,
  "maxLength":   <int> | null,
  "pattern":     "<ECMAScript regex source>" | null,
  "min":         <number|date|datetime> | null,
  "max":         <number|date|datetime> | null,
  "custom":      [ ... ]                          // profile-defined names; advisory
}
```

`validation` is advisory. Authoritative validation always runs server-side via the Action's Policy chain and Field validation rules. The Renderer MAY use this block for inline UX; it MUST NOT treat passing this validation as authorization to mutate.

### 5.6 Omission

Fields whose `visibility` Policy (Amendment-01 §A-1.2) does not return `Permit` for the requesting Actor MUST NOT appear in the `fields` array. They also MUST NOT appear in the corresponding `data` items (§8.2). There is no placeholder, no `redacted: true` flag, no `omittedFields` array. The Renderer cannot tell whether a Field exists.

This is the strict default. A future RFC MAY introduce a `Readability` Policy distinct from `Visibility`, where the Field exists in the schema but values are redacted. V1 commits only to omission.

When omission removes a Field that another descriptor references (e.g., a Filter's `field` target, an Action input's reference), that referencing descriptor MUST also be omitted. Dangling references in the wire format are conformance failures of the Presentation layer.

---

## 6. `actions`

### 6.1 Descriptor shape

`actions` is an array of Actions exposed by the Projection AND permitted by the Actor's Policy chain (RFC-001 §2.5, Amendment-01 §A-1.2 for per-Field input policies). Actions for which the Actor has no Policy `Permit` are omitted entirely.

```
{
  "fqn":              "<action FQN>",          // "billing.invoice.issue"
  "name":             "<local part>",          // "issue"
  "label":            "<localized string>",
  "description":      "<localized string>" | null,
  "icon":             "<icon identifier>" | null,
  "subjectRequired":  true | false,            // true for instance Actions; false for global
  "inputs":           [ ... ],                 // §6.2
  "confirmation":     null | { ... },          // §6.3
  "audited":          true,                    // always true for mutating Actions; informational
  "maintenance":      true | false,            // §6.4
  "hints":            { ... } | null           // §6.5
}
```

### 6.2 `inputs`

Each input is shaped identically to a `fields` descriptor (§5.1) so that the Renderer's form-rendering code is uniform between Field display and Action input collection. Inputs whose `visibility` Policy denies are omitted (§5.6). Required inputs whose Field is omitted produce an Action whose `inputs` cannot be satisfied; the Presentation layer MUST omit such Actions from the `actions` array.

### 6.3 `confirmation`

```
{
  "required":       true,
  "prompt":         "<localized string>",
  "challenge":      null | { "type": "type-name", "expected": "<localized string>" }
}
```

Used for destructive operations (delete, archive, void). Advisory; the Renderer SHOULD honour. The Action itself is the source of truth for destructive intent; the confirmation block carries it to the UI.

### 6.4 `maintenance`

`maintenance: true` flags MaintenanceActions (RFC-001 §2.4.1). Renderers MAY treat them differently (e.g., place them in an "Operations" menu rather than primary actions). Advisory.

### 6.5 `hints`

Action-level hints, e.g., presentation grouping, button style, modal vs navigation. Sealed per profile.

---

## 7. `filters`

### 7.1 Descriptor shape

`filters` is an array of filter slots the Projection declares (RFC-001 §2.7 "Filters it permits"). The Renderer builds query UI from this; the resulting filter values are sent back to L4 as part of the next list-request.

```
{
  "name":         "<filter identifier>",     // "status", "due_at"
  "field":        "<field FQN>",             // the Field this filter operates on; omitted for compound filters
  "operator":     "equals" | "in" | "comparison" | "range" | "null" | "string-match" | "relation-exists" | "reference-equals",
  "label":        "<localized string>",
  "operands":     { ... },                   // §7.2
  "default":      <value> | null
}
```

The set of `operator` values mirrors the closed Filter grammar of RFC-002 §10.1. A new operator requires both an RFC-002 amendment and an RFC-004 minor (additive variant).

### 7.2 `operands`

Operator-specific operand declarations:

- `equals`: `{ "valueType": "<value type>" }`
- `in`: `{ "valueType": "<value type>", "options": [ ... ] | null }`
- `comparison`: `{ "valueType": "<value type>", "operators": ["lt","lte","gt","gte"] }`
- `range`: `{ "valueType": "<value type>", "inclusive": true | false }`
- `null`: `{}`
- `string-match`: `{ "modes": ["prefix","suffix","contains","exact"] }`
- `relation-exists`: `{ "relation": "<relation name>", "subFilter": null | <filter descriptor> }`
- `reference-equals`: `{ "targetEntity": "<entity FQN>" }`

The operand shape determines what UI the Renderer constructs.

---

## 8. `data`

### 8.1 Inclusion

`data` is `null` for metadata-only requests and an object for data-bearing requests. The Projection's intent determines:

- A `list` Projection produces `{ "items": [ ... ], "pagination": { ... } }`.
- A `detail` Projection produces `{ "item": { ... } }` (single item) or `null` if the requested Subject does not exist.
- An `edit` Projection produces `{ "item": { ... } }` representing the editable state.

The shape of `data` is keyed by the Projection's kind, not by free choice. A future Projection kind requires extending this clause.

### 8.2 Item shape

Each item is a JSON object whose keys are the `name` values from `fields` and whose values are typed per §4. Fields omitted from `fields` (per §5.6) MUST NOT appear as keys in items.

`reference` fields in `reference-only` mode appear as `{ "entity": "...", "id": "..." }`. In `embedded` mode they appear as a nested object containing only the declared `embeddedFields`.

### 8.3 Pagination

```
{
  "nextCursor":         "<opaque string>" | null,
  "previousCursor":     "<opaque string>" | null,
  "totalEstimate":      <integer> | null,
  "pageSize":           <integer>
}
```

Cursors are opaque to the Renderer; the Renderer passes them back unchanged in the next request. Cursor stability is guaranteed by RFC-002 §10.3.

### 8.4 Data versions are not echoed

Optimistic-lock `Version` values (RFC-002 §8) MUST appear in data items as the field `_version` of type `string`. The Renderer passes this back unchanged with `update` and `delete` Action invocations.

The leading underscore distinguishes `_version` from domain Fields and reserves the underscore prefix for Renderer/Presentation interchange metadata. Plugin-declared Field names beginning with underscore are rejected at registration.

---

## 9. Locale handling

### 9.1 Resolution

The Presentation layer accepts a `locale` BCP-47 tag in the request. It resolves every localized string in the emitted schema (`label`, `help`, `description`, `prompt`, enum `label`, etc.) using:

1. The plugin-provided translations for the requested locale.
2. Fallback chain: requested locale → language-only (`en-US → en`) → deployment-configured default locale.
3. If all three fail: the source string from the descriptor (the developer-language literal).

Resolution is server-side. The Renderer never sees translation keys; it sees fully-resolved strings.

### 9.2 Cache key

`locale` is part of the §12 cache key. The Presentation layer caches per-locale schemas independently.

### 9.3 Locale negotiation

If the Renderer's requested locale is not supported by the deployment, the Presentation layer chooses per §9.1 and reports the chosen locale in `metadata.locale`. There is no separate "negotiated locale" field; `metadata.locale` is authoritative.

### 9.4 No client-side i18n bundles

The wire format does NOT carry translation bundles, translation keys, or ICU message templates. All localization is server-resolved. This decouples Renderer build artifacts from translation update cycles.

---

## 10. Renderer capability negotiation

### 10.1 Profiles

A **renderer profile** is a named, versioned bundle of capabilities. A profile FQN follows the pattern `<vendor>.<surface>.<major>`:

- `react.web.v1` — first-party React web renderer
- `react.web.v2` — future second-major React web renderer
- A third-party renderer registers its own FQN.

Profiles are registered with the Presentation layer at boot via a Kernel-defined registry. The registry's contents are advertised by `ausus:doctor`.

### 10.2 Profile shape

```
{
  "fqn":                  "react.web.v1",
  "acceptedSchemaVersions": ["1.0", "1.1", "1.2"],
  "widgets":              [ ... ],          // sealed list per profile
  "actionHints":          [ ... ],
  "operators":            [ ... ],          // subset of §7.1
  "capabilities": {
    "embeddedRelations":  true,
    "inlineValidation":   true,
    "confirmationModal":  true,
    "iconography":        true,
    "paginationKinds":    ["cursor"]
  }
}
```

A profile is data; it is registered, not coded into the wire format. Plugins MAY register profiles. The first-party `react.web.v1` is shipped by the Kernel's reference Renderer package.

### 10.3 Request

The Renderer signals its negotiation parameters via the request (transport defined by RFC-005, conceptually):

```
projection:          "billing.invoice.list"
tenant:              implicit from Tenant Context
actor:               implicit from API Surface
locale:              "en-US"
renderer:            "react.web.v1"
acceptSchemaVersions: ["1.0", "1.0.0"]
```

### 10.4 Resolution

The Presentation layer:

1. Looks up the requested profile. Unknown profile → 406-equivalent error with `UnknownRendererProfile`.
2. Intersects `acceptSchemaVersions` with the profile's `acceptedSchemaVersions` AND the Presentation layer's own emitted versions. No overlap → 406 with `NoCommonSchemaVersion`.
3. Emits the highest version in the intersection.
4. For each capability the Projection requires (e.g., `embeddedRelations` for a Field with `mode: "embedded"`) but the profile does not advertise:
   - **Downgrade** if a documented downgrade path exists (e.g., embedded → reference-only).
   - **Reject** otherwise, with `IncompatibleRenderer` and the list of unsatisfiable capabilities.
5. Records downgrades and rejected capabilities in `compatibility.downgrades` and `compatibility.rejectedCapabilities`.

Documented downgrades for V1:

| Capability | Fallback when unsupported |
|---|---|
| `embeddedRelations` | `mode: "reference-only"` for every affected reference field |
| `inlineValidation`  | omit the `validation` block |
| `confirmationModal` | omit the `confirmation` block; the Renderer SHOULD use its own confirmation UX |
| `iconography`       | omit `icon` keys |

`paginationKinds` and `operators` are NOT downgradable. A Projection that requires an operator the profile does not support fails with `IncompatibleRenderer`. A Projection requiring `cursor` pagination cannot fall back to offset (offset is not in the V1 grammar at all).

### 10.5 No silent feature drop

A capability the Projection requires and the profile does not support MUST appear in `compatibility.downgrades` (if downgraded) or `compatibility.rejectedCapabilities` (if the request was rejected). Silent dropping is a conformance failure.

---

## 11. Projection-to-ViewSchema transformation

### 11.1 Inputs

The Presentation layer computes a ViewSchema from:

- The `Projection` descriptor (resolved through the Tenant-merged graph per RFC-003 §9).
- The owning `Entity` descriptor (Fields, Relations, Actions, Policies, Workflows).
- The active `Tenant` (for tenant overrides; §A-1.3 additive set).
- The requesting `Actor` (for Policy evaluation: Projection-level, Action-level, Field `visibility`).
- The `locale` (§9).
- The negotiated renderer `profile` (§10).
- The requested `Subject` reference, for `detail` and `edit` Projections.

### 11.2 Algorithm

The transformation is deterministic given inputs. The Presentation layer:

1. Resolves the Projection FQN against the merged graph. Unknown projection → 404-equivalent with `ProjectionNotFound`.
2. Evaluates the Projection's Policy chain (RFC-001 §2.7) for the Actor. Deny → 403-equivalent with `ProjectionForbidden`.
3. For each Field declared by the Projection:
   - Resolves the Field descriptor against the merged graph.
   - Evaluates `visibility` Policy (Amendment-01 §A-1.2) with `(Actor, kernel.field.read, Subject | null, Context)`. Deny or Abstain → omit (§5.6).
   - Constructs the field descriptor (§5.1), localizing strings (§9), applying profile downgrades (§10.4).
   - Adds to `fields`.
4. For each Action declared by the Projection:
   - Evaluates the Action's Policy chain for the Actor. Deny or Abstain → omit.
   - Verifies every required input's Field is present in `fields`; if not, omit the Action (§6.2).
   - Constructs the action descriptor (§6.1).
   - Adds to `actions`.
5. For each Filter declared by the Projection:
   - Verifies the operator is supported by the profile (§10.4). If not and not downgradable → reject the entire request with `IncompatibleRenderer`.
   - Verifies the operator's `field` is present in `fields`. If omitted (per visibility) → omit the filter.
   - Constructs the filter descriptor (§7.1).
   - Adds to `filters`.
6. For data-bearing Projections, executes the Projection's read against the Repository (RFC-002 §5) or the ReportingDriver (RFC-010 — relevant when the Projection's read shape requires Field-level visibility filtering at storage time). Constructs `data` per §8.
7. Computes the cache key (§12) and emits the envelope.

### 11.3 Determinism

Given identical inputs (including identical Actor role hash and identical Tenant `overrideVersion`), the transformation MUST produce byte-identical output. This is what makes §12 caching safe.

Sources of non-determinism the transformation MUST suppress:

- Iteration order over Fields, Actions, Filters: stable per descriptor declaration order.
- Iteration order over Policies in the chain: stable per attachment order.
- Locale-resolution fallback: deterministic per §9.1.
- Timestamps in `metadata.generatedAt`: NOT part of the cache key; recomputed on cache hit; presence does not break the byte-identical claim for the cached body, which excludes this field per §12.2.

### 11.4 Errors short-circuit

Steps 1–6 MUST short-circuit on the first reject. The Presentation layer does not partially emit a schema and then attach an error. Either a full conforming ViewSchema is emitted or a structured error envelope (RFC-005) is returned. There is no "ViewSchema with a `.error` block."

---

## 12. Caching

### 12.1 Cache key

The cache key for a ViewSchema document is the tuple:

```
( graphHash, tenantId, overrideVersion, projectionFqn, actorRoleHash, locale, profileFqn, schemaVersion )
```

- `graphHash` — the compiled base graph hash (RFC-001 §4.2.5).
- `tenantId`, `overrideVersion` — pin to the Tenant's current merged-view version (RFC-003 §9).
- `projectionFqn`, `locale`, `profileFqn`, `schemaVersion` — request-determining.
- `actorRoleHash` — an opaque, deterministic hash of the Actor's Policy-relevant role/permission set. The Authorization plugin (RFC-001 §8.2) is responsible for producing it; two Actors with identical Policy-relevant attributes MUST yield the same hash. Distinct attributes MUST yield distinct hashes.

Cache invalidation is implicit: any change to any of the eight tuple components changes the key. No explicit invalidation channel is required.

### 12.2 Cacheable body

The cached body is the entire emitted JSON document EXCEPT for `metadata.generatedAt`, which is rewritten on every serve. All other bytes are byte-stable per §11.3.

### 12.3 Cache lifetime

The cache is per-deployment. Lifetime is bounded by:

- The `overrideVersion` probe staleness window from RFC-003 §9.3 (typically ≤ 1s in production).
- Explicit eviction on `ausus:cache:clear` (RFC-001 §5.5).
- The compiled-graph artifact lifetime (RFC-001 §7.1) — a new graph hash invalidates every keyed entry.

### 12.4 Per-request `cacheKey` echo

`metadata.cacheKey` is a serialization of the eight-tuple. Renderers MAY use it to deduplicate identical requests client-side. They MUST NOT parse its structure.

---

## 13. Anti-patterns

The wire format MUST NOT do any of the following. Each is paired with a concrete rule.

1. **Carry backend class names.** No `\\App\\Models\\Invoice` strings, no PHP namespaces. Conformance test.
2. **Carry database identifiers.** No table names, no column names, no connection names.
3. **Carry executable code.** No closures, no function references, no `eval`-style string scripts.
4. **Carry an error field inside a successful schema.** Either a schema is returned or an error envelope (RFC-005). Never both.
5. **Carry differential / patch instructions.** A ViewSchema is a complete snapshot. Differential updates are a post-V1 RFC.
6. **Include hidden fields with `redacted: true`.** Per §5.6, omission is total. Future RFCs may add a `Readability` distinct primitive; V1 does not.
7. **Expose validation rules as authoritative.** §5.5: advisory. Server-side enforcement is authoritative.
8. **Embed translation keys.** §9.4: locale resolution is server-side.
9. **Mix Projection kinds in one document.** A `list` ViewSchema does not also carry a `detail` sub-block. The Renderer makes a second request.
10. **Permit Renderer to pass back a modified `cacheKey`.** §12.4: opaque echo.

---

## 14. Alternatives considered

### 14.1 GraphQL as the wire format

**Rejected.** GraphQL is a query language with its own schema-shipping conventions; adopting it would either re-implement most of this RFC on top of GraphQL or surrender the Field-omission and capability-negotiation control to GraphQL's defaults. The wire format here is request/response with a fixed envelope, which makes caching (§12) and capability negotiation (§10) trivially deterministic.

### 14.2 JSON:API

**Rejected.** JSON:API addresses resource semantics, not view rendering. Adopting it would force every Projection into a "resource + relationships" shape that does not match Projections' Field / Action / Filter trichotomy.

### 14.3 Carry the Projection descriptor verbatim and let the Renderer interpret

**Rejected.** It would expose backend-shaped descriptors to L6 and recreate the coupling that RFC-001 §3.2.3 was designed to prevent. The Renderer needs a UI-tier vocabulary (widgets, hints, validation), not a domain-tier vocabulary (Policies, Workflow guards).

### 14.4 Per-Field `redacted: true` instead of omission

**Rejected for V1.** Confidentiality requirements vary; the strict default (omission) prevents leaking the existence of sensitive fields. A future RFC may add `Readability` as a separate Policy distinguishable from `Visibility`. Until then, the schema commits to omission only.

### 14.5 Server-side i18n keys returned to the client

**Rejected.** Coupling release cycles of the React renderer to translation bundle updates is operationally painful. Server-resolved strings (§9) keep i18n updates entirely server-side.

### 14.6 Allow the Renderer to request specific fields

**Rejected.** Field selection is the Projection's job. A Renderer that wants a different field set requests a different Projection. This keeps server-side caching (§12) effective and prevents the wire format from becoming a query language.

### 14.7 Allow `action.error` partial responses for failed pre-flight checks

**Rejected (§13.4).** Errors are structured envelopes returned by L4; the schema is either complete or not present. Mixing complicates Renderer parsing and audit reasoning.

---

## 15. Trade-offs

1. **Strict omission (§5.6)** prevents accidental leakage but breaks Renderer-side layouts that assumed a specific field would always be present. Renderers MUST be defensive about missing fields.
2. **Server-resolved locales (§9)** decouple Renderer builds from translations but multiply the cache key dimension.
3. **One Projection kind per document (§8.1)** forces multi-call patterns for composite UIs. Accepted; alternative (multi-Projection envelopes) explodes the schema's surface.
4. **No streaming / no live updates** in V1. Acknowledged; the request/response shape keeps reasoning about caching and consistency tractable. Live-update RFC deferred.
5. **Cursor pagination only** (§8.3). Offset pagination is incompatible with RFC-002's filter grammar and was never in the contract. Renderers requiring page-number UI compute it client-side from cursor traversal.
6. **`_version` collision** (§8.4) reserves the underscore prefix. Plugins must avoid underscore-prefixed Field names. The conformance gate rejects at registration.

---

## 16. Open questions

1. **RFC-005 (API Surface).** Transport semantics for ViewSchema requests: HTTP status codes for `UnknownRendererProfile` / `NoCommonSchemaVersion` / `IncompatibleRenderer` / `ProjectionNotFound` / `ProjectionForbidden`. Conventional candidates: 406, 406, 422, 404, 403. RFC-005 owns the binding.
2. **RFC-009 (Telemetry).** Per-ViewSchema metrics: cache hit rate, downgrade frequency, profile distribution. Out of this RFC.
3. **RFC-010 (ReportingDriver).** When a data-bearing Projection requires Field-level visibility filtering at storage time, the ReportingDriver enforces (Amendment-01 §A-1.2 / §A-1.9). RFC-010 must accept that the Field-level Policy evaluation contract is the one defined in Amendment-01 §A-1.2 (`kernel.field.read` sentinel).
4. **Post-V1 — Readability vs Visibility.** A second Policy primitive permitting "field exists, value redacted." Triggers a wire-format change (a `redacted` marker per item). Major bump.
5. **Post-V1 — Live updates.** Subscribing to ViewSchema changes (e.g., when an underlying record is updated). Requires a transport contract (WebSocket / SSE) and an invalidation protocol. Out of V1.
6. **Post-V1 — Composite ViewSchemas.** Multi-Projection documents (e.g., a dashboard combining list, chart, detail). Either a new envelope or a separate composition contract; out of V1.
7. **Profile registry write surface.** This RFC describes registration without specifying the API (`Ausus::profiles()->register(...)` etc.); to be specified by the Kernel reference implementation note alongside the Presentation layer scaffolding.

---

## 17. Challenger review — attack matrix

Every contract is attacked against: **layer violations**, **backend leakage**, **tenancy bypass**, **audit bypass**, **cache incoherence**, **omission bypass**, **SemVer traps**.

### 17.1 Envelope (§3)

| Attack | Defence |
|---|---|
| Layer violation: Renderer parses backend FQNs to import code by reflection. | §3.4: backend FQNs are forbidden in the envelope; field FQNs are domain-tier identifiers (e.g., `billing.invoice.number`), not class/file paths. No reflection target is exposed. |
| Backend leakage: `metadata.cacheKey` is a serialization of internal hashes. | §12.4: `cacheKey` is opaque; Renderer treats as black-box echo. Hash inputs are not parsable from the cache key string by design. |
| Tenancy bypass: Renderer alters `metadata.tenant` on resend. | The Tenant comes from the active Tenant Context (RFC-003 §3, §11.1); `metadata.tenant` is informational, not authoritative. L4 ignores it on action invocations. |
| Audit bypass: schema carries a `traceId` the Renderer can forge. | §3.4: trace identifiers live in the API Surface envelope, not in the ViewSchema. Renderer cannot forge anything by modifying the schema. |
| Cache incoherence: cache key omits a relevant dimension. | §12.1 enumerates eight dimensions. Any new dimension is a minor bump that adds a cache key component; old caches simply miss. |
| Omission bypass: `metadata` leaks Field existence via a count. | Envelope does not include field counts beyond `fields.length`, which is the count of admitted fields after omission. No "originally had N" disclosure. |
| SemVer trap: adding `metadata.x` at minor changes the cache key. | §12.1 fixed at eight dimensions for V1. New `metadata.*` fields are informational; they MUST NOT enter the cache key without a minor bump that documents the new dimension. |

### 17.2 Reserved value types (§4)

| Attack | Defence |
|---|---|
| Layer violation | Reserved set is profile-independent; profiles affect widgets, not value transport. |
| Backend leakage | `json` type is opaque; the Renderer cannot infer backend structure from it. |
| Tenancy bypass | n/a. |
| Audit bypass | n/a. |
| Cache incoherence | Value types affect serialization stability; `decimal` as string avoids float drift. |
| Omission bypass | n/a. |
| SemVer trap: adding `geo.point` later. | Per §4 last paragraph, plugins map to `json` until a value-type extension RFC. Adding to §4's table is a minor (additive) bump. |

### 17.3 Fields (§5)

| Attack | Defence |
|---|---|
| Layer violation: Renderer infers Workflow state from field hints. | Hints are advisory; Workflow state semantics travel as a normal field value (likely `enum`). Renderer cannot drive a transition; transitions go through Actions. |
| Backend leakage: `typeOptions.schemaDescriptor` for `json` fields leaks backend types. | §5.2: the schemaDescriptor is opaque JSON Schema; if a plugin emits backend FQNs in it, that is a plugin bug, not a wire-format bug. Conformance: the Presentation layer SHOULD strip any `$ref` to backend types before serialization. |
| Tenancy bypass: a Tenant-override added field leaks across Tenants. | Per RFC-003 §9, the merged view is per-Tenant; a Tenant's added field is included only in that Tenant's schemas. Cache key includes `(tenantId, overrideVersion)`. |
| Audit bypass | Reads are not audited by default (RFC-001 §8.3); §5 does not change that. |
| Cache incoherence: hints change but the cache key does not. | Hints are deterministic per profile and Field; both are in the cache key. |
| **Omission bypass: a referenced reference field is `embedded`, and the embedded fields leak data the user cannot see.** | §5.4 embedded fields are themselves subject to `visibility` evaluation. Embedded mode applies Field-level Policy evaluation transitively. A profile that requests `embeddedRelations` does not bypass visibility. |
| SemVer trap: adding a new widget identifier in V1.x. | Widget identifiers are profile-defined (§10.2); the profile registry advertises supported widgets. Adding one to a profile is the profile's own minor bump, not the schema's. |

### 17.4 Actions (§6)

| Attack | Defence |
|---|---|
| Layer violation: Renderer attempts to invoke an Action's effect locally. | Action descriptors carry no effect; they carry FQN + inputs. Invocation is exclusively through L4 (RFC-001 §4.5). |
| Backend leakage: Action `fqn` could be parsed for backend class lookup. | FQN is `namespace.entity.action`, a domain identifier; no class path correspondence is guaranteed. |
| Tenancy bypass: Renderer invokes an Action on behalf of another Tenant. | Invocation goes through L4, which binds Tenant from the active Tenant Context, not from the Renderer's request payload. |
| Audit bypass: Renderer suppresses confirmation, invokes a destructive Action. | The confirmation block is advisory UX. Server-side authorization (Policies, Workflow guards) is authoritative. |
| Cache incoherence: an Action's `inputs` list changes mid-deployment. | Cache key includes `actorRoleHash`; Policy changes affecting input visibility change the hash. Pure descriptor changes update the graph hash. |
| Omission bypass: an Action's `inputs` references an omitted Field. | §6.2: the Action is itself omitted. |
| SemVer trap: changing `confirmation.challenge.type` value set. | Sealed set; adding new types is minor only if marked `extensible: true`. Tightening is major. |

### 17.5 Filters (§7)

| Attack | Defence |
|---|---|
| Layer violation: Renderer constructs a Filter the operator set does not permit. | The Renderer sends filter inputs, L4 reconstructs the Filter on the server side from the descriptor and inputs; unknown operators are rejected at L4. |
| Backend leakage: `relation-exists.relation` exposes internal relation names. | Relation names are part of the kernel descriptor (RFC-001 §2.3); they are domain identifiers, intentionally part of the API. |
| Tenancy bypass: a filter on `tenant_id` reaches another Tenant. | The Repository / ReportingDriver enforce Tenant scope at the storage layer (RFC-002 §13.1). Filter cannot override it. |
| Audit bypass | n/a; filters are reads. |
| Cache incoherence | Filter descriptors are computed from the Projection; same Projection version = same descriptors. |
| Omission bypass: a filter targets an omitted Field. | §11.2 step 5: omit the filter. |
| SemVer trap: adding a new operator. | Linked to RFC-002 §10.1 amendment + RFC-004 minor bump together. The two grammars stay synchronized. |

### 17.6 Data (§8)

| Attack | Defence |
|---|---|
| Layer violation: Renderer expects `data` to carry mutable handles to backend objects. | `data` items are JSON, byte-for-byte. No handles. |
| Backend leakage: `_version` exposes driver internals. | RFC-002 §8 makes `Version::value()` opaque. Renderer round-trips unchanged. |
| Tenancy bypass: data items from another Tenant. | RFC-002 §5.3.1: every returned Entity carries a `Reference` whose `tenant_id` matches the active Tenant. Cross-Tenant leakage is a driver conformance failure. |
| Audit bypass | Reads are unaudited by default. |
| Cache incoherence: data freshness vs metadata freshness diverge. | Cache key includes data-affecting components implicitly via `overrideVersion`. For data-bearing requests, the cache TTL is typically short (deployment configures); for pure-metadata Projections, longer. Owned by L5 caching policy. |
| **Omission bypass: a data item includes a Field key omitted from `fields`.** | §8.2: forbidden. Conformance test verifies that every key in every item exists in `fields`. |
| SemVer trap: changing `_version` to `__version`. | Frozen for V1; rename is a major bump. |

### 17.7 Locale (§9)

| Attack | Defence |
|---|---|
| Layer violation | Locale resolution is server-side; Renderer cannot influence translation lookup paths. |
| Backend leakage: untranslated source strings leak developer language. | §9.1 fallback emits the source string when all translations fail; this is a deployment configuration concern, not a wire-format leak. |
| Tenancy bypass | Locale is request-scoped; not Tenant-scoped. |
| Audit bypass | n/a. |
| Cache incoherence: a translation update doesn't invalidate caches. | Translation bundles are part of the deployment artifact; updating bundles bumps the graph hash. New hash → new cache key. |
| Omission bypass | n/a. |
| SemVer trap: switching to client-side i18n. | Major bump; current commitment is server-resolved. |

### 17.8 Capability negotiation (§10)

| Attack | Defence |
|---|---|
| Layer violation: Renderer registers its own profile dynamically and elevates capabilities. | Profile registry is bound at boot (§10.1); runtime registration is not part of the contract. |
| Backend leakage | Profile data is the profile's own metadata; not backend internals. |
| Tenancy bypass | Capabilities apply per request, not per Tenant; negotiation outcome is recorded in the schema's `compatibility` block. |
| Audit bypass | Negotiation does not mutate; nothing to audit. |
| **Cache incoherence: changing a profile's capabilities mid-deployment leaks old caches.** | Cache key includes `profileFqn` (which encodes a major version). Minor profile updates that add capabilities are still keyed under the same FQN; old caches remain valid for the lower capability set. Adding capabilities does not invalidate old emissions; removing or changing capabilities requires a new profile FQN (a new major). |
| **Omission bypass: a profile that does not support `inlineValidation` causes validation to be dropped silently.** | §10.5: every drop is recorded in `compatibility.downgrades`. Silent drops are conformance failures. |
| SemVer trap: implicit downgrade of `embeddedRelations` for all profiles. | Downgrades are per-profile, explicitly listed in §10.4. New downgrades are minor bumps. |

### 17.9 Transformation (§11)

| Attack | Defence |
|---|---|
| Layer violation: transformation calls into a Plugin's helper to enrich the schema. | §11.1 lists all inputs; nothing else. Transformations consulting plugin helpers outside Field hints / Action descriptors are conformance failures. |
| Backend leakage: error envelopes leak backend exception strings. | §11.4 short-circuits to L4 error envelopes; sanitization is RFC-005's responsibility. |
| Tenancy bypass: transformation uses base graph without applying Tenant overlay. | §11.1 explicitly names the merged graph as the source. Conformance test verifies. |
| Audit bypass | Reads only. |
| Cache incoherence: non-deterministic ordering breaks cache. | §11.3 enumerates the determinism rules. Conformance test verifies byte-identical output for identical inputs (excluding `generatedAt` per §12.2). |
| Omission bypass: a Field visible to the Actor for one Subject is omitted for another. | Visibility is per-Subject (`Subject | null` in the Policy signature). Cache key includes `actorRoleHash` but does not include the Subject. Therefore the cache is safe ONLY for metadata-only requests; data-bearing requests with Subject-dependent visibility MUST NOT be cached across Subjects. The Presentation layer either disables caching for such Projections or scopes the cache key further. Acknowledged trade-off; conformance test must catch attempts to over-cache. |
| SemVer trap: transformation algorithm changes alter output. | The algorithm is normative (§11.2). Any change requires a minor bump if it produces additive output, a major if it changes existing output. |

### 17.10 Caching (§12)

| Attack | Defence |
|---|---|
| Layer violation: Renderer attempts to invalidate the L5 cache. | No invalidation API exposed to Renderer. Eviction is server-side only. |
| Backend leakage | Cache key is opaque on the wire. |
| Tenancy bypass: cache pollution across Tenants. | Cache key includes `(tenantId, overrideVersion)`. Cross-Tenant collision is structurally impossible. |
| Audit bypass | Cached responses do not skip audit because reads are unaudited by default; cached writes do not exist (writes are Actions, never cached). |
| Cache incoherence | §12.1 fixed tuple; new dimensions are minor bumps. |
| Omission bypass: cache returns a schema computed for a different Actor role. | `actorRoleHash` in the key prevents this when the Authorization plugin produces correct hashes. Conformance test on the Authorization plugin. |
| SemVer trap: changing the hashing of `cacheKey` echo. | `cacheKey` is opaque (§12.4); hash changes are invisible to Renderers that treat it as such. |

---

## 18. Acceptance criteria

This RFC is accepted when:

1. The three role signatories (architect, kernel, challenger) sign off on §3, §5, §10, §11, §13.
2. RFC-005 (API Surface) commits to the error-code bindings in §16.1.
3. RFC-010 (ReportingDriver) commits to consuming Amendment-01 §A-1.2's `kernel.field.read` sentinel for storage-tier visibility filtering (§16.3).
4. The first-party `react.web.v1` profile is fully enumerated (§10.2) and registered at boot by the Kernel reference Renderer package.
5. A conformance test suite is scoped (not built) before V1: at minimum, one test per §13 anti-pattern, one test per §11.3 determinism rule, one test verifying the data-bearing cache constraint of §17.9.
6. Appendices A and B below are reviewed by the React renderer author for completeness.
7. Appendices C and D are re-run before each subsequent draft.

Once accepted, this RFC is the source of truth for the L5↔L6 boundary. Any contradiction in a future RFC requires an amendment to this document or an explicit "supersedes."

---

## Appendix A — JSON Schema (informative)

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://ausus.dev/schemas/viewschema/1.0.0.json",
  "title": "AUSUS ViewSchema 1.0.0",
  "type": "object",
  "required": ["schemaVersion","targetProfile","metadata","compatibility","fields","actions","filters","data"],
  "additionalProperties": false,
  "properties": {
    "schemaVersion":  { "type": "string", "pattern": "^1\\.[0-9]+\\.[0-9]+$" },
    "targetProfile":  { "type": "string" },
    "metadata": {
      "type": "object",
      "required": ["projection","entity","tenant","locale","generatedAt","cacheKey","actorRoleHash"],
      "additionalProperties": false,
      "properties": {
        "projection":    { "type": "string" },
        "entity":        { "type": "string" },
        "tenant":        { "type": "string" },
        "locale":        { "type": "string" },
        "generatedAt":   { "type": "string", "format": "date-time" },
        "cacheKey":      { "type": "string" },
        "actorRoleHash": { "type": "string" }
      }
    },
    "compatibility": {
      "type": "object",
      "required": ["requestedProfile","negotiatedProfile","requestedVersions","emittedVersion","downgrades","rejectedCapabilities"],
      "additionalProperties": false
    },
    "fields":  { "type": "array", "items": { "$ref": "#/$defs/Field" } },
    "actions": { "type": "array", "items": { "$ref": "#/$defs/Action" } },
    "filters": { "type": "array", "items": { "$ref": "#/$defs/Filter" } },
    "data":    { "oneOf": [ { "type": "null" }, { "$ref": "#/$defs/ListData" }, { "$ref": "#/$defs/ItemData" } ] }
  },
  "$defs": {
    "Field":   { "$comment": "shape per §5" },
    "Action":  { "$comment": "shape per §6" },
    "Filter":  { "$comment": "shape per §7" },
    "ListData":{ "$comment": "shape per §8" },
    "ItemData":{ "$comment": "shape per §8" }
  }
}
```

The full `$defs` are omitted here for brevity and live in the Kernel reference Renderer package as the authoritative schema artifact.

---

## Appendix B — Example payloads (informative)

### B.1 `list` Projection, no data, English locale

```json
{
  "schemaVersion": "1.0.0",
  "targetProfile": "react.web.v1",
  "metadata": {
    "projection": "billing.invoice.list",
    "entity":     "billing.invoice",
    "tenant":     "acme",
    "locale":     "en-US",
    "generatedAt":"2026-05-18T14:32:00Z",
    "cacheKey":   "a1b2c3d4...",
    "actorRoleHash": "9f8e7d..."
  },
  "compatibility": {
    "requestedProfile":  "react.web.v1",
    "negotiatedProfile": "react.web.v1",
    "requestedVersions": ["1.0","1.0.0"],
    "emittedVersion":    "1.0.0",
    "downgrades":        [],
    "rejectedCapabilities": []
  },
  "fields": [
    { "name": "number", "type": "string", "label": "Invoice Number",
      "help": null, "nullable": false, "readOnly": false,
      "typeOptions": { "maxLength": 32 },
      "hints": { "widget": "text", "width": "small" },
      "validation": { "required": true, "minLength": 1, "maxLength": 32 } },
    { "name": "customer", "type": "reference", "label": "Customer",
      "help": null, "nullable": false, "readOnly": false,
      "typeOptions": { "targetEntity": "billing.customer",
                       "mode": "embedded",
                       "embeddedFields": ["id","display_name"] },
      "hints": { "widget": "reference-card", "width": "medium" },
      "validation": { "required": true } },
    { "name": "status", "type": "enum", "label": "Status",
      "help": null, "nullable": false, "readOnly": true,
      "typeOptions": { "options": [
        { "value": "draft",  "label": "Draft" },
        { "value": "issued", "label": "Issued" },
        { "value": "paid",   "label": "Paid" },
        { "value": "void",   "label": "Void" }
      ] },
      "hints": { "widget": "badge", "width": "small" },
      "validation": { "required": true } }
  ],
  "actions": [
    { "fqn": "billing.invoice.create", "name": "create",
      "label": "New invoice", "description": null, "icon": "plus",
      "subjectRequired": false,
      "inputs": [],
      "confirmation": null,
      "audited": true,
      "maintenance": false,
      "hints": { "style": "primary" } }
  ],
  "filters": [
    { "name": "status", "field": "status", "operator": "in",
      "label": "Status",
      "operands": { "valueType": "enum",
                    "options": [
                      { "value": "draft",  "label": "Draft" },
                      { "value": "issued", "label": "Issued" },
                      { "value": "paid",   "label": "Paid" }
                    ] },
      "default": null }
  ],
  "data": null
}
```

### B.2 Downgrade example

A profile lacking `embeddedRelations`:

```json
{
  "compatibility": {
    "requestedProfile":  "react.mobile.v1",
    "negotiatedProfile": "react.mobile.v1",
    "requestedVersions": ["1.0"],
    "emittedVersion":    "1.0.0",
    "downgrades": [
      { "capability": "embeddedRelations",
        "appliedTo": [ "fields[1].customer" ],
        "fallback":  "reference-only" }
    ],
    "rejectedCapabilities": []
  },
  "fields": [
    /* ... */
    { "name": "customer", "type": "reference",
      "typeOptions": { "targetEntity": "billing.customer",
                       "mode": "reference-only" },
      /* ... */ }
  ]
}
```

### B.3 Rejection example

A profile that lacks the `string-match` operator on a Projection that requires it:

```json
{
  "error": {
    "kind":   "IncompatibleRenderer",
    "reason": "Profile react.mobile.v1 does not support operator string-match required by filter 'number'.",
    "code":   "RFC004.IncompatibleRenderer",
    "downgrades": [],
    "rejectedCapabilities": [ "operator:string-match" ]
  }
}
```

The error envelope is RFC-005's concern; this payload is illustrative of how RFC-005 would wrap RFC-004's negotiation outcome.

---

## Appendix C — Contradiction scan

| ID    | Description | Status |
|-------|-------------|--------|
| C4-01 | §5.6 (strict omission) vs §17.9 ("cache safe ONLY for metadata-only requests"). | Consistent; the cache constraint is the necessary consequence of per-Subject visibility. Conformance test catches over-caching. |
| C4-02 | §6.2 (omit Action whose required input is omitted) vs Amendment-01 §A-1.3 (overrides are additive). | Consistent. Visibility-driven omission is not an override; it is per-request Policy filtering. Overrides remain additive against the base graph. |
| C4-03 | §8.4 (`_version` reserved) vs RFC-001 §5.8.5 (no domain logic at definition time). | Consistent. The underscore reservation is a wire-format constraint, enforced at Field registration, not at DSL execution. |
| C4-04 | §10.4 (downgrade list) vs §13 #6 (no `redacted: true` flag). | Consistent. Downgrades report a capability shift, not per-Field redaction. |
| C4-05 | §11.3 (deterministic output) vs §3.2 (`metadata.generatedAt`). | §12.2 excludes `generatedAt` from the cacheable body, preserving byte-identity for cached content. |
| C4-06 | §12.1 cache key vs RFC-001 §7.2 cache key proposal `(graph hash, tenant, projection, actor-role hash, locale)`. | RFC-004's key is a superset, adding `overrideVersion`, `profileFqn`, `schemaVersion`. Compatible extension. |
| C4-07 | §8.1 (Projection kind determines `data` shape) vs §16.6 (composite ViewSchemas deferred). | Consistent; one-kind-per-document is the V1 rule. |
| C4-08 | §9.4 (no client-side i18n) vs Renderer needing date/number formatting per locale. | Date/number formatting is the Renderer's concern using the locale value in `metadata.locale`; strings localized server-side. No contradiction. |
| C4-09 | §10.4 (downgrades) vs §17.8 (silent drops forbidden). | Consistent; every downgrade is enumerated. |
| C4-10 | §13 anti-patterns vs §17 challenger defences. | Mutually reinforcing. |

**Result.** No contradictions. One enumerated trade-off (C4-01) is acknowledged in §17.9.

---

## Appendix D — Layer boundary scan

| Component | Layer | Inbound | Outbound | Result |
|---|---|---|---|---|
| ViewSchema document | wire format between L5 and L6 | produced by L5 | consumed by L6 | OK |
| Envelope, fields, actions, filters, data | L5-emitted | — | rendered by L6 | OK |
| Profile registry | L5 + L0 contract | populated at boot by L7 plugins (renderer packages) | consulted by L5 during negotiation | OK |
| Cache key | L5-internal | constructed by L5 | echoed in §12.4 to Renderer (opaque) | OK |
| `metadata.tenant`, `metadata.locale`, `metadata.cacheKey` | wire-format metadata | from L2/L3 context | informational to L6 | OK |
| Action `fqn` | L0 domain identifier | from Projection | dispatched by L6 to L4 | OK |

**Findings.**

| ID | Description | Resolution |
|---|---|---|
| L4-01 | Profile registry is consulted at L5 but populated by L7 plugins. Does this cross-layer call constitute a violation? | No. Plugins contribute via the registry's L0 contract; L5 consults the contract. Direction is L5 → L0 ← L7. The L7 plugin does not call L5. |
| L4-02 | `actorRoleHash` is produced by the Authorization plugin (L7) and consumed by L5 for cache keying. | Same shape as L4-01. The hash is exposed via an L0 contract on the Authorization plugin. |
| L4-03 | `data` items contain Repository / ReportingDriver outputs (L3). L5 reads from L3 during data-bearing requests. | Per RFC-002 §3.1, the Repository contract is L0; the Repository instance is bound at L3. L5 calls the L0 contract; the L3 implementation responds. Consistent with the existing pattern. |
| L4-04 | The Renderer (L6) parses JSON. Does any field name expose a backend type? | §13.1: no backend FQNs. Field FQNs are domain identifiers (e.g., `billing.invoice.number`), not class paths. Conformance: Presentation layer rejects field names containing `\\` or PHP-style namespace separators. |
| L4-05 | `_version` (§8.4) is RFC-002 opaque `Version::value()`. Does this leak Persistence Driver internals? | RFC-002 §8.2 commits `Version::value()` to opacity. Wire format carries the opaque string. No leak. |

**Result.** No layer violations. The five findings all resolve cleanly under existing contracts.
