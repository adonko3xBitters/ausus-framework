<?php
declare(strict_types=1);

namespace Tasks\Domain;

use Ausus\{Plugin, EntityNode, FieldNode, ActionNode, PolicyNode,
           WorkflowNode, TransitionNode, ProjectionNode};

/**
 * Minimal CRUD consumer — `tasks.task` entity with two actions and one Projection.
 * Demonstrates the smallest plausible plugin a real user might write.
 *
 * DX measurements captured during authoring (see docs/CONSUMER-DX-PASS.md):
 *  - LOC: this file is the entire plugin
 *  - distinct framework FQNs imported: 7 (EntityNode, FieldNode, ActionNode,
 *    PolicyNode, WorkflowNode, TransitionNode, ProjectionNode + Plugin)
 *  - docs traversed:  packages/starter/src/HelloInvoice.php (1 reference)
 */
final class TaskPlugin implements Plugin
{
    public function name(): string          { return 'tasks'; }
    public function phpNamespace(): string  { return 'Tasks\\Domain'; }

    public function describe(): array
    {
        $entityFqn = 'tasks.task';
        $policyFqn = 'tasks.allow';

        return [
            'entities' => [
                new EntityNode(
                    fqn: $entityFqn,
                    tenantScoped: true,
                    fields: [
                        // System fields the SQL driver needs (RFC-002) — sugar helper.
                        ...FieldNode::systemSet(),
                        // Domain fields.
                        new FieldNode('title',  'string', false, false, [], null),
                        new FieldNode('status', 'enum',   false, false, ['options' => ['DRAFT', 'DONE']], 'DRAFT'),
                    ],
                    actionFqns:     ["{$entityFqn}.create", "{$entityFqn}.complete"],
                    projectionFqns: ["{$entityFqn}.list"],
                    workflowFqns:   ["{$entityFqn}.lifecycle"],
                ),
            ],
            'actions' => [
                new ActionNode(
                    fqn: "{$entityFqn}.create",
                    entityFqn: $entityFqn,
                    policyFqn: $policyFqn,
                    subjectRequired: false,
                    effectClass: 'kernel.builtin.create',
                    effectConfig: ['entityFqn' => $entityFqn, 'workflowStateField' => 'status', 'workflowInitial' => 'DRAFT'],
                    inputs: [new FieldNode('title', 'string', false, false, [], null)],
                    kind: 'standard',
                ),
                new ActionNode(
                    fqn: "{$entityFqn}.complete",
                    entityFqn: $entityFqn,
                    policyFqn: $policyFqn,
                    subjectRequired: true,
                    effectClass: 'kernel.builtin.transition',
                    effectConfig: ['entityFqn' => $entityFqn, 'stateField' => 'status', 'target' => 'DONE'],
                    inputs: [],
                    kind: 'standard',
                ),
            ],
            'policies' => [
                // The consumer's one demo Policy: any actor with the 'user' role
                // can create + complete tasks. Production would split per-action.
                new PolicyNode($policyFqn, \Ausus\Runtime\RoleRequired::class, ['role' => 'user']),
            ],
            'workflows' => [
                new WorkflowNode(
                    fqn: "{$entityFqn}.lifecycle",
                    ownerEntityFqn: $entityFqn,
                    stateField: 'status',
                    states: ['DRAFT', 'DONE'],
                    initial: 'DRAFT',
                    transitions: [new TransitionNode('DRAFT', 'DONE', "{$entityFqn}.complete")],
                ),
            ],
            'projections' => [
                new ProjectionNode(
                    fqn: "{$entityFqn}.list",
                    ownerEntityFqn: $entityFqn,
                    fields: ['id', 'title', 'status', 'created_at'],
                    actionFqns: ["{$entityFqn}.create", "{$entityFqn}.complete"],
                ),
            ],
        ];
    }
}
