<?php
declare(strict_types=1);

namespace Ausus\Runtime;

use Ausus\{
    Actor, Auditor, AuditEntry, AuditSink, ActorRef, BuiltinEffect, Context, Decision,
    Effect, EffectContext,
    Filter, Sort,
    Instant, MetadataGraph, NotFound, PagedRepository, PersistenceContext, PersistenceDriver, Policy, PolicyDenied,
    Reference, Repository, SingleSubject, Subject, Tenant, TransactionHandle, Ulid,
    UnknownAction, PolicySubjectRequired, UndeclaredActionInput, ActorRequired, TenantContextRequired,
    TenantBoundaryViolation, WorkflowStateMismatch, WorkflowSubjectNotFound,
    WorkflowGuardDenied, EffectFailed, AuditEmissionFailed,
    ActionNode, WorkflowNode, TransitionNode, PolicyNode,
    Provenance, Fact, FactRef, FactSet, Cond, Guard, Entity
};

// =============================================================================
// RFC-018 — GUARD RUNTIME (Phase 4: first real guard execution)
//
// Scope: Actor / SubjectField / SubjectIdentity / OperationInput / Context only.
// No Relation / Aggregate / History / External provenance. No VisibilityRule /
// CompletionGate / ApprovalChain. Pure, in-transaction, deny-overrides.
// =============================================================================

/** Immutable {@see FactSet} with O(1) get()/has(). No mutation after construction. */
final class ImmutableFactSet implements FactSet {
    /** @var array<string,int|string|float|bool|null> */
    private readonly array $index;
    /** @var list<Fact> */
    private readonly array $facts;
    /** @param list<Fact> $facts */
    public function __construct(array $facts) {
        $idx = [];
        foreach ($facts as $f) { $idx[$f->provenance->value . '|' . $f->key] = $f->value; }
        $this->index = $idx;
        $this->facts = array_values($facts);
    }
    public function get(Provenance $p, string $key): int|string|float|bool|null {
        return $this->index[$p->value . '|' . $key] ?? null;
    }
    public function has(Provenance $p, string $key): bool {
        return array_key_exists($p->value . '|' . $key, $this->index);
    }
    /** @return list<Fact> */
    public function all(): array { return $this->facts; }
}

/**
 * Pure evaluator for the {@see Cond} predicate DSL. Supports exactly:
 * eq, ne, lt, lte, gt, gte, in, not, and, or, mul. `mul(operand, scalar)` yields
 * an intermediate numeric value usable as a comparison operand.
 */
final class CondEvaluator {
    public static function eval(Cond $cond, FactSet $facts): bool {
        return self::truth($cond, $facts);
    }
    /** Resolve a FactRef/Cond/scalar operand to a value. */
    private static function operand(mixed $node, FactSet $facts): mixed {
        if ($node instanceof FactRef) { return $facts->get($node->provenance, $node->key); }
        if ($node instanceof Cond)    { return self::value($node, $facts); }
        return $node; // scalar literal (or array for `in`)
    }
    /** mul → numeric intermediate; any other Cond → its boolean truth. */
    private static function value(Cond $cond, FactSet $facts): mixed {
        if ($cond->op === 'mul') {
            $a = self::num(self::operand($cond->args[0], $facts));
            $k = (float) $cond->args[1];
            return $a === null ? null : $a * $k;
        }
        return self::truth($cond, $facts);
    }
    private static function truth(Cond $cond, FactSet $facts): bool {
        $args = $cond->args;
        switch ($cond->op) {
            case 'and': foreach ($args as $c) { if (!self::truth($c, $facts)) { return false; } } return true;
            case 'or':  foreach ($args as $c) { if (self::truth($c, $facts))  { return true; } }  return false;
            case 'not': return !self::truth($args[0], $facts);
            case 'in':
                $a = self::operand($args[0], $facts);
                return in_array($a, $args[1], true);
            case 'mul':
                return (bool) self::value($cond, $facts);
            default:
                return self::compare($cond->op, self::operand($args[0], $facts), self::operand($args[1], $facts));
        }
    }
    private static function num(mixed $v): ?float {
        return (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) ? (float) $v : null;
    }
    private static function compare(string $op, mixed $a, mixed $b): bool {
        $eq = ($a === $b) || (self::num($a) !== null && self::num($b) !== null && self::num($a) === self::num($b));
        if ($op === 'eq') { return $eq; }
        if ($op === 'ne') { return !$eq; }
        $x = self::num($a); $y = self::num($b);
        if ($x === null || $y === null) { return false; }   // fail-closed for non-numeric ordering
        return match ($op) { 'lt' => $x < $y, 'lte' => $x <= $y, 'gt' => $x > $y, 'gte' => $x >= $y, default => false };
    }
}

