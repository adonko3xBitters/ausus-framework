<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('roomtype', true)
    ->field('name', FieldType::String)
    ->field('capacity', FieldType::Integer)
    ->field('baseRate', FieldType::Decimal)
    ->action('create', ActionKind::Create, ['inputs' => ['name', 'capacity', 'baseRate']])
    // flat board — used as a single-hop expand target by Room.detail
    ->projection('board', ['fields' => [['field' => 'name'], ['field' => 'capacity'], ['field' => 'baseRate']]])
    ->build();
