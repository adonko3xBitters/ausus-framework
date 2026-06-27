<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('room', true)
    ->field('number', FieldType::String)
    ->field('floor', FieldType::Integer)
    ->field('status', FieldType::Enum, [
        'default' => 'available',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['available', 'occupied', 'closed']],
    ])
    ->field('roomType', FieldType::Reference, ['typeOptions' => ['target' => 'roomtype']])
    ->action('create', ActionKind::Create, ['inputs' => ['number', 'floor', 'roomType']])
    ->action('open', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'closed', 'to' => 'available']])
    ->action('close', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'available', 'to' => 'closed']])
    // flat board — single-hop expand target for Reservation/Housekeeping
    ->projection('board', ['fields' => [['field' => 'number'], ['field' => 'floor'], ['field' => 'status']]])
    // detail carries the Room → RoomType single-hop expand
    ->projection('detail', [
        'fields' => [['field' => 'number'], ['field' => 'floor'], ['field' => 'status']],
        'expand' => [['via' => 'roomType', 'projection' => 'board']],
    ])
    ->build();
