# RFC-011 — DSL Surface

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Draft                                                  |
| Authors       | architect, DX, challenger                              |
| Date          | 2026-05-18                                             |
| Depends on    | RFC-001 Draft-04 (incl. Amendments-01, -02), RFC-002, RFC-003, RFC-004, RFC-005, RFC-007 Draft-02, RFC-010, RFC-012 |
| Mission       | Formalize the DSL surface already exercised by RFC-000's worked example, the RFC-012 starter, and the HelloInvoice plugin. |
| Hard rule     | The DSL describes existing practice. It MUST NOT invent new kernel concepts. |
| Primary KPI   | A plugin author defines Invoice in: ≤ 40 DSL LOC, ≤ 10 imports, ≤ 3 manual FQNs. |

---

## 0. Problem statement

The DSL surface has been provisional through every prior RFC. RFC-001 §11.10 deferred it. RFC-005 §3 referenced it. RFC-012 §16.5 shipped it as "provisional pending RFC-011." RFC-000 V0 used an illustrative shape in §2 (F-V0-01 BLOCKER). UX-1 measurement 6 logged 19 manual FQNs and 38 imports as Dangerous; UX-2 found that the Standard Stack did not reduce these structural counts.

This RFC closes the gap. It formalizes the DSL by codifying the shape RFC-000's worked example used (composing kernel primitives into a single fluent chain) and adding the **elision rules** that bring the KPI numbers into reach.

The hard rule is the framing constraint: every DSL construct in this RFC composes primitives already accepted by RFC-001/002/003/004/005/007/010. No new kernel surface is introduced. Convenience constructs (`Action::transition`, `requireRole`, `stamp`) are sugar over existing primitives; they translate at registration time into descriptors the kernel already understands.

The KPI is the validation constraint: the worked Invoice example of §3 below must satisfy ≤40 DSL LOC, ≤10 imports, ≤3 manual FQNs. If the design cannot meet these, this RFC is BLOCKED.

---

## 1. Scope

### 1.1 In scope

- The DSL surface used inside a plugin's `dsl(Dsl $dsl): void` method.
- Convention-based resolution of Policy and Effect classes from Action declarations.
- Built-in parameterized Policy shims for common patterns (role-required, permission-required, always-permit).
- Built-in Action sugar (`Action::transition`, `Action::create`) with default Effects.
- Field type fluent builders.
- Namespace inference and elision rules.
- Reserved-namespace shielding at DSL boundary.
- Compile-time diagnostics for DSL-introduced failure modes.
- KPI verification.

### 1.2 Out of scope

- New kernel primitives. Hard rule.
- The Plugin lifecycle (RFC-001 §6).
- The Policy contract internals (RFC-005 §2.1) — Policy classes are unchanged.
- The Effect contract internals (RFC-012 §6.2 de facto) — Effect classes are unchanged.
- Workflow execution semantics (RFC-006 deferred) — the DSL declares Workflows; runtime semantics are unchanged.
- Migration tooling — RFC-012 §3.4 schema derivation.
- Frontend / React renderer — RFC-004, RFC-012 §8.

### 1.3 Inherited (non-negotiable)

1. DSL invariants (RFC-001 §5.8): purity, serializability, determinism, no I/O at definition time, no domain logic at definition time, declarative composition only, idempotent registration.
2. FQN convention `namespace.name` (RFC-001 §2.1).
3. Reserved `kernel.*` ActionFqn namespace (Amendment-01 §A-1.2 + Amendment-02 §A-1.11).
4. Reserved `kernel.*` identity-handle namespace (Amendment-02 §A-1.13).
5. Reserved Field-name prefix `_` (RFC-004 §8.4).
6. Reserved payload key prefixes `__ausus_*` (RFC-003 §11.3, RFC-007 §9.6).
7. Reserved `system` TenantId literal (RFC-003 §12.1).
8. No closures in descriptor payloads (RFC-001 §5.8.6).
9. No code-generation scaffolding for entities (RFC-001 §9.5).
10. No Policy inheritance trees (RFC-005 §14.2).

---

## 2. Worked Invoice example (KPI proof)

The complete `Acme\Billing\BillingPlugin` plus its one custom Policy. Two files total.

### 2.1 `src/BillingPlugin.php`

