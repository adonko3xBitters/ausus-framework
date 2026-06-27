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
 * IMPLEMENTATION-001 Phase 8 — DefaultAuthorizationEvaluator (Tests 1–13).
 */

use Ausus\Decision;
use Ausus\Definition\Enum\Comparator;
use Ausus\Definition\Enum\FactSource;
use Ausus\Definition\Enum\LogicalOp;
use Ausus\Definition\Expression\Comparison;
use Ausus\Definition\Expression\Expression;
use Ausus\Definition\Expression\FactRef;
use Ausus\Definition\Expression\Literal;
use Ausus\Definition\Expression\Logical;
use Ausus\Engine\Runtime\DefaultAuthorizationEvaluator;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

$ev = new DefaultAuthorizationEvaluator();
$facts = [
    'actor'   => ['role' => 'manager'],
    'subject' => ['status' => 'draft', 'amount' => 1000, 'customer' => ['country' => 'FR']],
];
$permit = Decision::Permit;
$deny = Decision::Deny;

// Builders -------------------------------------------------------------------
$f = fn (FactSource $s, string $p): FactRef => new FactRef($s, $p);
$actor = fn (string $p): FactRef => new FactRef(FactSource::Actor, $p);
$subject = fn (string $p): FactRef => new FactRef(FactSource::Subject, $p);
$lit = fn (mixed $v): Literal => new Literal($v);
$eq = fn ($l, $r): Comparison => new Comparison(Comparator::Eq, $l, $r);
$lt = fn ($l, $r): Comparison => new Comparison(Comparator::Lt, $l, $r);
$not = fn (Expression $e): Logical => new Logical(LogicalOp::Not, [$e]);
$and = fn (Expression ...$e): Logical => new Logical(LogicalOp::And, array_values($e));
$ev2 = fn (Expression $e): Decision => $ev->evaluate($e, $facts);

echo "── Test 1 — eq true → permit ───────────────────────────────\n";
$ok('Test 1', $ev2($eq($actor('role'), $lit('manager'))) === $permit);

echo "── Test 2 — eq false → deny ────────────────────────────────\n";
$ok('Test 2', $ev2($eq($actor('role'), $lit('admin'))) === $deny);

echo "── Test 3 — lt true → permit ───────────────────────────────\n";
$ok('Test 3', $ev2($lt($subject('amount'), $lit(2000))) === $permit);

echo "── Test 4 — lt false → deny ────────────────────────────────\n";
$ok('Test 4', $ev2($lt($subject('amount'), $lit(500))) === $deny);

echo "── Test 5 — not(eq) inverts ────────────────────────────────\n";
$ok('Test 5 — not(true) → deny', $ev2($not($eq($actor('role'), $lit('manager')))) === $deny);
$ok('Test 5 — not(false) → permit', $ev2($not($eq($actor('role'), $lit('admin')))) === $permit);

echo "── Test 6 — and(true,true,true) → permit ───────────────────\n";
$ok('Test 6', $ev2($and(
    $eq($actor('role'), $lit('manager')),
    $lt($subject('amount'), $lit(2000)),
    $eq($subject('status'), $lit('draft')),
)) === $permit);

echo "── Test 7 — and(true,false,true) → deny ────────────────────\n";
$ok('Test 7', $ev2($and(
    $eq($actor('role'), $lit('manager')),
    $eq($subject('status'), $lit('approved')),
    $eq($subject('amount'), $lit(1000)),
)) === $deny);

echo "── Test 8 — nested fact subject.customer.country ───────────\n";
$ok('Test 8', $ev2($eq($subject('customer.country'), $lit('FR'))) === $permit);

echo "── Test 9 — absent fact → fail closed (deny) ───────────────\n";
$ok('Test 9', $ev2($eq($subject('missing'), $lit(1))) === $deny);

echo "── Test 10 — absent intermediate branch → fail closed ──────\n";
$ok('Test 10', $ev2($eq($actor('department.name'), $lit('x'))) === $deny);

