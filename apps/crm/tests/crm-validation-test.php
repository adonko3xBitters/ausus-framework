<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

/**
 * VALIDATION-001 — CRM reference application, built ONLY from DSL + ViewDefinition.
 * Covers tests 1–4, 6–9 (test 5 is the React side, see crm-renderer.test.ts).
 */

use Ausus\Api\Runtime\Http\RequestContextFactory;
use Ausus\Api\Runtime\Http\RuntimeApi;
use Ausus\Cli\Command\CompileEntitiesCommand;
use Ausus\Cli\Repository\FileSchemaRepository;
use Ausus\Engine\Runtime\DefaultAuthorizationEvaluator;
use Ausus\Engine\Runtime\DefaultEntityEngine;
use Ausus\Persistence\Memory\MemoryDriver;
use Ausus\Reference;
use Ausus\Tenant;
use Ausus\TenantId;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};
$denied = function (string $label, callable $fn) use ($ok): void {
    try {
        $fn();
        $ok($label . ' → refused', false);
    } catch (\Throwable $e) {
        $ok($label, true);
    }
};

$entitiesDir = __DIR__ . '/../entities';
$root = sys_get_temp_dir() . '/ausus-crm-' . bin2hex(random_bytes(4));
$factory = new RequestContextFactory(new DateTimeImmutable('@1700000000'));
$user = $factory->fromHeaders(['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user']);
$manager = $factory->fromHeaders(['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'manager']);
$system = $factory->fromHeaders(['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'system']);
$crmEntities = ['user', 'customer', 'opportunity', 'activity', 'task'];

$findState = function (MemoryDriver $d, string $fqn, string $id, string $field) {
    $tenant = new Tenant(new TenantId('acme'));
    $tx = $d->beginTransaction($tenant);
    $e = $d->context($tenant, $tx)->repository($fqn)->find(new Reference('acme', $fqn, $id));
    $d->rollback($tx);
    return $e?->field($field);
};

echo "── Test 1 — full CRM compilation (DSL → .ausus) ────────────\n";
$code = (new CompileEntitiesCommand())->run($entitiesDir, $root, fopen('php://memory', 'r+'), fopen('php://memory', 'r+'));
$schemaFiles = glob($root . '/schemas/*.json') ?: [];
$ok('Test 1 — compile SUCCESS', $code === CompileEntitiesCommand::SUCCESS);
$ok('Test 1 — 5 entity schemas produced', count($schemaFiles) === 5);

echo "── Test 2 — load from FileSchemaRepository (no recompilation) ─\n";
$repo = new FileSchemaRepository($root); // fresh, reads disk only
$resolved = array_map(fn (string $e) => $repo->resolve($e)->identity, $crmEntities);
sort($resolved);
$ok('Test 2 — all 5 entities resolvable from disk', $resolved === ['activity', 'customer', 'opportunity', 'task', 'user']);

$engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$driver = new MemoryDriver();
$bind = fn (string $e) => $engine->bind($repo->resolve($e), $driver);

echo "── Test 3 — runtime (create/activate/win/complete …) ───────\n";
$customer = $bind('customer')->invoke('create', ['name' => 'Globex', 'email' => 'ops@globex.test', 'phone' => '555'], $user);
$custId = $customer->reference->identityHandle;
$bind('customer')->invoke('activate', ['id' => $custId], $user);
$ok('Test 3 — customer created + activated', $findState($driver, 'customer', $custId, 'status') === 'active');

$owner = $bind('user')->invoke('create', ['name' => 'Sam Sales', 'email' => 'sam@acme.test'], $user);
$ownerId = $owner->reference->identityHandle;

$opp = $bind('opportunity')->invoke('create', ['title' => 'Big deal', 'amount' => 5000, 'customer' => $custId], $user);
$oppId = $opp->reference->identityHandle;
$bind('opportunity')->invoke('qualify', ['id' => $oppId], $user);
$bind('opportunity')->invoke('win', ['id' => $oppId], $manager); // win requires manager
$ok('Test 3 — opportunity created → qualified → won', $findState($driver, 'opportunity', $oppId, 'stage') === 'won');

$task = $bind('task')->invoke('create', ['title' => 'Call Globex', 'dueDate' => '2025-01-15', 'owner' => $ownerId], $user);
$taskId = $task->reference->identityHandle;
$bind('task')->invoke('complete', ['id' => $taskId], $user);
$ok('Test 3 — task created + completed', $findState($driver, 'task', $taskId, 'status') === 'done');

$bind('activity')->invoke('create', ['type' => 'call', 'subject' => 'Intro call', 'customer' => $custId], $user);
$ok('Test 3 — activity created', count($bind('activity')->read('board', [], $user)) === 1);

echo "── Test 4 — API Runtime (schema / invoke / projection) ─────\n";
$api = new RuntimeApi($repo, $engine, $driver, $factory);
$h = ['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user'];
$schemaRes = $api->dispatch('GET', '/api/entities/opportunity', $h);
$ok('Test 4 — GET schema → 200 + actions/projections', $schemaRes['status'] === 200
    && in_array('win', array_column($schemaRes['body']['actions'], 'name'), true)
    && in_array('pipeline', array_column($schemaRes['body']['projections'], 'name'), true));
$invokeRes = $api->dispatch('POST', '/api/entities/user/actions/create', $h, ['inputs' => ['name' => 'Api User', 'email' => 'api@acme.test']]);
$ok('Test 4 — POST invoke → 200', $invokeRes['status'] === 200 && $invokeRes['body']['fields']['name'] === 'Api User');
$projRes = $api->dispatch('GET', '/api/entities/customer/projections/board', $h);
$ok('Test 4 — GET projection → 200 + rows', $projRes['status'] === 200 && count($projRes['body']['rows']) >= 1);

echo "── Test 6 — View System (CRM views) ────────────────────────\n";
/** @var \Ausus\View\ViewRegistry $views */
$views = require __DIR__ . '/../views/crm-views.php';
$nav = $views->navigation();
$ok('Test 6 — five CRM views registered', count($nav) === 5
    && array_column($nav, 'view') === ['crm-dashboard', 'customers', 'sales', 'activities', 'administration']);
// every section must point at an entity capability that actually exists in the schemas
$validSections = true;
foreach ($views->all() as $view) {
    foreach ($view->pages as $page) {
        foreach ($page->sections as $section) {
            $schema = $repo->resolve($section->entity);
            $names = $section->kind() === 'projection'
                ? array_map(fn ($p) => $p->name, $schema->projections)
                : array_map(fn ($a) => $a->name, $schema->actions);
            $target = $section->kind() === 'projection' ? $section->projection : $section->action;
            if (!in_array($target, $names, true)) {
                $validSections = false;
            }
        }
    }
}
$ok('Test 6 — every view section maps to a real schema capability', $validSections);

echo "── Test 7 — expand Opportunity → Customer ──────────────────\n";
$pipeline = $api->dispatch('GET', '/api/entities/opportunity/projections/pipeline', $h);
$pipelineRows = $pipeline['body']['rows'];
$expanded = array_values(array_filter($pipelineRows, fn ($r) => is_array($r['customer'] ?? null)));
$ok('Test 7 — pipeline row carries expanded customer.board', count($expanded) >= 1
    && ($expanded[0]['customer']['name'] ?? null) === 'Globex'
    && array_key_exists('status', $expanded[0]['customer']));

echo "── Test 8 — authorization (permit + deny) ──────────────────\n";
// permit: customer.create as user
$permit = $api->dispatch('POST', '/api/entities/customer/actions/create', ['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user'], ['inputs' => ['name' => 'Initech', 'email' => 'i@i.test']]);
$ok('Test 8 — guard PERMIT: customer.create as user → 200', $permit['status'] === 200);
// deny: customer.create as system
$denyCreate = $api->dispatch('POST', '/api/entities/customer/actions/create', ['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'system'], ['inputs' => ['name' => 'X', 'email' => 'x@x.test']]);
$ok('Test 8 — guard DENY: customer.create as system → 403', $denyCreate['status'] === 403);
// deny: opportunity.win as user (needs a qualified opp)
$opp2 = $bind('opportunity')->invoke('create', ['title' => 'Deal 2', 'amount' => 100, 'customer' => $custId], $user);
$bind('opportunity')->invoke('qualify', ['id' => $opp2->reference->identityHandle], $user);
$denied('Test 8 — guard DENY: opportunity.win as user', fn () => $bind('opportunity')->invoke('win', ['id' => $opp2->reference->identityHandle], $user));
$ok('Test 8 — guard PERMIT: opportunity.win as manager', $bind('opportunity')->invoke('win', ['id' => $opp2->reference->identityHandle], $manager)->field('stage') === 'won');

echo "── Test 9 — reload from .ausus (no recompilation) ──────────\n";
// "Stop" everything; reload schemas from the same .ausus into brand-new objects.
unset($repo, $engine, $driver, $api);
$repo2 = new FileSchemaRepository($root);
$engine2 = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo2);
$driver2 = new MemoryDriver();
$reloaded = $engine2->bind($repo2->resolve('customer'), $driver2);
$c = $reloaded->invoke('create', ['name' => 'Reload Co', 'email' => 'r@r.test'], $user);
$ok('Test 9 — app functional after reload from .ausus', $c->field('name') === 'Reload Co'
    && count($reloaded->read('board', [], $user)) === 1);

// ── Fixtures for the React/View renderer test (test 5 + 6 UI) ────────────────
$fixtures = __DIR__ . '/.fixtures';
@mkdir($fixtures, 0o775, true);
$api2 = new RuntimeApi($repo2, $engine2, $driver2, $factory);
$schemas = [];
foreach ($crmEntities as $e) {
    $schemas[$e] = $api2->dispatch('GET', "/api/entities/{$e}", $h)['body'];
}
file_put_contents($fixtures . '/schemas.json', json_encode($schemas));
file_put_contents($fixtures . '/views.json', json_encode($views->toArray()));
// a representative pipeline projection (seed a customer + opportunity on the reload driver)
$cust2 = $engine2->bind($repo2->resolve('customer'), $driver2)->invoke('create', ['name' => 'Umbrella', 'email' => 'u@u.test'], $user);
$engine2->bind($repo2->resolve('opportunity'), $driver2)->invoke('create', ['title' => 'Pipeline deal', 'amount' => 9000, 'customer' => $cust2->reference->identityHandle], $user);
file_put_contents($fixtures . '/pipeline.json', json_encode($api2->dispatch('GET', '/api/entities/opportunity/projections/pipeline', $h)['body']));

// cleanup compiled artefacts (keep fixtures for the TS test)
$rrm = function (string $d) use (&$rrm): void {
    foreach (@scandir($d) ?: [] as $x) {
        if ($x === '.' || $x === '..') {
            continue;
        }
        $p = $d . '/' . $x;
        is_dir($p) ? $rrm($p) : @unlink($p);
    }
    @rmdir($d);
};
$rrm($root);

echo "\n";
echo $fail === 0
    ? "VALIDATION-001 / CRM (PHP) OK — {$pass} checks passed\n"
    : "VALIDATION-001 / CRM (PHP) FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
