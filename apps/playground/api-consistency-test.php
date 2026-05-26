<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Application, BuiltinEffect, InvocationResult, Reference};
use Ausus\{NotFound, PolicyDenied, WorkflowStateMismatch};
use Ausus\Runtime\{EffectDispatcher, CreateEffect, TransitionEffect};
use Ausus\Api\Http\{Router, ErrorMapper};
use Nyholm\Psr7\Factory\Psr17Factory;
use Acme\Billing\HelloInvoiceDsl;

/**
 * AUSUS — v0.1.x public API consistency pass tests.
 *
 *  - BuiltinEffect enum has stable string values; EffectDispatcher uses it.
 *  - ActionBuilder::addTransition() is the canonical name; andTransition() is
 *    a backward-compatible alias.
 *  - Repository::findAll() returns entities (and ProjectionRenderer no longer
 *    uses reflection to reach into the SQLite PDO).
 *  - Application::run() returns a typed InvocationResult.
 *  - Application::auditSink() and ->router() hide the Ausus\Api\Http namespace.
 *  - ErrorMapper maps kernel exceptions to their documented HTTP statuses
 *    (the previous map referenced legacy class names and silently routed
 *    PolicyDenied / EffectFailed to 500).
 */

$BANNER = "═══ AUSUS — public API consistency pass ════════════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

$ROLES = ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'];

// ── test 1: BuiltinEffect enum values are stable ──────────────────────────────
echo "\n── test 1: BuiltinEffect enum values are stable ──────────────\n";
_assert('BuiltinEffect::Create->value     == kernel.builtin.create',
        BuiltinEffect::Create->value === 'kernel.builtin.create');
_assert('BuiltinEffect::Transition->value == kernel.builtin.transition',
        BuiltinEffect::Transition->value === 'kernel.builtin.transition');
_assert('tryFrom(create sentinel) resolves',
        BuiltinEffect::tryFrom('kernel.builtin.create') === BuiltinEffect::Create);
_assert('tryFrom(custom class FQN) is null',
        BuiltinEffect::tryFrom('Acme\\MyEffect') === null);

// ── test 2: EffectDispatcher resolves builtins through the enum ───────────────
echo "\n── test 2: EffectDispatcher resolves builtins via enum ───────\n";
$app = Application::create([
    'tenant' => 'acme',
    'roles'  => $ROLES,
])->register(new HelloInvoiceDsl())->boot();

$graph = $app->graph();
$dispatcher = new EffectDispatcher();
$createAction     = $graph->actions['billing.invoice.create'];
$transitionAction = $graph->actions['billing.invoice.issue'];
_assert('create  effect is CreateEffect',     $dispatcher->dispatch($createAction)     instanceof CreateEffect);
_assert('issue   effect is TransitionEffect', $dispatcher->dispatch($transitionAction) instanceof TransitionEffect);

// ── test 3: ActionBuilder::addTransition + andTransition parity ───────────────
echo "\n── test 3: addTransition() and andTransition() parity ────────\n";
$wf = $graph->workflows['billing.invoice.lifecycle'];
$cancelTransitions = array_values(array_filter(
    $wf->transitions, fn($t) => $t->viaActionFqn === 'billing.invoice.cancel',
));
_assert('cancel action has two transitions (DRAFT|ISSUED → CANCELLED)',
        count($cancelTransitions) === 2, 'actual=' . count($cancelTransitions));

use Ausus\{Dsl, DslPlugin, Field, Action};
$samePluginViaAdd = new class extends DslPlugin {
    public function name(): string         { return 'parity'; }
    public function phpNamespace(): string { return 'Parity'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('thing')
            ->fields(['status' => Field::enum('A', 'B', 'C')->default('A')])
            ->actions([
                'create' => Action::create()->requireRole('r'),
                'jump'   => Action::transition('status', from: 'A', to: 'C')
                              ->addTransition('status', from: 'B', to: 'C')
                              ->requireRole('r'),
            ])
            ->workflow(field: 'status', initial: 'A');
    }
};
$desc = $samePluginViaAdd->describe();
$jumpWf = $desc['workflows'][0];
$jumpTransitions = array_values(array_filter(
    $jumpWf->transitions, fn($t) => $t->viaActionFqn === 'parity.thing.jump',
));
_assert('addTransition() registers both transitions',
        count($jumpTransitions) === 2, 'actual=' . count($jumpTransitions));

