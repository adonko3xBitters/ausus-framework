<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('stay', true)
    ->field('reservation', FieldType::Reference, ['typeOptions' => ['target' => 'reservation']])
    ->field('actualCheckIn', FieldType::Date, ['nullable' => true])
    ->field('actualCheckOut', FieldType::Date, ['nullable' => true])
    ->field('status', FieldType::Enum, [
        'default' => 'in_house',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['in_house', 'checked_out']],
    ])
    // check-in CREATES the stay (records actualCheckIn at creation)
    ->action('checkIn', ActionKind::Create, ['inputs' => ['reservation', 'actualCheckIn']])
    // check-out is a state transition; NOTE: a transition can only flip the state field,
    // so actualCheckOut cannot be stamped here (documented limitation).
    ->action('checkOut', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'in_house', 'to' => 'checked_out']])
    // flat board — single-hop expand target for Invoice.detail
    ->projection('board', ['fields' => [['field' => 'status'], ['field' => 'actualCheckIn']]])
    // detail carries Stay → Reservation single-hop expand
    ->projection('detail', [
        'fields' => [['field' => 'status'], ['field' => 'actualCheckIn'], ['field' => 'actualCheckOut']],
        'expand' => [['via' => 'reservation', 'projection' => 'board']],
    ])
    ->build();
