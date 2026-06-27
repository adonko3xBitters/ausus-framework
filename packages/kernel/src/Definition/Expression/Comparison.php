<?php
declare(strict_types=1);

namespace Ausus\Definition\Expression;

use Ausus\Definition\Enum\Comparator;

/**
 * RFC-012 §Q5 — a comparison node ⟨op, left, right⟩.
 *
 * Operands are `FactRef|Literal`. Sugar comparators are carried verbatim and
 * normalized to primitives {Eq, Lt} at canonicalization (Phase 2).
 */
final readonly class Comparison implements Expression
{
    public function __construct(
        public Comparator $op,
        public FactRef|Literal $left,
        public FactRef|Literal $right,
    ) {
    }
}