// ── test 4: Repository::findAll() returns entities ────────────────────────────
echo "\n── test 4: Repository::findAll() returns entities ────────────\n";
$app->invoke('billing.invoice.create', null, [
    'number' => 'INV-API-001', 'customer_name' => 'A',
    'amount' => ['amount' => '10.00', 'currency' => 'USD'],
]);
$app->invoke('billing.invoice.create', null, [
    'number' => 'INV-API-002', 'customer_name' => 'B',
    'amount' => ['amount' => '20.00', 'currency' => 'USD'],
]);
$tenant = $app->tenant();
$tx = $app->driver()->beginTransaction($tenant);
$repo = $app->driver()->context($tenant, $tx)->repository('billing.invoice');
$all = $repo->findAll();
$app->driver()->commit($tx);
_assert('findAll() returns 2 entities', count($all) === 2, 'actual=' . count($all));
_assert('findAll() entries are Entity instances',
        $all[0] instanceof \Ausus\Entity);

// ── test 5: ProjectionRenderer renders list view without reflection ───────────
echo "\n── test 5: ProjectionRenderer renders list (no reflection) ───\n";
$summary = $app->render('billing.invoice.summary');
_assert('list view returns items',          count($summary['data']['items'] ?? []) === 2);
_assert('viewschema schemaVersion 1.0.0',   $summary['schemaVersion'] === '1.0.0');

// ── test 6: Application::run() returns a typed InvocationResult ───────────────
echo "\n── test 6: Application::run() → InvocationResult ─────────────\n";
$createResult = $app->run('billing.invoice.create', null, [
    'number' => 'INV-API-003', 'customer_name' => 'C',
    'amount' => ['amount' => '30.00', 'currency' => 'USD'],
]);
_assert('run() returns InvocationResult',     $createResult instanceof InvocationResult);
_assert('result.actionFqn matches input',     $createResult->actionFqn === 'billing.invoice.create');
_assert('result.subject is filled for create',
        $createResult->subject instanceof Reference
        && $createResult->subject->entityFqn === 'billing.invoice');
_assert('result.id() === outputs.id',
        $createResult->id() === ($createResult->outputs['id'] ?? null));

$transitionResult = $app->run('billing.invoice.issue', $createResult->subject);
_assert('transition result preserves subject',
        $transitionResult->subject?->identityHandle === $createResult->subject->identityHandle);
_assert('transition result outputs status = ISSUED',
        $transitionResult->output('status') === 'ISSUED');

// ── test 7: Application::auditSink() + ->router() facade ──────────────────────
echo "\n── test 7: Application accessors hide Ausus\\Api\\Http ────────\n";
_assert('auditSink() exposes an AuditSink', $app->auditSink() instanceof \Ausus\AuditSink);

$factory = new Psr17Factory();
$router  = $app->router($factory, $factory);
_assert('router() returns a Router',        $router instanceof Router);

// Issue a real PSR-7 request via the Router (no live server needed).
$healthReq = $factory->createServerRequest('GET', '/api/_health');
$healthRes = $router->handle($healthReq);
$health    = json_decode((string) $healthRes->getBody(), true);
_assert('/_health returns 200',             $healthRes->getStatusCode() === 200);
_assert('/_health graph hash matches',      ($health['graphHash'] ?? null) === $app->graph()->hash);

// ── test 8: ErrorMapper routes kernel exceptions to correct HTTP statuses ─────
echo "\n── test 8: ErrorMapper maps kernel exceptions correctly ──────\n";
foreach ([
    [new \Ausus\PolicyDenied('x'),            403, 'PolicyDenied'],
    [new \Ausus\TenantBoundaryViolation('x'), 403, 'TenantBoundaryViolation'],
    [new \Ausus\WorkflowStateMismatch('x'),   409, 'WorkflowStateMismatch'],
    [new \Ausus\UnknownAction('x'),           404, 'UnknownAction'],
    [new \Ausus\NotFound(new Reference('t','e','i')), 404, 'NotFound'],
    [new \Ausus\WorkflowSubjectNotFound('x'), 404, 'WorkflowSubjectNotFound'],
    [new \Ausus\AuditEmissionFailed('x'),     500, 'AuditEmissionFailed'],
] as [$e, $expectedStatus, $expectedKind]) {
    $r = ErrorMapper::toResponse($e, $factory, $factory);
    $body = json_decode((string) $r->getBody(), true);
    $cls = (new \ReflectionClass($e))->getShortName();
    _assert("ErrorMapper: {$cls} → {$expectedStatus} {$expectedKind}",
            $r->getStatusCode() === $expectedStatus && ($body['error']['kind'] ?? null) === $expectedKind,
            "got status=" . $r->getStatusCode() . " kind=" . ($body['error']['kind'] ?? 'null'));
}