echo "── Test 11 — strict types 1 === \"1\" → false → deny ─────────\n";
$ok('Test 11', $ev2($eq($lit(1), $lit('1'))) === $deny);

echo "── Test 12 — complex and(eq, not(eq)) ──────────────────────\n";
$complex = $and($eq($actor('role'), $lit('manager')), $not($eq($subject('status'), $lit('approved'))));
$ok('Test 12 — status draft → permit', $ev2($complex) === $permit);
$factsApproved = $facts;
$factsApproved['subject']['status'] = 'approved';
$ok('Test 12 — status approved → deny', $ev->evaluate($complex, $factsApproved) === $deny);

echo "── Test 13 — and short-circuits at first deny ──────────────\n";
// First operand denies; result is deny regardless of the later (would-be permit) operand.
$ok('Test 13', $ev2($and($eq($lit(1), $lit(2)), $eq($actor('role'), $lit('manager')))) === $deny);

echo "── RELEASE-001 — sugar operators evaluate (same semantics as canonicalization) ─\n";
// facts: subject.amount = 1000
$ok('gt(amount, 1) → permit', $ev2(new Comparison(Comparator::Gt, $subject('amount'), $lit(1))) === $permit);
$ok('gt(amount, 5000) → deny', $ev2(new Comparison(Comparator::Gt, $subject('amount'), $lit(5000))) === $deny);
$ok('gte(amount, 1000) → permit', $ev2(new Comparison(Comparator::Gte, $subject('amount'), $lit(1000))) === $permit);
$ok('lte(amount, 1000) → permit', $ev2(new Comparison(Comparator::Lte, $subject('amount'), $lit(1000))) === $permit);
$ok('lte(amount, 999) → deny', $ev2(new Comparison(Comparator::Lte, $subject('amount'), $lit(999))) === $deny);
$ok('ne(amount, 1) → permit', $ev2(new Comparison(Comparator::Ne, $subject('amount'), $lit(1))) === $permit);
$ok('ne(amount, 1000) → deny', $ev2(new Comparison(Comparator::Ne, $subject('amount'), $lit(1000))) === $deny);
$ok('in(amount, 1000) → permit (singleton)', $ev2(new Comparison(Comparator::In, $subject('amount'), $lit(1000))) === $permit);
$ok('or(false, true) → permit', $ev2(new Logical(LogicalOp::Or, [$eq($subject('amount'), $lit(1)), $eq($subject('amount'), $lit(1000))])) === $permit);
$ok('or(false, false) → deny', $ev2(new Logical(LogicalOp::Or, [$eq($subject('amount'), $lit(1)), $eq($subject('amount'), $lit(2))])) === $deny);
// equivalence: the DSL-natural sugar form == its primitive rewrite
$ok('gte(a,b) ≡ not(lt(a,b))',
    $ev2(new Comparison(Comparator::Gte, $subject('amount'), $lit(500))) === $ev2($not(new Comparison(Comparator::Lt, $subject('amount'), $lit(500)))));
$ok('gt(a,b) ≡ not(or(lt,eq))',
    $ev2(new Comparison(Comparator::Gt, $subject('amount'), $lit(500)))
    === $ev2($not(new Logical(LogicalOp::Or, [new Comparison(Comparator::Lt, $subject('amount'), $lit(500)), new Comparison(Comparator::Eq, $subject('amount'), $lit(500))]))));
$ok('fail-closed preserved: sugar with unresolved fact → deny', $ev2(new Comparison(Comparator::Gt, $subject('missing'), $lit(1))) === $deny);
$ok('evaluate never throws (unresolved → deny)', $ev2($eq($subject('a.b.c.d'), $lit(1))) === $deny);

echo "\n";
echo $fail === 0
    ? "PHASE 8 / AuthorizationEvaluator OK — {$pass} checks passed\n"
    : "PHASE 8 / AuthorizationEvaluator FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
