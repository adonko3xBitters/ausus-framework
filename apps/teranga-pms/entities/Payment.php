<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('payment', true)
    ->field('amount', FieldType::Decimal)
    ->field('method', FieldType::Enum, ['default' => 'card', 'typeOptions' => ['values' => ['cash', 'card', 'transfer']]])
    ->field('status', FieldType::Enum, [
        'default' => 'pending',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['pending', 'confirmed']],
    ])
    ->field('invoice', FieldType::Reference, ['typeOptions' => ['target' => 'invoice']])
    ->action('register', ActionKind::Create, ['inputs' => ['amount', 'method', 'invoice']])
    // guard (actor-dependent + the DENY scenario): only a manager may confirm a payment
    ->action('confirm', ActionKind::Transition, [
        'guard' => Expr::eq(Expr::actor('type'), 'manager'),
        'transition' => ['field' => 'status', 'from' => 'pending', 'to' => 'confirmed'],
    ])
    // board carries Payment → Invoice single-hop expand
    ->projection('board', [
        'fields' => [['field' => 'amount'], ['field' => 'method'], ['field' => 'status']],
        'expand' => [['via' => 'invoice', 'projection' => 'board']],
    ])
    ->build();
