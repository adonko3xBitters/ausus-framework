<?php
declare(strict_types=1);

namespace Ausus\Runtime;

use Ausus\{
    Actor, Auditor, AuditEntry, AuditSink, ActorRef, Context, Decision, Effect, EffectContext,
    Instant, MetadataGraph, PersistenceContext, PersistenceDriver, Policy, PolicyDenied,
    Reference, Repository, SingleSubject, Subject, Tenant, TransactionHandle, Ulid,
    UnknownAction, PolicySubjectRequired, ActorRequired, TenantContextRequired,
    TenantBoundaryViolation, WorkflowStateMismatch, WorkflowSubjectNotFound,
    WorkflowGuardDenied, EffectFailed, AuditEmissionFailed,
    ActionNode, WorkflowNode, TransitionNode, PolicyNode
};

// =============================================================================
// EFFECT CONTEXT IMPLEMENTATION
// =============================================================================

/**
 * @internal — built by the Invoker; consumers receive this through the
 * EffectContext interface and never instantiate the concrete class.
 * See docs/API-GOVERNANCE.md §6.
 */
final class DefaultEffectContext implements EffectContext {
    public function __construct(
        private readonly PersistenceContext $persistence,
        private readonly Actor $actor,
        private readonly Tenant $tenant,
        private readonly string $correlationId,
        private readonly ?string $traceId,
        private readonly Instant $clock,
    ) {}
    public function repository(string $entityFqn): Repository { return $this->persistence->repository($entityFqn); }
    public function actor(): Actor                            { return $this->actor; }
    public function tenant(): Tenant                          { return $this->tenant; }
    public function correlationId(): string                   { return $this->correlationId; }
    public function traceId(): ?string                        { return $this->traceId; }
    public function clock(): Instant                          { return $this->clock; }
}

// =============================================================================
// BUILT-IN POLICY  (V0 — role-required only)
// =============================================================================

final class RoleRequired implements Policy {
    public function __construct(private readonly string $role) {}
    public function evaluate(Actor $actor, string $actionFqn, ?Subject $subject, Context $context): Decision {
        return in_array($this->role, $actor->roles(), true) ? Decision::Permit : Decision::Deny;
    }
}

final class PolicyEngine {
    public function __construct(private readonly MetadataGraph $graph) {}
    public function evaluateAction(ActionNode $action, Actor $actor, ?Reference $subject, Context $context): Decision {
        $policy = $this->resolvePolicy($action->policyFqn);
        $subjVO = $subject !== null ? Subject::fromReference($subject) : null;
        try {
            $decision = $policy->evaluate($actor, $action->fqn, $subjVO, $context);
        } catch (\Throwable) {
            return Decision::Deny;     // fail-closed
        }
        return $decision === Decision::Abstain ? Decision::Deny : $decision;   // deny-by-default
    }
    public function resolvePolicy(string $fqn): Policy {
        $node = $this->graph->policies[$fqn] ?? throw new \RuntimeException("Unknown policy: {$fqn}");
        $class = $node->implementationClass;
        $args = $node->constructorArgs;
        return new $class(...$args);
    }
}

// =============================================================================
// WORKFLOW RUNTIME  (V0)
// =============================================================================

final class TransitionSetIndex {
    /** @var array<string, list<array{0:WorkflowNode,1:TransitionNode}>> */
    private array $byAction = [];
    public function __construct(MetadataGraph $graph) {
        foreach ($graph->workflows as $w) {
            foreach ($w->transitions as $t) {
                $this->byAction[$t->viaActionFqn][] = [$w, $t];
            }
        }
    }
    /** @return list<array{0:WorkflowNode,1:TransitionNode}> */
    public function forAction(string $actionFqn): array { return $this->byAction[$actionFqn] ?? []; }
}

