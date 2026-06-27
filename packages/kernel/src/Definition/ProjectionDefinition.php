<?php
declare(strict_types=1);

namespace Ausus\Definition;

/**
 * RFC-012 §Q4 — a declared read-shape over an Entity.
 *
 * Shape: {name, fields, expand}. Selection (filters, sort, pagination) is NOT
 * part of the shape — it is supplied per call to RuntimeEntity::read().
 */
final readonly class ProjectionDefinition
{
    /**
     * @param list<ExposedField> $fields
     * @param list<ExpandSpec>   $expand single-hop only
     */
    public function __construct(
        public string $name,
        public array $fields,
        public array $expand = [],
    ) {
    }
}
