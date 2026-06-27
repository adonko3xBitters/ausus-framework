<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('consultation', true)
    ->field('diagnosis', FieldType::String)
    ->field('notes', FieldType::String, ['nullable' => true])
    ->field('status', FieldType::Enum, [
        'default' => 'open',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['open', 'closed']],
    ])
    ->field('appointment', FieldType::Reference, ['typeOptions' => ['target' => 'appointment']])
    ->field('doctor', FieldType::Reference, ['typeOptions' => ['target' => 'doctor']])
    // guard (actor): only doctors may open a consultation
    ->action('create', ActionKind::Create, [
        'inputs' => ['diagnosis', 'notes', 'appointment', 'doctor'],
        'guard' => Expr::eq(Expr::actor('type'), 'doctor'),
    ])
    ->action('close', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'open', 'to' => 'closed']])
    // flat board — single-hop expand target
    ->projection('board', ['fields' => [['field' => 'diagnosis'], ['field' => 'status']]])
    ->projection('detail', [
        'fields' => [['field' => 'diagnosis'], ['field' => 'notes'], ['field' => 'status']],
        'expand' => [['via' => 'appointment', 'projection' => 'board']],
    ])
    ->build();