final class WorkflowRuntime {
    public function __construct(private readonly TransitionSetIndex $index) {}
    /**
     * Per RFC-006 §4.2: for each Workflow attached to the Action, find the
     * applicable transition for the current state (exact match OR wildcard).
     * Multiple Workflows may apply; each must have exactly one matching transition.
     */
    public function evaluate(ActionNode $action, ?Reference $subject, PersistenceContext $persistence): void {
        $transitions = $this->index->forAction($action->fqn);
        if (empty($transitions)) return;
        if ($subject === null) {
            throw new \RuntimeException("WorkflowSubjectRequired: action {$action->fqn} is Workflow-attached but subject is null");
        }
        $repo = $persistence->repository($subject->entityFqn);
        $entity = $repo->find($subject);
        if ($entity === null) throw new WorkflowSubjectNotFound("Subject not found: {$subject->identityHandle}");

        // Group transitions by Workflow FQN
        $byWorkflow = [];
        foreach ($transitions as [$w, $t]) {
            $byWorkflow[$w->fqn][] = [$w, $t];
        }
        foreach ($byWorkflow as $wfqn => $candidates) {
            $workflow = $candidates[0][0];
            $current = $entity->field($workflow->stateField);
            if ($current === null) {
                throw new WorkflowStateMismatch("state field {$workflow->stateField} is null on {$subject->identityHandle}");
            }
            // Find the single applicable transition for this current state
            $matched = null;
            foreach ($candidates as [, $t]) {
                if ($t->source === '*' || $t->source === $current) {
                    if ($matched !== null) {
                        throw new \RuntimeException("WorkflowAmbiguousTransition: workflow {$wfqn} has multiple transitions matching (current={$current}, via={$action->fqn})");
                    }
                    $matched = $t;
                }
            }
            if ($matched === null) {
                $sources = array_map(fn($c) => $c[1]->source, $candidates);
                throw new WorkflowStateMismatch("workflow {$wfqn}: current state '{$current}' does not match any declared source [" . implode(',', $sources) . "] for action {$action->fqn}");
            }
            // Transition matched; step 3 passes for this workflow.
            // (V0: no guard Policy on transitions in HelloInvoice; skip guard eval.)
        }
    }
}

// =============================================================================
// EFFECT DISPATCHER + BUILT-IN EFFECTS
// =============================================================================

/**
 * @internal — accessed via the `'kernel.builtin.create'` marker
 * (ActionNode.effectClass). Never instantiate directly; the
 * EffectDispatcher owns the construction.
 * See docs/API-GOVERNANCE.md §6.
 */
final class CreateEffect implements Effect {
    /** @param array{entityFqn:string, workflowStateField?:?string, workflowInitial?:?string} $config */
    public function __construct(private readonly array $config) {}
    public function execute(EffectContext $context, ?Reference $subject, array $inputs): array {
        $repo = $context->repository($this->config['entityFqn']);
        $payload = $inputs;
        if (($this->config['workflowStateField'] ?? null) !== null && !isset($payload[$this->config['workflowStateField']])) {
            $payload[$this->config['workflowStateField']] = $this->config['workflowInitial'];
        }
        $entity = $repo->create($payload);
        return ['id' => $entity->reference->identityHandle] + $entity->fields;
    }
}

/**
 * @internal — accessed via the `'kernel.builtin.transition'` marker.
 * Never instantiate directly. See docs/API-GOVERNANCE.md §6.
 */
final class TransitionEffect implements Effect {
    /** @param array{entityFqn:string, stateField:string, target:string, stamps?:array<string>} $config */
    public function __construct(private readonly array $config) {}
    public function execute(EffectContext $context, ?Reference $subject, array $inputs): array {
        if ($subject === null) throw new \RuntimeException("TransitionEffect requires Subject");
        $repo = $context->repository($subject->entityFqn);
        $entity = $repo->find($subject);
        if ($entity === null) throw new \RuntimeException("Subject vanished mid-transaction");
        $patch = [$this->config['stateField'] => $this->config['target']];
        foreach ($this->config['stamps'] ?? [] as $f) {
            $patch[$f] = $context->clock()->toRfc3339();
        }
        foreach ($inputs as $k => $v) {
            $patch[$k] = $v;
        }
        $updated = $repo->update($subject, $patch, $entity->version);
        return $patch + ['_version' => $updated->version->value];
    }
}

final class EffectDispatcher {
    public function dispatch(ActionNode $action): Effect {
        return match ($action->effectClass) {
            'kernel.builtin.create'     => new CreateEffect($action->effectConfig),
            'kernel.builtin.transition' => new TransitionEffect($action->effectConfig),
            default                     => new ($action->effectClass)(),
        };
    }
}