/** A {@see Guard} over a {@see Cond}: true → Permit, false → Deny. Never Abstain. */
final class CondGuard implements Guard {
    public function __construct(private readonly Cond $cond) {}
    /** @return list<FactRef> */
    public function reads(): array { return $this->cond->factRefs(); }
    public function decide(FactSet $facts): Decision {
        return CondEvaluator::eval($this->cond, $facts) ? Decision::Permit : Decision::Deny;
    }
}

/**
 * Resolves declared {@see FactRef}s into an immutable {@see FactSet}. Phase 4
 * provenances only: Actor / SubjectIdentity / SubjectField / OperationInput /
 * Context. No external calls, no aggregates, no relations, no history. Fail-safe:
 * an unresolvable fact yields null.
 */
final class FactResolver {
    /**
     * @param list<FactRef>       $refs
     * @param array<string,mixed> $inputs
     */
    public function resolve(array $refs, Actor $actor, ?Reference $subject, array $inputs, Context $context, PersistenceContext $persistence): FactSet {
        $needsSubject = false;
        foreach ($refs as $r) { if ($r->provenance === Provenance::SubjectField) { $needsSubject = true; break; } }
        $entity = ($needsSubject && $subject !== null)
            ? $persistence->repository($subject->entityFqn)->find($subject)
            : null;

        $facts = []; $seen = [];
        foreach ($refs as $r) {
            $k = $r->provenance->value . '|' . $r->key;
            if (isset($seen[$k])) { continue; }
            $seen[$k] = true;
            $facts[] = new Fact($r->provenance, $r->key, $this->resolveOne($r, $actor, $subject, $entity, $inputs, $context));
        }
        return new ImmutableFactSet($facts);
    }
    private function resolveOne(FactRef $r, Actor $actor, ?Reference $subject, ?Entity $entity, array $inputs, Context $context): int|string|float|bool|null {
        switch ($r->provenance) {
            case Provenance::Actor:
                return match ($r->key) {
                    'id'          => $actor->ref()->id,
                    'roles'       => implode(',', $actor->roles()),
                    'permissions' => implode(',', $actor->permissions()),
                    default       => $this->scalarize($actor->attribute($r->key)),
                };
            case Provenance::SubjectIdentity:
                if ($subject === null) { return null; }
                return match ($r->key) {
                    'id'     => $subject->identityHandle,
                    'type'   => $subject->entityFqn,
                    'tenant' => $subject->tenantId,
                    default  => null,
                };
            case Provenance::SubjectField:
                return $entity !== null ? $this->scalarize($entity->field($r->key)) : null;
            case Provenance::OperationInput:
                return array_key_exists($r->key, $inputs) ? $this->scalarize($inputs[$r->key]) : null;
            case Provenance::Context:
                return match ($r->key) {
                    'now'    => $context->clock->toRfc3339(),
                    'tenant' => $context->tenant->value(),
                    'actor'  => $actor->ref()->id,
                    default  => null,
                };
            default:
                return null; // unreachable — validateGuardClosure rejects other provenances
        }
    }
    private function scalarize(mixed $v): int|string|float|bool|null {
        return ($v === null || is_int($v) || is_string($v) || is_float($v) || is_bool($v)) ? $v : null;
    }
}

