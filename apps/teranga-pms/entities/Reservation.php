<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('reservation', true)
    ->field('code', FieldType::String)
    ->field('checkInDate', FieldType::Date)
    ->field('checkOutDate', FieldType::Date)
    ->field('status', FieldType::Enum, [
        'default' => 'pending',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['pending', 'confirmed', 'cancelled']],
    ])
    ->field('room', FieldType::Reference, ['typeOptions' => ['target' => 'room']])
    ->field('guest', FieldType::Reference, ['typeOptions' => ['target' => 'guest']])
    ->action('create', ActionKind::Create, ['inputs' => ['code', 'checkInDate', 'checkOutDate', 'room', 'guest']])
    ->action('confirm', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'pending', 'to' => 'confirmed']])
    ->action('cancel', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => ['pending', 'confirmed'], 'to' => 'cancelled']])
    // flat board — single-hop expand target for Stay.detail
    ->projection('board', ['fields' => [
        ['field' => 'code'], ['field' => 'checkInDate'], ['field' => 'checkOutDate'], ['field' => 'status'],
    ]])
    // detail carries Reservation → Guest and Reservation → Room single-hop expands
    ->projection('detail', [
        'fields' => [['field' => 'code'], ['field' => 'checkInDate'], ['field' => 'checkOutDate'], ['field' => 'status']],
        'expand' => [
            ['via' => 'guest', 'projection' => 'board'],
            ['via' => 'room', 'projection' => 'board'],
        ],
    ])
    ->build();
