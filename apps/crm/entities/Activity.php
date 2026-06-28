<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('activity', true)
    // 'type' is a category set at creation (not a state machine) → not writeProtected.
    ->field('type', FieldType::Enum, [
        'default' => 'note',
        'typeOptions' => ['values' => ['call', 'email', 'meeting', 'note']],
    ])
    ->field('subject', FieldType::String)
    ->field('customer', FieldType::Reference, ['nullable' => true, 'typeOptions' => ['target' => 'customer']])
    ->action('create', ActionKind::Create, ['inputs' => ['type', 'subject', 'customer']])
    ->projection('board', [
        'fields' => [['field' => 'type'], ['field' => 'subject']],
        'expand' => [['via' => 'customer', 'projection' => 'board']],
    ])
    ->build();
