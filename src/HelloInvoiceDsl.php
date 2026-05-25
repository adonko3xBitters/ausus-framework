<?php
declare(strict_types=1);

namespace Acme\Billing;

use Ausus\{DslPlugin, Dsl, Field, Action};

/**
 * HelloInvoice — RFC-011 fluent DSL version.
 *
 * Equivalent to HelloInvoicePlugin (manual descriptor arrays); produces a
 * byte-identical MetadataGraph hash via the same Compiler pipeline.
 */
final class HelloInvoiceDsl extends DslPlugin
{
    public function name(): string         { return 'billing'; }
    public function phpNamespace(): string  { return 'Acme\\Billing'; }

    public function dsl(Dsl $dsl): void
    {
        $dsl->entity('invoice')
            ->fields([
                'number'        => Field::string()->unique()->max(32),
                'customer_name' => Field::string()->max(200),
                'amount'        => Field::money()->currency('USD'),
                'status'        => Field::enum('DRAFT', 'ISSUED', 'CANCELLED')->default('DRAFT'),
                'issued_at'     => Field::datetime()->nullable(),
            ])
            ->actions([
                'create' => Action::create('number', 'customer_name', 'amount')
                              ->requireRole('invoice.creator'),
                'issue'  => Action::transition('status', from: 'DRAFT', to: 'ISSUED')
                              ->stamp('issued_at')
                              ->requireRole('invoice.issuer'),
                'cancel' => Action::transition('status', from: 'DRAFT',  to: 'CANCELLED')
                              ->andTransition('status', from: 'ISSUED', to: 'CANCELLED')
                              ->requireRole('invoice.canceler'),
            ])
            ->workflow(field: 'status', initial: 'DRAFT')
            ->projection('summary',
                fields:  ['id', 'number', 'customer_name', 'status', 'amount'],
                actions: ['create', 'cancel'],
                role:    'invoice.viewer')
            ->projection('detail',
                fields:  ['id', 'number', 'customer_name', 'status', 'amount', 'issued_at', 'created_at', 'updated_at'],
                actions: ['issue', 'cancel'],
                role:    'invoice.viewer');
    }
}
