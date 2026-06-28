<?php
declare(strict_types=1);

namespace Ausus\Engine\Runtime;

use Ausus\Compiled\EntitySchema;
use Ausus\Contracts\AuthorizationEvaluator;
use Ausus\Contracts\EntityEngine;
use Ausus\Contracts\RuntimeEntity;
use Ausus\Contracts\SchemaRepository;
use Ausus\PersistenceDriver;

/**
 * IMPLEMENTATION-001 Phase 10 — the Entity Engine, bind half (RFC-011).
 *
 * `bind` wires a compiled {@see EntitySchema} to a {@see PersistenceDriver},
 * yielding an executable {@see DefaultRuntimeEntity}. No recompilation, no DSL,
 * no file access — it consumes already-produced schemas only.
 *
 * The optional {@see SchemaRepository} (constructor) lets bound runtimes resolve
 * a sibling entity's schema for single-hop `expand`; the frozen
 * `bind(EntitySchema, PersistenceDriver)` signature is unchanged.
 */
final class DefaultEntityEngine implements EntityEngine
{
    public function __construct(
        private readonly AuthorizationEvaluator $evaluator = new DefaultAuthorizationEvaluator(),
        private readonly ?SchemaRepository $schemas = null,
    ) {
    }

    public function bind(EntitySchema $schema, PersistenceDriver $driver): RuntimeEntity
    {
        return new DefaultRuntimeEntity($schema, $driver, $this->evaluator, $this->schemas);
    }
}
