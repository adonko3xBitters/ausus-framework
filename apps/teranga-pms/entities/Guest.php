<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('guest', true)
    ->field('firstName', FieldType::String)
    ->field('lastName', FieldType::String)
    ->field('email', FieldType::String)
    ->field('phone', FieldType::String, ['nullable' => true])
    ->action('create', ActionKind::Create, ['inputs' => ['firstName', 'lastName', 'email', 'phone']])
    // flat board — single-hop expand target for Reservation.detail
    ->projection('board', ['fields' => [['field' => 'firstName'], ['field' => 'lastName'], ['field' => 'email']]])
    ->projection('detail', ['fields' => [
        ['field' => 'firstName'], ['field' => 'lastName'], ['field' => 'email'], ['field' => 'phone'],
    ]])
    ->build();