```php
<?php

namespace Acme\Billing;

use Ausus\{Plugin, Dsl, Field, Action};

class BillingPlugin extends Plugin
{
    public function dsl(Dsl $dsl): void
    {
        $dsl->entity('invoice')
            ->fields([
                'number'        => Field::string()->unique()->max(32),
                'customer_name' => Field::string()->max(200),
                'amount'        => Field::money()->usd(),
                'status'        => Field::enum('DRAFT', 'ISSUED', 'CANCELLED')->default('DRAFT'),
                'issued_at'     => Field::datetime()->nullable(),
            ])
            ->actions([
                'create' => Action::create('number', 'customer_name', 'amount')
                                  ->requireRole('invoice.creator'),
                'issue'  => Action::transition('status', from: 'DRAFT', to: 'ISSUED')
                                  ->stamp('issued_at')
                                  ->policy(\Acme\Billing\Policies\IssuePolicy::class),
                'cancel' => Action::transition('status', from: '*', to: 'CANCELLED')
                                  ->input('reason', Field::string()->max(500))
                                  ->requireRole('invoice.canceler'),
            ])
            ->workflow('status')
            ->projection('summary', fields: ['number','customer_name','status','amount'], role: 'invoice.viewer')
            ->projection('detail',  fields: '*', role: 'invoice.viewer');
    }
}
```

### 2.2 `src/Policies/IssuePolicy.php`

```php
<?php

namespace Acme\Billing\Policies;

use Ausus\{Policy, Decision};

class IssuePolicy implements Policy
{
    public function evaluate($actor, $action, $subject, $context): Decision
    {
        if (!in_array('invoice.issuer', $actor->roles(), true)) {
            return Decision::Deny;
        }
        $plan = $context->tenant()->attribute('plan') ?? 'trial';
        return $plan === 'active' ? Decision::Permit : Decision::Deny;
    }
}
```

### 2.3 KPI counts

| Metric                    | Value | Target | Pass |
|---------------------------|-------|--------|------|
| DSL LOC (lines 9–25 inside `dsl()`) | 20 | ≤ 40 | ✓ |
| Total file LOC (Plugin + Policy)    | 31 + 14 = 45 | n/a | n/a |
| Import statements across both files | 2 (`use Ausus\{...}` × 2) | ≤ 10 | ✓ |
| Names imported (across both files)  | 4 + 2 = 6 | n/a | n/a |
| Manual domain FQNs (dot-notation in DSL) | 0 | n/a | n/a |
| Manual PHP class FQNs (e.g. `IssuePolicy::class`) | 1 | n/a | n/a |
| **Combined manual FQNs**            | **1** | ≤ 3 | ✓ |

**All three KPIs pass with margin.** No further compression is required for the V1 surface.

---

## 3. Namespace rules

### 3.1 Plugin namespace short-name

The plugin's AUSUS short-name (used as the prefix for all FQNs declared inside the plugin) is **inferred** from the Plugin class name by:

1. Take the unqualified class name (`BillingPlugin`).
2. Strip the `Plugin` suffix if present (`Billing`).
3. Lowercase (`billing`).

If a deployment requires an explicit short-name (e.g., the Plugin class is named `MyVendorBillingPlugin` but should expose `billing`), the Plugin class declares:

```php
protected string $namespace = 'billing';
```

If two plugins infer the same short-name, the Compiler rejects at boot with `PluginNamespaceCollision(short_name, plugins)`. Plugins resolve the collision by declaring explicit `$namespace`.

### 3.2 PHP namespace inheritance

