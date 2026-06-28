<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('task', true)
    ->field('title', FieldType::String)
    ->field('dueDate', FieldType::Date, ['nullable' => true])
    ->field('status', FieldType::Enum, [
        'default' => 'open',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['open', 'done']],
    ])
    ->field('owner', FieldType::Reference, ['nullable' => true, 'typeOptions' => ['target' => 'user']])
    ->action('create', ActionKind::Create, ['inputs' => ['title', 'dueDate', 'owner']])
    ->action('complete', ActionKind::Transition, [
        'transition' => ['field' => 'status', 'from' => 'open', 'to' => 'done'],
    ])
    ->projection('board', [
        'fields' => [['field' => 'title'], ['field' => 'dueDate'], ['field' => 'status']],
        'expand' => [['via' => 'owner', 'projection' => 'board']],
    ])
    ->build();