// ── test 9: Live HTTP round-trip exercises the corrected mapping ──────────────
echo "\n── test 9: live HTTP route returns 403 for PolicyDenied ──────\n";
// Build a request that triggers PolicyDenied. The Router no longer falls back
// to any default role set when X-Actor-Roles is missing — omitting the header
// would already produce a 403. We send an explicit non-matching role to prove
// the policy gate fires on the role mismatch itself.
$req = $factory->createServerRequest('POST', '/api/actions/billing.invoice.create')
    ->withHeader('X-Tenant-ID', 'acme')
    ->withHeader('X-Actor-Roles', 'nobody')
    ->withHeader('Content-Type', 'application/json')
    ->withBody($factory->createStream(json_encode([
        'subject' => null,
        'inputs'  => ['number' => 'X', 'customer_name' => 'Y', 'amount' => ['amount' => '1.00', 'currency' => 'USD']],
    ])));
$res = $router->handle($req);
$resBody = json_decode((string) $res->getBody(), true);
_assert('roleless POST → 403 (was 500 before the fix)', $res->getStatusCode() === 403,
        'got ' . $res->getStatusCode());
_assert('error.kind == PolicyDenied',
        ($resBody['error']['kind'] ?? null) === 'PolicyDenied',
        'got ' . ($resBody['error']['kind'] ?? 'null'));

// ── test 10: ViewSchema exposes action.inputs (renderer form metadata) ────────
echo "\n── test 10: ViewSchema actions carry input descriptors ───────\n";
$schema    = $app->render('billing.invoice.summary');
$createDesc = null;
$cancelDesc = null;
foreach ($schema['actions'] as $a) {
    if ($a['fqn'] === 'billing.invoice.create') $createDesc = $a;
    if ($a['fqn'] === 'billing.invoice.cancel') $cancelDesc = $a;
}
_assert('create action exposes inputs array',
        isset($createDesc['inputs']) && is_array($createDesc['inputs']));
_assert('create inputs has 3 entries',
        count($createDesc['inputs'] ?? []) === 3,
        'actual=' . count($createDesc['inputs'] ?? []));

$byName = [];
foreach (($createDesc['inputs'] ?? []) as $i) $byName[$i['name']] = $i;

_assert('number input is string + required + has maxLength=32',
        ($byName['number']['type'] ?? null) === 'string'
        && ($byName['number']['required'] ?? null) === true
        && ($byName['number']['typeOptions']['maxLength'] ?? null) === 32);
_assert('customer_name input is string + required',
        ($byName['customer_name']['type'] ?? null) === 'string'
        && ($byName['customer_name']['required'] ?? null) === true);
_assert('amount input is money + required + currency=USD',
        ($byName['amount']['type'] ?? null) === 'money'
        && ($byName['amount']['required'] ?? null) === true
        && ($byName['amount']['typeOptions']['currency'] ?? null) === 'USD');
_assert('transition action exposes empty inputs array',
        ($cancelDesc['inputs'] ?? null) === []);

// Required is derived from `!nullable && default === null`. A fixture plugin
// with one defaulted enum input proves the negative case.
$defaultPlugin = new class extends \Ausus\DslPlugin {
    public function name(): string         { return 'defaulted'; }
    public function phpNamespace(): string { return 'Defaulted'; }
    public function dsl(\Ausus\Dsl $dsl): void {
        $dsl->entity('thing')
            ->fields([
                'label' => \Ausus\Field::string()->max(40),
                'mode'  => \Ausus\Field::enum('A', 'B')->default('A'),
            ])
            ->actions(['create' => \Ausus\Action::create('label', 'mode')->requireRole('thing.creator')])
            ->workflow(field: 'mode', initial: 'A')
            ->projection('summary', fields: ['id', 'label', 'mode'], actions: ['create'], role: 'thing.viewer');
    }
};
$defaultApp = Application::create([
    'tenant' => 'def', 'roles' => ['thing.creator', 'thing.viewer'],
])->register($defaultPlugin)->boot();
$schemaDef = $defaultApp->render('defaulted.thing.summary');
$createDef = null;
foreach ($schemaDef['actions'] as $a) if ($a['name'] === 'create') $createDef = $a;
$inputsByName = [];
foreach (($createDef['inputs'] ?? []) as $i) $inputsByName[$i['name']] = $i;
_assert('defaulted enum input: required=false, default present',
        ($inputsByName['mode']['required'] ?? null) === false
        && ($inputsByName['mode']['default'] ?? null) === 'A');
