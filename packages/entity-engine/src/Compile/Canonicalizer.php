<?php
declare(strict_types=1);

namespace Ausus\Engine\Compile;

use Ausus\Definition\ActionDefinition;
use Ausus\Definition\EntityDefinition;
use Ausus\Definition\Enum\Comparator;
use Ausus\Definition\Enum\LogicalOp;
use Ausus\Definition\ExpandSpec;
use Ausus\Definition\ExposedField;
use Ausus\Definition\Expression\Comparison;
use Ausus\Definition\Expression\Expression;
use Ausus\Definition\Expression\FactRef;
use Ausus\Definition\Expression\Literal;
use Ausus\Definition\Expression\Logical;
use Ausus\Definition\FieldDefinition;
use Ausus\Definition\ProjectionDefinition;
use Ausus\Definition\TransitionSpec;

/**
 * IMPLEMENTATION-001 Phase 2 — RFC-012 §Q7 semantic normal form.
 *
 * Pure, total function `EntityDefinition → array`. The returned array is the
 * JSON-ready normal form; encoding and hashing are the Hasher's job (Phase 3).
 *
 * Guarantees (RFC-012 §Q7):
 *   1. Expressions reduced to the primitives {eq, lt, not, and} (sugar removed).
 *   2. Sets canonically ordered (fields/actions/projections by name; inputs and
 *      expand by canonical key; commutative `and`/`eq` operands by canonical key).
 *   3. Semantic lists preserved (enum values, transition.from, `lt` operands).
 *   4. No hash, no version stamps, no timestamps/author/paths.
 *
 * NO disk, NO CLI, NO runtime, NO hash, NO closure validation. Two semantically
 * identical definitions produce identical output.
 */
final class Canonicalizer
{
    /**
     * @return array<string,mixed> the RFC-012 §Q7 normal form, JSON-ready
     */
    public function canonicalize(EntityDefinition $definition): array
    {
        return [
            'identity'     => $definition->identity,
            'tenantScoped' => $definition->tenantScoped,
            'fields'       => $this->sortByName(array_map($this->field(...), $definition->fields)),
            'actions'      => $this->sortByName(array_map($this->action(...), $definition->actions)),
            'projections'  => $this->sortByName(array_map($this->projection(...), $definition->projections)),
        ];
    }

    // ── Declarations ────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function field(FieldDefinition $f): array
    {
        return [
            'name'           => $f->name,
            'type'           => $f->type->value,
            'nullable'       => $f->nullable,
            'default'        => $f->default,
            'writeProtected' => $f->writeProtected,
            // typeOptions: canonical KEY order; list values (enum values) preserved.
            'typeOptions'    => $this->canonicalMap($f->typeOptions),
        ];
    }

