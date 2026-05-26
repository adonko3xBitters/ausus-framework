<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{
    Application, ApplicationConfig, BuiltinEffect, Dsl, DslPlugin, Field, Action,
    PolicyDenied, NotFound, ConcurrencyConflict,
};
use Ausus\Api\Http\Router;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * AUSUS — Action::update(...) implementation tests (ADR-0002).
 *
 * Exercises:
 *   1. DSL: compile-time validations (workflow-state, unknown field,
 *      system field, empty form).
 *   2. ActionNode shape: effectClass, subjectRequired, inputs, effectConfig.
 *   3. UpdateEffect runtime: partial patch, closed-list rejection,
 *      non-nullable null rejection, idempotent no-op on empty patch.
 *   4. Optimistic concurrency: stale write rejected.
 *   5. Pipeline: policy + audit still run for update.
 *   6. ViewSchema: inputs[] populated, initialValues on detail projection,
 *      absent on list projection.
 *   7. Live HTTP: 200 on valid update; closed-list violation → 500
 *      (kernel exception mapped to InternalError until a typed BadRequest
 *      kernel exception lands — see ADR-0002 §6 / §13).
 */

$BANNER = "═══ AUSUS — Action::update(...) (ADR-0002) ══════════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

function _throws(string $name, callable $fn, string $needle): void {
    $caught = null;
    try { $fn(); } catch (\Throwable $e) { $caught = $e; }
    global $passed, $failed;
    if ($caught !== null && str_contains($caught->getMessage(), $needle)) {
        echo "  ✓ {$name}\n"; $passed++;
    } else {
        echo "  ✗ {$name} — expected message containing \"{$needle}\", got "
            . ($caught === null ? 'no exception' : get_class($caught) . ': ' . $caught->getMessage()) . "\n";
        $failed++;
    }
}

// ── Fixture plugin — three update actions on a workflow-bearing entity ───────
final class TodoPlugin extends DslPlugin {
    public function name(): string         { return 'todo'; }
    public function phpNamespace(): string { return 'Todo'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('item')
            ->fields([
                'title'    => Field::string()->max(200),
                'note'     => Field::string()->max(2000)->nullable(),
                'priority' => Field::enum('LOW', 'NORMAL', 'HIGH')->default('NORMAL'),
                'cost'     => Field::money()->currency('EUR')->nullable(),
                'count'    => Field::integer()->default(0),
                'status'   => Field::enum('OPEN', 'CLOSED')->default('OPEN'),
            ])
            ->actions([
                'create'   => Action::create('title', 'note', 'priority', 'cost', 'count')
                                  ->requireRole('todo.writer'),
                'rename'   => Action::update('title')
                                  ->requireRole('todo.editor'),
                'reprice'  => Action::update('cost')
                                  ->requireRole('todo.editor'),
                'edit'     => Action::update('title', 'note', 'priority', 'cost', 'count')
                                  ->requireRole('todo.editor'),
                'close'    => Action::transition('status', from: 'OPEN', to: 'CLOSED')
                                  ->requireRole('todo.editor'),
            ])
            ->workflow(field: 'status', initial: 'OPEN')
            ->projection(
                'list',
                fields:  ['id', 'title', 'priority', 'status'],
                actions: ['create', 'rename', 'close'],
                role:    'todo.viewer',
            )
            ->projection(
                'detail',
                fields:  ['id', 'title', 'note', 'priority', 'cost', 'count', 'status'],
                actions: ['rename', 'reprice', 'edit', 'close'],
                role:    'todo.viewer',
            );
    }
}

// ── test 1: DSL compile-time validations ─────────────────────────────────────
echo "\n── 1. DSL compile-time validation ───────────────────────────\n";

_throws('Action::update() with no fields is rejected at the static factory',
    fn() => Action::update(),
    'requires at least one field name');

_throws('update(\'status\') on a workflow-state field is rejected at compile',
    fn() => (new class extends DslPlugin {
        public function name(): string         { return 'bad1'; }
        public function phpNamespace(): string { return 'Bad1'; }
        public function dsl(Dsl $dsl): void {
            $dsl->entity('x')
                ->fields(['state' => Field::enum('A', 'B')->default('A')])
                ->actions([
                    'create'  => Action::create()->requireRole('r'),
                    'tweak'   => Action::update('state')->requireRole('r'),
                ])
                ->workflow(field: 'state', initial: 'A');
        }
    })->describe(),
    'cannot patch the workflow state field');

