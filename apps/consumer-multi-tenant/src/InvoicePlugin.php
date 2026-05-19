<?php
declare(strict_types=1);

namespace BillingMt\Domain;

use Ausus\{Plugin, EntityNode, FieldNode, ActionNode, PolicyNode,
           WorkflowNode, TransitionNode, ProjectionNode};

/**
 * Multi-tenant consumer — same plugin pattern as App 1, but with a richer
 * domain (invoices) so we can verify cross-tenant isolation isn't a fluke
 * of small datasets. The plugin itself contains ZERO tenant logic — that's
 * the framework's job (RFC-003 row strategy).
 */
final class InvoicePlugin implements Plugin
{
    public function name(): string          { return 'billing'; }
    public function phpNamespace(): string  { return 'BillingMt\\Domain'; }

    public function describe(): array
    {
        $e = 'billing.invoice';
        $p = 'billing.allow';
        return [
            'entities' => [new EntityNode(
                fqn: $e,
                tenantScoped: true,
                fields: [
                    ...FieldNode::systemSet(),
                    new FieldNode('number', 'string', false, false, [], null),
                    new FieldNode('status', 'enum',   false, false, ['options' => ['DRAFT', 'PAID']], 'DRAFT'),
                ],
                actionFqns:     ["$e.create", "$e.pay"],
                projectionFqns: ["$e.list"],
                workflowFqns:   ["$e.lifecycle"],
            )],
            'actions' => [
                new ActionNode("$e.create", $e, $p, false,
                    'kernel.builtin.create',
                    ['entityFqn' => $e, 'workflowStateField' => 'status', 'workflowInitial' => 'DRAFT'],
                    [new FieldNode('number', 'string', false, false, [], null)],
                    'standard'),
                new ActionNode("$e.pay", $e, $p, true,
                    'kernel.builtin.transition',
                    ['entityFqn' => $e, 'stateField' => 'status', 'target' => 'PAID'],
                    [], 'standard'),
            ],
            'policies' => [
                new PolicyNode($p, \Ausus\Runtime\RoleRequired::class, ['role' => 'biller']),
            ],
            'workflows' => [
                new WorkflowNode("$e.lifecycle", $e, 'status',
                    ['DRAFT', 'PAID'], 'DRAFT',
                    [new TransitionNode('DRAFT', 'PAID', "$e.pay")]),
            ],
            'projections' => [
                new ProjectionNode("$e.list", $e,
                    ['id', 'number', 'status', 'created_at'],
                    ["$e.create", "$e.pay"]),
            ],
        ];
    }
}
