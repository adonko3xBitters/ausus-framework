<?php

declare(strict_types=1);

use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('customer', true)
    ->field('name', FieldType::String)
    ->field('email', FieldType::String)
    ->field('phone', FieldType::String, ['nullable' => true])
    ->field('status', FieldType::Enum, [
        'default' => 'inactive',
        'writeProtected' => true,
        'typeOptions' => ['values' => ['active', 'inactive']],
    ])
    // guard: only a real user may create a customer (permit for actor.type=user).
    ->action('create', ActionKind::Create, [
        'inputs' => ['name', 'email', 'phone'],
        'guard' => Expr::eq(Expr::actor('type'), 'user'),
    ])
    ->action('activate', ActionKind::Transition, [
        'transition' => ['field' => 'status', 'from' => 'inactive', 'to' => 'active'],
    ])
    ->action('deactivate', ActionKind::Transition, [
        'transition' => ['field' => 'status', 'from' => 'active', 'to' => 'inactive'],
    ])
    ->projection('board', ['fields' => [['field' => 'name'], ['field' => 'email'], ['field' => 'status']]])
    ->projection('detail', ['fields' => [
        ['field' => 'name'], ['field' => 'email'], ['field' => 'phone'], ['field' => 'status'],
    ]])
    ->build();