_throws('update(\'id\') on a system field is rejected at compile',
    fn() => (new class extends DslPlugin {
        public function name(): string         { return 'bad2'; }
        public function phpNamespace(): string { return 'Bad2'; }
        public function dsl(Dsl $dsl): void {
            $dsl->entity('x')
                ->fields(['t' => Field::string()->max(20)])
                ->actions([
                    'create' => Action::create('t')->requireRole('r'),
                    'rewrite'=> Action::update('id')->requireRole('r'),
                ]);
        }
    })->describe(),
    'cannot patch a system field');

_throws('update(\'missing\') on an unknown field is rejected at compile',
    fn() => (new class extends DslPlugin {
        public function name(): string         { return 'bad3'; }
        public function phpNamespace(): string { return 'Bad3'; }
        public function dsl(Dsl $dsl): void {
            $dsl->entity('x')
                ->fields(['t' => Field::string()->max(20)])
                ->actions([
                    'create' => Action::create('t')->requireRole('r'),
                    'fix'    => Action::update('missing')->requireRole('r'),
                ]);
        }
    })->describe(),
    'refers to a field that is not declared');

// ── test 2: ActionNode shape (update kind) ───────────────────────────────────
echo "\n── 2. ActionNode shape for update ───────────────────────────\n";
$app = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')
            ->roles(['todo.writer', 'todo.editor', 'todo.viewer'])
    )
    ->register(new TodoPlugin())
    ->boot();

$graph = $app->graph();
$rename = $graph->actions['todo.item.rename'];
_assert('rename.effectClass === BuiltinEffect::Update->value',
        $rename->effectClass === BuiltinEffect::Update->value);
_assert('rename.subjectRequired === true',
        $rename->subjectRequired === true);
_assert('rename.inputs lists exactly the one patchable field (title)',
        count($rename->inputs) === 1 && $rename->inputs[0]->name === 'title');
_assert('rename.effectConfig.updatableFields carries name+type+nullable',
        ($rename->effectConfig['updatableFields'][0]['name'] ?? null) === 'title'
        && ($rename->effectConfig['updatableFields'][0]['nullable'] ?? null) === false);

$edit = $graph->actions['todo.item.edit'];
_assert('edit.inputs lists all 5 patchable fields',
        count($edit->inputs) === 5);
_assert('edit.effectConfig.updatableFields includes "note" with nullable=true',
        (function() use ($edit) {
            foreach ($edit->effectConfig['updatableFields'] as $f) {
                if ($f['name'] === 'note') return $f['nullable'] === true;
            }
            return false;
        })());

// ── test 3: UpdateEffect runtime semantics ───────────────────────────────────
echo "\n── 3. UpdateEffect runtime ──────────────────────────────────\n";

$seed = $app->run('todo.item.create', null, [
    'title' => 'Original', 'note' => 'first', 'priority' => 'NORMAL',
    'count' => 1,
]);
$ref = $seed->subject;
_assert('seed item created (status=OPEN, priority=NORMAL)',
        $seed->output('status') === 'OPEN' && $seed->output('priority') === 'NORMAL');

// 3a: partial PATCH — only the listed field is in the patch and in outputs.
$renamed = $app->invoke('todo.item.rename', $ref, ['title' => 'Renamed']);
_assert('rename returns the new title in outputs',
        ($renamed['title'] ?? null) === 'Renamed');
_assert('rename outputs do not carry untouched fields (PATCH not PUT)',
        !array_key_exists('note', $renamed)
        && !array_key_exists('priority', $renamed));
_assert('rename returns the new _version',
        is_string($renamed['_version'] ?? null) && strlen($renamed['_version']) === 26);

// 3b: idempotent no-op when inputs is empty.
$noop = $app->invoke('todo.item.rename', $ref, []);
_assert('empty patch is a no-op (only _version returned)',
        array_keys($noop) === ['_version']);

// 3c: closed-list rejection — caller may not patch a field outside the action.
$caught = null;
try { $app->invoke('todo.item.rename', $ref, ['priority' => 'HIGH']); }
catch (\Throwable $e) { $caught = $e; }
_assert('payload key outside updatableFields raises an EffectFailed',
        $caught !== null && str_contains($caught->getMessage(), "'priority' is not patchable"));

