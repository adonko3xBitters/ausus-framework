<?php
declare(strict_types=1);

namespace Ausus\Definition;

/**
 * RFC-012 §Q3 — the state move of a transition Action.
 *
 * Present iff the owning ActionDefinition has kind = Transition. `$field` names
 * an enum-typed (state) field; `$from` lists one or more source members; `$to`
 * is the target member. Membership and from ≠ to are checked at compile.
 */
final readonly class TransitionSpec
{
    /** @param list<string> $from one or more source enum members */
    public function __construct(
        public string $field,
        public array $from,
        public string $to,
    ) {
    }
}
