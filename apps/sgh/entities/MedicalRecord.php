<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('medicalrecord', true)
    ->field('summary', FieldType::String)
    ->field('status', FieldType::Enum, [
        'default' => 'open',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['open', 'archived']],
    ])
    ->field('patient', FieldType::Reference, ['typeOptions' => ['target' => 'patient']])
    ->field('consultation', FieldType::Reference, ['nullable' => true, 'typeOptions' => ['target' => 'consultation']])
    // guard (tenant): medical records may only be created within the 'sgh' tenant
    ->action('create', ActionKind::Create, [
        'inputs' => ['summary', 'patient', 'consultation'],
        'guard' => Expr::eq(Expr::tenant('id'), 'sgh'),
    ])
    ->action('archive', ActionKind::Transition, ['transition' => ['field' => 'status', 'from' => 'open', 'to' => 'archived']])
    // flat board — single-hop expand target
    ->projection('board', ['fields' => [['field' => 'summary'], ['field' => 'status']]])
    ->projection('detail', [
        'fields' => [['field' => 'summary'], ['field' => 'status']],
        'expand' => [['via' => 'patient', 'projection' => 'board']],
    ])
    ->build();
