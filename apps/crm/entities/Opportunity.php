<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('opportunity', true)
    ->field('title', FieldType::String)
    ->field('amount', FieldType::Decimal)
    ->field('stage', FieldType::Enum, [
        'default' => 'open',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['open', 'qualified', 'won', 'lost']],
    ])
    ->field('customer', FieldType::Reference, ['nullable' => true, 'typeOptions' => ['target' => 'customer']])
    // guard: reject absurd amounts (permit for amount < 1,000,000).
    ->action('create', ActionKind::Create, [
        'inputs' => ['title', 'amount', 'customer'],
        'guard' => Expr::lt(Expr::input('amount'), 1000000),
    ])
    ->action('qualify', ActionKind::Transition, [
        'transition' => ['field' => 'stage', 'from' => 'open', 'to' => 'qualified'],
    ])
    // guard: only a manager may win a deal (deny for actor.type=user).
    ->action('win', ActionKind::Transition, [
        'guard' => Expr::eq(Expr::actor('type'), 'manager'),
        'transition' => ['field' => 'stage', 'from' => 'qualified', 'to' => 'won'],
    ])
    ->action('lose', ActionKind::Transition, [
        'transition' => ['field' => 'stage', 'from' => ['open', 'qualified'], 'to' => 'lost'],
    ])
    ->projection('pipeline', [
        'fields' => [['field' => 'title'], ['field' => 'amount'], ['field' => 'stage']],
        'expand' => [['via' => 'customer', 'projection' => 'board']],
    ])
    ->projection('detail', [
        'fields' => [['field' => 'title'], ['field' => 'amount'], ['field' => 'stage']],
        'expand' => [['via' => 'customer', 'projection' => 'detail']],
    ])
    ->build();