// 3d: non-nullable + null → rejected.
$caught = null;
try { $app->invoke('todo.item.rename', $ref, ['title' => null]); }
catch (\Throwable $e) { $caught = $e; }
_assert('null on a non-nullable field is rejected',
        $caught !== null && str_contains($caught->getMessage(), "'title' is not nullable"));

// 3e: nullable + null → accepted, stored as SQL NULL (rides on the v0.1.x
// serializeField null guard).
$nulled = $app->invoke('todo.item.edit', $ref, ['note' => null]);
_assert('null on a nullable field is accepted',
        array_key_exists('note', $nulled) && $nulled['note'] === null);

// 3f: workflow state still flows through transitions only.
$closed = $app->invoke('todo.item.close', $ref);
_assert('the existing close transition still works (state moves untouched)',
        ($closed['status'] ?? null) === 'CLOSED');

// ── test 4: optimistic-lock conflict on stale version ────────────────────────
echo "\n── 4. optimistic concurrency on update ──────────────────────\n";
$conflictApp = Application::create(
        ApplicationConfig::make()->tenant('acme')->roles(['todo.writer', 'todo.editor', 'todo.viewer'])
    )
    ->register(new TodoPlugin())
    ->boot();
$conflictSeed = $conflictApp->run('todo.item.create', null, [
    'title' => 'X', 'note' => null, 'priority' => 'NORMAL', 'count' => 0,
]);
// Manually pin the version we just read, then write twice — second write
// must race with the first via the repository's `_version` check.
$cRef = $conflictSeed->subject;
$tx   = $conflictApp->driver()->beginTransaction($conflictApp->tenant());
$repo = $conflictApp->driver()->context($conflictApp->tenant(), $tx)->repository('todo.item');
$stale = $repo->find($cRef);
$conflictApp->driver()->commit($tx);

$conflictApp->invoke('todo.item.rename', $cRef, ['title' => 'Y']);   // bumps version
$caught = null;
try {
    // Direct repository write with the *stale* version — bypassing UpdateEffect
    // to confirm the underlying lock fires.
    $tx2 = $conflictApp->driver()->beginTransaction($conflictApp->tenant());
    $r2  = $conflictApp->driver()->context($conflictApp->tenant(), $tx2)->repository('todo.item');
    $r2->update($cRef, ['title' => 'Z'], $stale->version);
} catch (\Throwable $e) { $caught = $e; }
finally { try { $conflictApp->driver()->rollback($tx2 ?? null); } catch (\Throwable) {} }
_assert('stale write raises ConcurrencyConflict (underlying lock still fires)',
        $caught instanceof ConcurrencyConflict);

// ── test 5: pipeline — policy + audit still run for update ───────────────────
echo "\n── 5. Invoker pipeline: policy + audit on update ────────────\n";
$viewer = Application::create(
        ApplicationConfig::make()->tenant('acme')->roles(['todo.viewer'])
    )
    ->register(new TodoPlugin())
    ->boot();
$caught = null;
try { $viewer->invoke('todo.item.rename', $ref, ['title' => 'NopeRename']); }
catch (\Throwable $e) { $caught = $e; }
_assert('viewer role cannot rename — PolicyDenied',
        $caught instanceof PolicyDenied);

// And audit: after our successful rename above, kernel_audit_log should hold
// one row for todo.item.rename with action_fqn matching.
$pdo = $app->pdo();
$auditRows = $pdo->query("SELECT action_fqn FROM kernel_audit_log WHERE action_fqn = 'todo.item.rename'")
    ->fetchAll(\PDO::FETCH_ASSOC);
_assert('audit log has at least one rename entry',
        count($auditRows) >= 1);

// ── test 6: ViewSchema — inputs + initialValues ──────────────────────────────
echo "\n── 6. ViewSchema: inputs + initialValues ────────────────────\n";

$listSchema = $app->render('todo.item.list');
$detailSchema = $app->render('todo.item.detail', $ref);

$listActions = [];
foreach ($listSchema['actions'] as $a) $listActions[$a['name']] = $a;
$detailActions = [];
foreach ($detailSchema['actions'] as $a) $detailActions[$a['name']] = $a;

_assert('list view: rename action has inputs[]',
        is_array($listActions['rename']['inputs'] ?? null)
        && count($listActions['rename']['inputs']) === 1);
