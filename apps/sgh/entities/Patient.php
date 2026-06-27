<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('patient', true)
    ->field('firstName', FieldType::String)
    ->field('lastName', FieldType::String)
    ->field('dob', FieldType::Date)
    ->field('gender', FieldType::Enum, ['default' => 'other', 'typeOptions' => ['values' => ['m', 'f', 'other']]])
    ->field('phone', FieldType::String, ['nullable' => true])
    ->field('status', FieldType::Enum, [
        'default' => 'active',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['active', 'inactive']],
    ])
    ->action('create', ActionKind::Create, ['inputs' => ['firstName', 'lastName', 'dob', 'gender', 'phone']])
    ->action('deactivate', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'active', 'to' => 'inactive']])
    ->action('reactivate', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'inactive', 'to' => 'active']])
    // flat board — single-hop expand target
    ->projection('board', ['fields' => [['field' => 'firstName'], ['field' => 'lastName'], ['field' => 'status']]])
    ->projection('detail', ['fields' => [
        ['field' => 'firstName'], ['field' => 'lastName'], ['field' => 'dob'], ['field' => 'gender'], ['field' => 'phone'], ['field' => 'status'],
    ]])
    ->build();
