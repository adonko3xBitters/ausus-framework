<?php
declare(strict_types=1);

namespace Ausus\Api\Runtime\Schema;

use Ausus\Contracts\Context;
use Ausus\Contracts\SchemaRepository;
use Ausus\Definition\ActionDefinition;
use Ausus\Definition\ExpandSpec;
use Ausus\Definition\ExposedField;
use Ausus\Definition\ProjectionDefinition;

/**
 * IMPLEMENTATION-002 — GET /api/entities/{entity}.
 *
 * Returns the compiled entity's exposed capabilities (identity, actions,
 * projections) so a future React Renderer can discover what is invokable and
 * readable. Pure resolve(entity); no bind, no compile, no DSL.
 */
final class ReadSchemaHandler
{
    public function __construct(private readonly SchemaRepository $schemas)
    {
    }

    /** @return array<string,mixed> */
    public function handle(string $entity, Context $context): array
    {
        $schema = $this->schemas->resolve($entity);

        return [
            'identity' => $schema->identity,
            'tenantScoped' => $schema->tenantScoped,
            'actions' => array_map(
                fn (ActionDefinition $a): array => [
                    'name' => $a->name,
                    'kind' => $a->kind->value,
                    'inputs' => $a->inputs,
                    'guarded' => $a->guard !== null,
                    'transition' => $a->transition !== null
                        ? ['field' => $a->transition->field, 'from' => $a->transition->from, 'to' => $a->transition->to]
                        : null,
                ],
                $schema->actions,
            ),
            'projections' => array_map(
                fn (ProjectionDefinition $p): array => [
                    'name' => $p->name,
                    'fields' => array_map(
                        fn (ExposedField $f): array => ['field' => $f->field, 'restricted' => $f->visibility !== null],
                        $p->fields,
                    ),
                    'expand' => array_map(
                        fn (ExpandSpec $x): array => ['via' => $x->via, 'projection' => $x->projection],
                        $p->expand,
                    ),
                ],
                $schema->projections,
            ),
        ];
    }
}