_assert('list view: rename has NO initialValues (no single subject)',
        !array_key_exists('initialValues', $listActions['rename']));
_assert('list view: create has NO initialValues (it is not an update)',
        !array_key_exists('initialValues', $listActions['create']));

_assert('detail view: rename has initialValues = { title: <current> }',
        is_array($detailActions['rename']['initialValues'] ?? null)
        && $detailActions['rename']['initialValues']['title'] === 'Renamed');
_assert('detail view: edit has all 5 prefill keys',
        is_array($detailActions['edit']['initialValues'] ?? null)
        && count(array_keys($detailActions['edit']['initialValues'])) === 5);
_assert('detail view: close (transition) has NO initialValues',
        !array_key_exists('initialValues', $detailActions['close']));
_assert('detail view: edit.initialValues.note === null (after the explicit clear in test 3e)',
        $detailActions['edit']['initialValues']['note'] === null);

// Input metadata is the same shape on update as on create — required + nullable
// + default + typeOptions still flow.
$editInputs = [];
foreach ($detailActions['edit']['inputs'] as $i) $editInputs[$i['name']] = $i;
_assert('update inputs preserve required flag (title required)',
        ($editInputs['title']['required'] ?? null) === true);
_assert('update inputs preserve nullable flag (note nullable)',
        ($editInputs['note']['nullable'] ?? null) === true);
_assert('update inputs preserve default (priority default = NORMAL)',
        ($editInputs['priority']['default'] ?? null) === 'NORMAL');
_assert('update inputs preserve typeOptions (cost.currency = EUR)',
        ($editInputs['cost']['typeOptions']['currency'] ?? null) === 'EUR');

// ── test 7: live HTTP — full PSR-7 round-trip on an update ───────────────────
echo "\n── 7. live HTTP: POST /api/actions/todo.item.rename ─────────\n";
$factory = new Psr17Factory();
$httpApp = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')
            ->roles(['todo.writer', 'todo.editor', 'todo.viewer'])
            ->psr17($factory)
    )
    ->register(new TodoPlugin())
    ->boot();
$httpSeed = $httpApp->run('todo.item.create', null, [
    'title' => 'wire', 'note' => null, 'priority' => 'NORMAL', 'count' => 0,
]);
$httpRef = $httpSeed->subject;

$req = $factory->createServerRequest('POST', '/api/actions/todo.item.rename')
    ->withHeader('X-Tenant-ID', 'acme')
    ->withHeader('X-Actor-Roles', 'todo.editor')
    ->withHeader('Content-Type', 'application/json')
    ->withBody($factory->createStream(json_encode([
        'subject' => [
            'tenantId' => 'acme', 'entityFqn' => 'todo.item',
            'identityHandle' => $httpRef->identityHandle,
        ],
        'inputs' => ['title' => 'wired'],
    ])));
$res = $httpApp->http($req);
$body = json_decode((string) $res->getBody(), true);
_assert('HTTP update → 200',
        $res->getStatusCode() === 200, 'status=' . $res->getStatusCode());
_assert('HTTP update body: ok=true, outputs.title = "wired"',
        ($body['ok'] ?? null) === true && ($body['outputs']['title'] ?? null) === 'wired');

// Closed-list violation over HTTP — kernel surfaces the runtime error as
// EffectFailed (and ErrorMapper maps it to 500).
$badReq = $factory->createServerRequest('POST', '/api/actions/todo.item.rename')
    ->withHeader('X-Tenant-ID', 'acme')
    ->withHeader('X-Actor-Roles', 'todo.editor')
    ->withHeader('Content-Type', 'application/json')
    ->withBody($factory->createStream(json_encode([
        'subject' => [
            'tenantId' => 'acme', 'entityFqn' => 'todo.item',
            'identityHandle' => $httpRef->identityHandle,
        ],
        'inputs' => ['priority' => 'HIGH'],   // not patchable by rename
    ])));
$badRes = $httpApp->http($badReq);
$badBody = json_decode((string) $badRes->getBody(), true);
_assert('HTTP closed-list violation → 500 EffectFailed (see ADR-0002 §13)',
        $badRes->getStatusCode() === 500
        && ($badBody['error']['kind'] ?? null) === 'EffectFailed');

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed={$passed} failed={$failed}\n";
echo "{$BANNER}\n";

exit($failed > 0 ? 1 : 0);