// =============================================================================
// AUDITOR  (V0 — single transactional primary)
// =============================================================================

final class DefaultAuditor implements Auditor {
    public function __construct(private readonly AuditSink $primarySink) {}
    public function emit(AuditEntry $entry, TransactionHandle $tx): void {
        try {
            $this->primarySink->writeInTransaction($entry, $tx);
        } catch (\Throwable $e) {
            throw new AuditEmissionFailed("primary sink rejected: " . $e->getMessage(), 0, $e);
        }
    }
}

// =============================================================================
// SEQUENCE COUNTER  (per correlationId; per process)
// =============================================================================

final class SequenceCounter {
    /** @var array<string,int> */
    private array $counters = [];
    public function next(string $correlationId): int {
        $next = ($this->counters[$correlationId] ?? -1) + 1;
        $this->counters[$correlationId] = $next;
        return $next;
    }
}

// =============================================================================
// INVOKER  (V0 — full 5-step chain)
// =============================================================================

final class Invoker {
    public function __construct(
        private readonly MetadataGraph $graph,
        private readonly PersistenceDriver $driver,
        private readonly PolicyEngine $policies,
        private readonly WorkflowRuntime $workflow,
        private readonly EffectDispatcher $effects,
        private readonly Auditor $auditor,
        private readonly SequenceCounter $sequence,
        private readonly Tenant $activeTenant,         // V0 — single Tenant per process
        private readonly Actor $actor,                  // V0 — single Actor
    ) {}

    public function invoke(string $actionFqn, ?Reference $subject, array $inputs = []): array {
        // Pre-flight
        $action = $this->graph->actions[$actionFqn] ?? null;
        if ($action === null) throw new UnknownAction("Unknown action: {$actionFqn}");
        if ($action->subjectRequired && $subject === null) {
            throw new PolicySubjectRequired("Action {$actionFqn} requires subject");
        }
        if ($subject !== null && $subject->tenantId !== $this->activeTenant->value()) {
            throw new TenantBoundaryViolation("subject tenant != active tenant");
        }

        $correlationId = Ulid::generate();
        $clock = new Instant(microtime(true));
        $context = new Context($this->activeTenant, $correlationId, null, $clock);

        // Step 2: Policy chain
        $decision = $this->policies->evaluateAction($action, $this->actor, $subject, $context);
        if ($decision !== Decision::Permit) {
            throw new PolicyDenied("Policy denied action {$actionFqn}: " . $decision->value);
        }

        // Open transaction
        $tx = $this->driver->beginTransaction($this->activeTenant);
        $committed = false;
        try {
            $persistence = $this->driver->context($this->activeTenant, $tx);

            // Step 3: Workflow guard
            $this->workflow->evaluate($action, $subject, $persistence);

            // Step 4: Effect
            $effect = $this->effects->dispatch($action);
            $effectContext = new DefaultEffectContext(
                $persistence, $this->actor, $this->activeTenant, $correlationId, null, $clock,
            );
            try {
                $outputs = $effect->execute($effectContext, $subject, $inputs);
            } catch (\Throwable $e) {
                if ($e instanceof \Ausus\AususError) throw $e;
                throw new EffectFailed($actionFqn, $e);
            }

            // Step 5: Audit
            $subjectForAudit = $this->buildAuditSubject($action, $subject, $outputs);
            $entry = new AuditEntry(
                entryId: Ulid::generate(),
                sequence: $this->sequence->next($correlationId),
                actor: $this->actor->ref(),
                tenant: $this->activeTenant->value(),
                actionFqn: $actionFqn,
                subject: $subjectForAudit,
                inputs: $inputs,
                outputs: $outputs,
                timestamp: $clock->toRfc3339(),
                correlationId: $correlationId,
                traceId: null,
                invocationClass: $action->kind === 'maintenance' ? 'Maintenance' : 'Standard',
                emitterVersion: '1.0.0',
            );
            $this->auditor->emit($entry, $tx);

            $this->driver->commit($tx);
            $committed = true;
            return $outputs;
        } finally {
            if (!$committed) {
                try { $this->driver->rollback($tx); } catch (\Throwable) { /* swallow secondary failure */ }
            }
        }
    }

