<?php
declare(strict_types=1);

namespace Ausus\Definition\Expression;

use Ausus\Definition\Enum\LogicalOp;

/**
 * RFC-012 §Q5 — a logical node ⟨op, operands⟩.
 *
 * `Not` carries exactly one operand; `And`/`Or` carry one or more. Arity and
 * well-formedness are enforced at compile (Phase 4), not here.
 */
final readonly class Logical implements Expression
{
    /** @param list<Expression> $operands */
    public function __construct(
        public LogicalOp $op,
        public array $operands,
    ) {
    }
}
