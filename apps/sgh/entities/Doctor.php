<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('doctor', true)
    ->field('firstName', FieldType::String)
    ->field('lastName', FieldType::String)
    ->field('speciality', FieldType::String)
    ->field('department', FieldType::Reference, ['typeOptions' => ['target' => 'department']])
    ->field('user', FieldType::Reference, ['nullable' => true, 'typeOptions' => ['target' => 'user']])
    ->action('create', ActionKind::Create, ['inputs' => ['firstName', 'lastName', 'speciality', 'department', 'user']])
    ->projection('board', ['fields' => [['field' => 'firstName'], ['field' => 'lastName'], ['field' => 'speciality']]])
    ->projection('detail', [
        'fields' => [['field' => 'firstName'], ['field' => 'lastName'], ['field' => 'speciality']],
        'expand' => [['via' => 'department', 'projection' => 'board']],
    ])
    ->build();