    private function buildAuditSubject(ActionNode $action, ?Reference $subject, array $outputs): SingleSubject {
        if ($subject !== null) {
            return new SingleSubject($subject->tenantId, $subject->entityFqn, $subject->identityHandle);
        }
        // For Action::create — use the new id from outputs
        $id = $outputs['id'] ?? 'kernel.reporting.aggregate';
        return new SingleSubject($this->activeTenant->value(), $action->entityFqn, (string) $id);
    }
}

// =============================================================================
// PROJECTION RENDERER  (V0 — emits ViewSchema JSON shell)
// =============================================================================

final class ProjectionRenderer {
    public function __construct(
        private readonly MetadataGraph $graph,
        private readonly PersistenceDriver $driver,
        private readonly Tenant $tenant,
    ) {}

    public function render(string $projectionFqn, ?Reference $subject = null): array {
        $proj = $this->graph->projections[$projectionFqn] ?? throw new \RuntimeException("Unknown projection: {$projectionFqn}");
        $entity = $this->graph->entities[$proj->ownerEntityFqn] ?? throw new \RuntimeException("Missing entity for projection");

        // Build fields block
        $fields = [];
        foreach ($proj->fields as $fieldName) {
            $f = $entity->field($fieldName);
            if ($f === null) throw new \RuntimeException("Projection {$projectionFqn} references unknown field {$fieldName}");
            $fields[] = [
                'name' => $f->name,
                'type' => $f->type,
                'label' => ucfirst(str_replace('_', ' ', $f->name)),
                'typeOptions' => $f->typeOptions,
            ];
        }

        // Build actions block
        $actions = [];
        foreach ($proj->actionFqns as $afqn) {
            $a = $this->graph->actions[$afqn] ?? null;
            if ($a === null) continue;
            $actions[] = [
                'fqn' => $a->fqn,
                'name' => substr($a->fqn, strrpos($a->fqn, '.') + 1),
                'label' => ucfirst(substr($a->fqn, strrpos($a->fqn, '.') + 1)),
                'subjectRequired' => $a->subjectRequired,
            ];
        }

        // Data
        $data = null;
        $tx = $this->driver->beginTransaction($this->tenant);
        try {
            $context = $this->driver->context($this->tenant, $tx);
            $repo = $context->repository($entity->fqn);
            if ($subject !== null) {
                $found = $repo->find($subject);
                $data = ['item' => $found?->fields];
            } else {
                // V0 minimal list — just enumerate via raw SQL (Repository V0 lacks findMany)
                // For HelloInvoice list view: read all rows for this tenant
                // This bypasses Repository contract — documented as V0 finding
                $tableName = str_replace('.', '_', $entity->fqn);
                $pdo = (new \ReflectionProperty($this->driver, 'pdo'))->getValue($this->driver);
                $stmt = $pdo->prepare("SELECT * FROM \"{$tableName}\" WHERE tenant_id = :tid ORDER BY id");
                $stmt->execute(['tid' => $this->tenant->value()]);
                $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $projected = [];
                foreach ($items as $row) {
                    $r = [];
                    foreach ($proj->fields as $fn) {
                        $r[$fn] = $row[$fn] ?? null;
                    }
                    $projected[] = $r;
                }
                $data = ['items' => $projected, 'pagination' => ['nextCursor' => null, 'pageSize' => count($projected)]];
            }
            $this->driver->commit($tx);
        } catch (\Throwable $e) {
            $this->driver->rollback($tx);
            throw $e;
        }

        return [
            'schemaVersion' => '1.0.0',
            'targetProfile' => 'react.web.v1',
            'metadata' => [
                'projection' => $proj->fqn,
                'entity'     => $entity->fqn,
                'tenant'     => $this->tenant->value(),
                'locale'     => 'en-US',
                'generatedAt'=> gmdate('Y-m-d\\TH:i:s\\Z'),
            ],
            'fields'  => $fields,
            'actions' => $actions,
            'filters' => [],
            'data'    => $data,
        ];
    }
}
