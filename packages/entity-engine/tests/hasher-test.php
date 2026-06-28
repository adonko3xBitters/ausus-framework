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
 * IMPLEMENTATION-001 Phase 3 — Hasher (RFC-012 §Q7 content hash).
 *
 * Scope: ONLY hashing a canonical definition. No closure, no schema, no disk.
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
use Ausus\Definition\Expression\Logical;
use Ausus\Definition\FieldDefinition;
use Ausus\Definition\TransitionSpec;
use Ausus\Engine\Compile\Canonicalizer;
use Ausus\Engine\Compile\Hasher;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

$canon  = new Canonicalizer();
$hasher = new Hasher();
$h = fn (EntityDefinition $d): string => $hasher->hash($canon->canonicalize($d));
$hExpr = fn (Expression $e): string => $hasher->hash(
    $canon->canonicalize(new EntityDefinition('e', false, [], [new ActionDefinition('g', ActionKind::Create, [], $e)], []))
);

$field  = fn (string $n, FieldType $t = FieldType::String, array $opts = []): FieldDefinition => new FieldDefinition($n, $t, false, null, false, $opts);
$action = fn (string $n): ActionDefinition => new ActionDefinition($n, ActionKind::Create);
$x = new FactRef(FactSource::Subject, 'x');
$y = new FactRef(FactSource::Subject, 'y');
$cmp = fn (Comparator $op): Expression => new Comparison($op, $x, $y);

echo "── TEST 1 — same definition → same hash ─────────────────────\n";
$d1 = new EntityDefinition('invoice', true, [$field('amount'), $field('status')], [$action('create')], []);
$d2 = new EntityDefinition('invoice', true, [$field('amount'), $field('status')], [$action('create')], []);
$ok('TEST 1', $h($d1) === $h($d2));

echo "── TEST 2 — field order differs → same hash ─────────────────\n";
$abc = new EntityDefinition('e', false, [$field('A'), $field('B'), $field('C')], [], []);
$cba = new EntityDefinition('e', false, [$field('C'), $field('B'), $field('A')], [], []);
$ok('TEST 2', $h($abc) === $h($cba));

echo "── TEST 3 — action order differs → same hash ────────────────\n";
$a1 = new EntityDefinition('e', false, [], [$action('alpha'), $action('beta'), $action('gamma')], []);
$a2 = new EntityDefinition('e', false, [], [$action('gamma'), $action('alpha'), $action('beta')], []);
$ok('TEST 3', $h($a1) === $h($a2));

echo "── TEST 4 — gt(x,y) === and(not(lt),not(eq)) → same hash ────\n";
// RFC-012 Q5: gt = ¬(lt ∨ eq) which the Canonicalizer normalizes to and(not lt, not eq).
$gt   = $cmp(Comparator::Gt);
$prim = new Logical(LogicalOp::And, [
    new Logical(LogicalOp::Not, [$cmp(Comparator::Lt)]),
    new Logical(LogicalOp::Not, [$cmp(Comparator::Eq)]),
]);
$ok('TEST 4', $hExpr($gt) === $hExpr($prim));

echo "── TEST 5 — integer → decimal → different hash ──────────────\n";
$int = new EntityDefinition('e', false, [$field('amount', FieldType::Integer)], [], []);
$dec = new EntityDefinition('e', false, [$field('amount', FieldType::Decimal)], [], []);
$ok('TEST 5', $h($int) !== $h($dec));

echo "── TEST 6 — transition target changes → different hash ──────\n";
$tr = fn (string $to): EntityDefinition => new EntityDefinition('e', false, [], [
    new ActionDefinition('move', ActionKind::Transition, [], null, new TransitionSpec('state', ['draft'], $to)),
], []);
$ok('TEST 6', $h($tr('approved')) !== $h($tr('rejected')));

echo "── TEST 7 — guard changes → different hash ──────────────────\n";
$g1 = new EntityDefinition('e', false, [], [new ActionDefinition('a', ActionKind::Create, [], $cmp(Comparator::Lt))], []);
$g2 = new EntityDefinition('e', false, [], [new ActionDefinition('a', ActionKind::Create, [], $cmp(Comparator::Gt))], []);
$ok('TEST 7', $h($g1) !== $h($g2));

echo "── TEST 8 — enum.values order reversed → different hash ─────\n";
$ev1 = new EntityDefinition('e', false, [$field('s', FieldType::Enum, ['values' => ['draft', 'approved']])], [], []);
$ev2 = new EntityDefinition('e', false, [$field('s', FieldType::Enum, ['values' => ['approved', 'draft']])], [], []);
$ok('TEST 8', $h($ev1) !== $h($ev2));

echo "── TEST 9 — transition.from order reversed → different hash ─\n";
$fr = fn (array $from): EntityDefinition => new EntityDefinition('e', false, [], [
    new ActionDefinition('m', ActionKind::Transition, [], null, new TransitionSpec('state', $from, 'done')),
], []);
$ok('TEST 9', $h($fr(['draft', 'pending'])) !== $h($fr(['pending', 'draft'])));

echo "── TEST 10 — two independent processes → same value ────────\n";
$fixture = new EntityDefinition('invoice', true,
    [$field('amount', FieldType::Decimal), $field('status', FieldType::Enum, ['values' => ['draft', 'approved']])],
    [new ActionDefinition('approve', ActionKind::Transition, [], $cmp(Comparator::Gte), new TransitionSpec('status', ['draft'], 'approved'))],
    []);
$via = $h($fixture);

// (a) In-process independent path: the raw documented algorithm, no Hasher class.
$independent = hash('sha256', (string) json_encode(
    $canon->canonicalize($fixture),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
));
$ok('TEST 10 — Hasher === raw documented algorithm', $via === $independent);

// (b) A genuinely separate OS process rebuilds the identical fixture and hashes it.
$autoloadPath = realpath(__DIR__ . '/../../../vendor/autoload.php');
$prog = "require " . var_export($autoloadPath, true) . ";"
    . "use Ausus\\Definition\\{EntityDefinition,FieldDefinition,ActionDefinition,TransitionSpec};"
    . "use Ausus\\Definition\\Enum\\{FieldType,ActionKind,Comparator,FactSource};"
    . "use Ausus\\Definition\\Expression\\{Comparison,FactRef};"
    . "use Ausus\\Engine\\Compile\\{Canonicalizer,Hasher};"
    . "\$x=new FactRef(FactSource::Subject,'x');\$y=new FactRef(FactSource::Subject,'y');"
    . "\$f=fn(\$n,\$t,\$o=[])=>new FieldDefinition(\$n,\$t,false,null,false,\$o);"
    . "\$d=new EntityDefinition('invoice',true,"
    . "[\$f('amount',FieldType::Decimal),\$f('status',FieldType::Enum,['values'=>['draft','approved']])],"
    . "[new ActionDefinition('approve',ActionKind::Transition,[],new Comparison(Comparator::Gte,\$x,\$y),new TransitionSpec('status',['draft'],'approved'))],"
    . "[]);"
    . "echo (new Hasher())->hash((new Canonicalizer())->canonicalize(\$d));";
$other = is_string($autoloadPath) ? trim((string) shell_exec('php -r ' . escapeshellarg($prog))) : '';
$ok('TEST 10 — separate process reproduces the hash', $other !== '' && $other === $via);
$ok('TEST 10 — SHA-256 shape (64 lowercase hex)', (bool) preg_match('/^[0-9a-f]{64}$/', $via));

echo "\n";
echo $fail === 0
    ? "PHASE 3 OK — {$pass} checks passed\n"
    : "PHASE 3 FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