    /** @return array<string,mixed> */
    private function action(ActionDefinition $a): array
    {
        $inputs = $a->inputs;
        sort($inputs, SORT_STRING); // inputs are a set — order is not semantic

        return [
            'name'       => $a->name,
            'kind'       => $a->kind->value,
            'inputs'     => array_values($inputs),
            'guard'      => $a->guard !== null ? $this->expression($a->guard) : null,
            'transition' => $a->transition !== null ? $this->transition($a->transition) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function transition(TransitionSpec $t): array
    {
        return [
            'field' => $t->field,
            'from'  => array_values($t->from), // PRESERVE order — semantic
            'to'    => $t->to,
        ];
    }

    /** @return array<string,mixed> */
    private function projection(ProjectionDefinition $p): array
    {
        $fields = array_map($this->exposedField(...), $p->fields);
        usort($fields, fn (array $x, array $y): int =>
            $this->key($x) <=> $this->key($y)); // set — canonical key order

        $expand = array_map($this->expand(...), $p->expand);
        usort($expand, fn (array $x, array $y): int =>
            $this->key($x) <=> $this->key($y)); // set — canonical key order

        return [
            'name'   => $p->name,
            'fields' => array_values($fields),
            'expand' => array_values($expand),
        ];
    }

    /** @return array<string,mixed> */
    private function exposedField(ExposedField $e): array
    {
        return [
            'field'      => $e->field,
            'visibility' => $e->visibility !== null ? $this->expression($e->visibility) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function expand(ExpandSpec $e): array
    {
        return [
            'via'        => $e->via,
            'projection' => $e->projection,
        ];
    }

    // ── Expression normalization → {eq, lt, not, and} ────────────────────────

    /** @return array<string,mixed> */
    private function expression(Expression $e): array
    {
        if ($e instanceof Comparison) {
            $l = $this->operand($e->left);
            $r = $this->operand($e->right);

            return match ($e->op) {
                Comparator::Eq  => $this->eq($l, $r),
                Comparator::Lt  => $this->lt($l, $r),
                Comparator::Ne  => $this->not($this->eq($l, $r)),
                Comparator::Gte => $this->not($this->lt($l, $r)),                 // ¬(a<b)
                Comparator::Lte => $this->or([$this->lt($l, $r), $this->eq($l, $r)]),     // lt ∨ eq
                Comparator::Gt  => $this->not($this->or([$this->lt($l, $r), $this->eq($l, $r)])), // ¬(lt ∨ eq)  [RFC-012 Q5]
                // `in` degenerates to `eq` here: the frozen Literal carries a single
                // scalar, so a set-valued `in` is not constructible in v0.1.
                Comparator::In  => $this->eq($l, $r),
            };
        }

        /** @var Logical $e */
        $operands = array_map($this->expression(...), $e->operands);

        return match ($e->op) {
            LogicalOp::Not => $this->not($operands[0]),
            LogicalOp::And => $this->and($operands),
            LogicalOp::Or  => $this->or($operands),
        };
    }

    /** @return array<string,mixed> */
    private function operand(FactRef|Literal $o): array
    {
        return $o instanceof FactRef
            ? ['node' => 'fact', 'source' => $o->source->value, 'path' => $o->path]
            : ['node' => 'lit', 'value' => $o->value];
    }

    /**
     * @param array<string,mixed> $l
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function eq(array $l, array $r): array
    {
        $args = [$l, $r];
        usort($args, fn (array $a, array $b): int => $this->key($a) <=> $this->key($b)); // commutative

        return ['node' => 'eq', 'args' => $args];
    }

    /**
     * @param array<string,mixed> $l
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function lt(array $l, array $r): array
    {
        return ['node' => 'lt', 'args' => [$l, $r]]; // ordered — preserve
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function not(array $node): array
    {
        // Double-negation elimination: not(not(x)) → x.
        if (($node['node'] ?? null) === 'not') {
            /** @var array<string,mixed> $inner */
            $inner = $node['arg'];
            return $inner;
        }

        return ['node' => 'not', 'arg' => $node];
    }

    /**
     * @param list<array<string,mixed>> $operands
     * @return array<string,mixed>
     */
    private function and(array $operands): array
    {
        // Associativity: flatten nested `and`.
        $flat = [];
        foreach ($operands as $op) {
            if (($op['node'] ?? null) === 'and') {
                foreach ($op['args'] as $inner) {
                    $flat[] = $inner;
                }
            } else {
                $flat[] = $op;
            }
        }
        usort($flat, fn (array $a, array $b): int => $this->key($a) <=> $this->key($b)); // commutative

        return ['node' => 'and', 'args' => array_values($flat)];
    }

    /**
     * `or(...)` → De Morgan into {not, and}: ¬(¬x₁ ∧ … ∧ ¬xₙ).
     *
     * @param list<array<string,mixed>> $operands
     * @return array<string,mixed>
     */
    private function or(array $operands): array
    {
        $negated = array_map($this->not(...), $operands);

        return $this->not($this->and($negated));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Canonical key-order for a map; sequential lists keep their order
     * (enum values and other semantic lists must not be reordered).
     *
     * @param array<int|string,mixed> $a
     * @return array<int|string,mixed>
     */
    private function canonicalMap(array $a): array
    {
        if (array_is_list($a)) {
            return array_map(
                fn (mixed $v): mixed => is_array($v) ? $this->canonicalMap($v) : $v,
                $a,
            );
        }
        ksort($a);
        $out = [];
        foreach ($a as $k => $v) {
            $out[$k] = is_array($v) ? $this->canonicalMap($v) : $v;
        }

        return $out;
    }

    /**
     * Sort a list of `{name: …}` arrays by name (declaration order is not
     * semantic for fields/actions/projections).
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function sortByName(array $items): array
    {
        usort($items, fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return array_values($items);
    }

    /**
     * Deterministic sort key for any normalized fragment. Used ONLY as a sort
     * comparator (never emitted) — keeps commutative operands order-independent.
     */
    private function key(mixed $v): string
    {
        if (is_array($v)) {
            if (array_is_list($v)) {
                return '[' . implode(',', array_map($this->key(...), $v)) . ']';
            }
            ksort($v);
            $parts = [];
            foreach ($v as $k => $vv) {
                $parts[] = $k . ':' . $this->key($vv);
            }

            return '{' . implode(',', $parts) . '}';
        }
        if (is_bool($v)) {
            return $v ? 'b:1' : 'b:0';
        }
        if ($v === null) {
            return 'n:';
        }
        if (is_int($v)) {
            return 'i:' . $v;
        }
        if (is_float($v)) {
            return 'f:' . $v;
        }

        return 's:' . $v;
    }
}
