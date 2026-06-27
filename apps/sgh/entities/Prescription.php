<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('prescription', true)
    ->field('code', FieldType::String)
    ->field('medication', FieldType::String)
    ->field('dosage', FieldType::String)
    ->field('status', FieldType::Enum, [
        'default' => 'active',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['active', 'fulfilled', 'cancelled']],
    ])
    ->field('consultation', FieldType::Reference, ['typeOptions' => ['target' => 'consultation']])
    ->field('patient', FieldType::Reference, ['typeOptions' => ['target' => 'patient']])
    ->action('create', ActionKind::Create, ['inputs' => ['code', 'medication', 'dosage', 'consultation', 'patient']])
    ->action('fulfill', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'active', 'to' => 'fulfilled']])
    ->action('cancel', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'active', 'to' => 'cancelled']])
    // flat board — single-hop expand target
    ->projection('board', ['fields' => [['field' => 'code'], ['field' => 'medication'], ['field' => 'status']]])
    ->projection('detail', [
        'fields' => [['field' => 'code'], ['field' => 'medication'], ['field' => 'dosage'], ['field' => 'status']],
        'expand' => [
            ['via' => 'patient', 'projection' => 'board'],
            ['via' => 'consultation', 'projection' => 'board'],
        ],
    ])
    ->build();
