# RFC-000 — V0 Vertical Slice Validation

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Draft (validation report)                              |
| Authors       | architect, kernel, challenger, domain                  |
| Date          | 2026-05-18                                             |
| Validates     | RFC-001 Draft-04, RFC-002, RFC-003, RFC-004, RFC-005, RFC-007 Draft-02, RFC-010 |
| Mission       | Freeze kernel expansion; prove end-to-end coherence via a single canonical slice. |

---

## 0. Mission

Twelve specification artifacts (eight RFCs + two amendments + two scan appendices) are accepted or drafted. No further kernel expansion is authorized until those artifacts prove they compose into a working platform. This RFC is the proof attempt.

The proof method: take **one** canonical business case — **Invoice Management** — and build it end-to-end on paper, using only what the existing RFCs commit. If a piece is missing, that gap is a finding. If a contradiction surfaces, that contradiction is a finding. If the developer experience is hostile, that is a finding. The goal is **honest validation**, not aspirational reassurance.

The slice is the smallest implementation that exercises every load-bearing primitive: Entity, Fields, Actions, Policies (role-based and Tenant-aware), Workflow, Projections, Persistence, Tenancy isolation, Audit, Presentation, React renderer, Reporting aggregate. Anything narrower would fail to validate composition.

**Determination** is at §14. The determination is one of `GO`, `BLOCKED`, `REQUIRES AMENDMENT`. Hard rule: no new primitives may be invented for the slice; every gap must be logged as a finding, not patched.

---

## 1. Slice scope

### 1.1 Business case

**Invoice Management.** A single Entity (`billing.invoice`) representing an invoice issued to a customer. Supports creation, issuance, cancellation. Drafts may be cancelled; issued invoices may be cancelled. Tenant `acme` runs the deployment; user `user42` operates against it.

### 1.2 Required primitives exercised

| # | Item                          | Spec ref                                   |
|---|-------------------------------|--------------------------------------------|
| 1 | Entity `billing.invoice`      | RFC-001 §2.1, §2.1.1, §2.1.2               |
| 2 | Fields: number, customer_name, amount, status, issued_at | RFC-001 §2.2; Amendment-02 §A-1.12 (audited_reads) |
| 3 | Actions: `invoice.issue`, `invoice.cancel` (plus `invoice.create`) | RFC-001 §2.4, §A-1.4                       |
| 4 | Policies: one role-based, one Tenant-aware | RFC-005 §2.1, §6, §7                       |
| 5 | Workflow: DRAFT → ISSUED → CANCELLED | RFC-001 §2.6 (descriptor only; see §10.WF) |
| 6 | Projections: `summary`, `detail` | RFC-001 §2.7; RFC-004 §3                   |
| 7 | Persistence: SQL driver       | RFC-002 (full)                             |
| 8 | Tenancy: row isolation        | RFC-003 §4                                 |
| 9 | Audit: database sink          | RFC-007 §5.3 (recommended Transactional primary) |
| 10 | Presentation: ViewSchema + React | RFC-004 §3, §10                            |
| 11 | Reporting: one aggregate query | RFC-010 §3                                 |

### 1.3 Out of scope for V0

- Multiple Tenants (one is sufficient).
- Multi-currency (USD only; RFC-004 §4 `money` type with `currency: "USD"`).
- Cross-Entity relations (`Invoice` is standalone in V0).
- MaintenanceActions (no bulk operations needed for the slice).
- Elevation (no cross-Tenant operations).
- Authentication implementation (Actor is provided pre-resolved).
- Tenant lifecycle beyond bootstrap (no archival, no migration, no suspension).
- ReportingDriver implementations beyond the one query.
- Strategy migration, override installation, override versioning (single Tenant in row-isolation, no overrides).

These are deliberate omissions to keep the slice minimal. They are not findings; their RFCs are accepted and the slice does not require their execution to prove end-to-end coherence.

---

## 2. (A) Exact DSL example

**The exact DSL surface is not normatively specified.** RFC-001 §11.10 defers DSL surface to RFC-011. RFC-005 §3 references PolicyDescriptor by FQN only. The syntax below conforms to RFC-001 §5.8 invariants (purity, serializability, determinism, declarative composition) but its specific shape is illustrative. Plugin authors writing against V0 today would be writing against a not-yet-fixed surface.

**This is finding F-V0-01 (BLOCKER).**

The DSL example below uses a plausible fluent surface consistent with the brief's example in the original `conversation_filament_framework.pdf`. It declares Entity, Fields, Actions, Policies, Workflow, Projections in one chain. No closures. No I/O. No domain logic at definition time. Every cross-reference is by FQN string.

```php
<?php

namespace Acme\Billing;

use Ausus\Kernel\Dsl\Entity;
use Ausus\Kernel\Dsl\Field;
use Ausus\Kernel\Dsl\Action;
use Ausus\Kernel\Dsl\Policy;
use Ausus\Kernel\Dsl\Workflow;
use Ausus\Kernel\Dsl\Projection;
use Ausus\Kernel\Contracts\Plugin;
use Ausus\Kernel\Contracts\PluginLifecycle;
use Illuminate\Support\ServiceProvider;

final class BillingPlugin extends ServiceProvider implements Plugin, PluginLifecycle
{
    public function register(): void
    {
        // Laravel container bindings only (RFC-001 §5.1).
    }

    public function boot(): void
    {
        // AUSUS DSL registration. Subject to RFC-001 §5.8 invariants.

        Entity::make('billing.invoice')
            ->fields([
                Field::id('id'),
                Field::system('tenant_id'),
                Field::string('number')
                    ->uniqueWithinTenant()
                    ->maxLength(32),
                Field::string('customer_name')
                    ->maxLength(200),
                Field::money('amount')
                    ->currency('USD'),
                Field::enum('status', ['DRAFT', 'ISSUED', 'CANCELLED'])
                    ->default('DRAFT'),
                Field::datetime('issued_at')
                    ->nullable(),
                Field::timestamps(),
                Field::version(),
            ])
            ->actions([
                Action::make('billing.invoice.create')
                    ->policy('billing.invoice.policy.create')
                    ->subjectRequired(false)
                    ->inputs([
                        Field::string('number')->maxLength(32),
                        Field::string('customer_name')->maxLength(200),
                        Field::money('amount')->currency('USD'),
                    ])
                    ->effect(\Acme\Billing\Effects\CreateInvoice::class),
                Action::make('billing.invoice.issue')
                    ->policy('billing.invoice.policy.issue')
                    ->subjectRequired(true)
                    ->effect(\Acme\Billing\Effects\IssueInvoice::class),
                Action::make('billing.invoice.cancel')
                    ->policy('billing.invoice.policy.cancel')
                    ->subjectRequired(true)
                    ->inputs([
                        Field::string('reason')->maxLength(500),
                    ])
                    ->effect(\Acme\Billing\Effects\CancelInvoice::class),
            ])
            ->policies([
                Policy::make('billing.invoice.policy.create')
                    ->implementedBy(\Acme\Billing\Policies\CanCreateInvoice::class),
                Policy::make('billing.invoice.policy.issue')
                    ->implementedBy(\Acme\Billing\Policies\CanIssueInvoice::class),
                Policy::make('billing.invoice.policy.cancel')
                    ->implementedBy(\Acme\Billing\Policies\CanCancelInvoice::class),
                Policy::make('billing.invoice.projection.read')
                    ->implementedBy(\Acme\Billing\Policies\CanReadInvoice::class),
            ])
            ->workflows([
                Workflow::make('billing.invoice.lifecycle')
                    ->states(['DRAFT', 'ISSUED', 'CANCELLED'])
                    ->initial('DRAFT')
                    ->transition('DRAFT',   'ISSUED',    via: 'billing.invoice.issue')
                    ->transition('DRAFT',   'CANCELLED', via: 'billing.invoice.cancel')
                    ->transition('ISSUED',  'CANCELLED', via: 'billing.invoice.cancel'),
            ])
            ->projections([
                Projection::make('summary')
                    ->fields(['id', 'number', 'customer_name', 'status', 'amount'])
                    ->actions(['billing.invoice.create', 'billing.invoice.cancel'])
                    ->filters(['status'])
                    ->policy('billing.invoice.projection.read'),
                Projection::make('detail')
                    ->fields(['id', 'number', 'customer_name', 'status', 'amount',
                              'issued_at', 'created_at', 'updated_at'])
                    ->actions(['billing.invoice.issue', 'billing.invoice.cancel'])
                    ->policy('billing.invoice.projection.read'),
            ]);
    }

    public function install(): void { /* migrations run via plugin's migration files */ }
    public function upgrade(string $from, string $to): void { /* none for V0 */ }
    public function uninstall(): void { /* drop tables; out of V0 scope */ }
}
```

