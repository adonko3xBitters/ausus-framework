<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('admission', true)
    ->field('code', FieldType::String)
    ->field('reason', FieldType::String)
    ->field('status', FieldType::Enum, [
        'default' => 'admitted',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['admitted', 'discharged']],
    ])
    ->field('patient', FieldType::Reference, ['typeOptions' => ['target' => 'patient']])
    ->field('bed', FieldType::Reference, ['typeOptions' => ['target' => 'bed']])
    ->field('consultation', FieldType::Reference, ['nullable' => true, 'typeOptions' => ['target' => 'consultation']])
    ->action('create', ActionKind::Create, ['inputs' => ['code', 'reason', 'patient', 'bed', 'consultation']])
    ->action('discharge', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'admitted', 'to' => 'discharged']])
    // flat board — single-hop expand target
    ->projection('board', ['fields' => [['field' => 'code'], ['field' => 'status']]])
    ->projection('detail', [
        'fields' => [['field' => 'code'], ['field' => 'reason'], ['field' => 'status']],
        'expand' => [
            ['via' => 'patient', 'projection' => 'board'],
            ['via' => 'bed', 'projection' => 'board'],
        ],
    ])
    ->build();
