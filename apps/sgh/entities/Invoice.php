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
        'typeOptions' => ['values' => ['draft', 'validated', 'paid']],
    ])
    ->field('admission', FieldType::Reference, ['nullable' => true, 'typeOptions' => ['target' => 'admission']])
    ->field('patient', FieldType::Reference, ['typeOptions' => ['target' => 'patient']])
    // guard (input): reject absurd totals (PRIMITIVE lt — sugar operators silently deny at runtime)
    ->action('create', ActionKind::Create, [
        'inputs' => ['number', 'total', 'admission', 'patient'],
        'guard' => Expr::lt(Expr::input('total'), 1000000),
    ])
    // guard (subject): total must be ≥ 1, expressed with primitives only (not(total < 1))
    ->action('validate', ActionKind::Transition, [
        'guard' => Expr::not(Expr::lt(Expr::subject('total'), 1)),
        'transition' => ['field' => 'status', 'from' => 'draft', 'to' => 'validated'],
    ])
    ->action('markPaid', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'validated', 'to' => 'paid']])
    // flat board — single-hop expand target
    ->projection('board', ['fields' => [['field' => 'number'], ['field' => 'total'], ['field' => 'status']]])
    ->projection('detail', [
        'fields' => [['field' => 'number'], ['field' => 'total'], ['field' => 'status']],
        'expand' => [
            ['via' => 'patient', 'projection' => 'board'],
            ['via' => 'admission', 'projection' => 'board'],
        ],
    ])
    ->build();
