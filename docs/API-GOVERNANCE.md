# API Governance — AUSUS v0.1

**Status:** ratified for v0.1.x
**Companion document:** [`SEMVER-CONTRACT.md`](SEMVER-CONTRACT.md)
**Scope:** every public symbol shipped by `ausus/kernel`, `ausus/persistence-sql`,
`ausus/runtime-default`, `ausus/api-http`, and `@ausus/renderer-react`, plus
the JSON wire formats and naming conventions they imply.

The governance tiers below tell you, for every export:

- whether you can rely on it in production
- what level of churn it can see in MINOR releases
- whether removal triggers a MAJOR bump (post-1.0)

---

## 0. Tier definitions

| Tier | Promise | MINOR churn allowed? | Required to document removal? |
|---|---|---|---|
| **STABLE** | locked surface; reliable in production builds | only additive (new methods, new optional params) | yes — deprecation in MINOR, removal in MAJOR |
| **EXPERIMENTAL** | shipped but explicitly scoped as in-flux | yes — signature changes permitted | called out in `CHANGELOG.md` |
| **INTERNAL** | not part of the public contract; do not depend | yes — any change at any time | no — internal moves never noted |
| **ACCIDENTAL** | technically reachable but not intended as API | should be hidden / `@internal` annotated | flagged in §6 |
| **EXAMPLE** | demo / starter / playground code; **never** depended on | replaced freely | n/a |

PHP packages autoload via classmap, so every class in `src/` is reachable
regardless of intent. PHP has no `internal` modifier. We therefore mark
INTERNAL classes with a `/** @internal */` docblock — see §6.

---

## 1. PHP — `Ausus\` (kernel)

### 1.1 Value objects — STABLE

| Symbol | Notes |
|---|---|
| `Ausus\TenantId`                  | `final readonly`, single `value: string` |
| `Ausus\Tenant`                    | wraps a `TenantId` |
| `Ausus\ActorRef`                  | `(type, id, homeTenant)` |
| `Ausus\Reference`                 | `(tenantId, entityFqn, identityHandle)` — primary cross-tier handle |
| `Ausus\Subject`                   | `(tenantId, entityFqn, identityHandle)` — RFC-005 Policy input |
| `Ausus\Version`                   | single `value: string` (a ULID, see §5.2) |
| `Ausus\Instant`                   | `epochSeconds: float` + `toRfc3339()` |
| `Ausus\Decision` (enum)           | `Permit`, `Deny`, `Abstain` |
| `Ausus\Entity`                    | Repository return value: `(reference, version, fields)` + `field(name)` |
| `Ausus\SingleSubject`             | audit single-subject shape (RFC-007) |
| `Ausus\AuditEntry`                | audit row shape (RFC-007) |

### 1.2 Contracts (interfaces) — STABLE

| Interface | Implementers / consumers |
|---|---|
| `Ausus\Actor`               | implemented by `StubActor` + future `ausus/auth-bridge` |
| `Ausus\Policy`              | implemented by `Ausus\Runtime\RoleRequired` + user policies |
| `Ausus\Effect`              | implemented by `CreateEffect`, `TransitionEffect`, user effects |
| `Ausus\EffectContext`       | constructed by Invoker; consumers see it from inside `Effect::execute` |
| `Ausus\Repository`          | implemented by `SqliteRepository`; consumers receive it from a `PersistenceContext` |
| `Ausus\PersistenceDriver`   | implemented by `SqlitePersistenceDriver`; consumers swap drivers here |
| `Ausus\PersistenceContext`  | per-`(Tenant, TransactionHandle)` |
| `Ausus\TransactionHandle`   | yielded by `PersistenceDriver::beginTransaction` |
| `Ausus\AuditSink`           | implemented by `DatabaseAuditSink` |
| `Ausus\Auditor`             | implemented by `Ausus\Runtime\DefaultAuditor` |
| `Ausus\Plugin`              | implemented by user plugins (HelloInvoice, future plugins) |

### 1.3 Concrete plugin helper — STABLE

| Symbol | Notes |
|---|---|
| `Ausus\StubActor`           | demo-grade Actor; documented for non-prod auth |

### 1.4 Metadata graph — STABLE

| Symbol | Notes |
|---|---|
| `Ausus\FieldNode`, `ActionNode`, `PolicyNode`, `WorkflowNode`, `TransitionNode`, `ProjectionNode`, `EntityNode` | `final readonly` descriptor records |
| `Ausus\MetadataGraph`       | sealed compiler output: `(hash, kernelVersion, entities, actions, policies, workflows, projections)` |

Constructor arities are part of the contract (see §1.2 of `SEMVER-CONTRACT.md`).

### 1.5 Compiler — STABLE

