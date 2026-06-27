<?php
declare(strict_types=1);

$autoload = [
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];
foreach ($autoload as $f) {
    if (file_exists($f)) {
        require $f;
        break;
    }
}

/**
 * IMPLEMENTATION-001 Phase 5A — Expr sub-language (Tests 8–9 + FactRefs).
 */

use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\Comparator;
use Ausus\Definition\Enum\FactSource;
use Ausus\Definition\Enum\LogicalOp;
use Ausus\Definition\Expression\Comparison;
use Ausus\Definition\Expression\FactRef;
use Ausus\Definition\Expression\Literal;
use Ausus\Definition\Expression\Logical;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

echo "── FactRef helpers → RFC-012 FactRef ───────────────────────\n";
$ok('actor', Expr::actor('limit') instanceof FactRef && Expr::actor('limit')->source === FactSource::Actor && Expr::actor('limit')->path === 'limit');
$ok('tenant', Expr::tenant('id')->source === FactSource::Tenant);
$ok('now', Expr::now('ts')->source === FactSource::Now);
$ok('subject', Expr::subject('amount')->source === FactSource::Subject);
$ok('input', Expr::input('amount')->source === FactSource::Input);

echo "── Test 8 — primitives {eq, lt, not, and} ──────────────────\n";
$eq = Expr::eq(Expr::subject('a'), Expr::input('a'));
$ok('eq → Comparison(Eq)', $eq instanceof Comparison && $eq->op === Comparator::Eq);
$ok('eq operands typed FactRef', $eq->left instanceof FactRef && $eq->right instanceof FactRef);
$lt = Expr::lt(Expr::subject('a'), 10);
$ok('lt → Comparison(Lt); scalar wrapped in Literal', $lt->op === Comparator::Lt && $lt->right instanceof Literal && $lt->right->value === 10);
$not = Expr::not($eq);
$ok('not → Logical(Not) single operand', $not instanceof Logical && $not->op === LogicalOp::Not && count($not->operands) === 1 && $not->operands[0] === $eq);
$and = Expr::and($eq, $lt);
$ok('and → Logical(And) variadic', $and->op === LogicalOp::And && count($and->operands) === 2);

echo "── Test 9 — sugar carried verbatim (NOT normalized) ────────\n";
$ok('ne → Comparator::Ne', Expr::ne(Expr::subject('a'), 1)->op === Comparator::Ne);
$ok('lte → Comparator::Lte', Expr::lte(Expr::subject('a'), 1)->op === Comparator::Lte);
$ok('gt → Comparator::Gt', Expr::gt(Expr::subject('a'), 1)->op === Comparator::Gt);
$ok('gte → Comparator::Gte', Expr::gte(Expr::subject('a'), 1)->op === Comparator::Gte);
$ok('in → Comparator::In', Expr::in(Expr::subject('a'), 1)->op === Comparator::In);
$or = Expr::or($eq, $lt);
$ok('or → LogicalOp::Or (verbatim, not reduced)', $or instanceof Logical && $or->op === LogicalOp::Or && count($or->operands) === 2);

echo "\n";
echo $fail === 0
    ? "PHASE 5A / Expr OK — {$pass} checks passed\n"
    : "PHASE 5A / Expr FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