/** Composes guard decisions: deny-overrides, abstain-neutral. No positive Permit required. */
final class GuardComposer {
    /** @param list<Guard> $guards */
    public function compose(array $guards, FactSet $facts): Decision {
        foreach ($guards as $g) {
            if ($g->decide($facts) === Decision::Deny) { return Decision::Deny; }
        }
        return Decision::Permit;
    }
}

// =============================================================================
// EFFECT CONTEXT IMPLEMENTATION
// =============================================================================

/**
 * @internal Package-private. Public only because PHP cannot model
 *           package visibility — this class is constructed exclusively
 *           by {@see Invoker} and passed to {@see Effect::execute()} as
 *           an {@see EffectContext}. Consumers MUST depend on the
 *           `EffectContext` interface (the public contract); the
 *           concrete class, its constructor signature, and its method
 *           set carry no backward-compatibility guarantee.
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

/**
 * UpdateEffect — partial PATCH semantics on a closed list of fields.
 *
 * Per ADR-0002:
 *   - `subject` is required (the runtime rejects null at preflight).
 *   - The effect loads the entity once inside the Invoker transaction,
 *     uses its `_version` for the optimistic-lock check, and patches only
 *     the fields the caller sent that are listed in `updatableFields`.
 *   - Unknown input keys → BadRequest-shaped runtime error.
 *   - Null on a non-nullable field → BadRequest-shaped runtime error.
 *   - Workflow state fields are unreachable here: the DSL builder refuses
 *     to declare an `update` action that touches them.
 *   - Empty inputs → idempotent no-op; returns the current version.
 */
final class UpdateEffect implements Effect {
    /**
     * @param array{
     *     entityFqn:string,
     *     updatableFields:list<array{name:string,type:string,nullable:bool}>
     * } $config
     */
    public function __construct(private readonly array $config) {}

    public function execute(EffectContext $context, ?Reference $subject, array $inputs): array {
        if ($subject === null) {
            throw new \RuntimeException("UpdateEffect requires Subject");
        }
        $repo = $context->repository($this->config['entityFqn']);
        $entity = $repo->find($subject);
        if ($entity === null) {
            throw new NotFound($subject);
        }

        // Index the closed list by name for O(1) checks.
        $allowed = [];
        foreach ($this->config['updatableFields'] as $f) {
            $allowed[$f['name']] = $f;
        }

        $patch = [];
        foreach ($inputs as $name => $value) {
            if (!isset($allowed[$name])) {
                throw new \RuntimeException(
                    "UpdateEffect: field '{$name}' is not patchable by this action."
                );
            }
            if ($value === null && $allowed[$name]['nullable'] === false) {
                throw new \RuntimeException(
                    "UpdateEffect: field '{$name}' is not nullable; got null."
                );
            }
            $patch[$name] = $value;
        }

        // Idempotent no-op when the caller sent nothing.
        if ($patch === []) {
            return ['_version' => $entity->version->value];
        }

        $updated = $repo->update($subject, $patch, $entity->version);
        return $patch + ['_version' => $updated->version->value];
    }
}

