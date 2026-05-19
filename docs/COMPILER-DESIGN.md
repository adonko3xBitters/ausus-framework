# AUSUS Compiler — V0 Implementation Design

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Active design (V0 implementation phase)                |
| Authors       | architect, kernel maintainer                           |
| Date          | 2026-05-19                                             |
| Scope         | Smallest executable compiler that produces a frozen, hashed Metadata Graph from a Plugin's DSL declarations |
| Owning package | `ausus/kernel` (namespaces `Ausus\Kernel\Compiler\*` + `Ausus\Kernel\Graph\*`) |

Implements: RFC-001 §4.2 (compilation), §5.8 (DSL invariants), §4.2.5 (graph hash), RFC-011 (DSL surface + FQN elision), RFC-006 (Workflow inference). Consumed by: RFC-005 (Policy Engine), RFC-006 (Workflow Runtime), RFC-013 (Effect dispatch), RFC-004 (Presentation), RFC-010 (Reporting).

This document is the **how** for the compiler. The frozen RFCs are the **what**. Where this document deviates, the RFCs win.

---

## 1. Mission and scope

### 1.1 Mission

Take a set of `Plugin` classes implementing `Plugin::boot()` and produce a single immutable `MetadataGraph` value object containing every Entity / Action / Policy / Workflow / Projection / Plugin descriptor, validated and cross-referenced, with a deterministic SHA-256 hash.

### 1.2 V0 scope

Sufficient to support: one Plugin, one Entity, one Action, one Projection, one Policy, one Workflow, one Tenant strategy (row). Specifically: the `HelloInvoice` demo from RFC-011 §2.1.

### 1.3 Out of V0

- Optimization passes (no constant folding, no dead-code elimination, no graph minification).
- Plugin hot-reload (process restart only).
- Distributed compilation (single PHP process).
- AST traversal beyond `ReflectionClass` for convention resolution. No PHP-Parser dependency.
- Code generation (no template scaffolding; the DSL IS the source per RFC-001 §9.5).
- Per-Tenant compiled graphs (overrides apply at resolution time per RFC-001 §4.4 / RFC-003 §9, not at compile time).
- Multi-graph deployments. One graph per process.

---

## 2. Compiler architecture

### 2.1 Pipeline (8 stages, strictly sequential)

```
┌─────────────────────┐
│ 1. Plugin Discovery │   Composer installed.json → PluginManifest[]
└──────────┬──────────┘
           ↓
┌─────────────────────┐
│ 2. DSL Execution    │   Plugin::boot() called; DSL writes to Registry
└──────────┬──────────┘
           ↓
┌─────────────────────┐
│ 3. Metadata Collect │   Registry snapshot → RawDescriptor[]
└──────────┬──────────┘
           ↓
┌─────────────────────┐
│ 4. Normalization    │   FQN elision; convention resolution; system fields added
└──────────┬──────────┘
           ↓
┌─────────────────────┐
│ 5. Validation       │   Reference resolution; reserved-namespace; coherence
└──────────┬──────────┘
           ↓
┌─────────────────────┐
│ 6. Canonicalization │   Deterministic ordering; canonical JSON
└──────────┬──────────┘
           ↓
┌─────────────────────┐
│ 7. Graph Freezing   │   RawDescriptor[] → immutable XxxNode[]
└──────────┬──────────┘
           ↓
┌─────────────────────┐
│ 8. Graph Hashing    │   SHA-256(canonical JSON) → hex(64)
└──────────┬──────────┘
           ↓
       MetadataGraph
```

### 2.2 Strict sequentiality

Each stage consumes the previous stage's output and produces input for the next. No back-edges. No re-runs. A failure at any stage raises a `CompilerError` and aborts boot.

This is the simplest possible architecture. No incremental recompilation, no partial graphs, no rollback.

### 2.3 Single-pass guarantee

Each descriptor is visited exactly once per stage. Cross-references are resolved by table lookup (collected during stage 3) rather than by deep traversal. The compiler runs in O(n) over the descriptor count.

---

## 3. Execution lifecycle (boot)

```
Laravel application boot
   ↓
Service providers register (in declared order)
   - KernelServiceProvider binds Compiler, Registry, Container
   - Plugin service providers are instantiated (Plugin classes constructed by container)
   ↓
KernelServiceProvider::boot()
   ↓
Compiler::compile()
   ├─ 1. PluginDiscovery::discover() → PluginManifest[]
   ├─ 2. For each manifest (topologically sorted):
   │       container->make(manifest.providerClass).boot()
   │       (DSL chain writes descriptors into Registry)
   ├─ 3. Registry::snapshot() → RawDescriptor[]
   ├─ 4. Normalizer::normalize(snapshot) → NormalizedDescriptor[]
   ├─ 5. Validator::validate(normalized) [throws on violation]
   ├─ 6. Canonicalizer::canonicalize(normalized) → CanonicalForm
   ├─ 7. GraphBuilder::freeze(canonical) → MetadataGraph (without hash)
   └─ 8. GraphHasher::hash(canonical) → hash; GraphBuilder::seal(graph, hash) → MetadataGraph (final)
   ↓
KernelServiceProvider binds MetadataGraph as singleton in container
   ↓
RuntimeServiceProvider::boot()  // ausus/runtime-default
   - Invoker, PolicyEngine, WorkflowRuntime, Auditor bound, all reading from MetadataGraph
   ↓
L3 driver providers (ausus/persistence-sql, ausus/tenancy-row, etc.) bind their implementations
   ↓
Application ready
```

The compiler runs once per process. Output is cached in the container; subsequent calls reuse the same `MetadataGraph` instance.

### 3.1 Production vs development

- **Production** (`APP_ENV=production`): Compiler results are serialized to `storage/framework/ausus/graph.{hash}.php` after stage 8 (RFC-001 §7.1). On next boot, if a cache file exists for the current plugin manifest hash, the compiler skips stages 1–8 and loads the cache. CI runs `php artisan ausus:compile` to pre-warm.
- **Development** (`APP_ENV=local`): Compiler runs from scratch on every boot. No cache writes. Loud failures surface immediately. (V0 ships dev-mode only; production cache write happens in M2.)