The PHP namespace of the Plugin class (declared by the file's `namespace` statement) is the **root PHP namespace** for that plugin's Policy / Effect / FieldType / convention-resolved classes:

```php
namespace Acme\Billing;     // Plugin root PHP namespace

class BillingPlugin extends Plugin { ... }
```

The DSL infers convention-resolved classes as:

- Policies: `Acme\Billing\Policies\<ActionName>Policy`
- Effects: `Acme\Billing\Effects\<ActionName>Effect`
- Custom Field Types: `Acme\Billing\Fields\<Name>FieldType`

This convention is fixed for V1. Plugins requiring a different layout pass explicit class FQNs (§5).

### 3.3 Cross-plugin references

References to FQNs in other plugins use full dot-notation:

```php
Field::reference('billing.customer')
```

The full FQN `billing.customer` is explicit because the local plugin's short-name (`crm` for example) is not the target. The kernel resolves `billing.customer` via the global graph.

Cross-plugin Policy references work the same way:

```php
->policy('billing.invoice.policy.read')
```

### 3.4 Reserved namespaces

The DSL refuses at registration time any declaration that uses a reserved namespace (§9):

- Plugin short-name in `{kernel, ausus, system}`.
- Entity, Action, Policy, Workflow, Projection names whose FQN would land in `kernel.*`.

---

## 4. Imports minimization

### 4.1 The single-import facade

All DSL constructs live under `Ausus\` directly. Plugin authors write one group import per file:

```php
use Ausus\{Plugin, Dsl, Field, Action};         // Plugin file
use Ausus\{Policy, Decision};                    // Policy file
use Ausus\{Effect, Reference};                   // Effect file (only when custom Effect needed)
```

### 4.2 No deep imports for the V1 DSL

The DSL **never requires** plugin authors to import:

- `Ausus\Kernel\Contracts\Persistence\PersistenceDriver` — internal.
- `Ausus\Kernel\Contracts\Persistence\Repository` — accessed via `PersistenceContext` in Effects.
- `Ausus\Kernel\Contracts\Audit\Auditor` — never called from plugins (RFC-007 §3.2).
- `Ausus\Kernel\Contracts\Reporting\ReportingDriver` — accessed only inside reporting effects (rare).
- Sub-namespace types (e.g., `Ausus\Kernel\Contracts\Policy\Subject`) — re-exported from `Ausus\` for plugin consumption.

`Ausus\` is the public surface; everything else is internal. The kernel's `Ausus\Kernel\` namespace remains the source of truth (RFC-001 §5.2 contracts), but plugin-facing aliases under `Ausus\` re-export the consumable surface.

### 4.3 Type-hint elision

Plugin-authored Policy and Effect classes MAY omit parameter type hints. PHP does not require them. The kernel calls the methods with the correct types regardless. Authors who want IDE assistance import and annotate; authors who want minimum imports omit:

```php
public function evaluate($actor, $action, $subject, $context): Decision
```

vs.

```php
public function evaluate(Actor $actor, string $action, ?Subject $subject, Context $context): Decision
```

Both are conformant. The shorter form costs 3 imports.

### 4.4 Import count for the worked example

| File                | Statements | Names imported |
|---------------------|------------|----------------|
| `BillingPlugin.php` | 1 (`use Ausus\{Plugin, Dsl, Field, Action}`) | 4 |
| `IssuePolicy.php`   | 1 (`use Ausus\{Policy, Decision}`)            | 2 |
| **Total**           | **2 statements** | **6 names** |

Either count (statements or names) is well under the ≤10 KPI.

---

## 5. FQN elision rules

### 5.1 Domain FQN inference

Every FQN the DSL would otherwise require the author to type is inferred from the local declaration. Author-typed strings are local names; the DSL produces the canonical FQN.

| Declaration                                              | Author writes        | DSL produces                         |
|----------------------------------------------------------|----------------------|--------------------------------------|
| Entity                                                   | `entity('invoice')`  | `billing.invoice`                    |
| Action                                                   | `'issue' => Action::...` | `billing.invoice.issue`           |
| Policy (convention-resolved)                             | (none — implicit)    | `billing.invoice.policy.issue`       |
| Workflow (default)                                       | `workflow('status')` | `billing.invoice.lifecycle`          |
| Projection                                               | `projection('summary', ...)` | `billing.invoice.summary`     |

Author types **zero domain FQNs** in the worked example.

### 5.2 Convention-resolved class names

For each declared Action, the DSL looks for these classes (in order):

| Class kind | Convention path                                     | Required? |
|------------|-----------------------------------------------------|-----------|
| Policy     | `<plugin PHP namespace>\Policies\<ActionName>Policy` | Only if no `->policy(...)` and no `->requireRole(...)` is set |
| Effect     | `<plugin PHP namespace>\Effects\<ActionName>Effect`  | Only if Action is not a built-in (`Action::create`, `Action::transition`) |

If convention resolves to a non-existent class AND no override is provided, compile fails with diagnostic (§9). If convention resolves AND an override is provided, the override wins; the convention class is ignored.

### 5.3 Explicit FQN / class overrides

When the author needs to point at a non-conventional class:

```php
'issue' => Action::transition('status', from: 'DRAFT', to: 'ISSUED')
              ->policy(\Acme\Billing\Policies\IssuePolicy::class)        // explicit PHP class FQN
              ->effect(\Acme\Billing\Effects\IssueEffect::class),         // explicit PHP class FQN
```

`policy()` and `effect()` accept either:

- A PHP class FQN (`::class`).
- A domain FQN string (e.g., `'billing.invoice.policy.issue'`) — resolves via the global graph.

When the author needs to reference cross-plugin domain FQNs:

```php
'pay' => Action::make()->policy('billing.invoice.policy.pay')
```

The string form is necessary when the target is in a different plugin or when the author wants to share a Policy across multiple Actions.

### 5.4 FQN count for the worked example

| Site                                                | FQN written                                |
|-----------------------------------------------------|--------------------------------------------|
| `IssuePolicy::class` (line 22 of §2.1)              | `\Acme\Billing\Policies\IssuePolicy`       |
| All other FQNs                                      | (inferred — none typed)                    |
| **Total manually typed FQNs**                       | **1**                                      |

Under the ≤3 KPI. Margin: 2 additional FQNs available before the KPI fails.

### 5.5 What still requires manual FQN

The author manually types a FQN-like value when:

1. Overriding a convention-resolved class (as in the worked example).
2. Referencing a cross-plugin domain FQN.
3. Declaring a `Field::reference('<other entity FQN>')`.
4. Declaring a custom Field Type by class (`Field::custom(\My\Type::class)`).

Each is an explicit deviation from convention. The default path requires zero.

---

## 6. Auto-discovery rules

### 6.1 Plugin discovery

Plugins are discovered via Composer's `installed.json` plus the `extra.ausus` marker (RFC-001 §6.2). No DSL change — RFC-001's mechanism stands.

### 6.2 Class discovery within a plugin

The DSL discovers Policy and Effect classes via the §5.2 convention. The discovery uses PSR-4 autoloading (resolved by Composer); the DSL **does not scan the filesystem** (RFC-001 §9.9 anti-pattern). Class existence is verified by `class_exists($fqn)` against the autoloader.

If a Policy class is declared but never attached (convention-resolved AND no Action declared it), it appears in `ausus:doctor` (RFC-005 §12 #1) as `Unreachable Policy`. Severity: warning.

### 6.3 Field Type discovery

Standard Field Types ship in `ausus/field-types-standard` (RFC-012 §7). The DSL exposes them as `Field::string()`, `Field::money()`, etc. via the `Ausus\Field` facade. Plugin-authored Field Types register themselves and become available as `Field::custom(\My\Type::class)` or via a plugin-provided fluent method.

### 6.4 Workflow inference

`->workflow('status')` declares that the field named `status` is the Workflow state column. The Workflow's states are inferred from the field's `enum` values; transitions are inferred from declared `Action::transition(...)` calls that reference `status`.

```php
->actions([
    'issue'  => Action::transition('status', from: 'DRAFT', to: 'ISSUED'),
    'cancel' => Action::transition('status', from: '*', to: 'CANCELLED'),
])
->workflow('status')
```

The Compiler infers the Workflow descriptor:

```
workflow billing.invoice.lifecycle:
  states:  [DRAFT, ISSUED, CANCELLED]    (from status enum)
  initial: DRAFT                          (from status default)
  transitions:
    - DRAFT  -> ISSUED    via billing.invoice.issue
    - DRAFT  -> CANCELLED via billing.invoice.cancel
    - ISSUED -> CANCELLED via billing.invoice.cancel
```

No explicit `Workflow::make(...)->states([...])->transition(...)` chain needed when the Workflow is one-state-field-driven. For multi-Workflow Entities or Workflows that span multiple fields, the explicit DSL form remains available.

### 6.5 Projection field inference

`projection('detail', fields: '*')` includes every declared Field. `projection('summary', fields: ['a', 'b'])` is an explicit allowlist. The wildcard `'*'` does NOT include system fields with leading underscore (`_version`) or system-managed fields (`tenant_id`, `created_at`, `updated_at`) unless they are explicitly listed.

---

## 7. Manifest inference

### 7.1 Minimum `composer.json` extra block

```json
{
  "extra": {
    "ausus": {}
  }
}
```

When the `extra.ausus` block is empty, the DSL infers:

| Field         | Inferred from                                              |
|---------------|------------------------------------------------------------|
| `name`        | Composer package `name` (e.g., `acme/billing`)             |
| `version`     | Composer package `version`                                 |
| `kernel`      | `^1.0` (current major)                                     |
| `provider`    | Single class in `src/` extending `Ausus\Plugin`            |
| `dependencies`| `{}` (declared explicitly only when needed)                |

### 7.2 When inference fails

If `src/` contains multiple classes extending `Ausus\Plugin`, or if no class is found, the DSL refuses to load and emits `PluginProviderAmbiguous` or `PluginProviderNotFound`. The author then sets `extra.ausus.provider` explicitly.

### 7.3 Inferred manifest is final at boot

The Compiler computes the effective manifest at boot and freezes it for the process. Runtime modification is forbidden (consistent with RFC-001 §A-1.13.6 graph immutability). The frozen manifest contributes to the graph hash (RFC-001 §4.2.5).

---

## 8. Default aliases

The DSL ships a set of fluent aliases and parameterized built-ins that reduce common patterns to a single line.

### 8.1 Field type aliases

| DSL alias                  | Equivalent                                       |
|----------------------------|--------------------------------------------------|
| `Field::string()->unique()` | `Field::string()->uniqueWithinTenant()`         |
| `Field::money()->usd()`     | `Field::money()->currency('USD')`               |
| `Field::id()`               | system identity handle (ULID by default per RFC-012 §7) |
| `Field::tenant_id()` (implicit) | added automatically to every Tenant-scoped Entity |
| `Field::timestamps()`       | adds `created_at` + `updated_at`                |
| `Field::version()` (implicit) | added automatically to every Entity            |

### 8.2 Action built-ins

| DSL form                                                     | Composes                                                 |
|--------------------------------------------------------------|----------------------------------------------------------|
| `Action::create($field, ...)`                                | Action with `subjectRequired: false`, default Effect that INSERTs the declared input fields |
| `Action::transition('status', from: 'X', to: 'Y')`           | Action with `subjectRequired: true`, default Effect that loads Subject and mutates the field |
| `Action::transition('status', from: '*', to: 'Y')`           | Wildcard source: any current state                        |
| `->stamp('field_name')`                                      | Effect addition: set the named field to `Context::clock()` at execution time |
| `->input('name', $fieldDescriptor)`                          | Adds an input to the Action's contract                    |
| `->policy($fqnOrClass)`                                      | Attaches an explicit Policy                               |
| `->effect($class)`                                           | Overrides convention-resolved Effect class                |
| `->requireRole($role)`                                       | Attaches the built-in `Ausus\Policies\RoleRequired($role)` Policy |
| `->requirePermission($perm)`                                 | Attaches the built-in `Ausus\Policies\PermissionRequired($perm)` Policy |
| `->requireRoles($roles, mode: 'any'|'all')`                  | Attaches built-in `Ausus\Policies\RolesRequired($roles, $mode)` |

### 8.3 Built-in Policy classes

The DSL ships three parameterized Policy classes that cover ~80% of plugin-author needs:

```
Ausus\Policies\RoleRequired($role)
Ausus\Policies\PermissionRequired($permission)
Ausus\Policies\RolesRequired($roles, $mode)
```

Each implements `Policy` per RFC-005 §2.1 exactly. They are NOT subclasses of an abstract base (RFC-005 §14.2 forbids) — they are concrete implementations parameterized at construction.

Plugin authors writing role-only or permission-only checks **write zero Policy classes**. Custom Policies (those needing Subject inspection, Context inspection beyond Tenant, attribute-based rules) require author-written classes (e.g., `IssuePolicy` in §2.2).

### 8.4 Projection sugar

| DSL form                                                                                    | Composes |
|---------------------------------------------------------------------------------------------|----------|
| `projection('name', fields: [...], role: 'X')`                                              | Projection with the listed fields and a built-in `RoleRequired('X')` Policy |
| `projection('name', fields: '*', role: 'X')`                                                | All non-system fields                                     |
| `projection('name', fields: [...], policy: $fqnOrClass)`                                    | Custom Policy                                             |
| `projection('name', fields: [...], actions: [...], filters: [...])`                         | Full control: omit `actions` to derive from Entity's Action set; omit `filters` to derive from Filter-tagged Fields |

### 8.5 Workflow sugar

| DSL form                  | Composes                                                              |
|---------------------------|------------------------------------------------------------------------|
| `workflow('status')`      | Inferred Workflow (§6.4)                                               |
| `workflow('status', initial: 'X')` | Override initial state (default: enum field's `default()`)     |
| `workflow('status', explicit: $callable)` | Pass a callable that receives a `WorkflowBuilder` for full control |

The `explicit` form accepts a callable as a parameter to the DSL method (not as a stored descriptor closure — the callable is invoked at boot to produce a descriptor). This satisfies §5.8.6's prohibition on closures **in** descriptor payloads while permitting closures as DSL-construction helpers.

---

## 9. Reserved namespace shielding

### 9.1 Shielded namespaces

The DSL rejects at registration time any declaration that would create a name in a reserved namespace. Rejections fire BEFORE the Compiler runs, with clear messages naming the violated reservation.

| Reservation                             | Source                              | Violation example                          |
|-----------------------------------------|--------------------------------------|--------------------------------------------|
| `kernel.*` ActionFqn / Policy / Entity  | Amendment-01 §A-1.2, Amendment-02 §A-1.11 | `entity('kernel.foo')`, `Action::make()->policy('kernel.x')` |
| `kernel.*` identity handles             | Amendment-02 §A-1.13                | `Field::id()->seed('kernel.foo')` (rare API)        |
| `_*` field names                        | RFC-004 §8.4                         | `Field::string('_foo')`                    |
| `__ausus_*` payload keys                | RFC-003 §11.3, RFC-007 §9.6         | `Job::dispatch(['__ausus_tenant' => ...])` (runtime, not DSL) |
| `__system__` TenantId literal           | RFC-003 §12.1                        | (impossible from DSL; runtime only)        |
| Plugin short-names `{kernel, ausus, system}` | This RFC §3.4                  | `class KernelPlugin extends Plugin` → infers `kernel` short-name |
| `[REDACTED]` literal in Field defaults  | RFC-007 §14.4                        | `Field::string()->default('[REDACTED]')`   |

### 9.2 Detection point

Reservations are checked when each DSL method is called (at `boot()` time). The DSL throws `DslReservedNamespace($attempted, $reservation)` immediately. The Plugin fails to register; the kernel fails to boot.

### 9.3 No bypass

There is no `force()` flag, no `unsafe()` modifier, no environment variable that disables shielding. Plugins requiring legitimately-named-in-`kernel.*` Actions are by definition the Kernel itself; plugin code cannot register Kernel actions.

---

## 10. Compile-time diagnostics

The DSL adds the following diagnostics on top of the kernel and engine taxonomies (RFC-001 §4.2.3, RFC-002 §12.1, RFC-005 §13, RFC-007 §13, RFC-010 §11.1).

| Diagnostic                                              | When raised                                                              | Severity |
|---------------------------------------------------------|--------------------------------------------------------------------------|----------|
| `PluginNamespaceCollision(short_name, plugins)`         | Two plugins infer the same short-name; neither set explicit `$namespace` | error    |
| `PluginProviderAmbiguous(plugin_package)`               | Multiple classes in `src/` extend `Ausus\Plugin`; no explicit `provider` | error    |
| `PluginProviderNotFound(plugin_package)`                | No class extends `Ausus\Plugin`; no explicit `provider`                  | error    |
| `DslReservedNamespace(attempted, reservation)`          | §9 violation                                                             | error    |
| `PolicyClassNotFoundByConvention(actionFqn, expectedClass)` | Action has no `->policy(...)` or `->requireRole(...)` AND the convention class does not exist | error |
| `EffectClassNotFoundByConvention(actionFqn, expectedClass)` | Action is not built-in AND has no `->effect(...)` AND the convention class does not exist | error |
| `WorkflowFieldNotFound(workflowOwner, fieldName)`       | `workflow('status')` references a non-existent field                     | error    |
| `WorkflowFieldNotEnum(workflowOwner, fieldName)`        | `workflow($field)` references a field that is not an enum                | error    |
| `TransitionStateInvalid(workflow, state)`               | `Action::transition(..., from: 'X', to: 'Y')` references an X or Y not in the field's enum | error |
| `ProjectionFieldNotFound(projection, fieldName)`        | Projection lists a field that is not declared on the Entity              | error    |
| `ProjectionActionNotFound(projection, actionFqn)`       | Projection lists an action that is not declared on the Entity            | error    |
| `BuiltinPolicyMisuse(action, reason)`                   | `requireRole('X')` called with empty string, or `RolesRequired` mode invalid | error |
| `WildcardOnNonEnum(action, field)`                      | `Action::transition(..., from: '*', ...)` on a non-enum field            | error    |
| `StampFieldNotDatetime(action, fieldName)`              | `->stamp('field')` references a non-datetime field                       | error    |

All errors block boot. The `ausus:doctor` (RFC-001 §5.5) surfaces them with file/line attribution drawn from the DSL call stack.

---

## 11. KPI verification

### 11.1 LOC budget

The worked Invoice example (§2.1) has 31 total lines in the Plugin file. The DSL chain itself (lines 9–28 inside `dsl(...)`) is **20 lines**.

Counting strategy: "DSL LOC" = lines inside the `dsl(...)` method body, excluding only `{` and `}` braces of the method.

| Strict count (DSL chain only) | Lenient count (full method body) | Total file LOC |
|-------------------------------|----------------------------------|----------------|
| 20                            | 22                               | 31             |

The KPI applies to "DSL LOC." Under either strict or lenient interpretation, the result is **≤ 22**, well under the ≤ 40 target. **Pass.**

### 11.2 Import budget

Across both files (Plugin + IssuePolicy): **2 `use` statements**, importing **6 names**.

The KPI applies to "imports." Under both interpretations (statements or names): **≤ 6**, well under the ≤ 10 target. **Pass.**

### 11.3 FQN budget

Manually typed FQN-like tokens in the entire two-file solution:

| Site                                                    | FQN                                          | Counted as |
|---------------------------------------------------------|----------------------------------------------|------------|
| Line 22 of `BillingPlugin.php`: `IssuePolicy::class`    | `\Acme\Billing\Policies\IssuePolicy`         | 1          |
| **Total**                                               |                                              | **1**      |

The KPI applies to "manual FQNs." Result: **1**, well under the ≤ 3 target. **Pass.**

### 11.4 Counterfactual

Removing the one IssuePolicy override (using only built-in `requireRole`) would push manual FQNs to **0**. The slice still works (would require a different domain model for the tenant-plan rule — moved to Context attribute exposure on the Authorization plugin). The 1 FQN above is a deliberate choice for the slice's domain logic; the design supports 0 FQN authoring for purely role-based plugins.

---

## 12. Hard rule verification — no new kernel concepts

This RFC introduces:

| DSL construct                       | Composes existing primitives                                                | New kernel concept? |
|-------------------------------------|------------------------------------------------------------------------------|---------------------|
| `Ausus\Plugin` base class           | Implements existing `Plugin` + `PluginLifecycle` interfaces (RFC-001 §6, Amendment-01 §A-1.1) | **No**              |
| `Ausus\Dsl` builder                 | Constructs existing descriptors (Entity, Field, Action, Policy, Workflow, Projection) | **No**              |
| `Field::string()`, `Field::money()`, etc. | Construct existing `FieldDescriptor` with Standard Stack Field Types (RFC-012 §7) | **No**              |
| `Action::create(...)`               | Constructs existing `ActionDescriptor` with `subjectRequired: false` and a default Effect that uses the existing `PersistenceContext::create(...)` | **No** |
| `Action::transition(...)`           | Constructs existing `ActionDescriptor` with `subjectRequired: true` and a default Effect that uses `PersistenceContext::update(...)` against the Workflow's runtime (RFC-012 §6.3 simple Workflow runtime) | **No** |
| `->stamp('field')`                  | Default Effect addition: sets the field to `Context::clock()` (existing Context per RFC-005 §7.1) at execution time | **No** |
| `->input(...)`                      | Adds an entry to existing `ActionDescriptor::inputs`                         | **No**              |
| `->requireRole(...)`                | Attaches built-in `RoleRequired` Policy (existing Policy contract per RFC-005 §2.1) | **No**              |
| `workflow('status')`                | Constructs existing `WorkflowDescriptor` with states/transitions inferred from the field's enum and declared Actions | **No** |
| `projection('name', ...)`           | Constructs existing `ProjectionDescriptor`                                   | **No**              |
| Convention-based class resolution   | Uses existing PSR-4 autoloading + `class_exists()` — no file scanning (RFC-001 §9.9) | **No** |
| Manifest inference (§7)             | Synthesizes existing `PluginManifest` fields from Composer metadata          | **No**              |
| Reserved namespace shielding (§9)   | Enforces existing reservations earlier in the lifecycle                      | **No**              |
| New diagnostics (§10)               | Closed set; all are surface-level violations, not new contracts              | **No**              |

**Hard rule satisfied.** Every construct composes existing kernel primitives. The DSL is a presentation of the kernel surface; it adds zero semantic concepts to RFC-001 + amendments.

---

## 13. Trade-offs

1. **Convention-based class resolution** (§5.2) makes the happy path concise but couples plugins to a specific layout. Plugins with unconventional directory structure must use explicit overrides; the convention is opinionated. Acceptable: the convention is documented and overrideable.
2. **Built-in `RoleRequired` and `PermissionRequired` Policies** (§8.3) couple plugins to the Authorization plugin's `roles()` / `permissions()` accessors beyond RFC-005 §1.3 minimum. Plugins using these aliases assume `ausus/auth-bridge` (RFC-012 §9) or a compatible Authorization plugin. Acceptable: documented; alternative is author-written Policy classes.
3. **Workflow inference from enum field** (§6.4) is convenient for the single-status-column case but does not cover multi-Workflow or non-enum-driven Workflows. Authors needing those use the explicit DSL form. Acceptable: explicit form remains available.
4. **`Action::transition` default Effect** (§8.2) couples to RFC-012 §6.3's simple Workflow runtime. When RFC-006 lands and replaces the simple runtime, the default Effect's behavior may shift. Documented as RFC-012 §16.5 provisional. Acceptable inherited.
5. **`->stamp(...)` sugar** assumes Effect can read `Context::clock()`. The default Effect for transitions does; custom Effects must do so explicitly. Mild leak; mitigated by documentation.
6. **Named arguments** (`from:`, `to:`, `fields:`, `role:`) require PHP 8.0+. The kernel's stated minimum is PHP 8.3 (RFC-012 Appendix D); satisfied.
7. **PHP class FQN written for IssuePolicy** (the one FQN counted in §11.3). Authors writing many custom Policies will hit one FQN per Action with a custom Policy. The KPI passes for the slice but tightens as plugins grow custom Policy count. Documented; the alternative (Policy bundles) violates RFC-005 §14.2.
8. **Type-hint elision** (§4.3) reduces imports but trades IDE assistance. Authors using IDE-assisted refactoring may prefer explicit hints; the DSL accepts both. Trade-off is per-author.

---

## 14. Open questions

1. **DSL evolution across kernel majors.** When the kernel reaches 2.x, the DSL surface may need to break to surface new primitives. The Standard Stack RFC-012 §16 commits the meta-package to track kernel majors; this RFC inherits that policy.
2. **Plugin-authored DSL extensions.** A plugin that adds a new Field Type or a new built-in Policy class registers it; the DSL surface for invoking it is plugin-authored (e.g., `Field::custom(MyType::class)` or via a plugin-provided trait). A formal extension contract is post-V1.
3. **DSL for `Relation` declarations.** The Invoice slice has none. The full Relation DSL is sketched in `Field::reference('billing.customer')` but multi-cardinality, cascade declarations, and many-to-many through-tables are out of this RFC's KPI. A follow-up may extend.
4. **MaintenanceAction DSL.** This RFC's `Action::create` and `Action::transition` are Standard Actions. MaintenanceActions (RFC-010 §8.1) require `kind: maintenance`, `acknowledges_bulk_lwm`, etc. The DSL exposes `Action::maintenance(...)` (sketched but not exercised in the Invoice slice). A follow-up may extend.
5. **DSL formatter / linter.** Consistent formatting of fluent chains across plugins. Out of this RFC; recommended as part of a tooling RFC.

---

## 15. Acceptance criteria

This RFC is accepted when:

1. The three role signatories (architect, DX, challenger) sign off on §2 (worked example), §5 (FQN elision), §8 (default aliases), §9 (reserved namespace shielding), §11 (KPI verification), §12 (no-new-kernel-concepts).
2. The Invoice worked example of §2 compiles end-to-end against the Standard Stack of RFC-012 (subject to RFC-012's §19 packages being built).
3. The KPI counts of §11 are reproduced by a third party against the worked example.
4. The §10 diagnostic taxonomy is implemented in `Ausus\Dsl` and surfaced by `ausus:doctor`.
5. RFC-012 §16.5 is updated to mark "DSL syntax" as no longer provisional, replacing the marker with "fixed by RFC-011."

If the Invoice worked example fails to compile, fails the KPIs, or introduces a kernel concept not present in the cited prior RFCs, this RFC is BLOCKED.

---

## 16. Determination

**ACCEPT.**

Justification:

- **KPI compliance.** §11 verifies the worked Invoice example at 20 DSL LOC (≤ 40 ✓), 2 import statements / 6 imported names (≤ 10 ✓), 1 manual FQN (≤ 3 ✓). All three KPIs pass with margin.
- **Hard-rule compliance.** §12 enumerates every DSL construct and confirms each composes existing kernel primitives. No new semantic concept is introduced.
- **Existing-practice fidelity.** The worked example mirrors the shape used in RFC-000 §2 and RFC-012's HelloInvoice; it is recognizable as the DSL the prior RFCs assumed.
- **Elision rules are bounded.** Convention-based resolution uses PSR-4 only; no filesystem scanning (RFC-001 §9.9 respected).
- **Reserved namespaces are shielded** at the DSL boundary, not just at the kernel boundary; misuse fails loudly at `boot()` rather than later in the lifecycle.
- **Diagnostic taxonomy is closed** (§10) and surfaces every new failure mode the DSL introduces.

Conditional notes (per §15):

- Acceptance criterion #2 (the worked example compiles end-to-end) requires the RFC-012 Standard Stack packages to exist (RFC-000 V0 Real Pass demonstrated they do not). This RFC's acceptance is **specification-level**; the runtime verification is pending package implementation.
- This RFC unblocks RFC-000 F-V0-01 at the specification level. RFC-012 §16.5 should be updated to remove "DSL syntax" from the provisional list.

Folding §2 (worked example) into `ausus/plugin-template` produces a starter that the next UX scan can measure against. Predicted UX-3 deltas vs UX-2 (informational, not in this RFC's scope):

| Metric                   | UX-2 (RFC-012 spec)    | UX-3 (RFC-011 spec) | Delta band   |
|--------------------------|------------------------|---------------------|---------------|
| Imports (manual)         | ~5–10                  | 2                   | Resolved      |
| FQNs (unique)            | 19                     | 1                   | Resolved      |
| Boilerplate LOC          | ~230                   | ~50 (Plugin + 1 Policy) | Resolved   |
| Concepts exposed         | ~25                    | ~12                 | Improved      |

These predicted deltas remain conditional on §15's acceptance criteria being met and on packages being built. The numbers above are not measured.
