<?php
declare(strict_types=1);

$autoload = [
    __DIR__ . '/../../../vendor/autoload.php', // monorepo dev
    __DIR__ . '/../vendor/autoload.php',        // installed package
];
foreach ($autoload as $f) {
    if (file_exists($f)) {
        require $f;
        break;
    }
}

/**
 * IMPLEMENTATION-001 Phase 2 — Canonicalizer (RFC-012 §Q7).
 *
 * Scope: ONLY semantic normalization. No hash, no closure, no disk.
 */

use Ausus\Definition\ActionDefinition;
use Ausus\Definition\EntityDefinition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\Comparator;
use Ausus\Definition\Enum\FactSource;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\Enum\LogicalOp;
use Ausus\Definition\Expression\Comparison;
use Ausus\Definition\Expression\Expression;
use Ausus\Definition\Expression\FactRef;
use Ausus\Definition\FieldDefinition;
use Ausus\Engine\Compile\Canonicalizer;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

$c = new Canonicalizer();

// Helpers ---------------------------------------------------------------------
$x = new FactRef(FactSource::Subject, 'x');
$y = new FactRef(FactSource::Subject, 'y');
$a = fn (): Expression => new Comparison(Comparator::Eq, new FactRef(FactSource::Actor, 'a'), new FactRef(FactSource::Input, 'a'));
$b = fn (): Expression => new Comparison(Comparator::Lt, new FactRef(FactSource::Actor, 'b'), new FactRef(FactSource::Input, 'b'));
$field = fn (string $n, array $opts = []): FieldDefinition => new FieldDefinition($n, FieldType::String, false, null, false, $opts);
$action = fn (string $n): ActionDefinition => new ActionDefinition($n, ActionKind::Create);
$cmp = fn (Comparator $op): Expression => new Comparison($op, $x, $y);
$canon = fn (Expression $e): array => (new Canonicalizer())->canonicalize(
    new EntityDefinition('e', false, [], [new ActionDefinition('g', ActionKind::Create, [], $e)], [])
)['actions'][0]['guard'];

echo "── TEST 1 — identical definitions → identical canonical form ─\n";
$d1 = new EntityDefinition('invoice', true, [$field('amount'), $field('status')], [$action('create')], []);
$d2 = new EntityDefinition('invoice', true, [$field('amount'), $field('status')], [$action('create')], []);
$ok('TEST 1', $c->canonicalize($d1) === $c->canonicalize($d2));

echo "── TEST 2 — field order A,B,C vs C,B,A → same ───────────────\n";
$abc = new EntityDefinition('e', false, [$field('A'), $field('B'), $field('C')], [], []);
$cba = new EntityDefinition('e', false, [$field('C'), $field('B'), $field('A')], [], []);
$ok('TEST 2', $c->canonicalize($abc) === $c->canonicalize($cba));

echo "── TEST 3 — action order differs → same ─────────────────────\n";
$ac1 = new EntityDefinition('e', false, [], [$action('alpha'), $action('beta'), $action('gamma')], []);
$ac2 = new EntityDefinition('e', false, [], [$action('gamma'), $action('alpha'), $action('beta')], []);
$ok('TEST 3', $c->canonicalize($ac1) === $c->canonicalize($ac2));

echo "── TEST 4 — gt sugar → primitives (RFC-012 Q5) ──────────────\n";
// RFC-012 Q5: gt = ¬(lt ∨ eq). Both forms normalize to and(not lt, not eq).
$gt          = $cmp(Comparator::Gt);
$correctGt   = new \Ausus\Definition\Expression\Logical(LogicalOp::Not, [
    new \Ausus\Definition\Expression\Logical(LogicalOp::Or, [$cmp(Comparator::Lt), $cmp(Comparator::Eq)]),
]);
$ok('TEST 4 — gt(x,y) === not(or(lt,eq))  [RFC-012 Q5 correct]', $canon($gt) === $canon($correctGt));
// The prompt's literal RHS not(and(lt,eq)) is a DIFFERENT predicate (tautology) — must NOT match.
$promptGt = new \Ausus\Definition\Expression\Logical(LogicalOp::Not, [
    new \Ausus\Definition\Expression\Logical(LogicalOp::And, [$cmp(Comparator::Lt), $cmp(Comparator::Eq)]),
]);
$ok('TEST 4 — gt(x,y) !== not(and(lt,eq))  [prompt slip, documented]', $canon($gt) !== $canon($promptGt));
$ok('TEST 4 — gt only contains primitives {eq,lt,not,and}', usesOnlyPrimitives($canon($gt)));