### 3.2 Error propagation

Every stage's failures are caught at the orchestrator and re-raised with stage context:

```
CompilerError::wrap($stage, $cause) → CompilerError with chain
```

Boot aborts. Laravel's exception handler surfaces the error with the stack of stage failures. `ausus:doctor` re-runs the compiler and presents structured output.

---

## 4. Class map

### 4.1 Namespaces

```
Ausus\Kernel\Compiler\          (stages)
Ausus\Kernel\Graph\             (immutable node types)
Ausus\Kernel\Registry\          (mutable collection during DSL execution)
Ausus\Kernel\Compiler\Errors\   (closed CompilerError taxonomy)
Ausus\                          (DSL facade — public surface, lives in same package)
```

### 4.2 Class layout (V0 minimum)

```
src/
├── Compiler/
│   ├── Compiler.php                 # orchestrator
│   ├── PluginDiscovery.php          # stage 1
│   ├── DslExecutor.php              # stage 2 driver (calls Plugin::boot())
│   ├── Normalizer.php               # stage 4
│   ├── Validator.php                # stage 5
│   ├── Canonicalizer.php            # stage 6
│   ├── GraphBuilder.php             # stage 7
│   ├── GraphHasher.php              # stage 8
│   └── Errors/
│       ├── CompilerError.php
│       ├── PluginDiscoveryError.php
│       ├── DslInvariantViolation.php
│       ├── DanglingReferenceError.php
│       ├── DuplicateRegistrationError.php
│       ├── ReservedNamespaceError.php
│       ├── ProjectionCoherenceError.php
│       ├── WorkflowCoherenceError.php
│       └── GraphHashError.php
├── Registry/
│   ├── Registry.php                 # mutable; collects RawDescriptors
│   └── RawDescriptor.php            # generic descriptor box (sum type via $kind discriminator)
├── Graph/
│   ├── MetadataGraph.php            # immutable root
│   ├── PluginNode.php
│   ├── EntityNode.php
│   ├── FieldNode.php
│   ├── ActionNode.php
│   ├── PolicyNode.php
│   ├── WorkflowNode.php
│   ├── TransitionNode.php
│   └── ProjectionNode.php
└── Dsl/                              # facade — public surface
    ├── Plugin.php                   # base class plugins extend (implements kernel Plugin contract)
    ├── Dsl.php                      # entry: $dsl->entity(...)
    ├── EntityBuilder.php            # fluent
    ├── FieldBuilder.php             # fluent (alias: Ausus\Field)
    ├── ActionBuilder.php            # fluent (alias: Ausus\Action)
    ├── PolicyBuilder.php
    ├── WorkflowBuilder.php
    └── ProjectionBuilder.php
```

**21 classes** for the compiler + graph + DSL facade. Smaller than any of the kernel RFCs.

### 4.3 Per-class responsibility (one paragraph each, V0 only)