| Symbol | Notes |
|---|---|
| `Ausus\Compiler::compile(array $plugins, string $kernelVersion = '1.0.0'): MetadataGraph` | signature locked |
| Graph hash format          | 64-char hex SHA-256 over canonical JSON of sorted FQN-key arrays (see SEMVER §5.1) |

### 1.6 Identity — EXPERIMENTAL

| Symbol | Notes |
|---|---|
| `Ausus\Ulid::generate(): string` | format guaranteed (Crockford base32, 26 chars, monotonic-within-process); generator IS public but the implementation is replaceable. Consumers depend on the **format**, not the class. |

### 1.7 Exception taxonomy — STABLE (catchable)

```
Ausus\AususError                       (base)
├── Ausus\UnknownAction
├── Ausus\TenantBoundaryViolation
├── Ausus\PolicyDenied
├── Ausus\WorkflowStateMismatch
├── Ausus\WorkflowSubjectNotFound
├── Ausus\EffectFailed
├── Ausus\ConcurrencyConflict
├── Ausus\NotFound
├── Ausus\AuditEmissionFailed
└── Ausus\WorkflowGuardDenied
```

Catch `Ausus\AususError` to swallow every framework-throwable. Catch a
specific subclass to handle that case. The class hierarchy is part of
the contract — subclass tree never narrows in a MINOR.

### 1.8 Internal — pre-condition exceptions

`PolicySubjectRequired`, `ActorRequired`, `TenantContextRequired` are
**not part of the catchable taxonomy** — they signal *internal* contract
violations (the caller mis-wired the Invoker). They're reachable but
should never appear in user code.

### 1.9 Context — INTERNAL

`Ausus\Context` is built internally by the Invoker to bind `(Tenant,
correlationId, traceId, clock)`. Consumers receive it transparently via
`EffectContext`. Do not construct it directly.

---

## 2. PHP — `Ausus\Runtime\` (runtime-default)

### 2.1 Runtime engines — STABLE

| Symbol | Notes |
|---|---|
| `Ausus\Runtime\Invoker`              | 5-step chain executor; constructor signature locked |
| `Ausus\Runtime\PolicyEngine`         | Policy resolution + DENY/Abstain handling |
| `Ausus\Runtime\WorkflowRuntime`      | per-Workflow source-state selection (RFC-006 Amendment-01) |
| `Ausus\Runtime\TransitionSetIndex`   | O(1) lookup over Workflow transitions |
| `Ausus\Runtime\EffectDispatcher`     | `kernel.builtin.create` / `kernel.builtin.transition` markers + user-class `new` |
| `Ausus\Runtime\DefaultAuditor`       | wraps any `AuditSink` |
| `Ausus\Runtime\SequenceCounter`      | per-tenant monotonic 64-bit sequence |
| `Ausus\Runtime\ProjectionRenderer`   | renders a Projection into RFC-004 ViewSchema |

### 2.2 Built-in Policy — STABLE

| Symbol | Notes |
|---|---|
| `Ausus\Runtime\RoleRequired` | constructor `(string $role)`; FQN appears in PolicyNode.implementationClass |

### 2.3 Built-in Effects — INTERNAL

| Symbol | Notes |
|---|---|
| `Ausus\Runtime\CreateEffect`     | accessed via `effectClass: 'kernel.builtin.create'` marker; do not construct directly |
| `Ausus\Runtime\TransitionEffect` | accessed via `effectClass: 'kernel.builtin.transition'` marker |
| `Ausus\Runtime\DefaultEffectContext` | constructed by Invoker; consumers see only the `EffectContext` interface |

---

## 3. PHP — `Ausus\Persistence\Sql\` (persistence-sql)

### 3.1 Driver entry points — STABLE

| Symbol | Notes |
|---|---|
| `Ausus\Persistence\Sql\SqlitePersistenceDriver` | constructor `(PDO, MetadataGraph)`; implements `PersistenceDriver` |
| `Ausus\Persistence\Sql\SchemaDeriver`           | static `deriveAll(MetadataGraph): string[]` (SQL DDL) |
| `Ausus\Persistence\Sql\DatabaseAuditSink`       | constructor `(PDO)`; implements `AuditSink` |

### 3.2 Driver internals — INTERNAL

| Symbol | Notes |
|---|---|
| `Ausus\Persistence\Sql\SqliteTransactionHandle` | returned from `beginTransaction`; treat as opaque |
| `Ausus\Persistence\Sql\SqliteContext`           | returned from `context()`; treat as `PersistenceContext` |
| `Ausus\Persistence\Sql\SqliteRepository`        | returned from `context()->repository()`; treat as `Repository` |

---

## 4. PHP — `Ausus\Api\Http\` (api-http)

### 4.1 HTTP surface — STABLE

