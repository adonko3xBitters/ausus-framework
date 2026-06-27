<?php
declare(strict_types=1);

namespace Ausus\Authoring\Dsl;

use Ausus\Definition\ActionDefinition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Expression\Expression;
use Ausus\Definition\TransitionSpec;

/**
 * IMPLEMENTATION-001 Phase 5A — translates `(name, kind, options)` into a frozen
 * RFC-012 {@see ActionDefinition}. Pure notation.
 *
 * Supported options: inputs, guard, transition{field, from(string|array), to}.
 */
final class ActionBuilder
{
    /**
     * @param array{inputs?: list<string>, guard?: ?Expression, transition?: array{field: string, from: string|list<string>, to: string}} $options
     */
    public static function build(string $name, ActionKind $kind, array $options = []): ActionDefinition
    {
        $transition = null;
        if (isset($options['transition'])) {
            $t = $options['transition'];
            $from = $t['from'];
            $transition = new TransitionSpec(
                field: $t['field'],
                from: is_array($from) ? array_values($from) : [$from],
                to: $t['to'],
            );
        }

        return new ActionDefinition(
            name: $name,
            kind: $kind,
            inputs: $options['inputs'] ?? [],
            guard: $options['guard'] ?? null,
            transition: $transition,
        );
    }
}
