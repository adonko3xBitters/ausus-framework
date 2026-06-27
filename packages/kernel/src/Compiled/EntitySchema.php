<?php
declare(strict_types=1);

namespace Ausus\Compiled;

use Ausus\Definition\ActionDefinition;
use Ausus\Definition\FieldDefinition;
use Ausus\Definition\ProjectionDefinition;

/**
 * RFC-011 §4 / RFC-CLI-001 §Q7 — the compiled, normalized, content-addressed
 * form of one Entity, consumed by EntityEngine::bind().
 *
 * The normalized content reuses the Definition value objects (no separate
 * FieldSchema/ActionSchema concepts are introduced). `$hash` addresses the
 * normalized definition; `$version` stamps are excluded from that hash.
 */
final readonly class EntitySchema
{
    /**
     * @param list<FieldDefinition>      $fields
     * @param list<ActionDefinition>     $actions
     * @param list<ProjectionDefinition> $projections
     */
    public function __construct(
        public SchemaVersion $version,
        public string $hash,
        public string $identity,
        public bool $tenantScoped,
        public array $fields,
        public array $actions,
        public array $projections,
    ) {
    }
}
