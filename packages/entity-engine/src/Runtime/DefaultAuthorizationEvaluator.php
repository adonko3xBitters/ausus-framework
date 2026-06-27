<?php
declare(strict_types=1);

namespace Ausus\Engine\Runtime;

use Ausus\Contracts\AuthorizationEvaluator;
use Ausus\Decision;
use Ausus\Definition\Enum\Comparator;
use Ausus\Definition\Enum\LogicalOp;
use Ausus\Definition\Expression\Comparison;
use Ausus\Definition\Expression\Expression;
use Ausus\Definition\Expression\FactRef;
use Ausus\Definition\Expression\Literal;
use Ausus\Definition\Expression\Logical;

/**
 * IMPLEMENTATION-001 Phase 8 — evaluates a (normalized) RFC-012 Expression
 * against a flat facts array (RFC-011).
 *
 * Evaluates the full RFC-012 §Q5 Expression operator set, using EXACTLY the same
 * reductions the Canonicalizer applies when normalizing for the content hash, so
 * the Runtime and the compile-time canonical form share one semantics:
 *   ne = not(eq) · gte = not(lt) · lte = (lt ∨ eq) · gt = not(lt ∨ eq)
 *   in = eq (singleton, == Canonicalizer) · or = ¬(¬x₁ ∧ … ∧ ¬xₙ)
 * The primitives {eq, lt, not, and} are evaluated directly; the sugar operators
 * are evaluated through the identities above — never silently denied.
 *
 * Fail-closed and total: an unresolved FactRef, a missing intermediate path
 * segment, a malformed node, or a non-primitive operator all yield
 * {@see Decision::Deny}. evaluate() NEVER throws and NEVER permits by default.
 *
 * No persistence, no Driver, no RuntimeEntity, no Context. Facts are passed in.
 */
final class DefaultAuthorizationEvaluator implements AuthorizationEvaluator
{
    /** Private sentinel for "fact could not be resolved" (distinct from any real value, incl. null). */
    private static ?object $unresolved = null;

    /** @param array<string,mixed> $facts */
    public function evaluate(Expression $rule, array $facts): Decision
    {
        // Tri-state: true = permit, false = deny-by-logic, null = unresolved → fail closed.
        return $this->test($rule, $facts) === true ? Decision::Permit : Decision::Deny;
    }

    /** @param array<string,mixed> $facts */
    private function test(Expression $e, array $facts): ?bool
    {
        if ($e instanceof Comparison) {
            return $this->compare($e, $facts);
        }
        if ($e instanceof Logical) {
            return match ($e->op) {
                LogicalOp::Not => $this->evalNot($e, $facts),
                LogicalOp::And => $this->evalAnd($e, $facts),
                LogicalOp::Or  => $this->evalOr($e, $facts),
            };
        }

        return null; // unknown expression type → fail closed
    }

    /** @param array<string,mixed> $facts */
    private function compare(Comparison $c, array $facts): ?bool
    {
        $left = $this->operand($c->left, $facts);
        $right = $this->operand($c->right, $facts);
        $u = $this->unresolved();
        if ($left === $u || $right === $u) {
            return null; // unresolved fact → fail closed
        }

        // Same reductions the Canonicalizer uses for the hash (RFC-012 §Q5);
        // operands are already resolved (unresolved → null above).
        return match ($c->op) {
            Comparator::Eq  => $left === $right,                          // strict, no coercion
            Comparator::Lt  => $left < $right,                            // ordered, no added coercion
            Comparator::Ne  => $left !== $right,                          // not(eq)
            Comparator::Gte => !($left < $right),                         // not(lt)
            Comparator::Lte => ($left < $right) || ($left === $right),    // lt ∨ eq
            Comparator::Gt  => !(($left < $right) || ($left === $right)), // not(lt ∨ eq)
            Comparator::In  => $left === $right,                          // singleton degenerate (== Canonicalizer)
        };
    }

    /** @param array<string,mixed> $facts */
    private function evalNot(Logical $e, array $facts): ?bool
    {
        if (count($e->operands) !== 1) {
            return null; // malformed → fail closed
        }
        $inner = $this->test($e->operands[0], $facts);

        return $inner === null ? null : !$inner;
    }

    /** @param array<string,mixed> $facts */
    private function evalAnd(Logical $e, array $facts): ?bool
    {
        if ($e->operands === []) {
            return null; // malformed → fail closed
        }
        foreach ($e->operands as $operand) {
            $r = $this->test($operand, $facts);
            if ($r === false) {
                return false; // short-circuit at the first deny
            }
            if ($r === null) {
                return null; // unresolved → fail closed
            }
        }

        return true;
    }

    /**
     * `or` evaluated as ¬(¬x₁ ∧ … ∧ ¬xₙ) (De Morgan — the Canonicalizer's reduction):
     * any operand true ⇒ true; otherwise unknown if any unresolved (fail closed),
     * else false.
     *
     * @param array<string,mixed> $facts
     */
    private function evalOr(Logical $e, array $facts): ?bool
    {
        if ($e->operands === []) {
            return null; // malformed → fail closed
        }
        $unresolved = false;
        foreach ($e->operands as $operand) {
            $r = $this->test($operand, $facts);
            if ($r === true) {
                return true; // short-circuit at the first permit
            }
            if ($r === null) {
                $unresolved = true;
            }
        }

        return $unresolved ? null : false;
    }

    /** @param array<string,mixed> $facts @return mixed value, or the unresolved sentinel */
    private function operand(FactRef|Literal $o, array $facts): mixed
    {
        return $o instanceof Literal ? $o->value : $this->resolveFact($o, $facts);
    }

    /**
     * FactRef resolution: facts[<source>][<path segments…>], dotted path.
     *
     * @param array<string,mixed> $facts
     * @return mixed the resolved value, or the unresolved sentinel
     */
    private function resolveFact(FactRef $f, array $facts): mixed
    {
        $node = $facts;
        foreach (array_merge([$f->source->value], explode('.', $f->path)) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $this->unresolved();
            }
            $node = $node[$segment];
        }

        return $node;
    }

    private function unresolved(): object
    {
        return self::$unresolved ??= new class {
        };
    }
}
