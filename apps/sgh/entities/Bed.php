<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('bed', true)
    ->field('number', FieldType::String)
    ->field('ward', FieldType::String)
    ->field('status', FieldType::Enum, [
        'default' => 'free',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['free', 'occupied', 'maintenance']],
    ])
    ->field('department', FieldType::Reference, ['typeOptions' => ['target' => 'department']])
    ->action('create', ActionKind::Create, ['inputs' => ['number', 'ward', 'department']])
    ->action('occupy', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'free', 'to' => 'occupied']])
    ->action('release', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'occupied', 'to' => 'free']])
    ->action('maintenance', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'free', 'to' => 'maintenance']])
    // flat board — single-hop expand target
    ->projection('board', ['fields' => [['field' => 'number'], ['field' => 'ward'], ['field' => 'status']]])
    ->projection('detail', [
        'fields' => [['field' => 'number'], ['field' => 'ward'], ['field' => 'status']],
        'expand' => [['via' => 'department', 'projection' => 'board']],
    ])
    ->build();