A role-based Policy (uses `Actor::roles()` per RFC-005 §1.3):

```php
<?php

namespace Acme\Billing\Policies;

use Ausus\Kernel\Contracts\Policy\Policy;
use Ausus\Kernel\Contracts\Policy\Decision;
use Ausus\Kernel\Contracts\Policy\Subject;
use Ausus\Kernel\Contracts\Policy\Context;
use Ausus\Kernel\Contracts\Policy\Actor;

final class CanIssueInvoice implements Policy
{
    public function evaluate(Actor $actor, string $actionFqn, ?Subject $subject, Context $context): Decision
    {
        return in_array('invoice.issuer', $actor->roles(), true)
            ? Decision::Permit
            : Decision::Deny;
    }
}
```

A Tenant-aware Policy (uses `Context::tenant()` per RFC-005 §7.1; reads its own static configuration per RFC-005 §10.2 #3):

```php
<?php

namespace Acme\Billing\Policies;

use Ausus\Kernel\Contracts\Policy\Policy;
use Ausus\Kernel\Contracts\Policy\Decision;
use Ausus\Kernel\Contracts\Policy\Subject;
use Ausus\Kernel\Contracts\Policy\Context;
use Ausus\Kernel\Contracts\Policy\Actor;

final class CanCreateInvoice implements Policy
{
    /** @var array<string,string> tenant_id => plan ("trial" | "active") */
    private array $tenantPlans;

    public function __construct(array $tenantPlans)
    {
        $this->tenantPlans = $tenantPlans;
    }

    public function evaluate(Actor $actor, string $actionFqn, ?Subject $subject, Context $context): Decision
    {
        if (!in_array('invoice.creator', $actor->roles(), true)) {
            return Decision::Deny;
        }
        $plan = $this->tenantPlans[$context->tenant()->id()->value()] ?? 'trial';
        return $plan === 'active' ? Decision::Permit : Decision::Deny;
    }
}
```

The Action effect shape is **not specified** by any current RFC. The DSL example above uses `->effect(ClassName::class)`. The class shape is invented for the slice:

```php
<?php

namespace Acme\Billing\Effects;

interface ActionEffect
{
    public function handle(
        \Ausus\Kernel\Contracts\Persistence\PersistenceContext $ctx,
        ?\Ausus\Kernel\Contracts\Persistence\Reference $subject,
        array $inputs,
        \Ausus\Kernel\Contracts\Policy\Context $context
    ): array;   // returns outputs map
}

final class IssueInvoice implements ActionEffect
{
    public function handle(
        \Ausus\Kernel\Contracts\Persistence\PersistenceContext $ctx,
        ?\Ausus\Kernel\Contracts\Persistence\Reference $subject,
        array $inputs,
        \Ausus\Kernel\Contracts\Policy\Context $context
    ): array {
        $repo = $ctx->repository('billing.invoice');
        $invoice = $repo->find($subject) ?? throw new \DomainException('Invoice not found');

        if ($invoice->field('status') !== 'DRAFT') {
            throw new \DomainException("Cannot issue invoice in state {$invoice->field('status')}");
        }

        $updated = $repo->update(
            $subject,
            ['status' => 'ISSUED', 'issued_at' => $context->clock()->toRfc3339()],
            $invoice->version()
        );

        return ['status' => 'ISSUED', 'issued_at' => $updated->field('issued_at')];
    }
}
```

**`ActionEffect` is not declared by RFC-001.** RFC-001 §2.4 names the Action descriptor but does not specify how the effect is dispatched, what signature it has, or how the Invoker (RFC-001 §A-1.4 §8.2.1) acquires and invokes it. **Finding F-V0-02 (BLOCKER).**

The Workflow descriptor in the DSL is compiled into the metadata graph for cross-reference validation (every transition's `via` Action exists, every state is well-defined). However, **Workflow execution semantics are deferred to RFC-006** (RFC-001 §11.5). For V0, the state-change logic lives inside each Action effect (as shown above: `IssueInvoice::handle` directly sets `status` and `issued_at`). The Workflow descriptor is **declarative documentation only** in V0. **Finding F-V0-03 (BLOCKER for declared-as-spec Workflow execution).**

---

## 3. (B) Compiled metadata graph (excerpt)

The Compiler (RFC-001 §4.2) consumes the §2 DSL registration and produces the Metadata Graph. The relevant excerpts follow. Format is illustrative JSON; the actual graph artifact is a PHP-serialized opcache-friendly file per RFC-001 §7.1.

```json
{
  "kernelVersion": "1.0.0",
  "manifestHash": "8f3c9a2b...",
  "graphHash": "7e1a4d6c...",

  "entities": {
    "billing.invoice": {
      "fqn": "billing.invoice",
      "tenantScoped": true,
      "auditedReads": false,
      "fields": [
        { "name": "id",            "type": "identity", "system": true },
        { "name": "tenant_id",     "type": "string",   "system": true, "immutable": true },
        { "name": "number",        "type": "string",   "maxLength": 32, "uniqueWithinTenant": true },
        { "name": "customer_name", "type": "string",   "maxLength": 200 },
        { "name": "amount",        "type": "money",    "currency": "USD" },
        { "name": "status",        "type": "enum",     "values": ["DRAFT","ISSUED","CANCELLED"], "default": "DRAFT" },
        { "name": "issued_at",     "type": "datetime", "nullable": true },
        { "name": "created_at",    "type": "datetime", "system": true },
        { "name": "updated_at",    "type": "datetime", "system": true },
        { "name": "_version",      "type": "version",  "system": true }
      ],
      "actions": [
        "billing.invoice.create",
        "billing.invoice.issue",
        "billing.invoice.cancel"
      ],
      "workflows": ["billing.invoice.lifecycle"],
      "projections": ["billing.invoice.summary", "billing.invoice.detail"]
    }
  },

  "actions": {
    "billing.invoice.create": {
      "fqn": "billing.invoice.create",
      "policy": "billing.invoice.policy.create",
      "subjectRequired": false,
      "inputs": [
        { "name": "number",        "type": "string", "maxLength": 32 },
        { "name": "customer_name", "type": "string", "maxLength": 200 },
        { "name": "amount",        "type": "money",  "currency": "USD" }
      ],
      "effect": "Acme\\Billing\\Effects\\CreateInvoice",
      "kind": "standard",
      "audited": true
    },
    "billing.invoice.issue": {
      "fqn": "billing.invoice.issue",
      "policy": "billing.invoice.policy.issue",
      "subjectRequired": true,
      "inputs": [],
      "effect": "Acme\\Billing\\Effects\\IssueInvoice",
      "kind": "standard",
      "audited": true
    },
    "billing.invoice.cancel": {
      "fqn": "billing.invoice.cancel",
      "policy": "billing.invoice.policy.cancel",
      "subjectRequired": true,
      "inputs": [{ "name": "reason", "type": "string", "maxLength": 500 }],
      "effect": "Acme\\Billing\\Effects\\CancelInvoice",
      "kind": "standard",
      "audited": true
    }
  },

  "policies": {
    "billing.invoice.policy.create":   { "fqn": "...", "class": "Acme\\Billing\\Policies\\CanCreateInvoice",  "cacheable": true,  "timeoutMs": null },
    "billing.invoice.policy.issue":    { "fqn": "...", "class": "Acme\\Billing\\Policies\\CanIssueInvoice",   "cacheable": true,  "timeoutMs": null },
    "billing.invoice.policy.cancel":   { "fqn": "...", "class": "Acme\\Billing\\Policies\\CanCancelInvoice",  "cacheable": true,  "timeoutMs": null },
    "billing.invoice.projection.read": { "fqn": "...", "class": "Acme\\Billing\\Policies\\CanReadInvoice",    "cacheable": true,  "timeoutMs": null }
  },

  "workflows": {
    "billing.invoice.lifecycle": {
      "states": ["DRAFT","ISSUED","CANCELLED"],
      "initial": "DRAFT",
      "transitions": [
        { "from": "DRAFT",  "to": "ISSUED",    "via": "billing.invoice.issue"  },
        { "from": "DRAFT",  "to": "CANCELLED", "via": "billing.invoice.cancel" },
        { "from": "ISSUED", "to": "CANCELLED", "via": "billing.invoice.cancel" }
      ]
    }
  },

  "projections": {
    "billing.invoice.summary": {
      "owner": "billing.invoice",
      "fields":  ["id","number","customer_name","status","amount"],
      "actions": ["billing.invoice.create","billing.invoice.cancel"],
      "filters": ["status"],
      "policy":  "billing.invoice.projection.read"
    },
    "billing.invoice.detail": {
      "owner": "billing.invoice",
      "fields":  ["id","number","customer_name","status","amount","issued_at","created_at","updated_at"],
      "actions": ["billing.invoice.issue","billing.invoice.cancel"],
      "filters": [],
      "policy":  "billing.invoice.projection.read"
    }
  }
}
```

The Compiler's validations (per RFC-001 §4.2.3) pass:
- All Action `policy` FQNs resolve to registered Policy descriptors.
- All Action `effect` classes — **the Compiler does not currently validate effect classes** because no contract requires them (see F-V0-02).
- All Workflow `via` FQNs resolve to registered Actions on the owning Entity.
- All Projection `fields` and `actions` resolve to descriptors on the owning Entity.
- All Projection `policy` FQNs resolve.
- No cyclic Relations (no Relations declared in V0).
- No cross-Tenant Relations (no Relations).

---

## 4. (C) Runtime invocation trace

`POST https://acme.app.example/api/billing/invoices/inv_01J/issue`

Tracing the call through every kernel layer per the current RFC stack:

```
1. HTTP middleware (L4 entry)
   - TenantResolver.HTTP.resolve(request)  [RFC-003 §3, §11.1]
     - subdomain resolver: "acme.app.example" → TenantId("acme")
   - TenantCatalog.load(TenantId("acme"))  [RFC-003 §5]
     - state = ACTIVE → admit
   - Bind active TenantContext
   - CorrelationId allocated: 01HXZ...
   - TraceId from W3C traceparent header: 9f8e7d...
   - Clock pinned: 2026-05-18T14:32:00.123456Z

2. Authentication (out of slice; stubbed)
   - Actor resolved: { type: "user", id: "user42", homeTenant: "acme" }
   - roles(): ["invoice.creator", "invoice.issuer", "invoice.viewer"]
   - roleHash(): "9f8e7d..."  [RFC-005 §1.3]

3. L4 API Surface dispatch
   - Path → action FQN: "billing.invoice.issue"
   - Path → Subject: Reference(acme, billing.invoice, "inv_01J")
   - Inputs: {} (empty body)
   - Invoker.invoke(Actor=user42, ActionFqn="billing.invoice.issue",
                    Subject=Reference, inputs={})
                                       [RFC-001 §A-1.4 §8.2.1; RFC-005 §11]

4. Invoker step 1 — TenantContext check
   - Active TenantContext = acme ✓                [RFC-001 §8.2.1]
   - Subject.tenant_id = "acme" ✓                 [RFC-002 §13.1]

5. Invoker step 2 — Policy chain
   - Chain assembled per RFC-005 §4.1:
       segment 1: Action-attached base → ["billing.invoice.policy.issue"]
       segment 2: Action-attached Tenant-added → []   (no overrides)
       segment 3: Entity-level → not evaluated (no kernel.entity.read for Action invocation)
       segments 4–7: not applicable (Action, not Field/Projection)
       segment 8: Global → []                     (no global Policies in V0)
   - evaluate("billing.invoice.policy.issue", actor=user42, action=...,
              subject=Reference, context=Context{tenant=acme, ...})
     - in_array("invoice.issuer", actor.roles()) → true
     - returns Permit
   - Combined: Permit                              [RFC-005 §5.1]
   - Cache key tier 2: (graphHash, "acme", overrideVersion=0,
                         "action:billing.invoice.issue", actorRoleHash,
                         "instance-bound", decisionContextHash)
     - subjectClass = "instance-bound" → NOT cached  [RFC-005 §8.3]

6. Invoker step 3 — Workflow guard
   - Transition lookup: (current_state=?, target=ISSUED, via=billing.invoice.issue)
   - Workflow guard mechanism: NOT SPECIFIED in current RFCs
   - V0 shim: guard logic embedded in IssueInvoice::handle effect (raises if not DRAFT)
   - See Finding F-V0-03

7. Invoker step 4 — Action effect
   - PersistenceDriver.beginTransaction(tenant=acme) → txn  [RFC-002 §3.1, §7]
   - PersistenceContext.repository("billing.invoice") → repo
   - repo.find(Reference) → Entity{
       id: "inv_01J",
       tenant_id: "acme",
       number: "INV-2026-0001",
       customer_name: "Globex",
       amount: { amount: "1500.00", currency: "USD" },
       status: "DRAFT",
       issued_at: null,
       created_at: "2026-05-17T10:00:00Z",
       updated_at: "2026-05-17T10:00:00Z",
       _version: Version("v1")
     }
   - guard: invoice.status == "DRAFT" ✓
   - repo.update(Reference, {status: "ISSUED",
                              issued_at: "2026-05-18T14:32:00.123456Z"},
                 expected: Version("v1"))
     - SQL: UPDATE billing_invoices SET status=?, issued_at=?, _version=?
            WHERE tenant_id=? AND id=? AND _version=?
     - rowsAffected=1 → success; new version v2
   - effect returns: {status: "ISSUED", issued_at: "2026-05-18T14:32:00.123456Z"}

8. Invoker step 5 — Audit emission
   - AuditEntry constructed (RFC-007 §2.1; RFC-001 §A-1.8):
       entryId:        01HX5Y7Z9V2K4M6N8P0Q  (UUID v7)
       sequence:       0  (first under this correlationId)
       actor:          {type:"user", id:"user42", homeTenant:"acme"}
       tenant:         "acme"
       actionFqn:      "billing.invoice.issue"
       subject:        SingleSubject(acme, billing.invoice, "inv_01J")
       inputs:         {}
       outputs:        {status: "ISSUED", issued_at: "2026-05-18T14:32:00.123456Z"}
       timestamp:      "2026-05-18T14:32:00.234567Z"
       correlationId:  01HXZ...
       traceId:        9f8e7d...
       invocationClass: "Standard"
       elevation:      null
       emitterVersion: "1.0.0"
   - Redaction (RFC-007 §14): global patterns empty; no Field marked sensitive; no Action-level redaction. inputs/outputs unchanged.
   - Primary sink: Transactional database sink (RFC-007 §4.2, §5.3)
     - sink.writeInTransaction(entry, txn) → INSERT INTO kernel_audit_log ...
     - returns; entry is part of txn
   - Auditor returns EmissionOutcome(primaryAcked=true, secondaryDispatched=[])
   - No secondary sinks in V0

9. Invoker step 6 — Transaction commit
   - driver.commit(txn)                            [RFC-002 §7]
   - Data + audit committed atomically (Transactional primary sink)

10. L4 returns 200 OK
    Body: { "ok": true, "subject": "inv_01J", "outputs": { "status": "ISSUED", "issued_at": "..." } }
```

The trace exercises: Tenant resolution, Tenant catalog admission, Invoker chain, Policy evaluation, Workflow-via-effect-shim, Repository read + write with optimistic lock, audit emission with Transactional primary, atomic commit. Eleven RFC commitments touched. Every contract resolves.

**One shim, two unspecified contracts:**

- Workflow guard runs inside the effect (F-V0-03).
- `ActionEffect` interface invented for the slice (F-V0-02).
- Action `effect` class field present in graph but Compiler does not validate it (F-V0-02 derivative).

---

## 5. (D) Audit entries

V0 emits audit entries for every mutating Action. Below: literal entry from §4 step 8, plus a `billing.invoice.create` entry preceding it. Wire format per RFC-007 §2.1 + RFC-007 Amendment-01 §A-7.2 (elevation preservation; null here) + RFC-007 Amendment-01 §A-7.4 (sentinel handling; not applicable here).

```json
[
  {
    "entryId":         "01HX5W3T8N1J5K9M2P0R",
    "sequence":        0,
    "actor":           { "type": "user", "id": "user42", "homeTenant": "acme" },
    "tenant":          "acme",
    "actionFqn":       "billing.invoice.create",
    "subject":         {
      "kind":            "single",
      "tenant_id":       "acme",
      "entity_fqn":      "billing.invoice",
      "identity_handle": "inv_01J"
    },
    "inputs":          {
      "number":          "INV-2026-0001",
      "customer_name":   "Globex",
      "amount":          { "amount": "1500.00", "currency": "USD" }
    },
    "outputs":         {
      "id":              "inv_01J",
      "status":          "DRAFT"
    },
    "timestamp":       "2026-05-17T10:00:00.118273Z",
    "correlationId":   "01HX5W2V0K3J7M9P1Q3R",
    "traceId":         "4a3b2c1d...",
    "invocationClass": "Standard",
    "elevation":       null,
    "emitterVersion":  "1.0.0"
  },
  {
    "entryId":         "01HX5Y7Z9V2K4M6N8P0Q",
    "sequence":        0,
    "actor":           { "type": "user", "id": "user42", "homeTenant": "acme" },
    "tenant":          "acme",
    "actionFqn":       "billing.invoice.issue",
    "subject":         {
      "kind":            "single",
      "tenant_id":       "acme",
      "entity_fqn":      "billing.invoice",
      "identity_handle": "inv_01J"
    },
    "inputs":          {},
    "outputs":         {
      "status":          "ISSUED",
      "issued_at":       "2026-05-18T14:32:00.123456Z"
    },
    "timestamp":       "2026-05-18T14:32:00.234567Z",
    "correlationId":   "01HXZ8A7B6C5D4E3F2G1",
    "traceId":         "9f8e7d6c...",
    "invocationClass": "Standard",
    "elevation":       null,
    "emitterVersion":  "1.0.0"
  }
]
```

Notes against the spec:

- `entryId` is UUID v7 per RFC-007 §2.2.1.
- `sequence: 0` per RFC-007 §2.2.2 (first emission under each correlationId; create and issue are separate top-level invocations with separate correlationIds).
- `subject.kind: "single"` — Standard Action, real instance Subject (per RFC-001 §A-1.8 + Amendment-02 §A-1.13 — no synthetic).
- No `outputs.bulk_entities` (RFC-007 Amendment-01 §A-7.1 requires this only for `invocationClass: "Maintenance"`; V0 has no MaintenanceAction).
- `elevation: null` — no `Ausus::elevate` in V0.
- `traceId` honoured if upstream sends `traceparent`; otherwise null. Two different traceIds shown to reflect two distinct top-level HTTP calls.

---

## 6. (E) Rendered ViewSchema

`GET https://acme.app.example/api/billing/invoice/summary?locale=en-US&renderer=react.web.v1&acceptSchemaVersions=1.0.0`

Per RFC-004 §3, §11, with the negotiated profile `react.web.v1`.

```json
{
  "schemaVersion": "1.0.0",
  "targetProfile": "react.web.v1",
  "metadata": {
    "projection":    "billing.invoice.summary",
    "entity":        "billing.invoice",
    "tenant":        "acme",
    "locale":        "en-US",
    "generatedAt":   "2026-05-18T14:35:01.998877Z",
    "cacheKey":      "7e1a4d6c|acme|0|billing.invoice.summary|9f8e7d|en-US|react.web.v1|1.0.0",
    "actorRoleHash": "9f8e7d..."
  },
  "compatibility": {
    "requestedProfile":     "react.web.v1",
    "negotiatedProfile":    "react.web.v1",
    "requestedVersions":    ["1.0.0"],
    "emittedVersion":       "1.0.0",
    "downgrades":           [],
    "rejectedCapabilities": []
  },
  "fields": [
    { "name": "id",            "type": "string",  "label": "ID",
      "help": null, "nullable": false, "readOnly": true,
      "typeOptions": {},
      "hints": { "widget": "text", "width": "small" },
      "validation": { "required": true } },
    { "name": "number",        "type": "string",  "label": "Invoice number",
      "help": null, "nullable": false, "readOnly": false,
      "typeOptions": { "maxLength": 32 },
      "hints": { "widget": "text", "width": "small" },
      "validation": { "required": true, "maxLength": 32 } },
    { "name": "customer_name", "type": "string",  "label": "Customer",
      "help": null, "nullable": false, "readOnly": false,
      "typeOptions": { "maxLength": 200 },
      "hints": { "widget": "text", "width": "medium" },
      "validation": { "required": true, "maxLength": 200 } },
    { "name": "status",        "type": "enum",    "label": "Status",
      "help": null, "nullable": false, "readOnly": true,
      "typeOptions": { "options": [
        { "value": "DRAFT",     "label": "Draft"     },
        { "value": "ISSUED",    "label": "Issued"    },
        { "value": "CANCELLED", "label": "Cancelled" }
      ] },
      "hints": { "widget": "badge", "width": "small" },
      "validation": { "required": true } },
    { "name": "amount",        "type": "money",   "label": "Amount",
      "help": null, "nullable": false, "readOnly": false,
      "typeOptions": { "allowedCurrencies": ["USD"] },
      "hints": { "widget": "money", "width": "small" },
      "validation": { "required": true } }
  ],
  "actions": [
    { "fqn": "billing.invoice.create", "name": "create",
      "label": "New invoice", "description": null, "icon": "plus",
      "subjectRequired": false,
      "inputs": [
        { "name": "number",        "type": "string", "label": "Number",
          "typeOptions": { "maxLength": 32 }, "validation": { "required": true } },
        { "name": "customer_name", "type": "string", "label": "Customer",
          "typeOptions": { "maxLength": 200 }, "validation": { "required": true } },
        { "name": "amount",        "type": "money",  "label": "Amount",
          "typeOptions": { "allowedCurrencies": ["USD"] }, "validation": { "required": true } }
      ],
      "confirmation": null, "audited": true, "maintenance": false,
      "hints": { "style": "primary" } },
    { "fqn": "billing.invoice.cancel", "name": "cancel",
      "label": "Cancel", "description": null, "icon": "x",
      "subjectRequired": true,
      "inputs": [
        { "name": "reason", "type": "string", "label": "Reason",
          "typeOptions": { "maxLength": 500 }, "validation": { "required": true } }
      ],
      "confirmation": { "required": true,
                        "prompt": "Cancel this invoice?",
                        "challenge": null },
      "audited": true, "maintenance": false,
      "hints": { "style": "danger" } }
  ],
  "filters": [
    { "name": "status", "field": "status", "operator": "in",
      "label": "Status",
      "operands": { "valueType": "enum", "options": [
        { "value": "DRAFT",     "label": "Draft"     },
        { "value": "ISSUED",    "label": "Issued"    },
        { "value": "CANCELLED", "label": "Cancelled" }
      ] },
      "default": null }
  ],
  "data": {
    "items": [
      {
        "id": "inv_01J",
        "number": "INV-2026-0001",
        "customer_name": "Globex",
        "status": "ISSUED",
        "amount": { "amount": "1500.00", "currency": "USD" },
        "_version": "v2"
      },
      {
        "id": "inv_02K",
        "number": "INV-2026-0002",
        "customer_name": "Acme Holdings",
        "status": "DRAFT",
        "amount": { "amount": "850.00", "currency": "USD" },
        "_version": "v1"
      }
    ],
    "nextCursor":     null,
    "previousCursor": null,
    "totalEstimate":  2,
    "pageSize":       50
  }
}
```

The ViewSchema exercises: envelope (§3.1), metadata (§3.2), compatibility (§3.3), fields with validation + hints (§5), actions with input descriptors + confirmation (§6), filters (§7), data with `_version` reserved key (§8.4). React renderer consumes JSON; no backend imports. Cache key includes all eight RFC-004 §12.1 components.

The aggregate reporting query — "total outstanding amount per customer in tenant 'acme'" — exercises RFC-010 §3:

```json
{
  "from": { "entity": "billing.invoice", "alias": "inv" },
  "joins": [],
  "filter": {
    "kind": "FieldEquals",
    "field": "inv.status",
    "value": "ISSUED"
  },
  "project": [
    { "kind": "field",     "source": "inv.customer_name",         "alias": "customer" },
    { "kind": "aggregate", "function": "sum",
      "source": "inv.amount", "alias": "total_outstanding" },
    { "kind": "aggregate", "function": "count",
      "source": null, "alias": "invoice_count" }
  ],
  "groupBy": ["inv.customer_name"],
  "having": null,
  "orderBy": [{ "source": "total_outstanding", "direction": "desc" }],
  "pagination": { "kind": "cursor", "pageSize": 100, "cursor": null },
  "parameters": []
}
```

Response (RFC-010 §4.1 `ReportingResult`):

```json
{
  "rows": [
    { "customer": "Globex",        "total_outstanding": { "amount": "1500.00", "currency": "USD" }, "invoice_count": 1 },
    { "customer": "Acme Holdings", "total_outstanding": { "amount":  "850.00", "currency": "USD" }, "invoice_count": 1 }
  ],
  "nextCursor":     null,
  "previousCursor": null,
  "totalEstimate":  2,
  "pageSize":       100,
  "schemaVersion":  "1.0.0",
  "metadata": {
    "generatedAt":      "2026-05-18T14:40:00.000000Z",
    "driverProfile":    "ausus.reporting.sql.v1",
    "queryFingerprint": "a7b3c2d1..."
  }
}
```

No `FieldVisibilityDenied` (no Fields marked `visibility` Policy in V0 — finding F-V0-04). No `EntityReadDenied` (no Entity-level read Policy required because `audited_reads: false`). Query succeeds.

---

## 7. (F) Developer setup steps

Steps a Laravel developer follows to bring this slice live from scratch. Assumes Laravel 11.x, PHP 8.3+, Postgres 15+, Node 20+ already installed.

```
1.  composer create-project laravel/laravel acme-billing
    cd acme-billing

2.  composer require                                            [presumed packages]
    ausus/kernel
    ausus/persistence-sql            # RFC-002 SQL driver implementation
    ausus/tenancy-row                # RFC-003 row-level isolation strategy
    ausus/audit-database             # RFC-007 transactional database sink
    ausus/reporting-sql              # RFC-010 SQL-based ReportingDriver
    ausus/authorization-stub         # RFC-005 §1.3 Actor minimum + roles config
    ausus/presentation-default       # RFC-004 §10 react.web.v1 profile + ViewSchema generator
    ausus/renderer-react             # the React renderer reference package
    acme/billing                     # the plugin authored above

3.  publish config:
    php artisan vendor:publish --tag=ausus-config
    edit config/ausus.php:                                       [RFC-001 §5.4]
      kernel.version: read
      compiler.strategy: lazy        # dev mode
      runtime.strict_tenant: true
      tenancy.default_resolver: ausus.tenancy.http.subdomain
      tenancy.default_isolation: ausus.tenancy.row              # RFC-003 §4
      plugins.autodiscovery: true
      audit.primary_sink: ausus.audit.database                  # RFC-007 §16.1
      audit.secondary_sinks: []
      audit.redact: []
      audit.primary_ack_timeout_ms: 5000
      reporting.default_driver: ausus.reporting.sql
      reporting.query_timeout_seconds: 60
      reporting.max_page_size: 1000
      maintenance.default_acknowledgement_required: true
      policy_engine.default_timeout_ms: 100

4.  run migrations:
    php artisan migrate                                          # creates:
       - kernel_tenant, kernel_tenant_state_log                  # RFC-003 §5
       - kernel_tenant_override, kernel_audit_pending            # RFC-003 §8, RFC-007 §12
       - kernel_audit_log, kernel_audit_retry_queue              # RFC-007 §5.3, §11
       - billing_invoices                                        # from acme/billing migration

5.  bootstrap a Tenant:
    php artisan ausus:tenant:create acme --strategy=row          # RFC-003 §6

6.  create an Actor (handled by ausus/authorization-stub):
    php artisan auth:stub:create user42 \
       --tenant=acme --roles=invoice.creator,invoice.issuer,invoice.viewer

7.  compile the metadata graph (production builds; lazy in dev):
    php artisan ausus:compile                                    # RFC-001 §4.2

8.  verify:
    php artisan ausus:doctor
       - PASS: graph valid, cache present, plugin compatibility, persistence reachable,
               reporting driver reachable, audit sink reachable, MaintenanceAction inventory (empty).
       - PASS (Policy doctor per RFC-005 §12): no unreachable, no duplicates, no kernel.* collisions.

9.  start dev server:
    php artisan serve

10. mount the React renderer (from ausus/renderer-react):
    cd frontend
    npm install ausus-renderer-react
    npm run dev

11. point browser at:
    http://acme.localhost:8000/dashboard/billing.invoice.summary

12. issue a draft invoice via the API:
    POST http://acme.localhost:8000/api/billing/invoice
    Body: { "number": "INV-2026-0003", "customer_name": "Test", "amount": {"amount":"100.00","currency":"USD"} }

13. observe in the React renderer: new invoice appears with status DRAFT,
    "Issue" button visible (per Projection.detail), "Cancel" button visible (per both Projections).

14. click "Issue":
    POST http://acme.localhost:8000/api/billing/invoice/inv_xyz/issue
    Status updates to ISSUED; issued_at timestamp set; audit entry visible
    via:  php artisan ausus:audit:tail --tenant=acme

15. query the report:
    POST http://acme.localhost:8000/api/reporting
    Body: <ReportingQuery from §6>
    Response: aggregate rows as in §6.
```

**Steps 2 and 10 reference packages that may not yet exist.** The kernel contracts (RFC-001, -002, -003, -004, -005, -007, -010) define what each package must implement, but the packages are implementation work not validated by this RFC. **Finding F-V0-05.**

Steps 5, 6 use `ausus:tenant:create` (RFC-001 §5.5, RFC-003 §6) and `auth:stub:create` (RFC-005 §1.3). The first is in scope; the second is invented for the slice — no Authorization plugin RFC exists. **Finding F-V0-06.**

---

## 8. (G) Lines-of-code metrics

Plugin author's `acme/billing` package, by category:

| Category                                | Lines  | File(s) / notes                            |
|-----------------------------------------|--------|--------------------------------------------|
| `composer.json` (Composer + AUSUS extra)| 30     | RFC-001 §6.1 manifest                       |
| Plugin/ServiceProvider class            | 60     | RFC-001 §5.1; one class implements three interfaces (Amendment-01 §A-1.1) |
| DSL declaration (Entity, Fields, Actions, Policies, Workflow, Projections) | 85 | §2 of this RFC; one method |
| Policy implementations (4 classes)      | 90     | CanCreate, CanIssue, CanCancel, CanRead — 20–25 lines each |
| Action effect implementations (3 classes)| 110   | Create, Issue, Cancel — 30–40 lines each |
| Migration (`billing_invoices` table)    | 40     | Standard Laravel migration                  |
| Tests (Policy + Effect unit, integration smoke) | 250 | Plugin author responsibility (not slice-required) |
| **Plugin total**                        | **665**| (155 lines without tests)                   |

Deployment configuration / setup:

| Item                                | Lines  |
|-------------------------------------|--------|
| `config/ausus.php` (overrides only) | 20     |
| Authorization stub seed             | 10     |
| Tenant bootstrap CLI calls          | 2      |
| **Deployment total**                | **32** |

React frontend (consumer of ViewSchema):

| Item                                          | Lines  |
|-----------------------------------------------|--------|
| `App` component + router                      | 60     |
| `ViewSchemaConsumer` component (renders any) | 180    |
| Field-type renderers (text, enum, money, badge, date) | 200 |
| Action invocation + confirmation dialog       | 90     |
| Filter chip strip                             | 60     |
| Cursor pagination                             | 50     |
| **Frontend total**                            | **640**|

The frontend lines are amortized across all future projections — only the application-specific parts (routes, custom theme) accrue per slice. For a second Entity, the per-Entity frontend cost is ~0 (the ViewSchemaConsumer handles it).

**Grand total for V0**: **1,337 lines** for the working slice (plugin + deployment + frontend), of which **155 lines** are the plugin's domain-level DSL + manifest. Most of the rest is reusable across all future Entities.

For comparison (not a benchmark, an order-of-magnitude check):

- A Filament resource for the same Entity: ~300 lines (one resource file, no separate frontend).
- A Nova resource: ~400 lines.
- A Retool app: zero code, ~30 minutes of UI configuration.

AUSUS V0 is 4× the Filament size for the first Entity. The amortization slope is the V0 bet: at Entity #2, AUSUS would add ~155 lines (DSL + Policies + effects) vs Filament's ~300 (full resource). Crossover at ~3 Entities. By Entity #10, AUSUS would be at ~1,400 + 9×155 = 2,795 lines, Filament at ~3,000. By Entity #50: AUSUS ~9,100 vs Filament ~15,000.

The amortization is the bet. It is unproven until V0 ships and someone builds Entity #2.

---

## 9. (H) Time-to-first-screen metric

Estimated for a Laravel + React developer who has read the RFCs once.

| Phase                                              | Estimated time |
|----------------------------------------------------|----------------|
| Setup steps 1–9 (install, compile, doctor)         | 90 minutes     |
| Step 10–11 (React mount, browse blank screen)      | 30 minutes     |
| Step 12 (curl POST to create, observe response)    | 15 minutes     |
| Step 13–14 (UI shows invoice; issue button works)  | 45 minutes     |
| Step 15 (reporting query response)                 | 30 minutes     |
| Reading documentation + first DSL trial            | 180 minutes    |
| Debugging the inevitable typo / missing config     | 90 minutes     |
| **Total**                                          | **~8 hours**   |

Compared to:

| Platform         | Time-to-first-screen |
|------------------|----------------------|
| Filament         | 30 minutes           |
| Nova             | 45 minutes           |
| Retool           | 10 minutes           |
| AUSUS V0         | ~8 hours             |
| Odoo (new dev)   | days                 |

AUSUS V0 is **16× slower** than Filament for the first screen. RFC-001 §10.5.1 acknowledges this as the explicit V0 cost of architectural rigor. The crossover argument is identical to §8's line-count argument: per-Entity cost is much lower (estimated 30 minutes for Entity #2). Unproven until V0 ships.

---

## 10. (I) Friction log

Every place the slice's construction surfaced a developer-experience pain point or unspecified surface. Numbered for cross-reference in §13 findings.

| # | Friction | Severity |
|---|----------|----------|
| 10.01 | DSL surface is illustrative; plugin authors writing V0 today have no normative syntax. Their code will need to be rewritten when RFC-011 lands. | BLOCKER |
| 10.02 | `ActionEffect` contract is not specified by any current RFC. Plugin authors must invent one; the Compiler cannot validate effect class shape; the Invoker has no defined dispatch mechanism. | BLOCKER |
| 10.03 | Workflow execution semantics are deferred (RFC-006). V0 plugins must embed state-transition logic inside Action effects, making the Workflow descriptor declarative documentation only. The Invoker's step 3 "Workflow guard" (RFC-001 §8.2.1) has no implementation contract. | BLOCKER |
| 10.04 | Authorization plugin contract is sketched in RFC-005 §1.3 (minimum surface only). No RFC exists for `actor->roles()`, `actor->permissions()`, attribute precomputation freshness, or session lifecycle. V0 ships a stub; production deployments need a real Authorization plugin nobody has specified. | BLOCKER |
| 10.05 | Policies cannot read Entity field values from a `Subject` (only the identity triple). Tenant-aware Policies that depend on field thresholds (e.g., "high-amount invoices require approval") cannot express this rule without either (a) denormalizing the relevant fields into Action inputs or (b) moving the rule into the Action effect. Neither is documented as canonical. | HIGH |
| 10.06 | Every Projection requires a Policy (per RFC-001 §2.7 / RFC-005 §5.4: empty chain → Abstain → deny-by-default). Plugin authors must write a "is this Actor allowed to read this Projection at all?" Policy for every Projection, even when the answer is universally Permit. A `Policy::permitAll()` convenience would reduce ~15 lines × N Projections. | MEDIUM |
| 10.07 | Action and Workflow-transition guards are both Policies; for invoice.issue, the same Policy fires twice unless the Workflow guard is omitted. RFC-001 §2.6 makes guards optional, but the documentation does not establish the "omit guard when Action Policy is sufficient" pattern. Plugin authors will duplicate. | MEDIUM |
| 10.08 | Configuration sprawl: V0 `config/ausus.php` has 25+ keys across audit, reporting, tenancy, compiler, runtime, plugins, policy_engine, maintenance. A first-time author needs to either accept defaults silently or read 7 RFCs to understand each key. No "minimal-config quickstart" surface exists. | MEDIUM |
| 10.09 | Per-Entity boilerplate is high: one DSL declaration + N Policy classes + M Effect classes + 1 migration + (optionally) Field-Type-Plugin dependencies. A 5-field Entity with 3 Actions and 2 Projections needs roughly 7 files. Filament needs 1. | MEDIUM |
| 10.10 | Cross-RFC navigation: a plugin author writing V0 needs to consult RFC-001, RFC-002, RFC-003, RFC-004, RFC-005, RFC-007, RFC-010 (seven specs, ~70k words) before writing 155 lines of DSL. Onboarding is severe. A consolidated "Plugin Author Handbook" derived from the RFCs would be a major DX investment, currently unscheduled. | HIGH |
| 10.11 | The `_version` field is reserved by RFC-004 §8.4 on wire format and RFC-002 §8 on Repository return values. Plugin authors must declare `Field::version()` in every Entity (RFC-002 §8.4 implies it but is silent on the declaration). If omitted, optimistic locking presumably fails at first `update`. The "every Entity must have a version field" requirement is not visible in any single RFC. | LOW |
| 10.12 | Identity handles are opaque per RFC-002 §6.1. URLs like `/api/billing/invoice/inv_01J/issue` embed the handle as a string. For row-level tenancy the handle is `(tenant_id, entity_fqn, identity_handle)` per RFC-001 §2.1.1.4 — but URL routing typically extracts just the identity_handle string, relying on the resolver to bind the tenant. This pattern is correct but undocumented. | LOW |
| 10.13 | The `ausus:doctor` output (RFC-001 §5.5, RFC-005 §12) emits warnings, errors, notices across many subsystems. The output format is unspecified; first-time users may not know how to interpret. | LOW |
| 10.14 | React renderer's exact rendering of every widget hint (`badge`, `money`, `date-picker`) is profile-specified (RFC-004 §10.2) but the `ausus/renderer-react` package implementing the profile does not exist yet. The slice depends on a package that no one has shipped. | BLOCKER |
| 10.15 | Conformance test suite is "scoped" but not built in every RFC (RFC-002 §19.5, RFC-005 §18.4, RFC-007 §21.6, RFC-010 §17.5). V0 ships with no automated way to verify a Policy, Sink, Driver, or Strategy actually conforms. Plugin developers and platform packagers are on their own. | HIGH |

Total: **15 frictions**. Five BLOCKER, three HIGH, three MEDIUM, four LOW.

---

## 11. (J) Contradictions discovered

Contradictions surfaced by the slice's construction — places where two RFCs disagree, or where one RFC's contract cannot be satisfied without violating another's invariant.

| # | Contradiction | Resolution path |
|---|---------------|-----------------|
| 11.01 | RFC-001 §2.6 says Workflows have transitions and guards; RFC-001 §A-1.4 §8.2.1 says the Invoker's step 3 is "Workflow guard, if the Action triggers a Workflow transition" — but no RFC specifies how the Invoker discovers whether an Action triggers a transition, who loads the Subject's current state, or who applies the post-effect state mutation. The contract is named but not executable. | RFC-006 (deferred) must specify. V0 shim: state mutation in effect; guard logic in effect or absent. |
| 11.02 | RFC-005 §10.1 forbids Policies from reading from PersistenceContext. RFC-005 §6.1 case 1 names "pre-create operations" as a Subject=null case. Yet a Policy gating creation may legitimately want to check uniqueness ("a Customer cannot have more than one DRAFT invoice"). It cannot do so without I/O. | Documented as a friction (10.05). The canonical pattern is to move such rules into Action effects, which can read. |
| 11.03 | RFC-001 §2.4.1 requires MaintenanceActions to be surfaced separately by `ausus:doctor`. V0 declares zero MaintenanceActions. `ausus:doctor` should report "MaintenanceAction inventory: empty" rather than erroring; behaviour with zero entries is unspecified. | Trivial: doctor reports empty. Implementation detail. Not a real contradiction. |
| 11.04 | RFC-005 §1.3 commits the `Actor::roleHash()` to be deterministic. RFC-004 §12.1 cache key uses `actorRoleHash`. The Authorization plugin's contract for computing `roleHash` is undefined; two plugin implementations could compute incompatible hashes, breaking cache correctness across deployments. | Authorization plugin RFC (deferred) must specify. Until then, only one Authorization plugin per deployment (single-driver style). |
| 11.05 | RFC-001 §A-1.13.4 forbids synthetic identity handles in any non-AuditEntry-Subject context. RFC-007 §15.1 has the Repository call the Auditor on read of an audited-reads Entity, with `subject = SingleSubject(<real instance reference>)`. No contradiction in V0 because no Entity sets `audited_reads: true`. If one did, the Repository would need to construct a Subject containing the real `identity_handle` for the read audit — which works. **Not a contradiction.** Logged for completeness. | No action. |
| 11.06 | RFC-004 §11.3 commits to byte-identical determinism of ViewSchema generation given identical inputs. The `metadata.generatedAt` field is excluded from the cacheable body per RFC-004 §12.2. The `metadata.cacheKey` field is a serialization of the eight-tuple; it is deterministic. **Not a contradiction.** Logged for clarity. | No action. |
| 11.07 | RFC-007 §15.3 says read-audit failure aborts the read. For a paginated reporting query touching an `audited_reads` Entity (RFC-010 §7.4), each page emits an audit. If page 5 fails audit while pages 1–4 succeeded, page 5 fails, but the consumer already received pages 1–4. Pages 1–4 are not rolled back (they are reads; nothing to rollback). The audit log has entries for pages 1–4 and none for page 5. From an audit-trail perspective, page 5 was attempted but unrecorded. RFC-007 §15.4 acknowledges "one audit per chunk"; the multi-page semantic is consistent but produces an audit-gap that operators must reason about. | No structural fix needed in V0 (no audited_reads Entity in slice). Documented as future-RFC topic. |

Two genuine contradictions (11.01, 11.04) plus one DX-friction-restated-as-contradiction (11.02). Items 11.03 and 11.05–11.07 resolve to "not actually contradictions" on careful reading.

---

## 12. Challenger analysis

### 12.1 Developer experience (DX)

- The DSL is fluent and Laravel-native, which fits the target audience.
- 155 lines of plugin-author code for an Entity with 3 Actions, 2 Projections, 4 Policies, 1 Workflow is on par with Nova and 4× Filament.
- The DSL example in §2 reads well **only because the surface was invented for this RFC**. A different RFC-011 author might choose attribute-based metadata over fluent builders, in which case the slice's DSL is rewritten before V0 ships.
- The cross-RFC documentation burden (friction 10.10) is severe. A first-time developer reads ~70k words across seven specs to write 155 lines.
- Verdict: **Acceptable for V0 if RFC-011 lands and a Plugin Author Handbook is written.** Without both, V0 ships into a documentation vacuum.

### 12.2 Boilerplate

- Per-Entity boilerplate: ~7 files. Filament: 1. Nova: 1–2.
- Per-Projection boilerplate: a separate Policy class (4 lines of body + class scaffold) when the universal answer is Permit. This is the #1 source of boilerplate friction (10.06).
- Per-Action boilerplate: an Effect class. This is unavoidable given the §10 separation; the alternative (inline closures in the DSL) would violate RFC-001 §5.8.6.
- Verdict: **High but bounded.** Mitigations: ship `Policy::permitAll()` (a one-line shim per Projection), ship Effect base classes for common patterns (create / update / delete / transition).

### 12.3 Layer violations

- The slice does not produce any layer violation if the shims (10.02, 10.04) are accepted as L7 plugin code conforming to engine contracts.
- Risk: the invented `ActionEffect` interface accesses `PersistenceContext` directly. This is the documented pattern (RFC-002 §4); not a violation.
- Risk: V0's hand-written transition-via-effect (10.03 shim) couples Action effects to Workflow knowledge. When RFC-006 lands, effects must be refactored to delegate state transitions to the Workflow runtime. Plugin authors writing V0 today must understand they are writing throwaway transition code.
- Verdict: **Clean if shims are documented as temporary.**

### 12.4 Hidden coupling

- Plugin authors are coupled to the Authorization plugin's `Actor` shape (10.04). Different Authorization plugins are incompatible. The kernel doesn't enforce a richer Actor contract because no RFC specifies one.
- React renderer is coupled to `react.web.v1` profile (RFC-004 §10). Profile registry is well-specified; coupling is explicit and acceptable.
- Action effect coupled to Workflow state transitions (10.03 shim). Will be repaid by RFC-006.
- Verdict: **One acknowledged coupling (Authorization), one shim coupling (Workflow). Both have clear repayment paths.**

### 12.5 Onboarding cost

- Time-to-first-screen: ~8 hours for a fluent Laravel+React developer (§9). 16× Filament.
- Documentation: 70k words across 7 RFCs to understand V0 fully. Plugin Author Handbook needed.
- Conformance test suites unbuilt (friction 10.15). New plugin packages and new drivers cannot self-verify.
- Boilerplate (12.2) amplifies onboarding cost per Entity until amortization sets in.
- Verdict: **Severe.** This is the V0 tax. Either the per-Entity amortization (§8) and the platform's higher-end use cases (multi-tenant SaaS, ERP workflows) justify it, or V0 fails to attract developers.

---

## 13. Findings (BLOCKERS, GAPS, FRICTIONS)

### 13.1 BLOCKERS — V0 cannot be implemented end-to-end

| ID         | Description                                                       | Required unblocker                                  |
|------------|-------------------------------------------------------------------|-----------------------------------------------------|
| F-V0-01    | DSL surface not normatively specified.                            | **RFC-011** (DSL surface) MUST land before V0.      |
| F-V0-02    | `ActionEffect` dispatch contract is undefined.                    | **New RFC** (Action effect contract) OR **RFC-001 amendment** adding `ActionDescriptor.effect` contract. |
| F-V0-03    | Workflow execution semantics undefined.                           | **RFC-006** (Workflow execution) MUST land before V0. |
| F-V0-04    | Authorization plugin contract undefined beyond `Actor` minimum.   | **New RFC** (Authorization plugin contract) OR explicit "V0 ships with ausus/authorization-stub, no third-party Authorization plugins supported." |
| F-V0-05    | Reference packages (`ausus/persistence-sql`, `ausus/tenancy-row`, `ausus/audit-database`, `ausus/reporting-sql`, `ausus/authorization-stub`, `ausus/presentation-default`, `ausus/renderer-react`) do not exist. | Build them. Each is bounded by its consuming RFC; implementation work is well-defined but unscheduled. |
| F-V0-14    | React renderer package (`ausus/renderer-react`) does not exist.   | Same as F-V0-05; specifically blocking step 10–14 of §7 setup. |

**Six blockers. V0 cannot ship without addressing all six.**

### 13.2 GAPS — V0 can ship with explicit shims, but the shims accrue debt

| ID         | Description                                                       | Shim                                                | Debt repayment                                       |
|------------|-------------------------------------------------------------------|-----------------------------------------------------|------------------------------------------------------|
| G-V0-01    | Workflow guard in Invoker step 3 unspecified.                     | V0 plugins embed guard logic in Action effects.     | RFC-006.                                             |
| G-V0-02    | Universal-permit Projection Policy boilerplate.                   | Ship `Policy::permitAll()` reference class.         | Convention adopted; no RFC needed.                   |
| G-V0-03    | Action / transition guard duplication risk.                       | Documentation pattern: omit transition guard when Action Policy is sufficient. | Convention adopted; no RFC needed. |
| G-V0-04    | Authorization plugin contract minimum-only.                       | V0 ships `ausus/authorization-stub`; documentation forbids third-party Authorization plugins until contract lands. | Authorization plugin RFC. |
| G-V0-05    | Conformance test suites unbuilt.                                  | Document "V0 ships without automated conformance verification."| Build suites pre-V1; each consuming RFC §-acceptance gate. |

### 13.3 FRICTIONS — V0 is usable but uncomfortable

The 15 frictions of §10 (BLOCKER, HIGH, MEDIUM, LOW). The five BLOCKER frictions are already captured as F-V0-01 through F-V0-05 + F-V0-14. The three HIGH frictions (10.05, 10.10, 10.15) become V0 caveats documented in the Plugin Author Handbook. The seven MEDIUM/LOW frictions are absorbed as platform maturity tax.

### 13.4 CONTRADICTIONS — Two genuine

- C-V0-01 (= friction 11.01): Workflow guard contract named but not executable. Resolved by RFC-006.
- C-V0-02 (= friction 11.04): Authorization plugin's `roleHash` deterministic across implementations is asserted but unenforced. Resolved by Authorization plugin RFC; V0 mitigated by single-Authorization-plugin constraint.

---

## 14. Determination

**BLOCKED.**

The slice cannot be implemented end-to-end against the current RFC stack. Six BLOCKER findings (§13.1) prevent V0 implementation:

1. **F-V0-01** — RFC-011 DSL surface is undefined. Plugin authors have no syntax.
2. **F-V0-02** — `ActionEffect` dispatch contract is undefined. The Invoker cannot dispatch effects.
3. **F-V0-03** — Workflow execution semantics are undefined. The Invoker cannot execute step 3.
4. **F-V0-04** — Authorization plugin contract beyond `Actor::roleHash`/`Actor::ref` is undefined. Plugin authors cannot write meaningful Policies.
5. **F-V0-05** — Reference packages for every L3 implementation (persistence, tenancy, audit, reporting, authorization, presentation, renderer) do not exist.
6. **F-V0-14** — Specifically the React renderer package is absent; the L6 surface has no implementation.

Each blocker has a clear unblocking path; none requires kernel-primitive invention. Resolving all six produces a coherent V0. The unblocking work, ordered:

| Order | Unblocker                          | Estimated effort |
|-------|------------------------------------|------------------|
| 1     | RFC-011 (DSL surface)              | ~1 RFC + amendment to RFC-005 if Policy registration syntax changes |
| 2     | RFC-006 (Workflow execution)       | ~1 RFC at RFC-005 depth |
| 3     | RFC for `ActionEffect` contract    | ~1 RFC; small scope (one interface + dispatch semantics + Invoker amendment) |
| 4     | Authorization plugin RFC           | ~1 RFC at RFC-002 depth |
| 5     | Reference packages built           | ~7 packages, 6–12 weeks of engineering each |
| 6     | Plugin Author Handbook             | Documentation derived from accepted RFCs |
| 7     | Conformance test suites            | Per-RFC; built as packages mature |

**No kernel-primitive invention is proposed**, per the brief's hard rule. Every unblocker is either (a) a new RFC at the same depth as existing ones, (b) a small amendment to a current RFC, or (c) implementation work bounded by existing contracts.

The kernel expansion freeze is appropriate. The current eight RFCs + two amendments cover the kernel surface; the six blockers are at the integration and reference-implementation layers, not the kernel. **Freeze the kernel; build the reference packages; document; then revisit V0.**

When the six blockers are addressed, this RFC should be re-run end-to-end (a real implementation pass, not a paper pass) and the determination revisited as `GO`. Until then, V0 is **BLOCKED**.