| Symbol | Notes |
|---|---|
| `Ausus\Api\Http\Router`       | `implements RequestHandlerInterface`; routes frozen (RFC L4 §1) |
| `Ausus\Api\Http\Emitter`      | minimal PSR-7 → SAPI emit utility |
| `Ausus\Api\Http\BadRequest`   | catchable when receiving malformed input |

### 4.2 HTTP internals — INTERNAL

| Symbol | Notes |
|---|---|
| `Ausus\Api\Http\ErrorMapper`  | used by `Router::handle`; consumers should never call it directly |

---

## 5. PHP — DSL facades (under `Ausus\` namespace)

The DSL facades currently live in the root `Ausus\` namespace (same as
the kernel value objects). Moving them to a dedicated `Ausus\Dsl\`
sub-namespace is a v0.2 candidate, but is **not** breaking the v0.1
contract because the names are unique.

### 5.1 DSL fluent surface — STABLE

| Symbol | Notes |
|---|---|
| `Ausus\DslPlugin`      | abstract base class for DSL-style plugins (`extends DslPlugin`) |
| `Ausus\Dsl`            | top-level builder: `Dsl::entity('billing.invoice')->fields(…)` |
| `Ausus\EntityBuilder`  | fluent entity builder |
| `Ausus\FieldBuilder`   | fluent field builder |
| `Ausus\ActionBuilder`  | fluent action builder; methods: `create()`, `transition()`, `andTransition()`, `role()`, `policy()`, `inputs()` |
| `Ausus\Field`          | static facade: `Field::string()`, `Field::integer()`, `Field::enum(...)`, `Field::money()`, `Field::datetime()` |
| `Ausus\Action`         | static facade: `Action::create()`, `Action::transition()` |

### 5.2 DSL invariant — STABLE

> **Byte-identical hash equivalence (RFC-011 §11).** For any plugin
> authored via the DSL, its compiled `MetadataGraph.hash` MUST equal the
> hash of the same plugin authored via the manual descriptor-array form
> with the same content. Tested by `apps/playground/run.php` test 10.

---

## 6. PHP — accidental public exposure

Classmap autoload makes the following classes reachable from any
consumer, but they are NOT part of the contract. They will be tagged
`@internal` in their docblock; rely on the interfaces or marker strings
instead.

| Class | Use the interface / marker instead |
|---|---|
| `Ausus\Context`                                   | `EffectContext` (interface) |
| `Ausus\Runtime\DefaultEffectContext`              | `EffectContext` (interface) |
| `Ausus\Runtime\CreateEffect`                      | `effectClass: 'kernel.builtin.create'` marker |
| `Ausus\Runtime\TransitionEffect`                  | `effectClass: 'kernel.builtin.transition'` marker |
| `Ausus\Persistence\Sql\SqliteTransactionHandle`   | `TransactionHandle` (interface) |
| `Ausus\Persistence\Sql\SqliteContext`             | `PersistenceContext` (interface) |
| `Ausus\Persistence\Sql\SqliteRepository`          | `Repository` (interface) |
| `Ausus\Api\Http\ErrorMapper`                      | Router handles error mapping; do not call directly |
| `Ausus\PolicySubjectRequired`, `ActorRequired`, `TenantContextRequired` | internal pre-condition violations; never appear in working code |

This list is the **complete** accidental-exposure set for v0.1. Adding
a new accidental exposure during v0.1.x is a defect, not a feature.

---

## 7. npm — `@ausus/renderer-react`

### 7.1 Main entrypoint — STABLE

```ts
// import "@ausus/renderer-react"
export { AususProvider, useAusus }                       // context
export { useViewSchema, useAction }                      // hooks
export { ViewSchemaConsumer }                            // top-level dispatcher
export { ListView, DetailView, ActionModal,
         WorkflowBadge, FieldDisplay }                   // 5 view primitives

export type { ViewSchema, FieldDescriptor,
              ActionDescriptor, FilterDescriptor,
              Reference, ActionResult, Fetcher }
