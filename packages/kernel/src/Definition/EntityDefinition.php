<?php
declare(strict_types=1);

namespace Ausus\Definition;

/**
 * RFC-012 §Q1 — the unique conceptual model authored per entity.
 *
 * Composition: identity + tenantScoped + Field[] + Action[] + Projection[].
 * The AuthorizationRule is not a top-level collection — it lives as an embedded
 * Expression on actions (guard) and exposed fields (visibility). The system
 * identity field is injected by the Compiler (Phase 4), not declared here.
 */
final readonly class EntityDefinition
{
    /**
     * @param list<FieldDefinition>      $fields
     * @param list<ActionDefinition>     $actions
     * @param list<ProjectionDefinition> $projections
     */
    public function __construct(
        public string $identity,
        public bool $tenantScoped,
        public array $fields,
        public array $actions = [],
        public array $projections = [],
    ) {
    }
}
