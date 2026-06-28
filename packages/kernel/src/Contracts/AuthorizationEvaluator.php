<?php
declare(strict_types=1);

namespace Ausus\Contracts;

use Ausus\Decision;
use Ausus\Definition\Expression\Expression;

/**
 * RFC-011 §4 — evaluate an embedded AuthorizationRule against resolved facts.
 *
 * The facts are the resolved FactSet materialized as a map keyed by
 * "<source>.<path>" (e.g. 'actor.limit', 'subject.amount', 'input.amount').
 * The engine's FactSet builder (entity-engine, Phase 8) assembles this map from
 * Context + subject + input. Facts stay a primitive map so the kernel (L0)
 * keeps its zero-dependency rule — it cannot reference an L1 type. Returns the
 * frozen kernel {@see Decision} (fail-closed: Deny on unresolved facts).
 */
interface AuthorizationEvaluator
{
    /** @param array<string,mixed> $facts */
    public function evaluate(Expression $rule, array $facts): Decision;
}