- **`Compiler`** — orchestrator. Single public method `compile(): MetadataGraph`. Holds references to the stage classes; runs them in order; wraps errors.
- **`PluginDiscovery`** — reads `vendor/composer/installed.json`, filters by `extra.ausus`, performs topological sort by declared `dependencies`, returns `PluginManifest[]`. Verifies `kernel` SemVer range against current kernel version; throws `PluginDiscoveryError` on mismatch.
- **`DslExecutor`** — for each manifest, container-resolves the provider class, calls `Plugin::boot()` with the shared `Dsl` builder. DSL methods write into the `Registry`. Catches violations of the DSL invariants of RFC-001 §5.8 (closures detected via `is_callable` checks, I/O detected via Laravel's `Cache::isOpen` hook for V0 — best effort).
- **`Registry`** — mutable in-memory collection. Methods: `register($kind, $rawDescriptor)`, `snapshot(): RawDescriptor[]`. After `snapshot()`, calling `register()` raises (registry frozen).
- **`Normalizer`** — applies FQN elision (RFC-011 §5.1): `entity('invoice')` in plugin `billing` → FQN `billing.invoice`. Resolves convention class names: Policy `billing.invoice.policy.issue` → looks for `Acme\Billing\Policies\IssuePolicy`. Adds implicit system fields (`id`, `tenant_id`, `_version`, `created_at`, `updated_at` for any Entity declaring `Field::timestamps()` and `Field::version()`). Infers Workflow descriptors from enum field + `Action::transition` declarations per RFC-011 §6.4.
- **`Validator`** — runs every compile-time check (§5 below). Validation table per kind; each check is a single function. Throws on first violation.
- **`Canonicalizer`** — sorts every collection per deterministic-ordering rules (§7 below); produces a `CanonicalForm` array with stable key ordering.
- **`GraphBuilder`** — converts `NormalizedDescriptor[]` to `MetadataGraph` with frozen `XxxNode` instances. Two-phase: `freeze(canonical)` produces `MetadataGraph` without hash; `seal(graph, hash)` returns the final graph with hash. Two-phase because hash computation needs the canonical form, which is built during normalization, not from the frozen graph.
- **`GraphHasher`** — SHA-256 over the canonical JSON. Returns lowercase hex (64 chars). Hash includes the kernel version constant + per-plugin manifest hashes (sorted) + the canonical descriptor JSON.

### 4.4 Graph node types (V0)

Detailed in §6 below.

### 4.5 DSL facade

These classes live in `Ausus\` (public surface, no `Kernel\` infix per RFC-011 §4.1):

- `Ausus\Plugin` — abstract base class. Plugin authors extend. Implements `Ausus\Kernel\Contracts\Plugin` + `Ausus\Kernel\Contracts\PluginLifecycle`.
- `Ausus\Dsl` — entry-point class with one public method per primitive: `entity()`, `policy()`, etc. Returns `EntityBuilder` etc.
- `Ausus\Field`, `Ausus\Action`, `Ausus\Policy`, `Ausus\Workflow`, `Ausus\Projection` — static-method facades for the fluent builders. `Field::string()`, `Action::create()`, etc.

V0 implements these for the HelloInvoice surface only. New DSL methods are added as needed; the V0 set covers RFC-011 §2.1's example exactly.

---

## 5. Validation separation

### 5.1 Compile-time validation (Validator class — boot fails on any violation)

| Check                                                                  | Error                                      |
|------------------------------------------------------------------------|--------------------------------------------|
| Every Action's `policy` FQN resolves to a registered Policy            | `DanglingReferenceError`                   |
| Every Workflow transition's `via` resolves to a registered Action      | `DanglingReferenceError`                   |
| Every Workflow transition's `source` / `target` is in the Workflow's states | `WorkflowCoherenceError`              |
| Every Projection's `fields` resolves to a Field on the owner Entity     | `ProjectionCoherenceError`                 |
| Every Projection's `actions` resolves to an Action on the owner Entity | `ProjectionCoherenceError`                 |
| Every Projection's `policy` resolves                                    | `DanglingReferenceError`                   |
| Every Field's `visibility` (if set) resolves to a registered Policy    | `DanglingReferenceError`                   |
| No two descriptors share the same FQN                                  | `DuplicateRegistrationError`               |
| No descriptor declares an FQN in `kernel.*` (plugin-side)              | `ReservedNamespaceError`                   |
| No Field name starts with `_` (reserved for system per RFC-004 §8.4)   | `ReservedNamespaceError`                   |
| No Policy class is in the `kernel.*` namespace from plugin code        | `ReservedNamespaceError`                   |
| Workflow's `stateField` exists on owner Entity and is `enum` type      | `WorkflowCoherenceError`                   |
| Workflow's `initial` state is in the declared states                   | `WorkflowCoherenceError`                   |
| No two transitions in one Workflow share `(source, via)` (incl. wildcard) | `WorkflowCoherenceError`              |
| Action `policy` class is convention-resolved OR explicitly declared     | `DanglingReferenceError`                   |
| Action `effect` class is convention-resolved OR a built-in OR explicit | `DanglingReferenceError`                   |
| Plugin manifests' kernel SemVer ranges are satisfied                   | `PluginDiscoveryError`                     |

### 5.2 Runtime validation (not compiler concerns — listed for separation clarity)

These happen during Invoker calls, NOT during compilation:

- Tenant context is bound (Invoker step 1).
- Policy chain evaluation (Invoker step 2).
- Optimistic locking conflicts (Repository writes, RFC-002 §8).
- Subject existence (`WorkflowSubjectNotFound`, RFC-006 §12).
- Subject state matches transition source (`WorkflowStateMismatch`).
- Policy timeout exceeded (RFC-005 §9.2).
- Audit primary sink failure (Amendment-01 §A-1.6).

The compiler does not concern itself with any of these. They are not even reachable from compile-time information.

### 5.3 Doctor checks (run on demand, do not abort boot unless severity = error)

These run via `ausus:doctor`; some run during boot, some only on explicit invocation:

| Check                                                                                            | Severity | Frequency |
|--------------------------------------------------------------------------------------------------|----------|-----------|
| Unreachable Policy (registered but not attached to any Action / Field / Projection)              | warning  | doctor    |
| Terminal Workflow state with no incoming transitions (dead code)                                 | notice   | doctor    |
| Workflow state unreachable from `initial`                                                        | notice   | doctor    |
| Action declared as transition AND has a custom Effect (possible state-write inconsistency)       | warning  | doctor    |
| `MaintenanceAction` with `skip_workflow_guards: true` on Workflow-attached Action                | warning  | doctor    |
| Effect class FQN does not match convention AND no explicit `->effect()` override                 | error    | boot      |
| Plugin short-name collision (two plugins infer the same short-name)                              | error    | boot      |
| Two plugins both extending `Ausus\Plugin` in the same `src/` (ambiguous provider)                | error    | boot      |

Items marked "boot" run as part of compile (Validator stage 5). Items marked "doctor" run only on explicit `ausus:doctor` invocation, after compile succeeds.

---

## 6. Minimal V0 graph shape

The frozen `MetadataGraph` and its node types. All `final readonly` value objects.

### 6.1 `MetadataGraph` (root)

```php
final readonly class MetadataGraph
{
    public function __construct(
        public string $hash,                                  // 64-char lowercase hex (SHA-256)
        public string $kernelVersion,                         // e.g. "1.0.0"
        public string $manifestHash,                          // hash of plugin manifests
        /** @var array<string, PluginNode> keyed by short-name */
        public array $plugins,
        /** @var array<string, EntityNode> keyed by FQN */
        public array $entities,
        /** @var array<string, ActionNode> keyed by FQN */
        public array $actions,
        /** @var array<string, PolicyNode> keyed by FQN */
        public array $policies,
        /** @var array<string, WorkflowNode> keyed by FQN */
        public array $workflows,
        /** @var array<string, ProjectionNode> keyed by FQN */
        public array $projections,
    ) {}

    public function entity(string $fqn): ?EntityNode { /* lookup */ }
    public function action(string $fqn): ?ActionNode { /* lookup */ }
    public function policy(string $fqn): ?PolicyNode { /* lookup */ }
    public function workflow(string $fqn): ?WorkflowNode { /* lookup */ }
    public function projection(string $fqn): ?ProjectionNode { /* lookup */ }
}
```

Eight properties; six accessors (each is a one-line array lookup).

### 6.2 `PluginNode`

```php
final readonly class PluginNode
{
    public function __construct(
        public string $shortName,           // "billing"
        public string $phpNamespace,        // "Acme\\Billing"
        public string $providerClass,       // "Acme\\Billing\\BillingPlugin"
        public string $version,             // SemVer string
        public string $kernelRange,         // SemVer range, e.g. "^1.0"
        public string $manifestHash,        // hash of this plugin's manifest contribution
    ) {}
}
```

### 6.3 `EntityNode`

```php
final readonly class EntityNode
{
    public function __construct(
        public string $fqn,                              // "billing.invoice"
        public string $pluginShortName,                  // "billing"
        public bool $tenantScoped,                       // true unless `system`
        public bool $auditedReads,                       // RFC-007 §15.1 opt-in flag
        /** @var FieldNode[] declaration-ordered */
        public array $fields,
        /** @var string[] action FQNs declaration-ordered */
        public array $actionFqns,
        /** @var string[] projection FQNs alphabetical */
        public array $projectionFqns,
        /** @var string[] workflow FQNs alphabetical */
        public array $workflowFqns,
    ) {}

    public function field(string $name): ?FieldNode { /* search $fields */ }
}
```

### 6.4 `FieldNode`

```php
final readonly class FieldNode
{
    public function __construct(
        public string $name,                              // "number"
        public string $type,                              // "string" | "integer" | "decimal" | "boolean" | "date" | "datetime" | "time" | "enum" | "money" | "json" | "reference" | "identity" | "version"
        public bool $system,                              // true for id, tenant_id, _version, timestamps
        public bool $nullable,
        public bool $readOnly,
        public mixed $default,                            // null | scalar | DeferredExpression marker
        /** @var array<string,mixed> */
        public array $typeOptions,                        // type-specific: e.g. enum.options, money.currency, string.maxLength
        public bool $uniqueWithinTenant,
        public ?string $visibilityPolicyFqn,              // RFC-001 Amendment-01 §A-1.2
    ) {}
}
```

V0 does not need a separate `validation` block on the field; validation rules collapse into `typeOptions` (e.g., `string.maxLength`).

### 6.5 `ActionNode`

```php
final readonly class ActionNode
{
    public function __construct(
        public string $fqn,                               // "billing.invoice.issue"
        public string $entityFqn,                         // "billing.invoice"
        public string $policyFqn,                         // "billing.invoice.policy.issue"
        public bool $subjectRequired,
        /** @var FieldNode[] inputs, declaration-ordered */
        public array $inputs,
        public string $effectClass,                       // PHP class FQN (convention-resolved or explicit) or built-in marker
        public string $kind,                              // "standard" | "maintenance"
        public bool $audited,                             // always true for V0
        /** @var array<string, array> transition declarations for Workflow inference: {workflowName => {from, to, stamp?}} */
        public array $transitionDeclarations,
    ) {}
}
```

`transitionDeclarations` is the bridge between Action::transition DSL sugar and the inferred Workflow. After normalization it is consumed by the Workflow inference and the resulting WorkflowNode is generated.

### 6.6 `PolicyNode`

```php
final readonly class PolicyNode
{
    public function __construct(
        public string $fqn,                               // "billing.invoice.policy.issue"
        public string $implementationClass,               // PHP class FQN; convention-resolved or explicit
        public bool $cacheable,                           // default true
        public ?int $timeoutMs,                           // null = use deployment default
    ) {}
}
```

### 6.7 `WorkflowNode`

```php
final readonly class WorkflowNode
{
    public function __construct(
        public string $fqn,                               // "billing.invoice.lifecycle"
        public string $ownerEntityFqn,                    // "billing.invoice"
        public string $stateField,                        // "status"
        /** @var string[] declaration order */
        public array $states,                             // ["DRAFT", "ISSUED", "CANCELLED"]
        public string $initial,                           // "DRAFT"
        /** @var TransitionNode[] canonical order: by (source, target, via) */
        public array $transitions,
    ) {}
}
```

### 6.8 `TransitionNode`

```php
final readonly class TransitionNode
{
    public function __construct(
        public string $source,                            // state name or "*"
        public string $target,                            // state name
        public string $viaActionFqn,                      // "billing.invoice.issue"
        public ?string $guardPolicyFqn,                   // optional
    ) {}
}
```

### 6.9 `ProjectionNode`

```php
final readonly class ProjectionNode
{
    public function __construct(
        public string $fqn,                               // "billing.invoice.summary"
        public string $ownerEntityFqn,                    // "billing.invoice"
        /** @var string[] field names declaration-ordered */
        public array $fieldNames,
        /** @var string[] action FQNs declaration-ordered */
        public array $actionFqns,
        /** @var string[] filter names */
        public array $filterNames,
        public string $policyFqn,                         // mandatory per RFC-005 §5.4 deny-by-default
    ) {}
}
```

### 6.10 V0 graph instance counts (for HelloInvoice)

| Type                | Count                                                            |
|---------------------|------------------------------------------------------------------|
| `PluginNode`        | 1 (`billing`)                                                    |
| `EntityNode`        | 1 (`billing.invoice`)                                            |
| `FieldNode`         | 10 (5 declared + 5 system: id, tenant_id, _version, created_at, updated_at) |
| `ActionNode`        | 3 (`create`, `issue`, `cancel`)                                  |
| `PolicyNode`        | 4 (3 Action policies + 1 Projection policy)                      |
| `WorkflowNode`      | 1 (`lifecycle`)                                                  |
| `TransitionNode`    | 3 (DRAFT→ISSUED, DRAFT→CANCELLED, ISSUED→CANCELLED)               |
| `ProjectionNode`    | 2 (`summary`, `detail`)                                          |
| **Total**           | **25 nodes**                                                     |

The entire compiled HelloInvoice graph is 25 frozen objects. Serialized canonical JSON: < 5 KB.

---

## 7. Deterministic ordering rules

Determinism is required for the graph hash to be reproducible across runs (RFC-001 §5.8.3, §4.2 last sentence). Every collection in the graph is ordered by a documented rule.

### 7.1 Top-level (in `MetadataGraph`)

| Collection      | Order                                         |
|-----------------|-----------------------------------------------|
| `plugins`       | by `shortName` lex ascending (UTF-8 byte order) |
| `entities`      | by FQN lex ascending                          |
| `actions`       | by FQN lex ascending                          |
| `policies`      | by FQN lex ascending                          |
| `workflows`     | by FQN lex ascending                          |
| `projections`   | by FQN lex ascending                          |

### 7.2 Within nodes

| Collection                                | Order                                                                          |
|-------------------------------------------|--------------------------------------------------------------------------------|
| `EntityNode.fields`                       | declaration order in the DSL (the order `Field::string('number')` etc. appear) |
| `EntityNode.actionFqns`                   | declaration order                                                              |
| `EntityNode.projectionFqns`               | lex ascending                                                                  |
| `EntityNode.workflowFqns`                 | lex ascending                                                                  |
| `ActionNode.inputs`                       | declaration order                                                              |
| `WorkflowNode.states`                     | declaration order (DSL: `->states('DRAFT', 'ISSUED', 'CANCELLED')`)            |
| `WorkflowNode.transitions`                | by tuple `(source, target, viaActionFqn)` lex ascending                        |
| `ProjectionNode.fieldNames`               | declaration order                                                              |
| `ProjectionNode.actionFqns`               | declaration order                                                              |
| `ProjectionNode.filterNames`              | declaration order                                                              |
| `FieldNode.typeOptions` (when emitted)    | keys lex ascending                                                             |

Where the rule is "declaration order," determinism is preserved because the DSL produces descriptors in a fixed sequence (PHP method call order is deterministic).

### 7.3 Canonical JSON

The canonicalizer emits JSON with:

- Object keys sorted alphabetically (NOT declaration order — the canonical-JSON convention overrides the per-collection rules for hash purposes).
- Arrays preserved in the order above.
- No whitespace.
- No trailing newline.
- UTF-8 encoded.
- Strings escaped per RFC 8259 minimum (only `\"`, `\\`, `\n`, `\r`, `\t`, `\b`, `\f`, `\uXXXX` for control chars).

Identical to RFC-014 §3.3 conventions; reuse the same canonical-JSON helper.

### 7.4 Determinism verification

A V0 test asserts:

```
$graph1 = $compiler->compile();
$graph2 = $compiler->compile();
assert($graph1->hash === $graph2->hash);
```

If determinism breaks, the canonical-JSON helper or the ordering rules are buggy. Catch in CI.

---

## 8. Graph hash algorithm

Per RFC-001 §4.2.5 + RFC-014 §3.3 pattern.

### 8.1 Hash inputs (in order, concatenated into one canonical JSON document)

```json
{
  "actions":      [<canonical action descriptors in lex order>],
  "entities":     [<canonical entity descriptors in lex order>],
  "kernelVersion":"<e.g. 1.0.0>",
  "manifests":    [<plugin manifests in shortName lex order>],
  "policies":     [<canonical policy descriptors in lex order>],
  "projections":  [<canonical projection descriptors in lex order>],
  "workflows":    [<canonical workflow descriptors in lex order>]
}
```

Top-level keys lex-sorted: `actions`, `entities`, `kernelVersion`, `manifests`, `policies`, `projections`, `workflows`. (PluginNodes are folded into `manifests`.)

### 8.2 Per-descriptor canonical shape

Each descriptor type has a fixed canonical-key set. Example for `ActionNode`:

```json
{"audited":true,"effectClass":"...","entityFqn":"billing.invoice","fqn":"billing.invoice.issue","inputs":[...],"kind":"standard","policyFqn":"billing.invoice.policy.issue","subjectRequired":true,"transitionDeclarations":{...}}
```

Keys lex-sorted; values typed per their PHP types (strings quoted, bools as `true`/`false`, ints as integer literals, nulls as `null`).

### 8.3 Hash computation

```php
$canonical = $canonicalizer->canonicalize($graph);   // returns the JSON string above
$hash      = strtolower(bin2hex(hash('sha256', $canonical, true)));   // 64 hex chars
```

Returned as `MetadataGraph::$hash`.

### 8.4 Hash uniqueness properties

- Adding any Field, Action, Policy, etc. changes the hash.
- Reordering Fields within an Entity (declaration order matters per §7.2) changes the hash.
- Plugin version bumps change the per-plugin manifest hash and therefore the graph hash.
- Kernel version bumps change the graph hash.
- Identical sources at identical kernel + plugin versions produce identical hashes.

### 8.5 Storage

V0: hash is computed and stored on the in-memory `MetadataGraph` only. M2 adds disk caching at `storage/framework/ausus/graph.{hash}.php`.

---

## 9. Data flow diagram

```
[Composer installed.json]      [Plugin source files]
         │                              │
         ↓                              ↓
   PluginDiscovery               (DslExecutor calls Plugin::boot())
         │                              │
         ↓                              ↓
   PluginManifest[]              [DSL fluent chain invocations]
         │                              │
         │                              ↓
         │                       Registry.register(kind, raw)
         │                              │
         └───────────────────┬──────────┘
                             ↓
                    Registry.snapshot()
                             │
                             ↓
                    RawDescriptor[] (mutable; ~25 items for HelloInvoice)
                             │
                             ↓
                    Normalizer.normalize()
                       (FQN elision, convention resolution,
                        system field injection, Workflow inference)
                             │
                             ↓
                    NormalizedDescriptor[]
                             │
                             ↓
                    Validator.validate()
                       (cross-reference checks, namespace checks,
                        coherence checks; throws on first failure)
                             │
                             ↓
                    CanonicalForm (sorted, key-ordered arrays)
                             │
                             ↓
                    Canonicalizer.toJson()
                             │
                             ↓
                    canonical JSON string  ──→  GraphHasher.hash()  ──→  64-char hex
                             │                                            │
                             └─────────────────┬──────────────────────────┘
                                               ↓
                                      GraphBuilder.build()
                                               │
                                               ↓
                                       MetadataGraph (final, immutable)
                                               │
                                               ↓
                                  Container binds singleton
                                               │
                                               ↓
                            Available to RuntimeServiceProvider, etc.
```

Single direction. No back-edges. Each stage's output is the only input to the next.

---

## 10. Implementation order (within M1)

The compiler is the highest-priority subsystem. Within M1's two-week sprint, the compiler's class set is built in the following order.

### 10.1 Day 1 — graph node types

Build all `Ausus\Kernel\Graph\*` value objects. Pure data; no logic. PHPUnit tests verify construction + accessors.

Order:
1. `PluginNode`
2. `FieldNode`
3. `TransitionNode`
4. `PolicyNode`
5. `WorkflowNode`
6. `ProjectionNode`
7. `ActionNode`
8. `EntityNode`
9. `MetadataGraph`

Each class is `final readonly`. Total: ~80 lines of code per class incl. type hints. 9 × ~80 = ~720 lines for the entire graph type system.

### 10.2 Day 2 — DSL facade

Build `Ausus\*` DSL classes. Each is a fluent builder that, when chained, produces a `RawDescriptor` and registers it.

Order:
1. `Ausus\Plugin` (abstract base)
2. `Ausus\Dsl` (entry point)
3. `Ausus\Field` (static facade) + `Ausus\Dsl\FieldBuilder` (instance fluent)
4. `Ausus\Action` + `Ausus\Dsl\ActionBuilder`
5. `Ausus\Policy` (static helper for inline declaration)
6. `Ausus\Workflow` + `Ausus\Dsl\WorkflowBuilder`
7. `Ausus\Projection` (or inline via `EntityBuilder::projection()`)
8. `Ausus\Dsl\EntityBuilder` (the main fluent chain root)

Tests: hand-author `Acme\Billing\BillingPlugin` per RFC-011 §2.1; assert `Plugin::boot()` populates the Registry with 25 raw descriptors of correct shape.

### 10.3 Day 3 — Registry + PluginDiscovery

1. `Ausus\Kernel\Registry\RawDescriptor` (generic kind-tagged box).
2. `Ausus\Kernel\Registry\Registry` (mutable, snapshot-able).
3. `Ausus\Kernel\Compiler\PluginDiscovery` (composer.json reader + topological sort).

Tests: minimal `vendor/composer/installed.json` fixture; assert discovery returns expected manifests.

### 10.4 Day 4 — Normalizer

1. FQN elision (apply plugin short-name prefix to local names).
2. Convention-class resolution (Policy / Effect class lookups via `class_exists`).
3. System-field injection (id, tenant_id, _version, created_at, updated_at).
4. Workflow inference (per RFC-011 §6.4).

Tests: round-trip raw descriptors → normalized descriptors; assert FQNs are full, system fields present, Workflow descriptor reconstructed.

### 10.5 Day 5 — Validator + Canonicalizer

1. Validator with one check function per row in §5.1 table. Each function returns `void` or throws.
2. Canonicalizer: sort + serialize to canonical JSON.

Tests:
- Inject invalid descriptors; assert correct error type raised.
- Two canonicalizations of the same normalized form produce identical JSON.

### 10.6 Day 6 — GraphBuilder + GraphHasher + Compiler

1. `GraphHasher` (one method: `hash($canonicalJson): string`).
2. `GraphBuilder` (converts `NormalizedDescriptor[]` to `XxxNode[]`).
3. `Compiler` orchestrator (8 stages).

Tests:
- End-to-end: hand-authored Plugin → MetadataGraph with valid hash.
- Same Plugin compiled twice produces identical hashes.

### 10.7 Day 7 — KernelServiceProvider integration

Wire `Compiler` into Laravel's boot lifecycle. Singleton binding for `MetadataGraph`. Smoke test from `apps/playground`.

### 10.8 Day 8+ — Subsequent M1 packages consume

Once the compiler ships a valid `MetadataGraph`, downstream packages (runtime, persistence, audit, tenancy, auth) can resolve descriptors from it. M1 days 8–10 are integration work.

---

## 11. Error propagation strategy

### 11.1 Closed error taxonomy (V0)

```
CompilerError                                       (abstract base, extends RuntimeException)
├── PluginDiscoveryError(reason)
├── DslInvariantViolation(plugin, violation_type, detail)
├── DanglingReferenceError(source_descriptor, target_fqn, target_kind)
├── DuplicateRegistrationError(fqn, kind, first_source, second_source)
├── ReservedNamespaceError(attempted_fqn, reservation)
├── ProjectionCoherenceError(projection_fqn, reason)
├── WorkflowCoherenceError(workflow_fqn, reason)
└── GraphHashError(detail)                           (rare; canonicalization bug)
```

8 error types. All extend `CompilerError`. All carry enough context to point at the offending plugin and descriptor.

### 11.2 Stage wrapping

Each stage catches its native errors and re-throws as `CompilerError`:

```php
public function compile(): MetadataGraph
{
    try {
        $manifests = $this->discovery->discover();
    } catch (\Throwable $e) {
        throw new PluginDiscoveryError(prev: $e);
    }
    // ...stages 2-8 with similar try/throw
}
```

The wrapping preserves the original exception as `previous`. Laravel's error handler surfaces both.

### 11.3 First-failure abort

Each stage aborts on the first failure. The compiler does NOT collect all errors and report them together. Reasons:

- Validation failures are typically chained (one missing Policy makes its Actions invalid, which makes their Workflows invalid).
- "All errors at once" pattern produces noisy output dominated by cascading failures.
- First-failure is simpler to implement and easier to debug.

A future doctor mode MAY run validation in "collect mode" for diagnostics. Not in V0.

### 11.4 Error message format

Every `CompilerError` exposes:

- `$pluginShortName` — which plugin's compilation caused the error (when known).
- `$descriptorFqn` — the offending descriptor (when known).
- `$stage` — the pipeline stage where the error occurred (set by the orchestrator wrapper).
- `getMessage()` — human-readable.

`ausus:doctor` formats these for terminal output. Laravel's exception page renders them as-is.

---

## 12. Immutable graph strategy

### 12.1 PHP `readonly` properties

All node types use PHP 8.2+ `readonly` properties. Assignment after construction throws `\Error`. Mutation attempts via reflection are not prevented (PHP limitation) but are detectable; the conformance test verifies no reflection-based mutation happens in the codebase.

### 12.2 Defensive arrays

Arrays inside readonly properties (e.g., `EntityNode->fields`) are not deeply immutable — PHP arrays are value types but cloned-on-write semantics mean a caller can `$fields[] = ...` on a returned reference. Defence:

- Node accessors return arrays by value (PHP default). Mutating the returned array does not mutate the node.
- Nested objects (e.g., `FieldNode` inside `EntityNode->fields`) are themselves `readonly`. The reference is shared, but the target is immutable.

This is "shallow immutability" by PHP convention. Sufficient for V0.

### 12.3 No setters, no factories returning mutable forms

There is no `MetadataGraph::withEntity(...)`, no `EntityNode::withFields(...)`. The graph is built once during compile and discarded only when the process exits. No copy-on-write builders.

### 12.4 Serialization for cache (M2)

`MetadataGraph` and all node types use PHP's native `serialize()` for the disk cache. All fields are typed-primitive or arrays of typed-primitive; no closures, no resources. M2 implementation verifies round-trip.

---

## 13. Boot lifecycle alignment

This compiler design slots into the M1 boot order (per `docs/IMPLEMENTATION-PLAN.md` §3 and RFC-001 §5.1) as follows:

| Phase                                  | What happens                                                                                  | Owns                          |
|----------------------------------------|-----------------------------------------------------------------------------------------------|-------------------------------|
| Composer autoload                      | Class autoloader registered.                                                                  | Composer                      |
| Laravel kernel boot                    | `App\\Http\\Kernel::handle()` → service providers register.                                  | Laravel                       |
| KernelServiceProvider::register()      | Container binds `Compiler`, `Registry`, `Container`-ref for plugin resolution.                | `ausus/kernel`                |
| Plugin service providers register()    | Each `Ausus\\Plugin`-extending class is registered with Laravel.                              | Plugin packages               |
| Laravel boot()                         | KernelServiceProvider::boot() runs.                                                           | `ausus/kernel`                |
|   ↳ Compiler::compile()                | 8-stage pipeline; produces `MetadataGraph`.                                                   | `ausus/kernel`                |
|   ↳ Container::instance(MetadataGraph) | Graph bound as singleton.                                                                     | `ausus/kernel`                |
| RuntimeServiceProvider::boot()         | Invoker, PolicyEngine, WorkflowRuntime constructed, reading the bound MetadataGraph.          | `ausus/runtime-default`       |
| L3 driver providers boot()             | Persistence, Tenancy, Audit driver implementations bound.                                     | `ausus/persistence-sql` etc.  |
| Laravel routes register                | L4 API Surface ready.                                                                         | `ausus/runtime-default`       |
| First request                          | Invoker handles; reads from `MetadataGraph` (already compiled).                                | All                           |

The compiler is the first thing to run after plugin registration. Nothing else can boot until the graph exists.

---

## 14. What "minimum for V0" looks like in code

A complete worked example walking the compiler end-to-end against `HelloInvoicePlugin`.

### 14.1 Input — `HelloInvoicePlugin.php`

(Per RFC-011 §2.1 worked example, ~20 lines of DSL.)

### 14.2 Plugin Discovery output

```php
[
    PluginManifest{
        shortName: 'billing',
        phpNamespace: 'Acme\\Billing',
        providerClass: 'Acme\\Billing\\BillingPlugin',
        version: '0.0.0-dev',
        kernelRange: '^1.0',
        dependencies: [],
        manifestHash: 'a1b2c3...',
    },
]
```

### 14.3 Registry snapshot

```
RawDescriptor{kind: 'entity',     payload: {fqn: 'invoice', fields: [...], actions: [...], ...}}
RawDescriptor{kind: 'action',     payload: {name: 'create', ...}}
RawDescriptor{kind: 'action',     payload: {name: 'issue',  transition: {workflow: 'lifecycle', from: 'DRAFT', to: 'ISSUED'}}}
RawDescriptor{kind: 'action',     payload: {name: 'cancel', transition: {workflow: 'lifecycle', from: '*', to: 'CANCELLED'}}}
RawDescriptor{kind: 'policy',     payload: {name: 'create', implementsClass: 'RoleRequired', args: ['invoice.creator']}}
RawDescriptor{kind: 'policy',     payload: {name: 'issue',  fqn-class: 'Acme\\Billing\\Policies\\IssuePolicy'}}
RawDescriptor{kind: 'policy',     payload: {name: 'cancel', implementsClass: 'RoleRequired', args: ['invoice.canceler']}}
RawDescriptor{kind: 'policy',     payload: {name: 'projection.read', implementsClass: 'RoleRequired', args: ['invoice.viewer']}}
RawDescriptor{kind: 'workflow',   payload: {field: 'status'}}    // inferred declaration
RawDescriptor{kind: 'projection', payload: {name: 'summary', fields: [...], role: 'invoice.viewer'}}
RawDescriptor{kind: 'projection', payload: {name: 'detail',  fields: '*', role: 'invoice.viewer'}}
```

### 14.4 After Normalization

- Entity FQN becomes `billing.invoice`.
- Action FQNs become `billing.invoice.create`, `billing.invoice.issue`, `billing.invoice.cancel`.
- Policy FQNs become `billing.invoice.policy.create`, etc.
- Workflow descriptor inferred: states from `status` enum (DRAFT, ISSUED, CANCELLED), transitions from Action::transition declarations.
- System fields injected: `id`, `tenant_id`, `_version`, `created_at`, `updated_at`.
- All references full FQNs.

### 14.5 After Validation

All checks pass (HelloInvoice is well-formed). No errors.

### 14.6 After Canonicalization

A 4-KB JSON document; key-sorted; whitespace-free.

### 14.7 After Hashing

`hash = "7e1a4d6c8f3b2a5d9e0f1c2b3a4d5e6f7890abcdef..."` (64 hex chars).

### 14.8 Final `MetadataGraph`

25 frozen node instances accessible via `$graph->entity('billing.invoice')`, `$graph->action('billing.invoice.issue')`, etc.

### 14.9 Verification

```php
$graph1 = $compiler->compile();
$graph2 = $compiler->compile();
assert($graph1->hash === $graph2->hash);                            // determinism
assert(count($graph1->entities) === 1);                              // exact count
assert($graph1->entity('billing.invoice')->fields[0]->name === 'id'); // system field injected first
assert($graph1->action('billing.invoice.issue')->policyFqn === 'billing.invoice.policy.issue');
```

If any assertion fails, the compiler is buggy. CI runs these.

---

## 15. What is explicitly NOT in V0

| Feature                                              | Why deferred                                             |
|------------------------------------------------------|----------------------------------------------------------|
| Multi-Tenant override layer                          | RFC-001 §4.4: overrides apply at resolve time, not compile. Out of compiler scope. |
| Disk cache (`storage/framework/ausus/graph.{hash}.php`) | M2.                                                  |
| Incremental compilation                              | RFC-001 §4.2: single-pass only. No partial graphs.       |
| Plugin hot-reload                                    | Process restart only.                                    |
| Distributed compilation                              | Single PHP process. Multi-process deployments compile per-process. |
| Optimization passes (DCE, common-subexpression, etc.) | Useless at the descriptor scale; would add complexity for zero benefit. |
| Workflow guard inference from Policy chains          | RFC-001 §2.6: guards are explicit. Compiler does not infer beyond what RFC-011 §6.4 specifies (state field + transition Actions). |
| AST traversal of plugin source files                 | RFC-001 §9.9: no file scanning. Convention resolution uses `class_exists` only. |
| Code generation (artisan make:*)                     | RFC-001 §9.5: forbidden.                                 |
| Cross-plugin Relation closure tracking               | V0 has no Relations; HelloInvoice is standalone. M2.    |
| Custom Field Type registration via plugin            | V0 uses Standard Stack Field Types only (RFC-012 §7). M2. |
| Error collection mode (all errors at once)           | First-failure only; doctor may add later.                |
| Plugin marketplace verification, signing             | Post-V1.                                                 |

Building any of these prematurely violates the implementation-mode rule.

---

## 16. Risks and watchpoints

### 16.1 DSL non-determinism

Most likely failure: a DSL chain that returns different descriptors on repeated invocation. Sources:

- `now()` called as a default value: violates RFC-001 §5.8.3. Detected by Normalizer when a `mixed $default` is callable instead of typed.
- PHP `array` iteration order: in PHP 7+ associative arrays preserve insertion order, but if the DSL uses `array_merge` with non-string keys, the order can vary. Convention: use explicit numeric-keyed `add()` calls or string keys.
- Random IDs: any UUID generated at DSL-call time (rather than at runtime) breaks determinism. Compiler does NOT inject IDs at DSL-call time; identities are runtime concerns.

CI test: compile twice; assert identical hashes.

### 16.2 Convention-resolution misses

`class_exists()` returns false for autoloader-dropped classes. If Composer's classmap is stale, the Compiler reports `DanglingReferenceError` for a class that exists on disk. Mitigation: `php artisan ausus:compile` is preceded by `composer dump-autoload` in production deploys.

### 16.3 PHP `readonly` and reflection

PHP `readonly` properties can be bypassed via reflection. The compiler-emitted graph is shallowly immutable by convention. If runtime code mutates a frozen node, behaviour becomes undefined. Convention: nobody mutates the graph. CI scans for reflection-based assignment.

### 16.4 Plugin `boot()` performing I/O

RFC-001 §5.8.4 forbids I/O at definition time. V0 detection is best-effort: the DSL accepts only typed scalars and FQN strings; closures passed to DSL methods raise `DslInvariantViolation` at registration. Calls to `\file_get_contents`, `\Cache::get`, etc. from inside `Plugin::boot()` cannot be intercepted without monkey-patching; out of V0.

### 16.5 Graph hash collision

SHA-256 collision is infeasible. Not a practical concern.

### 16.6 Compile time at scale

For HelloInvoice (~25 descriptors), compilation takes < 10ms on commodity hardware. For 100 plugins × 50 Entities = 5000 descriptors, expect ~500ms (linear scaling). M2's disk cache amortizes; production boots load the cache in ~10ms regardless.

---

## 17. Acceptance for the compiler subsystem (within M1)

The compiler is M1-complete when:

1. All 9 graph node types compile + have unit tests verifying construction and accessor correctness.
2. All 8 DSL facade classes compile + have unit tests verifying that a hand-authored `Plugin::boot()` chain produces correct `RawDescriptor[]`.
3. The full 8-stage pipeline runs on `HelloInvoicePlugin` and produces a `MetadataGraph` with the expected node counts (§6.10).
4. `Compiler::compile()` is deterministic: two consecutive calls produce identical hashes.
5. The Validator catches every "MUST" condition in §5.1 (one test per row).
6. The `apps/playground` integration test imports `MetadataGraph` from the container and resolves at least one descriptor by FQN.

When all six hold, the compiler is ready to support M1's downstream packages (runtime-default, etc.).

---

## 18. Summary

- **One orchestrator class** (`Compiler`) coordinates **8 pipeline stages**.
- **9 immutable graph node types** capture the V0 metadata.
- **8 DSL facade classes** in `Ausus\*` accept plugin author input.
- **Closed error taxonomy** with 8 types.
- **Deterministic ordering rules** documented per collection.
- **Single-pass, single-direction** data flow.
- **No optimization, no hot-reload, no codegen, no AST traversal, no per-Tenant compilation.**

Total class count: 21. Total LOC estimate: ~2,500 lines including tests. The smallest possible compiler that satisfies the frozen RFCs.

Implementation order: graph nodes → DSL facade → Registry + Discovery → Normalizer → Validator + Canonicalizer → GraphBuilder + Hasher + Compiler → service-provider integration → playground smoke test. Maps to M1 days 1–8 of the sprint plan.
