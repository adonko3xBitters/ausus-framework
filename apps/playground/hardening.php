<?php
declare(strict_types=1);

/**
 * AUSUS — hardening + edge-case probe (PHP side).
 *
 * Exercises every classified edge case from compiler / runtime / persistence /
 * DSL. Each probe captures one of:
 *   PREVENTED — exception was raised at the expected layer
 *   UNHANDLED — silently succeeded
 *   WRONG-EX  — exception kind / message didn't match expectation
 *
 * Run: php apps/playground/hardening.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{
    Compiler, Tenant, TenantId, ActorRef, StubActor, Reference,
    FieldNode, ActionNode, PolicyNode, WorkflowNode, TransitionNode,
    ProjectionNode, EntityNode, Plugin
};
use Ausus\Persistence\Sql\{SqlitePersistenceDriver, SchemaDeriver, DatabaseAuditSink};
use Ausus\Runtime\{
    PolicyEngine, WorkflowRuntime, TransitionSetIndex, EffectDispatcher,
    DefaultAuditor, SequenceCounter, Invoker,
};
use Acme\Billing\HelloInvoicePlugin;

// Helpers to build correctly-shaped descriptors (matches the actual ctor arity).
function _entity(string $fqn, array $fields, array $actionFqns = [], array $projectionFqns = [], array $workflowFqns = []): EntityNode {
    return new EntityNode($fqn, true, $fields, $actionFqns, $projectionFqns, $workflowFqns);
}
function _field(string $name, string $type = 'string', bool $sys = false, mixed $default = null, array $opts = []): FieldNode {
    return new FieldNode($name, $type, $sys, false, $opts, $default);
}
function _action(string $fqn, string $entityFqn, string $policyFqn, bool $subjectRequired, string $effect = 'kernel.builtin.create', array $config = [], array $inputs = []): ActionNode {
    return new ActionNode($fqn, $entityFqn, $policyFqn, $subjectRequired, $effect, $config, $inputs, 'standard');
}
function _policy(string $fqn, string $impl = 'role.required', array $cfg = []): PolicyNode {
    return new PolicyNode($fqn, $impl, $cfg);
}
function _workflow(string $fqn, string $ownerEntity, string $stateField, array $states, string $initial, array $transitions): WorkflowNode {
    return new WorkflowNode($fqn, $ownerEntity, $stateField, $states, $initial, $transitions);
}

// ── tiny test harness ────────────────────────────────────────────────────────
$results = [];
function probe(string $name, callable $fn, ?string $expectedMatcher = null): void {
    global $results;
    try {
        $fn();
        $results[] = [$name, 'UNHANDLED', '(no exception raised)'];
    } catch (\Throwable $e) {
        $class = (new \ReflectionClass($e))->getShortName();
        $msg = $e->getMessage();
        // Match against either class name OR message substring (either is fine).
        $matched = ($expectedMatcher === null
            || $class === $expectedMatcher
            || str_contains($msg, $expectedMatcher));
        $results[] = [$name, $matched ? "PREVENTED ({$class})" : "WRONG-EX ({$class})", $msg];
    }
}

// ── shared bootstrap (real graph + real DB) ──────────────────────────────────
$dbPath = sys_get_temp_dir() . '/ausus-hardening.sqlite';
if (file_exists($dbPath)) unlink($dbPath);
$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$compiler  = new Compiler();
$mainGraph = $compiler->compile([new HelloInvoicePlugin()]);
foreach (SchemaDeriver::deriveAll($mainGraph) as $stmt) $pdo->exec($stmt);

$driver  = new SqlitePersistenceDriver($pdo, $mainGraph);
$auditor = new DefaultAuditor(new DatabaseAuditSink($pdo));
$tenant  = new Tenant(new TenantId('acme'));
$actor   = new StubActor(new ActorRef('user', 'hard', 'acme'),
            ['invoice.creator','invoice.issuer','invoice.canceler','invoice.viewer']);
$invoker = new Invoker(
    $mainGraph, $driver,
    new PolicyEngine($mainGraph),
    new WorkflowRuntime(new TransitionSetIndex($mainGraph)),
    new EffectDispatcher(),
    $auditor, new SequenceCounter(),
    $tenant, $actor,
);

$out = $invoker->invoke('billing.invoice.create', null, [
    'number' => 'INV-HARDEN-001', 'customer_name' => 'Probe Co',
    'amount' => ['amount' => '10.00', 'currency' => 'USD'],
]);
$realId = $out['id'];
$realRef = new Reference('acme', 'billing.invoice', $realId);

// =============================================================================
// COMPILE-TIME PROBES
// =============================================================================

probe('CT-01 empty action FQN accepted (no validation)', function() use ($compiler) {
    $compiler->compile([new class implements Plugin {
        public function name(): string { return 'bad'; }
        public function phpNamespace(): string { return 'Bad\\'; }
        public function describe(): array {
            return [
                'entities' => [_entity('bad.thing', [_field('id', sys: true)])],
                'actions'  => [_action('', 'bad.thing', 'bad.allow', false)],
                'policies' => [_policy('bad.allow')],
            ];
        }
    }]);
});

probe('CT-02 duplicate action FQN', function() use ($compiler) {
    $make = fn() => new class implements Plugin {
        public function name(): string { return 'dup'; }
        public function phpNamespace(): string { return 'Dup\\'; }
        public function describe(): array {
            return [
                'entities' => [_entity('dup.thing', [_field('id', sys: true)])],
                'actions'  => [_action('dup.thing.create', 'dup.thing', 'dup.allow', false)],
                'policies' => [_policy('dup.allow')],
            ];
        }
    };
    $compiler->compile([$make(), $make()]);
}, 'DuplicateRegistration');

probe('CT-03 duplicate Entity FQN silently overwrites', function() use ($compiler) {
    $g = $compiler->compile([
        new class implements Plugin {
            public function name(): string { return 'a'; }
            public function phpNamespace(): string { return 'A\\'; }
            public function describe(): array {
                return ['entities' => [_entity('shared.thing', [_field('id', sys: true), _field('a_field')])]];
            }
        },
        new class implements Plugin {
            public function name(): string { return 'b'; }
            public function phpNamespace(): string { return 'B\\'; }
            public function describe(): array {
                return ['entities' => [_entity('shared.thing', [_field('id', sys: true), _field('b_field', 'integer')])]];
            }
        },
    ]);
    // Only ONE entity survives. The first one is silently lost.
    $fields = array_map(fn($f) => $f->name, $g->entities['shared.thing']->fields);
    if (in_array('a_field', $fields, true) && in_array('b_field', $fields, true)) {
        throw new \RuntimeException("merged-as-expected");
    }
});

probe('CT-04 dangling entity ref from action', function() use ($compiler) {
    $compiler->compile([new class implements Plugin {
        public function name(): string { return 'd'; }
        public function phpNamespace(): string { return 'D\\'; }
        public function describe(): array {
            return [
                'entities' => [_entity('d.thing', [_field('id', sys: true)])],
                'actions'  => [_action('d.other.create', 'd.NOPE', 'd.allow', false)],
                'policies' => [_policy('d.allow')],
            ];
        }
    }]);
}, 'DanglingReference');

probe('CT-05 workflow source state not in states[]', function() use ($compiler) {
    $compiler->compile([new class implements Plugin {
        public function name(): string { return 'w'; }
        public function phpNamespace(): string { return 'W\\'; }
        public function describe(): array {
            return [
                'entities' => [_entity('w.thing', [
                    _field('id', sys: true),
                    _field('status','enum', default: 'DRAFT', opts: ['options'=>['DRAFT','DONE']]),
                ])],
                'actions'  => [_action('w.thing.go','w.thing','w.allow', true, 'transition')],
                'policies' => [_policy('w.allow')],
                'workflows'=> [_workflow('w.thing.flow','w.thing','status',
                    ['DRAFT','DONE'], 'DRAFT',
                    [new TransitionNode('WRONG_STATE','DONE','w.thing.go')])],
            ];
        }
    }]);
}, 'WorkflowCoherence');

probe('CT-06 workflow wildcard `*` source accepted', function() use ($compiler) {
    $g = $compiler->compile([new class implements Plugin {
        public function name(): string { return 'wstar'; }
        public function phpNamespace(): string { return 'Wstar\\'; }
        public function describe(): array {
            return [
                'entities' => [_entity('wstar.thing', [
                    _field('id', sys: true),
                    _field('status','enum', default: 'A', opts: ['options'=>['A','B','C']]),
                ])],
                'actions'  => [_action('wstar.thing.zap','wstar.thing','wstar.allow', true, 'transition')],
                'policies' => [_policy('wstar.allow')],
                'workflows'=> [_workflow('wstar.thing.flow','wstar.thing','status',
                    ['A','B','C'], 'A', [new TransitionNode('*','C','wstar.thing.zap')])],
            ];
        }
    }]);
    if (isset($g->workflows['wstar.thing.flow'])) throw new \RuntimeException('compile-accepted-as-expected');
});

probe('CT-07 workflow ambiguity (* + specific) accepted at compile', function() use ($compiler) {
    $g = $compiler->compile([new class implements Plugin {
        public function name(): string { return 'wamb'; }
        public function phpNamespace(): string { return 'Wamb\\'; }
        public function describe(): array {
            return [
                'entities' => [_entity('wamb.thing', [
                    _field('id', sys: true),
                    _field('status','enum', default: 'A', opts: ['options'=>['A','B']]),
                ])],
                'actions'  => [_action('wamb.thing.zap','wamb.thing','wamb.allow', true, 'transition')],
                'policies' => [_policy('wamb.allow')],
                'workflows'=> [_workflow('wamb.thing.flow','wamb.thing','status', ['A','B'], 'A', [
                    new TransitionNode('*','B','wamb.thing.zap'),
                    new TransitionNode('A','B','wamb.thing.zap'),
                ])],
            ];
        }
    }]);
    if (isset($g->workflows['wamb.thing.flow'])) throw new \RuntimeException('compile-accepted-as-expected');
});

probe('CT-08 projection references unknown field', function() use ($compiler) {
    $compiler->compile([new class implements Plugin {
        public function name(): string { return 'p'; }
        public function phpNamespace(): string { return 'P\\'; }
        public function describe(): array {
            return [
                'entities'    => [_entity('p.thing', [_field('id', sys: true)])],
                'projections' => [new ProjectionNode('p.thing.summary', 'p.thing', ['id','GHOST_FIELD'], [])],
            ];
        }
    }]);
});

probe('CT-09 projection references unknown actionFqn', function() use ($compiler) {
    $compiler->compile([new class implements Plugin {
        public function name(): string { return 'pa'; }
        public function phpNamespace(): string { return 'Pa\\'; }
        public function describe(): array {
            return [
                'entities'    => [_entity('pa.thing', [_field('id', sys: true)])],
                'projections' => [new ProjectionNode('pa.thing.summary', 'pa.thing', ['id'], ['pa.thing.NOPE'])],
            ];
        }
    }]);
});

// =============================================================================
// RUNTIME PROBES (against seeded HelloInvoice graph)
// =============================================================================

probe('RT-01 Reference with empty entityFqn', function() use ($invoker) {
    $invoker->invoke('billing.invoice.issue', new Reference('acme', '', 'whatever'), []);
});

probe('RT-02 Reference with empty identityHandle', function() use ($invoker) {
    $invoker->invoke('billing.invoice.issue', new Reference('acme', 'billing.invoice', ''), []);
}, 'Subject not found');

probe('RT-03 Reference with non-ULID identityHandle (rejected?)', function() use ($invoker) {
    $invoker->invoke('billing.invoice.issue', new Reference('acme', 'billing.invoice', 'not-a-ulid'), []);
}, 'Subject not found');

probe('RT-04 valid-shape ULID but no record → WorkflowSubjectNotFound', function() use ($invoker) {
    $ghostId = '01KRZGHOSTGHOSTGHOSTGHOST01';
    $invoker->invoke('billing.invoice.issue', new Reference('acme', 'billing.invoice', $ghostId), []);
}, 'Subject not found');

probe('RT-05 cross-tenant Reference', function() use ($invoker, $realId) {
    $invoker->invoke('billing.invoice.issue', new Reference('evil', 'billing.invoice', $realId), []);
});

probe('RT-07 action replay (no idempotency key) — second issue from ISSUED', function() use ($invoker, $realRef) {
    $invoker->invoke('billing.invoice.issue', $realRef, []);
    $invoker->invoke('billing.invoice.issue', $realRef, []);
}, 'WorkflowStateMismatch');

probe('RT-08 invoke with unknown extra inputs', function() use ($invoker, $realRef) {
    $invoker->invoke('billing.invoice.cancel', $realRef, [
        'unknown_input_x' => 'whatever',
        'malicious_field' => "'; DROP TABLE foo;--",
    ]);
}, 'UnknownField');

probe('RT-09 wildcard transition collision → runtime ambiguity', function() {
    $tmp = sys_get_temp_dir() . '/ausus-hardening-rt09.sqlite';
    if (file_exists($tmp)) unlink($tmp);
    $pdo = new PDO("sqlite:$tmp");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $plugin = new class implements Plugin {
        public function name(): string { return 'amb'; }
        public function phpNamespace(): string { return 'Amb\\'; }
        public function describe(): array {
            return [
                'entities' => [_entity('amb.thing', [
                    _field('id','identity', sys: true),
                    _field('tenant_id','system_string', sys: true),
                    _field('_version','version', sys: true),
                    _field('created_at','datetime', sys: true),
                    _field('updated_at','datetime', sys: true),
                    _field('status','enum', default: 'A', opts: ['options'=>['A','B']]),
                ], ['amb.thing.create','amb.thing.zap'])],
                'actions'  => [
                    _action('amb.thing.create','amb.thing','amb.allow', false, 'kernel.builtin.create',
                        ['entityFqn'=>'amb.thing','workflowStateField'=>'status','workflowInitial'=>'A']),
                    _action('amb.thing.zap','amb.thing','amb.allow', true, 'kernel.builtin.transition',
                        ['entityFqn'=>'amb.thing','stateField'=>'status','target'=>'B']),
                ],
                'policies' => [_policy('amb.allow', \Ausus\Runtime\RoleRequired::class, ['role'=>'allowed'])],
                'workflows'=> [_workflow('amb.thing.flow','amb.thing','status', ['A','B'], 'A', [
                    new TransitionNode('*','B','amb.thing.zap'),
                    new TransitionNode('A','B','amb.thing.zap'),
                ])],
            ];
        }
    };
    $g = (new Compiler())->compile([$plugin]);
    foreach (SchemaDeriver::deriveAll($g) as $stmt) $pdo->exec($stmt);
    $d = new SqlitePersistenceDriver($pdo, $g);
    $t = new Tenant(new TenantId('acme'));
    $a = new StubActor(new ActorRef('user','x','acme'), ['allowed']);
    $inv = new Invoker($g, $d, new PolicyEngine($g),
        new WorkflowRuntime(new TransitionSetIndex($g)), new EffectDispatcher(),
        new DefaultAuditor(new DatabaseAuditSink($pdo)), new SequenceCounter(), $t, $a);
    $o = $inv->invoke('amb.thing.create', null, []);
    $inv->invoke('amb.thing.zap', new Reference('acme','amb.thing',$o['id']), []);
}, 'WorkflowAmbiguousTransition');

probe('RT-10 invoke unknown actionFqn', function() use ($invoker) {
    $invoker->invoke('billing.invoice.ghost', null, []);
}, 'Unknown action');

// =============================================================================
// DSL PROBES
// =============================================================================

probe('DSL-02 duplicate entity across two plugins silently overwrites', function() use ($compiler) {
    $g = $compiler->compile([
        new class implements Plugin {
            public function name(): string { return 'd1'; }
            public function phpNamespace(): string { return 'D1\\'; }
            public function describe(): array {
                return ['entities' => [_entity('shared.e', [_field('id', sys: true), _field('x')])]];
            }
        },
        new class implements Plugin {
            public function name(): string { return 'd2'; }
            public function phpNamespace(): string { return 'D2\\'; }
            public function describe(): array {
                return ['entities' => [_entity('shared.e', [_field('id', sys: true), _field('y')])]];
            }
        },
    ]);
    $fields = array_map(fn($f) => $f->name, $g->entities['shared.e']->fields);
    if (in_array('x', $fields, true) && in_array('y', $fields, true)) {
        throw new \RuntimeException('merged-not-overwritten');
    }
});

// =============================================================================
// REPORT
// =============================================================================
echo "\n══════════════════════════════════════════════════════════════════════\n";
echo "  HARDENING PROBE SUMMARY\n";
echo "══════════════════════════════════════════════════════════════════════\n";
$prevented = $unhandled = $wrong = 0;
foreach ($results as [$name, $outcome, $detail]) {
    if (str_starts_with($outcome, 'PREVENTED')) $prevented++;
    elseif (str_starts_with($outcome, 'WRONG-EX')) $wrong++;
    else $unhandled++;
    $short = strlen($detail) > 90 ? substr($detail, 0, 87) . '...' : $detail;
    printf("  %-25s  %-55s  %s\n", $outcome, $name, $short);
}
echo "\n";
printf("Prevented: %d   Wrong-exception: %d   Unhandled: %d\n", $prevented, $wrong, $unhandled);
echo "\n";