final class EffectDispatcher {
    public function dispatch(ActionNode $action): Effect {
        // `effectClass` is either a `BuiltinEffect` sentinel value or a class FQN.
        $builtin = BuiltinEffect::tryFrom($action->effectClass);
        return match ($builtin) {
            BuiltinEffect::Create     => new CreateEffect($action->effectConfig),
            BuiltinEffect::Transition => new TransitionEffect($action->effectConfig),
            BuiltinEffect::Update     => new UpdateEffect($action->effectConfig),
            null                      => new ($action->effectClass)(),
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
        // RFC-018 (Phase 4) — defaulted so the existing construction site is unchanged.
        private readonly FactResolver $factResolver = new FactResolver(),
        private readonly GuardComposer $guardComposer = new GuardComposer(),
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

        // Enforce the action-input contract uniformly: reject any input the
        // action did not declare. UpdateEffect already rejects undeclared
        // fields (the `not patchable by this action` check); create/transition
        // did not — which let a caller smuggle entity fields (e.g. the workflow
        // state) past the declared inputs, bypassing role/workflow guards. The
        // allowed set is the already-compiled $action->inputs (FieldNode[]).
        $declaredInputs = [];
        foreach ($action->inputs as $declaredField) {
            $declaredInputs[$declaredField->name] = true;
        }
        foreach ($inputs as $inputName => $_inputValue) {
            if (!isset($declaredInputs[$inputName])) {
                throw new UndeclaredActionInput(
                    "input '{$inputName}' is not declared by action {$actionFqn}"
                );
            }
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

            // RFC-018 (Phase 4) — data-aware guard evaluation, IN-TRANSACTION,
            // after the (pre-transaction) role policy and before the workflow
            // guard. Actions without guards skip this block entirely and follow
            // the exact historical path. Deny → PolicyDenied → rollback (403).
            $decisionBasis = [];
            if ($action->guards !== []) {
                $refs = [];
                foreach ($action->guards as $cond) {
                    $refs = array_merge($refs, $cond->factRefs());
                }
                $facts  = $this->factResolver->resolve($refs, $this->actor, $subject, $inputs, $context, $persistence);
                $guards = array_map(static fn(Cond $c) => new CondGuard($c), $action->guards);
                if ($this->guardComposer->compose($guards, $facts) !== Decision::Permit) {
                    throw new PolicyDenied("Policy denied action {$actionFqn}: data-aware guard");
                }
                $decisionBasis = $facts->all();
            }

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
                decisionBasis: $decisionBasis,
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

    /**
     * Render a ViewSchema (RFC-004 wire) for the given projection.
     *
     * Pagination (list mode only):
     *   - `$limit` (default 50, max 1000) — items per page.
     *   - `$offset` (default 0) — items to skip before the page.
     *   The HTTP API layer is responsible for parsing/clamping these from
     *   the request; this method assumes pre-validated values and re-asserts
     *   defensively. Repositories implementing {@see PagedRepository} push
     *   the window into SQL; others fall back to an in-memory slice.
     *
     * Wire shape (schemaVersion 1.1.0):
     *   data.pagination = {
     *     limit, offset, totalCount, pageSize,    // added in 1.1
     *     nextCursor                              // reserved for cursor support
     *   }
     */
    /**
     * @param list<Filter> $filters whitelisted by the caller against the projection's fields
     * @param list<Sort>   $sort    whitelisted by the caller against the entity's fields
     */
    public function render(
        string $projectionFqn,
        ?Reference $subject = null,
        int $limit = 50,
        int $offset = 0,
        array $filters = [],
        array $sort = [],
    ): array {
        // Defensive clamps — also surface a clear failure if a non-HTTP caller
        // passes garbage values directly into the renderer.
        if ($limit < 1)    $limit = 1;
        if ($limit > 1000) $limit = 1000;
        if ($offset < 0)   $offset = 0;

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
                'label' => $f->label ?? ucfirst(str_replace('_', ' ', $f->name)),
                'typeOptions' => $f->typeOptions,
            ];
        }

        // RFC-015 — relation expansion plan. Validate the projection's `expand`
        // map and append a derived `{refField}_label` descriptor for each
        // expanded reference. Values are folded into the data rows below, inside
        // the transaction. Each expanded reference must (a) be a declared
        // reference field, (b) be selected in the projection's fields, and
        // (c) name a display field that exists on the target entity.
        $expandPlan = [];   // refField => ['target'=>fqn, 'display'=>field]
        foreach ($proj->expand as $refField => $displayField) {
            $rf = $entity->field($refField);
            if ($rf === null || $rf->type !== 'reference') {
                throw new \RuntimeException(
                    "ProjectionExpandInvalid: projection {$projectionFqn} expands '{$refField}', "
                    . "which is not a declared reference field on {$entity->fqn}."
                );
            }
            if (!in_array($refField, $proj->fields, true)) {
                throw new \RuntimeException(
                    "ProjectionExpandInvalid: projection {$projectionFqn} expands '{$refField}' but does not "
                    . "select it; add '{$refField}' to the projection fields to expand it."
                );
            }
            $target = $rf->typeOptions['targetEntityFqn'] ?? null;
            $targetEntity = $target !== null ? ($this->graph->entities[$target] ?? null) : null;
            if ($targetEntity === null) {
                throw new \RuntimeException(
                    "ProjectionExpandInvalid: reference '{$refField}' on {$entity->fqn} has no resolvable target entity."
                );
            }
            if ($targetEntity->field($displayField) === null) {
                throw new \RuntimeException(
                    "ProjectionExpandInvalid: target entity '{$target}' has no field '{$displayField}' "
                    . "to expand for '{$refField}' in projection {$projectionFqn}."
                );
            }
            $header = ucfirst(str_replace('_', ' ', (string) preg_replace('/_id$/', '', $refField)));
            $expandPlan[$refField] = ['target' => $target, 'display' => $displayField];
            $fields[] = [
                'name'        => $refField . '_label',
                'type'        => 'string',
                'label'       => $header,
                'typeOptions' => [
                    'expandedFrom'    => $refField,
                    'targetEntityFqn' => $target,
                    'displayField'    => $displayField,
                ],
            ];
        }

        // Build actions block. Each action carries its declared input fields
        // (with required / default / nullable hints) so the renderer can
        // generate a working create or update form from the metadata alone.
        $actions = [];
        foreach ($proj->actionFqns as $afqn) {
            $a = $this->graph->actions[$afqn] ?? null;
            if ($a === null) continue;
            $actions[] = [
                'fqn' => $a->fqn,
                'name' => substr($a->fqn, strrpos($a->fqn, '.') + 1),
                'label' => ucfirst(substr($a->fqn, strrpos($a->fqn, '.') + 1)),
                'subjectRequired' => $a->subjectRequired,
                'inputs' => $this->describeActionInputs($a),
            ];
        }

        // Data — go through the Repository contract for both shapes.
        $data = null;
        $tx = $this->driver->beginTransaction($this->tenant);
        try {
            $context = $this->driver->context($this->tenant, $tx);
            $repo = $context->repository($entity->fqn);
            if ($subject !== null) {
                $found = $repo->find($subject);
                $data = ['item' => $found?->fields];
            } else {
                // Push pagination into SQL when the driver supports it; otherwise
                // hydrate everything and slice in memory. Both branches converge
                // to the same wire shape, so consumers do not see the difference.
                if ($repo instanceof PagedRepository) {
                    $page = $repo->findPaged($limit, $offset, $filters, $sort);
                    $entities  = $page['items'];
                    $totalCount = $page['totalCount'];
                } else {
                    // Non-paged adapter: filters and sort cannot be honoured.
                    // Fall back to findAll() and surface the limitation
                    // honestly by ignoring the windowing inputs — the caller
                    // is the api-http layer which always uses PagedRepository.
                    $allEntities = $repo->findAll();
                    $totalCount  = count($allEntities);
                    $entities    = array_slice($allEntities, $offset, $limit);
                }
                $projected = [];
                foreach ($entities as $row) {
                    $r = [];
                    foreach ($proj->fields as $fn) {
                        $r[$fn] = $row->fields[$fn] ?? null;
                    }
                    $projected[] = $r;
                }
                $data = [
                    'items' => $projected,
                    'pagination' => [
                        'limit'      => $limit,
                        'offset'     => $offset,
                        'totalCount' => $totalCount,
                        'pageSize'   => count($projected),
                        'nextCursor' => null,
                    ],
                ];
            }
            // RFC-015 — fold expanded reference labels into the rows. One read
            // per expanded reference (no N+1): the target table is loaded once
            // through the Repository contract and indexed by id, then every row
            // reads its label from the map. Runs in the same transaction as the
            // primary read for a consistent snapshot.
            if ($expandPlan !== []) {
                $maps = [];
                foreach ($expandPlan as $refField => $plan) {
                    $idToLabel = [];
                    foreach ($context->repository($plan['target'])->findAll() as $trow) {
                        $idToLabel[$trow->reference->identityHandle] = $trow->fields[$plan['display']] ?? null;
                    }
                    $maps[$refField] = $idToLabel;
                }
                if (is_array($data['item'] ?? null)) {
                    foreach ($expandPlan as $refField => $_plan) {
                        $refVal = $data['item'][$refField] ?? null;
                        $data['item'][$refField . '_label'] =
                            $refVal !== null ? ($maps[$refField][$refVal] ?? null) : null;
                    }
                }
                if (isset($data['items']) && is_array($data['items'])) {
                    foreach ($data['items'] as $i => $row) {
                        foreach ($expandPlan as $refField => $_plan) {
                            $refVal = $row[$refField] ?? null;
                            $data['items'][$i][$refField . '_label'] =
                                $refVal !== null ? ($maps[$refField][$refVal] ?? null) : null;
                        }
                    }
                }
            }

            $this->driver->commit($tx);
        } catch (\Throwable $e) {
            $this->driver->rollback($tx);
            throw $e;
        }

        // Inject `initialValues` on every update-action descriptor when the
        // projection rendered a detail subject. The renderer uses this to
        // prefill the form. Per ADR-0002 §8: list views never carry
        // initialValues (there is no single subject to prefill from).
        if (is_array($data['item'] ?? null)) {
            foreach ($actions as $i => $descriptor) {
                $action = $this->graph->actions[$descriptor['fqn']] ?? null;
                if ($action === null) continue;
                if ($action->effectClass !== BuiltinEffect::Update->value) continue;
                $initial = [];
                foreach ($action->inputs as $f) {
                    $initial[$f->name] = $data['item'][$f->name] ?? null;
                }
                $actions[$i]['initialValues'] = $initial;
            }
        }

        return [
            'schemaVersion' => '1.2.0',
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
            // Echo the applied query metadata back to the renderer so the UI
            // can render breadcrumbs / filter chips without having to parse
            // the request URL itself. Empty lists in list mode mean "no
            // filtering / no sorting requested"; in subject mode the values
            // are conceptually undefined but stay as empty lists for shape
            // stability across the two modes.
            'filters' => array_map(
                static fn (Filter $f) => ['field' => $f->field, 'op' => $f->op, 'value' => $f->value],
                $filters,
            ),
            'sort'    => array_map(
                static fn (Sort $s) => ['field' => $s->field, 'direction' => $s->direction],
                $sort,
            ),
            'data'    => $data,
        ];
    }

    /**
     * Render an action's declared input fields as ViewSchema-shaped
     * FieldDescriptors carrying enough metadata for the React renderer to draw
     * a working form: name, scalar type, label, required flag, default value,
     * and the underlying type options (`maxLength`, `currency`, `options`).
     *
     * @return list<array<string,mixed>>
     */
    private function describeActionInputs(ActionNode $a): array {
        $out = [];
        foreach ($a->inputs as $f) {
            $required = !$f->nullable && $f->default === null;
            $desc = [
                'name'        => $f->name,
                'type'        => $f->type,
                'label'       => $f->label ?? ucfirst(str_replace('_', ' ', $f->name)),
                'typeOptions' => $f->typeOptions,
                'required'    => $required,
                'nullable'    => $f->nullable,
            ];
            if ($f->default !== null) {
                $desc['default'] = $f->default;
            }
            $out[] = $desc;
        }
        return $out;
    }
}
