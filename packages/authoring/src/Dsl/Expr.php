<?php
declare(strict_types=1);

namespace Ausus\Authoring\Dsl;

use Ausus\Definition\Enum\Comparator;
use Ausus\Definition\Enum\FactSource;
use Ausus\Definition\Enum\LogicalOp;
use Ausus\Definition\Expression\Comparison;
use Ausus\Definition\Expression\Expression;
use Ausus\Definition\Expression\FactRef;
use Ausus\Definition\Expression\Literal;
use Ausus\Definition\Expression\Logical;

/**
 * IMPLEMENTATION-001 Phase 5A — closed notation for the RFC-012 Expression
 * sub-language. Produces frozen RFC-012 nodes only.
 *
 * Sugar (ne/lte/gt/gte/in/or) is carried VERBATIM — normalization to the
 * primitives {eq, lt, not, and} belongs exclusively to the Canonicalizer.
 * Bare scalars are wrapped into {@see Literal}; FactRefs come from the
 * actor/tenant/now/subject/input helpers.
 */
final class Expr
{
    // ── Comparison primitives ────────────────────────────────────────────────

    public static function eq(FactRef|Literal|string|int|float|bool|null $left, FactRef|Literal|string|int|float|bool|null $right): Comparison
    {
        return new Comparison(Comparator::Eq, self::operand($left), self::operand($right));
    }

    public static function lt(FactRef|Literal|string|int|float|bool|null $left, FactRef|Literal|string|int|float|bool|null $right): Comparison
    {
        return new Comparison(Comparator::Lt, self::operand($left), self::operand($right));
    }

    // ── Comparison sugar (NOT normalized here) ───────────────────────────────

    public static function ne(FactRef|Literal|string|int|float|bool|null $left, FactRef|Literal|string|int|float|bool|null $right): Comparison
    {
        return new Comparison(Comparator::Ne, self::operand($left), self::operand($right));
    }

    public static function lte(FactRef|Literal|string|int|float|bool|null $left, FactRef|Literal|string|int|float|bool|null $right): Comparison
    {
        return new Comparison(Comparator::Lte, self::operand($left), self::operand($right));
    }

    public static function gt(FactRef|Literal|string|int|float|bool|null $left, FactRef|Literal|string|int|float|bool|null $right): Comparison
    {
        return new Comparison(Comparator::Gt, self::operand($left), self::operand($right));
    }

    public static function gte(FactRef|Literal|string|int|float|bool|null $left, FactRef|Literal|string|int|float|bool|null $right): Comparison
    {
        return new Comparison(Comparator::Gte, self::operand($left), self::operand($right));
    }

    public static function in(FactRef|Literal|string|int|float|bool|null $left, FactRef|Literal|string|int|float|bool|null $right): Comparison
    {
        return new Comparison(Comparator::In, self::operand($left), self::operand($right));
    }

    // ── Logical primitives ───────────────────────────────────────────────────

    public static function not(Expression $operand): Logical
    {
        return new Logical(LogicalOp::Not, [$operand]);
    }

    public static function and(Expression ...$operands): Logical
    {
        return new Logical(LogicalOp::And, array_values($operands));
    }

    // ── Logical sugar (NOT normalized here) ──────────────────────────────────

    public static function or(Expression ...$operands): Logical
    {
        return new Logical(LogicalOp::Or, array_values($operands));
    }

    // ── FactRef helpers ──────────────────────────────────────────────────────

    public static function actor(string $path): FactRef
    {
        return new FactRef(FactSource::Actor, $path);
    }

    public static function tenant(string $path): FactRef
    {
        return new FactRef(FactSource::Tenant, $path);
    }

    public static function now(string $path): FactRef
    {
        return new FactRef(FactSource::Now, $path);
    }

    public static function subject(string $path): FactRef
    {
        return new FactRef(FactSource::Subject, $path);
    }

    public static function input(string $path): FactRef
    {
        return new FactRef(FactSource::Input, $path);
    }

    // ── internal ─────────────────────────────────────────────────────────────

    private static function operand(FactRef|Literal|string|int|float|bool|null $v): FactRef|Literal
    {
        if ($v instanceof FactRef || $v instanceof Literal) {
            return $v;
        }

        return new Literal($v);
    }
}