```

**10 named exports + 7 named types** — frozen for v0.1.x. Adding more is
allowed (additive); removing or renaming requires a MAJOR.

### 7.2 Subpath entrypoint — STABLE

```ts
// import "@ausus/renderer-react/types"
// re-exports every type from the main types module, including:
export interface ViewSchemaMetadata     // accessible only via /types
```

### 7.3 Renderer internals — INTERNAL

These functions are defined but NOT exported (no `export` keyword):
`ActionButton`, `ActionBar`, `formatMoney`, `isWorkflowStateField`,
`BADGE_PALETTE`. Consumers can't reach them through any exposed module
path. They can change in any MINOR.

### 7.4 Component prop types — STABLE

- `AususProvider`: `{ apiBaseUrl, tenant, fetcher?, children }`
- `useViewSchema(projection, subject?)` returns `{ schema, loading, error, refetch }`
- `useAction(actionFqn)` returns `{ invoke, pending, lastError }`
- `ListView`: `{ schema, onRefetch }`
- `DetailView`: `{ schema, subject?, onRefetch }`
- `ActionModal`: `{ action, subject?, onClose, onSuccess? }`
- `WorkflowBadge`: `{ value: string | null | undefined }`
- `FieldDisplay`: `{ field, value }`

Adding optional props is non-breaking. Renaming or removing props is
breaking (MAJOR).

---

## 8. JSON wire formats — STABLE

These are the cross-language contracts that bind PHP ↔ HTTP ↔ React.
Format changes require a MAJOR bump or an explicit version field.

### 8.1 `ViewSchema` (RFC-004 §3.1) — STABLE

| Field | Type | Notes |
|---|---|---|
| `schemaVersion`  | string  | `"1.0.0"` for v0.1; semver |
| `targetProfile`  | string  | `"react.web.v1"` |
| `metadata`       | object  | `{ projection, entity, tenant, locale?, generatedAt? }` |
| `fields[]`       | array   | `FieldDescriptor` |
| `actions[]`      | array   | `ActionDescriptor` |
| `filters[]`      | array   | `FilterDescriptor` (V0: empty) |
| `data`           | union   | `{items, pagination?}` (List) \| `{item}` (Detail) \| `null` |

Renderer dispatches on `data` shape (`items` vs `item`).

### 8.2 `ActionResult` — STABLE

```json
{ "ok": true,  "outputs": { ... } }
{ "ok": false, "error": { "kind": "<TypedKind>", "message": "..." } }
```

`error.kind` is one of the strings listed in `docs/L4-API-DESIGN.md §4`.
The mapping `kind ↔ HTTP status` is part of the contract.

### 8.3 ULID — STABLE

Crockford base32, 26 chars, monotonic within process.
Regex: `^[0-9A-HJKMNP-TV-Z]{26}$`. Consumers MUST treat ULIDs as opaque
strings; do not parse the timestamp prefix to drive logic.

### 8.4 MetadataGraph hash — STABLE

```php
hash('sha256',
    json_encode([
        'actions'       => sorted_action_fqns,
        'entities'      => sorted_entity_fqns,
        'kernelVersion' => string,
        'policies'      => sorted_policy_fqns,
        'projections'   => sorted_projection_fqns,
        'workflows'     => sorted_workflow_fqns,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
)
```

**Stability promise:** for any plugin set whose FQN keys are
byte-identical, the hash is byte-identical. This is the basis for the
DSL parity invariant (§5.2).

### 8.5 HTTP headers — STABLE

| Header | Direction | Status |
|---|---|---|
| `X-Tenant-ID`       | client → server | required on `/projections`, `/actions` — **frozen** |
| `Content-Type: application/json` | client → server | required on POST — **frozen** |
| `Access-Control-Allow-Origin: *` | server → client | V0 demo policy — **EXPERIMENTAL** (production deployments will restrict) |

### 8.6 Headers — EXPERIMENTAL (V0 stub-actor only)

| Header | Status |
|---|---|
| `X-Actor-Id`        | EXPERIMENTAL — replaced by real auth middleware in v0.2 |
| `X-Actor-Roles`     | EXPERIMENTAL — same |

A consumer relying on these in v0.1.x will need to migrate once
`ausus/auth-bridge` ships (RFC-014).

---

## 9. Conventions — INTERNAL metadata (not consumer-API)

These appear in `composer.json` but are **not** part of the contract:

```json
"extra": {
  "ausus": {
    "layer":              "L0" | "L2" | "L3" | "L4" | "L5" | "L7" | "meta",
    "role":               "kernel" | "runtime" | "driver" | "presentation" | "tenancy" | "audit-sink" | …,
    "status":             "skeleton" (optional — marks v0.1 reservation),
    "implementation-rfc": "RFC-007" (optional),
    "v0-scope":           [list of FQNs included by standard-stack]
  }
}
```

These are informational. Consumers must not key behavior off them.

---

## 10. Effect class markers — STABLE

```
'kernel.builtin.create'      → CreateEffect      (built-in)
'kernel.builtin.transition'  → TransitionEffect  (built-in)
'\\Fully\\Qualified\\Class'  → user-defined Effect, instantiated via `new`
```

The two `kernel.builtin.*` strings are part of the contract. User
effect FQNs follow PSR-4 / classmap autoload rules.

---

## 11. Determination

Every public symbol in v0.1.x is now classified into one of five tiers.
Accidental exposures are flagged in §6 and tagged `@internal` in their
docblocks (separate commit). No symbol is in an undefined state.

**Status: ratified for v0.1.0 → v0.1.x.**

Any new public symbol in v0.1.x MUST be reviewed against this document
and assigned a tier in its accompanying CHANGELOG entry.
