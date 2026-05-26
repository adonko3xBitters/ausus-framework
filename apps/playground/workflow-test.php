<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Application, Compiler, Plugin, DslPlugin, Dsl, Field, Action};
use Ausus\{EntityNode, FieldNode, WorkflowNode};

/**
 * AUSUS — explicit Workflow declaration API tests.
 *
 * Covers ->workflow(field:, initial:) on the DSL EntityBuilder:
 *   - explicit workflow success (initial overrides the field default)
 *   - missing / non-enum workflow field          (validation errors)
 *   - invalid initial state                      (validation error)
 *   - ambiguous implicit inference               (validation error)
 *   - Compiler initial-not-in-states coherence   (validation error)
 *   - backward compatibility: legacy implicit inference still works + warns
 *
 * Run: php apps/playground/workflow-test.php   (exit 0 on success)
 */

$BANNER = "═══ AUSUS — explicit Workflow declaration ══════════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

/** Run $fn, returning [result, list-of-deprecation-messages]. */
function _captureDeprecations(callable $fn): array {
    $msgs = [];
    set_error_handler(static function (int $errno, string $errstr) use (&$msgs): bool {
        if ($errno === E_USER_DEPRECATED) { $msgs[] = $errstr; return true; }
        return false;
    });
    try { $result = $fn(); }
    finally { restore_error_handler(); }
    return [$result, $msgs];
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

/** Explicit workflow; initial 'PAID' deliberately differs from the field default 'NEW'. */
final class WfExplicitOrder extends DslPlugin {
    public function name(): string         { return 'shop'; }
    public function phpNamespace(): string { return 'Shop'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('order')
            ->fields([
                'label'  => Field::string()->max(50),
                'status' => Field::enum('NEW', 'PAID', 'SHIPPED')->default('NEW'),
            ])
            ->actions([
                'create' => Action::create('label')->requireRole('order.creator'),
                'ship'   => Action::transition('status', from: 'PAID', to: 'SHIPPED')
                              ->requireRole('order.shipper'),
            ])
            ->workflow(field: 'status', initial: 'PAID');
    }
}

/** Legacy: positional workflow('status'), no explicit initial → partial inference. */
final class WfLegacyPartial extends DslPlugin {
    public function name(): string         { return 'legacy'; }
    public function phpNamespace(): string { return 'Legacy'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('doc')
            ->fields([
                'title'  => Field::string()->max(50),
                'status' => Field::enum('DRAFT', 'DONE')->default('DRAFT'),
            ])
            ->actions(['create' => Action::create('title')->requireRole('doc.creator')])
            ->workflow('status');
    }
}

/** Legacy: no workflow() call at all, one enum-default field → implicit inference. */
final class WfLegacyImplicit extends DslPlugin {
    public function name(): string         { return 'imp'; }
    public function phpNamespace(): string { return 'Imp'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('note')
            ->fields([
                'title'  => Field::string()->max(50),
                'status' => Field::enum('OPEN', 'CLOSED')->default('OPEN'),
            ])
            ->actions(['create' => Action::create('title')->requireRole('note.creator')]);
    }
}

/** Two enum-default fields, no workflow() → ambiguous. */
final class WfAmbiguous extends DslPlugin {
    public function name(): string         { return 'amb'; }
    public function phpNamespace(): string { return 'Amb'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('ticket')
            ->fields([
                'status'   => Field::enum('OPEN', 'CLOSED')->default('OPEN'),
                'priority' => Field::enum('LOW', 'HIGH')->default('LOW'),
            ])
            ->actions(['create' => Action::create()->requireRole('ticket.creator')]);
    }
}

// ── test 1: explicit workflow success ─────────────────────────────────────────
echo "\n── test 1: explicit workflow success ─────────────────────────\n";
$app = Application::create([
    'tenant' => 'shop',
    'roles'  => ['order.creator', 'order.shipper'],
])->register(new WfExplicitOrder())->boot();

$wf = $app->graph()->workflows['shop.order.lifecycle'] ?? null;
_assert('workflow node registered',          $wf instanceof WorkflowNode);
_assert('workflow stateField == status',     $wf?->stateField === 'status');
_assert('workflow initial == PAID (explicit)', $wf?->initial === 'PAID');

$created = $app->invoke('shop.order.create', null, ['label' => 'Widget']);
_assert('create seeds explicit initial (PAID, not field default NEW)',
        ($created['status'] ?? null) === 'PAID',
        'actual=' . ($created['status'] ?? 'null'));

// ── test 2: missing workflow field ────────────────────────────────────────────
echo "\n── test 2: missing workflow field ────────────────────────────\n";
$caught = null;
try {
    (new class extends DslPlugin {
        public function name(): string         { return 'badf'; }
        public function phpNamespace(): string { return 'Badf'; }
        public function dsl(Dsl $dsl): void {
            $dsl->entity('x')
                ->fields(['status' => Field::enum('A', 'B')->default('A')])
                ->actions(['create' => Action::create()->requireRole('r')])
                ->workflow(field: 'ghost', initial: 'A');
        }
    })->describe();
} catch (\Throwable $e) { $caught = $e; }
_assert('unknown field throws WorkflowFieldNotFound',
        $caught !== null && str_contains($caught->getMessage(), 'WorkflowFieldNotFound'),
        $caught?->getMessage());

// ── test 3: non-enum workflow field ───────────────────────────────────────────
echo "\n── test 3: non-enum workflow field ───────────────────────────\n";
$caught = null;
try {
    (new class extends DslPlugin {
        public function name(): string         { return 'badt'; }
        public function phpNamespace(): string { return 'Badt'; }
        public function dsl(Dsl $dsl): void {
            $dsl->entity('x')
                ->fields(['label' => Field::string()->max(10)])
                ->actions(['create' => Action::create('label')->requireRole('r')])
                ->workflow(field: 'label', initial: 'A');
        }
    })->describe();
} catch (\Throwable $e) { $caught = $e; }
_assert('non-enum field throws WorkflowFieldNotEnum',
        $caught !== null && str_contains($caught->getMessage(), 'WorkflowFieldNotEnum'),
        $caught?->getMessage());

// ── test 4: invalid initial state ─────────────────────────────────────────────
echo "\n── test 4: invalid initial state ─────────────────────────────\n";
$caught = null;
try {
    (new class extends DslPlugin {
        public function name(): string         { return 'badi'; }
        public function phpNamespace(): string { return 'Badi'; }
        public function dsl(Dsl $dsl): void {
            $dsl->entity('x')
                ->fields(['status' => Field::enum('A', 'B')->default('A')])
                ->actions(['create' => Action::create()->requireRole('r')])
                ->workflow(field: 'status', initial: 'BOGUS');
        }
    })->describe();
} catch (\Throwable $e) { $caught = $e; }
_assert('out-of-range initial throws WorkflowInitialInvalid',
        $caught !== null && str_contains($caught->getMessage(), 'WorkflowInitialInvalid'),
        $caught?->getMessage());

// ── test 5: ambiguous workflow fields ─────────────────────────────────────────
echo "\n── test 5: ambiguous workflow fields ─────────────────────────\n";
$caught = null;
try { (new WfAmbiguous())->describe(); }
catch (\Throwable $e) { $caught = $e; }
_assert('two enum-default fields throw AmbiguousWorkflowField',
        $caught !== null && str_contains($caught->getMessage(), 'AmbiguousWorkflowField'),
        $caught?->getMessage());

// ── test 6: Compiler coherence — initial not in states ────────────────────────
echo "\n── test 6: Compiler coherence — initial not in states ────────\n";
$manualBadInitial = new class implements Plugin {
    public function name(): string         { return 'mf'; }
    public function phpNamespace(): string { return 'Mf'; }
    public function describe(): array {
        $entity = new EntityNode('mf.thing', true, [
            new FieldNode('id',    'identity', true,  false, [], null),
            new FieldNode('state', 'enum',     false, false, ['options' => ['A', 'B']], 'A'),
        ], [], [], ['mf.thing.lifecycle']);
        // initial 'Z' is deliberately not among the declared states.
        $wf = new WorkflowNode('mf.thing.lifecycle', 'mf.thing', 'state', ['A', 'B'], 'Z', []);
        return ['entities' => [$entity], 'workflows' => [$wf]];
    }
};
$caught = null;
try { (new Compiler())->compile([$manualBadInitial]); }
catch (\Throwable $e) { $caught = $e; }
_assert('Compiler rejects initial not in states (WorkflowCoherence)',
        $caught !== null && str_contains($caught->getMessage(), 'WorkflowCoherence'),
        $caught?->getMessage());

// ── test 7: backward compatibility — legacy workflow('status') ────────────────
echo "\n── test 7: backward compatibility — workflow('status') ───────\n";
[$descriptor, $deps] = _captureDeprecations(fn() => (new WfLegacyPartial())->describe());
_assert('legacy workflow(\'status\') still compiles', is_array($descriptor) && $descriptor !== []);
_assert('legacy partial inference emits a deprecation', count($deps) === 1,
        'deprecations=' . count($deps));
_assert('deprecation names the entity + suggests explicit form',
        isset($deps[0]) && str_contains($deps[0], "legacy.doc")
        && str_contains($deps[0], "initial: 'DRAFT'"));

[$legacyApp, $deps2] = _captureDeprecations(fn() => Application::create([
    'tenant' => 'legacy', 'roles' => ['doc.creator'],
])->register(new WfLegacyPartial())->boot());
$legacyOut = $legacyApp->invoke('legacy.doc.create', null, ['title' => 'Old']);
_assert('legacy workflow still seeds the initial state (DRAFT)',
        ($legacyOut['status'] ?? null) === 'DRAFT');
$legacyWf = $legacyApp->graph()->workflows['legacy.doc.lifecycle'] ?? null;
_assert('legacy workflow node registered', $legacyWf instanceof WorkflowNode);

// ── test 8: backward compatibility — legacy implicit (no workflow() call) ──────
echo "\n── test 8: backward compatibility — implicit inference ───────\n";
[$descImp, $depsImp] = _captureDeprecations(fn() => (new WfLegacyImplicit())->describe());
_assert('entity with no workflow() call still compiles', is_array($descImp));
_assert('implicit inference emits a deprecation', count($depsImp) === 1,
        'deprecations=' . count($depsImp));
_assert('no workflow node is created without a workflow() call',
        ($descImp['workflows'] ?? []) === []);

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed={$passed} failed={$failed}\n";
echo "{$BANNER}\n";

exit($failed > 0 ? 1 : 0);
