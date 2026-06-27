<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('user', true)
    ->field('name', FieldType::String)
    ->field('email', FieldType::String)
    ->action('create', ActionKind::Create, ['inputs' => ['name', 'email']])
    ->projection('board', ['fields' => [['field' => 'name'], ['field' => 'email']]])
    ->build();