_assert('enum input carries options in typeOptions',
        ($inputsByName['mode']['typeOptions']['options'] ?? null) === ['A', 'B']);

// ── test 11: FieldBuilder::label() propagates through ViewSchema ──────────────
// Strictly additive feature — labels declared with ->label() flow into both
// `fields[].label` and `actions[].inputs[].label`; fields without a label
// fall back to ucfirst(str_replace('_', ' ', $name)).
echo "\n── test 11: FieldBuilder::label() propagation + fallback ─────\n";

$labelPlugin = new class extends \Ausus\DslPlugin {
    public function name(): string         { return 'lab'; }
    public function phpNamespace(): string { return 'Lab'; }
    public function dsl(\Ausus\Dsl $dsl): void {
        $dsl->entity('thing')
            ->fields([
                // Explicit label — overrides the humanised default.
                'project_id' => \Ausus\Field::string()->max(26)->label('Project'),
                // No label — keeps the auto-humanised fallback ("Customer name").
                'customer_name' => \Ausus\Field::string()->max(120),
                // Explicit label on a numeric input (proves labels travel on action inputs too).
                'qty'        => \Ausus\Field::integer()->label('Quantity'),
                // Defaulted enum — still gets its label propagated.
                'priority'   => \Ausus\Field::enum('LOW', 'HIGH')->default('LOW')->label('Priority level'),
                'state'      => \Ausus\Field::enum('A', 'B')->default('A'),   // workflow anchor
            ])
            ->actions([
                'create' => \Ausus\Action::create('project_id', 'customer_name', 'qty', 'priority')
                                ->requireRole('lab.writer'),
            ])
            ->workflow(field: 'state', initial: 'A')
            ->projection(
                'summary',
                fields:  ['id', 'project_id', 'customer_name', 'qty', 'priority', 'state', 'created_at'],
                actions: ['create'],
                role:    'lab.viewer',
            );
    }
};

$labApp = Application::create(
        \Ausus\ApplicationConfig::make()->tenant('lab')->roles(['lab.writer', 'lab.viewer'])
    )
    ->register($labelPlugin)
    ->boot();

$labSchema = $labApp->render('lab.thing.summary');
$labFields = [];
foreach ($labSchema['fields'] as $f) $labFields[$f['name']] = $f;

_assert('field with ->label("Project") emits label="Project"',
        ($labFields['project_id']['label'] ?? null) === 'Project');
_assert('field without label falls back to humanised name "Customer name"',
        ($labFields['customer_name']['label'] ?? null) === 'Customer name');
_assert('explicit label on integer field propagates',
        ($labFields['qty']['label'] ?? null) === 'Quantity');
_assert('explicit label on enum field propagates',
        ($labFields['priority']['label'] ?? null) === 'Priority level');
_assert('enum without explicit label still humanises (state → "State")',
        ($labFields['state']['label'] ?? null) === 'State');

// System fields are not user-declared and therefore never carry a label —
// they fall back to the humanised name on every projection.
_assert('system field "id" auto-humanises to "Id" (no breakage)',
        ($labFields['id']['label'] ?? null) === 'Id');
_assert('system field "created_at" auto-humanises to "Created at"',
        ($labFields['created_at']['label'] ?? null) === 'Created at');

// Action input descriptors carry the same labels.
$labCreate = null;
foreach ($labSchema['actions'] as $a) if ($a['name'] === 'create') $labCreate = $a;
$labInputs = [];
foreach (($labCreate['inputs'] ?? []) as $i) $labInputs[$i['name']] = $i;

_assert('action.inputs preserves the explicit label ("Project")',
        ($labInputs['project_id']['label'] ?? null) === 'Project');
_assert('action.inputs falls back for fields without ->label() (customer_name → "Customer name")',
        ($labInputs['customer_name']['label'] ?? null) === 'Customer name');
_assert('action.inputs preserves explicit label on integer ("Quantity")',
        ($labInputs['qty']['label'] ?? null) === 'Quantity');

// Validation — empty label rejected at the builder.
$caught = null;
try { \Ausus\Field::string()->label(''); }
catch (\Throwable $e) { $caught = $e; }
_assert('FieldBuilder::label("") throws InvalidArgumentException',
        $caught instanceof \InvalidArgumentException
        && str_contains($caught->getMessage(), 'non-empty'));

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed={$passed} failed={$failed}\n";
echo "{$BANNER}\n";

exit($failed > 0 ? 1 : 0);