echo "── TEST 5 — or(a,b) === not(and(not a, not b)) ──────────────\n";
$or  = new \Ausus\Definition\Expression\Logical(LogicalOp::Or, [$a(), $b()]);
$dem = new \Ausus\Definition\Expression\Logical(LogicalOp::Not, [
    new \Ausus\Definition\Expression\Logical(LogicalOp::And, [
        new \Ausus\Definition\Expression\Logical(LogicalOp::Not, [$a()]),
        new \Ausus\Definition\Expression\Logical(LogicalOp::Not, [$b()]),
    ]),
]);
$ok('TEST 5', $canon($or) === $canon($dem));
$ok('TEST 5 — or only contains primitives', usesOnlyPrimitives($canon($or)));

echo "── TEST 6 — enum values order is semantic → differ ──────────\n";
$e1 = new EntityDefinition('e', false, [$field('status', ['values' => ['draft', 'approved']])], [], []);
$e2 = new EntityDefinition('e', false, [$field('status', ['values' => ['approved', 'draft']])], [], []);
$ok('TEST 6', $c->canonicalize($e1) !== $c->canonicalize($e2));

echo "── TEST 7 — transition.from order is semantic → differ ──────\n";
$mk = fn (array $from): EntityDefinition => new EntityDefinition('e', false, [], [
    new ActionDefinition('move', ActionKind::Transition, [], null, new \Ausus\Definition\TransitionSpec('state', $from, 'done')),
], []);
$ok('TEST 7', $c->canonicalize($mk(['draft', 'pending'])) !== $c->canonicalize($mk(['pending', 'draft'])));

echo "── TEST 8 — and(a,b) === and(b,a) (commutative) ─────────────\n";
$ab = new \Ausus\Definition\Expression\Logical(LogicalOp::And, [$a(), $b()]);
$ba = new \Ausus\Definition\Expression\Logical(LogicalOp::And, [$b(), $a()]);
$ok('TEST 8', $canon($ab) === $canon($ba));

echo "── Exclusions — no hash/stamp/timestamp keys leak ───────────\n";
$flat = json_encode($c->canonicalize($d1));
$ok('no hash/version/timestamp keys', $flat !== false
    && !str_contains($flat, 'hash')
    && !str_contains($flat, 'schemaVersion')
    && !str_contains($flat, 'timestamp'));

echo "\n";
echo $fail === 0
    ? "PHASE 2 OK — {$pass} checks passed\n"
    : "PHASE 2 FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

/**
 * Recursively assert a normalized expression tree uses only {eq, lt, not, and}
 * (plus fact/lit operand leaves).
 *
 * @param array<string,mixed> $node
 */
function usesOnlyPrimitives(array $node): bool
{
    $kind = $node['node'] ?? null;
    if (!in_array($kind, ['eq', 'lt', 'not', 'and', 'fact', 'lit'], true)) {
        return false;
    }
    foreach (['args', 'arg'] as $childKey) {
        if (!isset($node[$childKey])) {
            continue;
        }
        $children = $childKey === 'arg' ? [$node[$childKey]] : $node[$childKey];
        foreach ($children as $child) {
            if (is_array($child) && isset($child['node']) && !usesOnlyPrimitives($child)) {
                return false;
            }
        }
    }

    return true;
}
