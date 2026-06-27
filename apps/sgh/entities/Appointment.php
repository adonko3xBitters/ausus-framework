<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('appointment', true)
    ->field('code', FieldType::String)
    ->field('date', FieldType::Date)
    ->field('status', FieldType::Enum, [
        'default' => 'scheduled',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['scheduled', 'confirmed', 'cancelled', 'completed']],
    ])
    ->field('patient', FieldType::Reference, ['typeOptions' => ['target' => 'patient']])
    ->field('doctor', FieldType::Reference, ['typeOptions' => ['target' => 'doctor']])
    // guard (actor): only front-desk clerks book appointments
    ->action('create', ActionKind::Create, [
        'inputs' => ['code', 'date', 'patient', 'doctor'],
        'guard' => Expr::eq(Expr::actor('type'), 'clerk'),
    ])
    ->action('confirm', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'scheduled', 'to' => 'confirmed']])
    ->action('cancel', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => ['scheduled', 'confirmed'], 'to' => 'cancelled']])
    ->action('complete', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'confirmed', 'to' => 'completed']])
    // flat board — single-hop expand target
    ->projection('board', ['fields' => [['field' => 'code'], ['field' => 'date'], ['field' => 'status']]])
    ->projection('detail', [
        'fields' => [['field' => 'code'], ['field' => 'date'], ['field' => 'status']],
        'expand' => [
            ['via' => 'patient', 'projection' => 'board'],
            ['via' => 'doctor', 'projection' => 'board'],
        ],
    ])
    ->build();
