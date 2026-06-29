<?php

declare(strict_types=1);

/**
 * Hello Invoice — the AUSUS 2.0 Authoring DSL declaration of one entity.
 *
 * This file is pure data: it returns exactly one immutable EntityDefinition.
 * No side effects, no I/O, no business logic — only `Definition`, `Expr`, and
 * the public enums from `ausus/kernel` + `ausus/authoring`.
 *
 * Guards use PRIMITIVE operators only (eq / lt / not): the runtime
 * AuthorizationEvaluator denies on unresolved facts, so a guard reads facts
 * from `actor` / `subject` / `input` and resolves to a permit/deny.
 */

use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;

return Definition::make('invoice', true)            // tenant-scoped
    ->field('number', FieldType::String)
    ->field('customer', FieldType::String)
    ->field('issueDate', FieldType::Date)
    ->field('dueDate', FieldType::Date)
    ->field('status', FieldType::Enum, [
        'default' => 'draft',
        'writeProtected' => true,                   // only transitions change it
        'typeOptions' => ['values' => ['draft', 'paid', 'cancelled']],
    ])
    ->field('total', FieldType::Decimal)

    // create — only a real user may open an invoice
    ->action('create', ActionKind::Create, [
        'inputs' => ['number', 'customer', 'issueDate', 'dueDate', 'total'],
        'guard' => Expr::eq(Expr::actor('type'), 'user'),
    ])
    // update — patch the editable fields (status & number stay fixed)
    ->action('update', ActionKind::Update, [
        'inputs' => ['customer', 'dueDate', 'total'],
        'guard' => Expr::eq(Expr::actor('type'), 'user'),
    ])
    // pay — draft → paid; a non-positive invoice cannot be paid.
    // `not(total < 1)` ≡ total >= 1, using primitive operators only.
    ->action('pay', ActionKind::Transition, [
        'guard' => Expr::not(Expr::lt(Expr::subject('total'), 1)),
        'transition' => ['field' => 'status', 'from' => 'draft', 'to' => 'paid'],
    ])
    // cancel — draft → cancelled
    ->action('cancel', ActionKind::Transition, [
        'guard' => Expr::eq(Expr::actor('type'), 'user'),
        'transition' => ['field' => 'status', 'from' => 'draft', 'to' => 'cancelled'],
    ])

    // Invoice List — flat read shape
    ->projection('board', ['fields' => [
        ['field' => 'number'], ['field' => 'customer'], ['field' => 'status'], ['field' => 'total'],
    ]])
    // Invoice Details — every field
    ->projection('detail', ['fields' => [
        ['field' => 'number'], ['field' => 'customer'], ['field' => 'issueDate'],
        ['field' => 'dueDate'], ['field' => 'status'], ['field' => 'total'],
    ]])
    ->build();
