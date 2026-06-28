<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('housekeepingtask', true)
    ->field('room', FieldType::Reference, ['typeOptions' => ['target' => 'room']])
    ->field('taskType', FieldType::Enum, ['default' => 'cleaning', 'typeOptions' => ['values' => ['cleaning', 'maintenance', 'inspection']]])
    ->field('status', FieldType::Enum, [
        'default' => 'open',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['open', 'done']],
    ])
    ->action('create', ActionKind::Create, ['inputs' => ['room', 'taskType']])
    ->action('complete', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'open', 'to' => 'done']])
    // board carries HousekeepingTask → Room single-hop expand
    ->projection('board', [
        'fields' => [['field' => 'taskType'], ['field' => 'status']],
        'expand' => [['via' => 'room', 'projection' => 'board']],
    ])
    ->build();
