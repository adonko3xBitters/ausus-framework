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
 * IMPLEMENTATION-001 Phase 4 — ClosureValidator (16 RFC-012 §Q6 invariants).
 */

use Ausus\Definition\ActionDefinition;
use Ausus\Definition\EntityDefinition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\Comparator;
use Ausus\Definition\Enum\FactSource;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\Enum\LogicalOp;
use Ausus\Definition\ExpandSpec;
use Ausus\Definition\ExposedField;
use Ausus\Definition\Expression\Comparison;
use Ausus\Definition\Expression\FactRef;
use Ausus\Definition\Expression\Literal;
use Ausus\Definition\Expression\Logical;
use Ausus\Definition\FieldDefinition;
use Ausus\Definition\ProjectionDefinition;
use Ausus\Definition\TransitionSpec;
use Ausus\Engine\Compile\ClosureValidator;
use Ausus\Engine\Compile\CompilationError;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

$v = new ClosureValidator();
$err = function (string $label, array $defs) use ($v, $ok): void {
    try {
        $v->validate($defs);
        $ok($label . ' → CompilationError', false);
    } catch (CompilationError $e) {
        $ok($label . '  (' . $e->getMessage() . ')', true);
    }
};

// Reusable valid building blocks ---------------------------------------------
$str  = fn (string $n): FieldDefinition => new FieldDefinition($n, FieldType::String, false);
$dec  = fn (string $n): FieldDefinition => new FieldDefinition($n, FieldType::Decimal, false);
$enum = fn (string $n, array $vals, ?string $def = null, bool $wp = false): FieldDefinition =>
    new FieldDefinition($n, FieldType::Enum, false, $def, $wp, ['values' => $vals]);
$ref  = fn (string $n, string $target): FieldDefinition =>
    new FieldDefinition($n, FieldType::Reference, true, null, false, ['target' => $target]);

$customer = fn (): EntityDefinition => new EntityDefinition('customer', true,
    [$str('name')], [new ActionDefinition('create', ActionKind::Create, ['name'])],
    [new ProjectionDefinition('card', [new ExposedField('name')])]);

echo "── Family A — references ───────────────────────────────────\n";
// A1: reference target absent
$err('TEST A1 — reference target absent', [
    new EntityDefinition('invoice', true, [$ref('buyer', 'ghost')], [], []),
]);
// A2: enum empty
$err('TEST A2 — enum empty', [
    new EntityDefinition('invoice', true, [$enum('status', [])], [], []),
]);
// A3: FieldRef (input) unknown
$err('TEST A3 — FieldRef unknown', [
    new EntityDefinition('invoice', true, [$dec('amount')], [new ActionDefinition('create', ActionKind::Create, ['nope'])], []),
]);

echo "── Family B — actions ──────────────────────────────────────\n";
// B1: transition to non-existent state
$err('TEST B1 — transition to unknown state', [
    new EntityDefinition('invoice', true, [$enum('status', ['draft', 'approved'], 'draft')],
        [new ActionDefinition('move', ActionKind::Transition, [], null, new TransitionSpec('status', ['draft'], 'shipped'))], []),
]);
// B2: writeProtected in create
$err('TEST B2 — writeProtected in create', [
    new EntityDefinition('invoice', true, [$enum('status', ['draft', 'approved'], 'draft', true)],
        [new ActionDefinition('create', ActionKind::Create, ['status'])], []),
]);
// B3: kind/transition incoherent (transition kind, no spec)
$err('TEST B3 — kind incoherent', [
    new EntityDefinition('invoice', true, [$enum('status', ['draft', 'approved'], 'draft')],
        [new ActionDefinition('move', ActionKind::Transition, [], null, null)], []),
]);

echo "── Family C — expressions ──────────────────────────────────\n";
// C1: FactRef (subject) unknown
$err('TEST C1 — FactRef unknown', [
    new EntityDefinition('invoice', true, [$dec('amount')],
        [new ActionDefinition('a', ActionKind::Create, [], new Comparison(Comparator::Eq, new FactRef(FactSource::Subject, 'ghost'), new Literal(1)))], []),
]);
// C2: lt on bool/string
$err('TEST C2 — lt on bool', [
    new EntityDefinition('invoice', true, [$dec('amount')],
        [new ActionDefinition('a', ActionKind::Create, [], new Comparison(Comparator::Lt, new Literal(true), new Literal(false)))], []),
]);
// C3: malformed expression (not with 2 operands)
$err('TEST C3 — malformed expression', [
    new EntityDefinition('invoice', true, [$dec('amount')],
        [new ActionDefinition('a', ActionKind::Create, [], new Logical(LogicalOp::Not, [
            new Comparison(Comparator::Eq, new FactRef(FactSource::Subject, 'amount'), new Literal(1)),
            new Comparison(Comparator::Eq, new FactRef(FactSource::Subject, 'amount'), new Literal(2)),
        ]))], []),
]);

echo "── Family D — projections ──────────────────────────────────\n";
// D1: expand to absent projection
$err('TEST D1 — expand to absent projection', [
    $customer(),
    new EntityDefinition('invoice', true, [$ref('buyer', 'customer')], [],
        [new ProjectionDefinition('board', [], [new ExpandSpec('buyer', 'ghostcard')])]),
]);
// D2: expand depth > 1
$agent = new EntityDefinition('agent', true, [$str('an')], [], [new ProjectionDefinition('mini', [new ExposedField('an')])]);
$customerDeep = new EntityDefinition('customer', true, [$str('cname'), $ref('rep', 'agent')], [],
    [new ProjectionDefinition('card', [new ExposedField('cname')], [new ExpandSpec('rep', 'mini')])]);
$err('TEST D2 — expand depth > 1', [
    $agent, $customerDeep,
    new EntityDefinition('invoice', true, [$ref('buyer', 'customer')], [],
        [new ProjectionDefinition('board', [], [new ExpandSpec('buyer', 'card')])]),
]);

echo "── Family F — identity ─────────────────────────────────────\n";
// F1: duplicate EntityId
$err('TEST F1 — duplicate EntityId', [
    new EntityDefinition('invoice', true, [$dec('amount')], [], []),
    new EntityDefinition('invoice', true, [$str('x')], [], []),
]);

echo "── Happy path ──────────────────────────────────────────────\n";
// G1: a fully valid two-entity graph (covers every invariant positively)
$invoice = new EntityDefinition('invoice', true,
    [$dec('amount'), $enum('status', ['draft', 'approved'], 'draft', true), $ref('buyer', 'customer')],
    [
        new ActionDefinition('create', ActionKind::Create, ['amount', 'buyer']),
        new ActionDefinition('approve', ActionKind::Transition, [],
            new Comparison(Comparator::Gte, new FactRef(FactSource::Actor, 'limit'), new FactRef(FactSource::Subject, 'amount')),
            new TransitionSpec('status', ['draft'], 'approved')),
    ],
    [new ProjectionDefinition('board', [new ExposedField('amount'), new ExposedField('status')], [new ExpandSpec('buyer', 'card')])]);
try {
    $v->validate([$customer(), $invoice]);
    $ok('TEST G1 — valid graph → success (void)', true);
} catch (CompilationError $e) {
    $ok('TEST G1 — valid graph → success, got: ' . $e->getMessage(), false);
}

echo "\n";
echo $fail === 0
    ? "PHASE 4 / ClosureValidator OK — {$pass} checks passed\n"
    : "PHASE 4 / ClosureValidator FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
