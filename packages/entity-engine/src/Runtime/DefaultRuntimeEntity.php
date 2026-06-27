<?php
declare(strict_types=1);

namespace Ausus\Engine\Runtime;

use Ausus\Compiled\EntitySchema;
use Ausus\Contracts\AuthorizationEvaluator;
use Ausus\Contracts\Context;
use Ausus\Contracts\RuntimeEntity;
use Ausus\Contracts\SchemaRepository;
use Ausus\Decision;
use Ausus\Definition\ActionDefinition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\ProjectionDefinition;
use Ausus\Entity;
use Ausus\PersistenceDriver;
use Ausus\Reference;
use Ausus\TransactionHandle;
use Ausus\Version;
use RuntimeException;
use Throwable;

/**
 * IMPLEMENTATION-001 Phase 10 — executable, Driver-bound Entity (RFC-011 §4).
 *
 * Operates ONLY on a compiled {@see EntitySchema}, the
 * {@see AuthorizationEvaluator}, and the {@see PersistenceDriver}/Repository
 * contracts (Driver-agnostic). No recompilation, no DSL, no files, no Compiler,
 * no Frontend. Authorization is delegated to the evaluator; facts (actor /
 * tenant / now / subject / input) are assembled from the Context, the current
 * entity, and the call parameters. Fail-closed throughout.
 *
 * The optional {@see SchemaRepository} resolves a target entity's schema for
 * single-hop `expand` (depth ≤ 1 — guaranteed by the closure; no recursion).
 */
final class DefaultRuntimeEntity implements RuntimeEntity
{
    public function __construct(
        private readonly EntitySchema $schema,
        private readonly PersistenceDriver $driver,
        private readonly AuthorizationEvaluator $evaluator,
        private readonly ?SchemaRepository $schemas = null,
    ) {
    }

    /** @param array<string,mixed> $inputs */
    public function invoke(string $action, array $inputs, Context $context): Entity
    {
        $definition = $this->action($action)
            ?? throw new RuntimeException("ausus:invoke: unknown action '{$action}'");

        return match ($definition->kind) {
            ActionKind::Create     => $this->invokeCreate($definition, $inputs, $context),
            ActionKind::Transition => $this->invokeTransition($definition, $inputs, $context),
            ActionKind::Update     => $this->invokeUpdate($definition, $inputs, $context),
        };
    }

    /**
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function read(string $projection, array $params, Context $context): array
    {
        $definition = $this->projection($projection)
            ?? throw new RuntimeException("ausus:read: unknown projection '{$projection}'");

        $tx = $this->driver->beginTransaction($context->tenant());
        try {
            $entities = $this->driver->context($context->tenant(), $tx)
                ->repository($this->schema->identity)
                ->findAll();
            $rows = [];
            foreach ($entities as $entity) {
                $rows[] = $this->renderRow($definition, $entity, $context, $tx);
            }
            $this->driver->rollback($tx); // read-only

            return $rows;
        } catch (Throwable $e) {
            $this->driver->rollback($tx);
            throw $e;
        }
    }

    // ── invoke pipelines ─────────────────────────────────────────────────────

    /** @param array<string,mixed> $inputs */
    private function invokeCreate(ActionDefinition $a, array $inputs, Context $ctx): Entity
    {
        // No subject on create; guard sees actor/tenant/now/input.
        $this->authorize($a, $this->facts($ctx, [], $inputs), $a->name);

        $tx = $this->driver->beginTransaction($ctx->tenant());
        try {
            $entity = $this->driver->context($ctx->tenant(), $tx)
                ->repository($this->schema->identity)
                ->create($this->createPayload($a, $inputs));
            $this->driver->commit($tx);

            return $entity;
        } catch (Throwable $e) {
            $this->driver->rollback($tx); // rollback on any error
            throw $e;
        }
    }

    /** @param array<string,mixed> $inputs */
    private function invokeTransition(ActionDefinition $a, array $inputs, Context $ctx): Entity
    {
        $transition = $a->transition
            ?? throw new RuntimeException("ausus:invoke: '{$a->name}' has no transition");
        $identity = $this->subjectIdentity($inputs);

        $tx = $this->driver->beginTransaction($ctx->tenant());
        try {
            $repo = $this->driver->context($ctx->tenant(), $tx)->repository($this->schema->identity);
            $entity = $repo->find(new Reference($ctx->tenant()->value(), $this->schema->identity, $identity))
                ?? throw new RuntimeException("ausus:invoke: subject '{$identity}' not found");

            // Verify the transition is legal from the current state.
            if (!in_array($entity->field($transition->field), $transition->from, true)) {
                throw new RuntimeException("ausus:invoke: invalid transition for '{$a->name}'");
            }
            $this->authorize($a, $this->facts($ctx, $entity->fields, $inputs), $a->name);

            $updated = $repo->update($entity->reference, [$transition->field => $transition->to], $entity->version);
            $this->driver->commit($tx);

            return $updated;
        } catch (Throwable $e) {
            $this->driver->rollback($tx);
            throw $e;
        }
    }

