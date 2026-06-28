<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('hotel', true)
    ->field('name', FieldType::String)
    ->field('code', FieldType::String)
    ->field('status', FieldType::Enum, [
        'default' => 'inactive',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['active', 'inactive']],
    ])
    ->action('create', ActionKind::Create, ['inputs' => ['name', 'code']])
    ->action('activate', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'inactive', 'to' => 'active']])
    ->action('deactivate', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'active', 'to' => 'inactive']])
    ->projection('board', ['fields' => [['field' => 'name'], ['field' => 'code'], ['field' => 'status']]])
    ->build();
