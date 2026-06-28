<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('user', true)
    ->field('name', FieldType::String)
    ->field('email', FieldType::String)
    // 'role' is stored, but NOT available to guards (guards see only actor.type/id/homeTenant).
    ->field('role', FieldType::Enum, ['default' => 'clerk', 'typeOptions' => ['values' => ['clerk', 'manager', 'admin']]])
    ->action('create', ActionKind::Create, ['inputs' => ['name', 'email', 'role']])
    ->projection('board', ['fields' => [['field' => 'name'], ['field' => 'email'], ['field' => 'role']]])
    ->build();