    /** @param array<string,mixed> $inputs */
    private function invokeUpdate(ActionDefinition $a, array $inputs, Context $ctx): Entity
    {
        $identity = $this->subjectIdentity($inputs);

        $tx = $this->driver->beginTransaction($ctx->tenant());
        try {
            $repo = $this->driver->context($ctx->tenant(), $tx)->repository($this->schema->identity);
            $entity = $repo->find(new Reference($ctx->tenant()->value(), $this->schema->identity, $identity))
                ?? throw new RuntimeException("ausus:invoke: subject '{$identity}' not found");

            $this->authorize($a, $this->facts($ctx, $entity->fields, $inputs), $a->name);

            $patch = [];
            foreach ($a->inputs as $field) {
                if (array_key_exists($field, $inputs)) {
                    $patch[$field] = $inputs[$field];
                }
            }
            $updated = $repo->update($entity->reference, $patch, $entity->version);
            $this->driver->commit($tx);

            return $updated;
        } catch (Throwable $e) {
            $this->driver->rollback($tx);
            throw $e;
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $facts */
    private function authorize(ActionDefinition $a, array $facts, string $name): void
    {
        if ($a->guard !== null && $this->evaluator->evaluate($a->guard, $facts) !== Decision::Permit) {
            throw new RuntimeException("ausus:invoke: action '{$name}' denied");
        }
    }

    /**
     * Build the create payload: declared input values plus field defaults.
     *
     * @param array<string,mixed> $inputs
     * @return array<string,mixed>
     */
    private function createPayload(ActionDefinition $a, array $inputs): array
    {
        $payload = [];
        foreach ($this->schema->fields as $field) {
            if ($field->type === FieldType::Identity) {
                continue; // system identity is the Driver's concern
            }
            if (in_array($field->name, $a->inputs, true) && array_key_exists($field->name, $inputs)) {
                $payload[$field->name] = $inputs[$field->name];
            } elseif ($field->default !== null) {
                $payload[$field->name] = $field->default; // initial value (incl. state via writeProtected default)
            }
        }

        return $payload;
    }

    /** @param array<string,mixed> $inputs */
    private function subjectIdentity(array $inputs): string
    {
        $id = $inputs['id'] ?? throw new RuntimeException("ausus:invoke: missing subject 'id'");

        return (string) $id;
    }

    /**
     * Assemble the flat facts map consumed by the AuthorizationEvaluator.
     *
     * @param array<string,mixed> $subject current entity fields ([] when none, e.g. create)
     * @param array<string,mixed> $input   invoke/read parameters
     * @return array<string,mixed>
     */
    private function facts(Context $ctx, array $subject, array $input): array
    {
        $actor = $ctx->actor();

        return [
            'actor'   => ['type' => $actor->type, 'id' => $actor->id, 'homeTenant' => $actor->homeTenant],
            'tenant'  => ['id' => $ctx->tenant()->value()],
            'now'     => ['timestamp' => $ctx->now()->getTimestamp(), 'iso' => $ctx->now()->format(\DateTimeInterface::ATOM)],
            'subject' => $subject,
            'input'   => $input,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function renderRow(ProjectionDefinition $p, Entity $entity, Context $ctx, TransactionHandle $tx): array
    {
        $row = $this->renderFields($p, $entity, $ctx);
        foreach ($p->expand as $expand) {
            $row[$expand->via] = $this->renderExpand($expand->via, $expand->projection, $entity, $ctx, $tx);
        }

        return $row;
    }

    /**
     * Exposed fields with per-field visibility applied; denied fields are omitted.
     *
     * @return array<string,mixed>
     */
    private function renderFields(ProjectionDefinition $p, Entity $entity, Context $ctx): array
    {
        $facts = $this->facts($ctx, $entity->fields, []);
        $row = [];
        foreach ($p->fields as $exposed) {
            if ($exposed->visibility !== null
                && $this->evaluator->evaluate($exposed->visibility, $facts) !== Decision::Permit) {
                continue; // visibility deny → field absent
            }
            $row[$exposed->field] = $entity->field($exposed->field);
        }

        return $row;
    }

    /**
     * Single-hop expand: resolve the target schema, load the referenced entity,
     * and render the target projection's fields (no nested expand — depth ≤ 1).
     *
     * @return array<string,mixed>|null
     */
    private function renderExpand(string $via, string $projection, Entity $entity, Context $ctx, TransactionHandle $tx): ?array
    {
        if ($this->schemas === null) {
            return null;
        }
        $target = $this->referenceTarget($via);
        $foreignKey = $entity->field($via);
        if ($target === null || !is_string($foreignKey)) {
            return null;
        }
        $targetSchema = $this->schemas->resolve($target);
        $targetProjection = null;
        foreach ($targetSchema->projections as $candidate) {
            if ($candidate->name === $projection) {
                $targetProjection = $candidate;
                break;
            }
        }
        if ($targetProjection === null) {
            return null;
        }
        $found = $this->driver->context($ctx->tenant(), $tx)->repository($target)
            ->find(new Reference($ctx->tenant()->value(), $target, $foreignKey));

        return $found === null ? null : $this->renderFields($targetProjection, $found, $ctx);
    }

    private function referenceTarget(string $fieldName): ?string
    {
        foreach ($this->schema->fields as $field) {
            if ($field->name === $fieldName && $field->type === FieldType::Reference) {
                $target = $field->typeOptions['target'] ?? null;

                return is_string($target) ? $target : null;
            }
        }

        return null;
    }

    private function action(string $name): ?ActionDefinition
    {
        foreach ($this->schema->actions as $action) {
            if ($action->name === $name) {
                return $action;
            }
        }

        return null;
    }

    private function projection(string $name): ?ProjectionDefinition
    {
        foreach ($this->schema->projections as $projection) {
            if ($projection->name === $name) {
                return $projection;
            }
        }

        return null;
    }
}
