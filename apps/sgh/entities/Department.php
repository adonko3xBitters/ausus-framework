<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('department', true)
    ->field('name', FieldType::String)
    ->field('code', FieldType::String)
    ->action('create', ActionKind::Create, ['inputs' => ['name', 'code']])
    ->projection('board', ['fields' => [['field' => 'name'], ['field' => 'code']]])
    ->build();
