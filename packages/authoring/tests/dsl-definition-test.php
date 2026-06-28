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
 * IMPLEMENTATION-001 Phase 5A — DSL → RFC-012 DTO construction (Tests 1–7).
 */

use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\ActionDefinition;
use Ausus\Definition\EntityDefinition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\ExpandSpec;
use Ausus\Definition\ExposedField;
use Ausus\Definition\Expression\Comparison;
use Ausus\Definition\FieldDefinition;
use Ausus\Definition\ProjectionDefinition;
use Ausus\Definition\TransitionSpec;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

echo "── Test 1 — minimal construction ───────────────────────────\n";
$d = Definition::make('invoice')->build();
$ok('Test 1 — build() returns EntityDefinition', $d instanceof EntityDefinition);
$ok('Test 1 — identity + default tenantScoped=false', $d->identity === 'invoice' && $d->tenantScoped === false);
$ok('Test 1 — empty lists', $d->fields === [] && $d->actions === [] && $d->projections === []);
$ok('Test 1 — tenantScoped=true honored', Definition::make('x', true)->build()->tenantScoped === true);

echo "── Test 2 — field enum ─────────────────────────────────────\n";
$d = Definition::make('invoice')
    ->field('status', FieldType::Enum, ['default' => 'draft', 'writeProtected' => true, 'typeOptions' => ['values' => ['draft', 'approved']]])
    ->build();
$f = $d->fields[0];
$ok('Test 2 — FieldDefinition', $f instanceof FieldDefinition && $f->type === FieldType::Enum);
$ok('Test 2 — enum values + default + writeProtected', $f->typeOptions['values'] === ['draft', 'approved'] && $f->default === 'draft' && $f->writeProtected === true);

echo "── Test 3 — field reference ────────────────────────────────\n";
$d = Definition::make('invoice')->field('buyer', FieldType::Reference, ['nullable' => true, 'typeOptions' => ['target' => 'customer']])->build();
$f = $d->fields[0];
$ok('Test 3 — reference target + nullable', $f->type === FieldType::Reference && $f->typeOptions['target'] === 'customer' && $f->nullable === true);

echo "── Test 4 — action with guard ──────────────────────────────\n";
$guard = Expr::gte(Expr::actor('limit'), Expr::subject('amount'));
$d = Definition::make('invoice')->action('approve', ActionKind::Create, ['inputs' => ['amount'], 'guard' => $guard])->build();
$a = $d->actions[0];
$ok('Test 4 — ActionDefinition + inputs', $a instanceof ActionDefinition && $a->inputs === ['amount']);
$ok('Test 4 — guard is the same Expression', $a->guard === $guard && $a->guard instanceof Comparison);

echo "── Test 5 — action transition ──────────────────────────────\n";
$d = Definition::make('invoice')->action('approve', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'draft', 'to' => 'approved']])->build();
$a = $d->actions[0];
$ok('Test 5 — kind transition', $a->kind === ActionKind::Transition);
$ok('Test 5 — TransitionSpec from string → list', $a->transition instanceof TransitionSpec && $a->transition->from === ['draft'] && $a->transition->to === 'approved');
$d2 = Definition::make('invoice')->action('a', ActionKind::Transition, ['transition' => ['field' => 's', 'from' => ['draft', 'pending'], 'to' => 'done']])->build();
$ok('Test 5 — from array preserved', $d2->actions[0]->transition->from === ['draft', 'pending']);

echo "── Test 6 — projection with visibility ─────────────────────\n";
$vis = Expr::eq(Expr::actor('role'), 'admin');
$d = Definition::make('invoice')->projection('board', ['fields' => [['field' => 'amount'], ['field' => 'secret', 'visibility' => $vis]]])->build();
$p = $d->projections[0];
$ok('Test 6 — ProjectionDefinition + exposed fields', $p instanceof ProjectionDefinition && $p->fields[0] instanceof ExposedField && $p->fields[0]->field === 'amount');
$ok('Test 6 — visibility carried; default null', $p->fields[1]->visibility === $vis && $p->fields[0]->visibility === null);

echo "── Test 7 — projection with expand ─────────────────────────\n";
$d = Definition::make('invoice')->projection('board', ['fields' => [['field' => 'amount']], 'expand' => [['via' => 'buyer', 'projection' => 'card']]])->build();
$p = $d->projections[0];
$ok('Test 7 — ExpandSpec single-hop', $p->expand[0] instanceof ExpandSpec && $p->expand[0]->via === 'buyer' && $p->expand[0]->projection === 'card');

echo "\n";
echo $fail === 0
    ? "PHASE 5A / Definition OK — {$pass} checks passed\n"
    : "PHASE 5A / Definition FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
