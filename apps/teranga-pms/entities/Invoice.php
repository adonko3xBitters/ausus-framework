<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('invoice', true)
    ->field('number', FieldType::String)
    ->field('total', FieldType::Decimal)
    ->field('status', FieldType::Enum, [
        'default' => 'draft',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['draft', 'validated']],
    ])
    ->field('stay', FieldType::Reference, ['typeOptions' => ['target' => 'stay']])
    // guard (input-dependent + a real permit path): reject absurd totals
    ->action('create', ActionKind::Create, [
        'inputs' => ['number', 'total', 'stay'],
        'guard' => Expr::lt(Expr::input('total'), 1000000),
    ])
    // guard (subject-dependent): cannot validate a non-positive invoice.
    // NB: expressed with PRIMITIVE operators only ({not, lt}) — the runtime
    // AuthorizationEvaluator fail-closes on sugar operators (gt/gte/lte/ne/in/or),
    // so `gt(subject.total, 0)` would always DENY. `not(total < 1)` ≡ total ≥ 1.
    ->action('validate', ActionKind::Transition, [
        'guard' => Expr::not(Expr::lt(Expr::subject('total'), 1)),
        'transition' => ['field' => 'status', 'from' => 'draft', 'to' => 'validated'],
    ])
    // flat board — single-hop expand target for Payment.board
    ->projection('board', ['fields' => [['field' => 'number'], ['field' => 'total'], ['field' => 'status']]])
    // detail carries Invoice → Stay single-hop expand
    ->projection('detail', [
        'fields' => [['field' => 'number'], ['field' => 'total'], ['field' => 'status']],
        'expand' => [['via' => 'stay', 'projection' => 'board']],
    ])
    ->build();
