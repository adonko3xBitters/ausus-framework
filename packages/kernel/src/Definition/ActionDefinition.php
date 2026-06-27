<?php
declare(strict_types=1);

namespace Ausus\Definition;

use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Expression\Expression;

/**
 * RFC-012 §Q3 — a declared mutation (command) on an Entity.
 *
 * Shape: {name, kind, inputs, guard?, transition?}. `$inputs` are FieldRefs into
 * the owning entity's writable, non-writeProtected fields. `$guard` is the
 * embedded AuthorizationRule (absent ⇒ ungated). `$transition` is present iff
 * kind = Transition.
 */
final readonly class ActionDefinition
{
    /** @param list<string> $inputs FieldRefs into the owning entity's fields */
    public function __construct(
        public string $name,
        public ActionKind $kind,
        public array $inputs = [],
        public ?Expression $guard = null,
        public ?TransitionSpec $transition = null,
    ) {
    }
}
