<?php
declare(strict_types=1);

namespace Acme\Billing;

use Ausus\{
    Plugin, EntityNode, FieldNode, ActionNode, PolicyNode, WorkflowNode, TransitionNode, ProjectionNode
};

final class HelloInvoicePlugin implements Plugin {
    public function name(): string         { return 'billing'; }
    public function phpNamespace(): string  { return 'Acme\\Billing'; }

    public function describe(): array {
        // V0 — manual descriptor construction (no DSL fluent chain; RFC-011 facade not built in this pass)
        $entityFqn = 'billing.invoice';

        $fields = [
            new FieldNode('id',            'identity',      true, false, [], null),
            new FieldNode('tenant_id',     'system_string', true, false, [], null),
            new FieldNode('_version',      'version',       true, false, [], null),
            new FieldNode('created_at',    'datetime',      true, false, [], null),
            new FieldNode('updated_at',    'datetime',      true, false, [], null),
            new FieldNode('number',        'string',        false, false, ['maxLength' => 32], null),
            new FieldNode('customer_name', 'string',        false, false, ['maxLength' => 200], null),
            new FieldNode('amount',        'money',         false, false, ['currency' => 'USD'], null),
            new FieldNode('status',        'enum',          false, false, ['options' => ['DRAFT','ISSUED','CANCELLED']], 'DRAFT'),
            new FieldNode('issued_at',     'datetime',      false, true,  [], null),
        ];

        $actions = [
            new ActionNode(
                fqn: "{$entityFqn}.create",
                entityFqn: $entityFqn,
                policyFqn: "{$entityFqn}.policy.create",
                subjectRequired: false,
                effectClass: 'kernel.builtin.create',
                effectConfig: ['entityFqn' => $entityFqn, 'workflowStateField' => 'status', 'workflowInitial' => 'DRAFT'],
                inputs: [
                    new FieldNode('number',        'string', false, false, ['maxLength'=>32], null),
                    new FieldNode('customer_name', 'string', false, false, ['maxLength'=>200], null),
                    new FieldNode('amount',        'money',  false, false, ['currency'=>'USD'], null),
                ],
                kind: 'standard',
            ),
            new ActionNode(
                fqn: "{$entityFqn}.issue",
                entityFqn: $entityFqn,
                policyFqn: "{$entityFqn}.policy.issue",
                subjectRequired: true,
                effectClass: 'kernel.builtin.transition',
                effectConfig: ['entityFqn' => $entityFqn, 'stateField' => 'status', 'target' => 'ISSUED', 'stamps' => ['issued_at']],
                inputs: [],
                kind: 'standard',
            ),
            new ActionNode(
                fqn: "{$entityFqn}.cancel",
                entityFqn: $entityFqn,
                policyFqn: "{$entityFqn}.policy.cancel",
                subjectRequired: true,
                effectClass: 'kernel.builtin.transition',
                effectConfig: ['entityFqn' => $entityFqn, 'stateField' => 'status', 'target' => 'CANCELLED'],
                inputs: [],
                kind: 'standard',
            ),
        ];

        $policies = [
            new PolicyNode("{$entityFqn}.policy.create", \Ausus\Runtime\RoleRequired::class, ['role' => 'invoice.creator']),
            new PolicyNode("{$entityFqn}.policy.issue",  \Ausus\Runtime\RoleRequired::class, ['role' => 'invoice.issuer']),
            new PolicyNode("{$entityFqn}.policy.cancel", \Ausus\Runtime\RoleRequired::class, ['role' => 'invoice.canceler']),
            new PolicyNode("{$entityFqn}.projection.read", \Ausus\Runtime\RoleRequired::class, ['role' => 'invoice.viewer']),
        ];

        $workflows = [
            new WorkflowNode(
                fqn: "{$entityFqn}.lifecycle",
                ownerEntityFqn: $entityFqn,
                stateField: 'status',
                states: ['DRAFT', 'ISSUED', 'CANCELLED'],
                initial: 'DRAFT',
                transitions: [
                    new TransitionNode('DRAFT', 'ISSUED', "{$entityFqn}.issue"),
                    new TransitionNode('DRAFT', 'CANCELLED', "{$entityFqn}.cancel"),
                    new TransitionNode('ISSUED', 'CANCELLED', "{$entityFqn}.cancel"),
                ],
            ),
        ];

        $projections = [
            new ProjectionNode(
                fqn: "{$entityFqn}.summary",
                ownerEntityFqn: $entityFqn,
                fields: ['id', 'number', 'customer_name', 'status', 'amount'],
                actionFqns: ["{$entityFqn}.create", "{$entityFqn}.cancel"],
            ),
            new ProjectionNode(
                fqn: "{$entityFqn}.detail",
                ownerEntityFqn: $entityFqn,
                fields: ['id', 'number', 'customer_name', 'status', 'amount', 'issued_at', 'created_at', 'updated_at'],
                actionFqns: ["{$entityFqn}.issue", "{$entityFqn}.cancel"],
            ),
        ];

        $entity = new EntityNode(
            fqn: $entityFqn,
            tenantScoped: true,
            fields: $fields,
            actionFqns: array_map(fn($a) => $a->fqn, $actions),
            projectionFqns: array_map(fn($p) => $p->fqn, $projections),
            workflowFqns: array_map(fn($w) => $w->fqn, $workflows),
        );

        return [
            'entities'    => [$entity],
            'actions'     => $actions,
            'policies'    => $policies,
            'workflows'   => $workflows,
            'projections' => $projections,
        ];
    }
}
