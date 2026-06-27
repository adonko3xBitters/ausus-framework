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
 * IMPLEMENTATION-001 Phase 5A — Test 10: DSL ≡ EntityDefinition RFC-012.
 *
 * Build the SAME entity twice — once via the raw RFC-012 DTOs, once via the DSL
 * — and assert value-equality ($dto == $dsl).
 */

use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\ActionDefinition;
use Ausus\Definition\EntityDefinition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\Comparator;
use Ausus\Definition\Enum\FactSource;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\ExpandSpec;
use Ausus\Definition\ExposedField;
use Ausus\Definition\Expression\Comparison;
use Ausus\Definition\Expression\FactRef;
use Ausus\Definition\Expression\Literal;
use Ausus\Definition\FieldDefinition;
use Ausus\Definition\ProjectionDefinition;
use Ausus\Definition\TransitionSpec;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

// (1) via raw RFC-012 DTOs --------------------------------------------------
$guard = new Comparison(Comparator::Gte, new FactRef(FactSource::Actor, 'limit'), new FactRef(FactSource::Subject, 'amount'));
$vis   = new Comparison(Comparator::Eq, new FactRef(FactSource::Actor, 'role'), new Literal('admin'));
$dto = new EntityDefinition('invoice', true,
    [
        new FieldDefinition('amount', FieldType::Decimal, false),
        new FieldDefinition('status', FieldType::Enum, false, 'draft', true, ['values' => ['draft', 'approved']]),
        new FieldDefinition('buyer', FieldType::Reference, true, null, false, ['target' => 'customer']),
    ],
    [
        new ActionDefinition('create', ActionKind::Create, ['amount', 'buyer']),
        new ActionDefinition('approve', ActionKind::Transition, [], $guard, new TransitionSpec('status', ['draft'], 'approved')),
    ],
    [
        new ProjectionDefinition('board',
            [new ExposedField('amount'), new ExposedField('status', $vis)],
            [new ExpandSpec('buyer', 'card')]),
    ]);

// (2) via the DSL -----------------------------------------------------------
$dsl = Definition::make('invoice', true)
    ->field('amount', FieldType::Decimal)
    ->field('status', FieldType::Enum, ['default' => 'draft', 'writeProtected' => true, 'typeOptions' => ['values' => ['draft', 'approved']]])
    ->field('buyer', FieldType::Reference, ['nullable' => true, 'typeOptions' => ['target' => 'customer']])
    ->action('create', ActionKind::Create, ['inputs' => ['amount', 'buyer']])
    ->action('approve', ActionKind::Transition, [
        'guard'      => Expr::gte(Expr::actor('limit'), Expr::subject('amount')),
        'transition' => ['field' => 'status', 'from' => 'draft', 'to' => 'approved'],
    ])
    ->projection('board', [
        'fields' => [['field' => 'amount'], ['field' => 'status', 'visibility' => Expr::eq(Expr::actor('role'), 'admin')]],
        'expand' => [['via' => 'buyer', 'projection' => 'card']],
    ])
    ->build();

echo "── Test 10 — DSL ≡ RFC-012 DTO ─────────────────────────────\n";
$ok('Test 10 — both are EntityDefinition', $dto instanceof EntityDefinition && $dsl instanceof EntityDefinition);
$ok('Test 10 — $dto == $dsl (value equality)', $dto == $dsl);
$ok('Test 10 — distinct instances ($dto !== $dsl)', $dto !== $dsl);
$ok('Test 10 — guard sub-tree equal', $dto->actions[1]->guard == $dsl->actions[1]->guard);
$ok('Test 10 — visibility sub-tree equal', $dto->projections[0]->fields[1]->visibility == $dsl->projections[0]->fields[1]->visibility);

echo "\n";
echo $fail === 0
    ? "PHASE 5A / Equivalence OK — {$pass} checks passed\n"
    : "PHASE 5A / Equivalence FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
