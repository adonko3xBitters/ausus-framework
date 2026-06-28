<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

/**
 * VALIDATION-002 — Teranga PMS, built ONLY from DSL + ViewDefinition.
 * Covers tests 1–4, 6–10 (test 5 is the React side, see pms-renderer.test.ts).
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
$root = sys_get_temp_dir() . '/teranga-' . bin2hex(random_bytes(4));
$factory = new RequestContextFactory(new DateTimeImmutable('@1700000000'));
$clerk = $factory->fromHeaders(['X-Tenant-ID' => 'teranga', 'X-Actor-Type' => 'clerk']);
$manager = $factory->fromHeaders(['X-Tenant-ID' => 'teranga', 'X-Actor-Type' => 'manager']);
$pmsEntities = ['user', 'hotel', 'roomtype', 'room', 'guest', 'reservation', 'stay', 'invoice', 'payment', 'housekeepingtask'];

$state = function (MemoryDriver $d, string $fqn, string $id, string $field) {
    $tenant = new Tenant(new TenantId('teranga'));
    $tx = $d->beginTransaction($tenant);
    $e = $d->context($tenant, $tx)->repository($fqn)->find(new Reference('teranga', $fqn, $id));
    $d->rollback($tx);
    return $e?->field($field);
};

echo "── Test 1 — full PMS compilation (DSL → .ausus) ────────────\n";
$code = (new CompileEntitiesCommand())->run($entitiesDir, $root, fopen('php://memory', 'r+'), fopen('php://memory', 'r+'));
$ok('Test 1 — compile SUCCESS', $code === CompileEntitiesCommand::SUCCESS);
$ok('Test 1 — 10 entity schemas', count(glob($root . '/schemas/*.json') ?: []) === 10);

echo "── Test 2 — load from FileSchemaRepository (no recompilation) ─\n";
$repo = new FileSchemaRepository($root);
$resolved = array_map(fn (string $e) => $repo->resolve($e)->identity, $pmsEntities);
$ok('Test 2 — all 10 entities resolvable from disk', count(array_filter($resolved)) === 10);

$engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$driver = new MemoryDriver();
$bind = fn (string $e) => $engine->bind($repo->resolve($e), $driver);

echo "── Test 10 — full business workflow Guest→Reservation→Stay→Invoice→Payment ─\n";
// supporting data
$roomType = $bind('roomtype')->invoke('create', ['name' => 'Deluxe', 'capacity' => 2, 'baseRate' => 150], $clerk);
$room = $bind('room')->invoke('create', ['number' => '101', 'floor' => 1, 'roomType' => $roomType->reference->identityHandle], $clerk);
$roomId = $room->reference->identityHandle;
// 1. Guest
$guest = $bind('guest')->invoke('create', ['firstName' => 'Awa', 'lastName' => 'Diop', 'email' => 'awa@teranga.test', 'phone' => '77'], $clerk);
$guestId = $guest->reference->identityHandle;
// 2. Reservation → confirm
$res = $bind('reservation')->invoke('create', ['code' => 'RES-1', 'checkInDate' => '2025-02-01', 'checkOutDate' => '2025-02-05', 'room' => $roomId, 'guest' => $guestId], $clerk);
$resId = $res->reference->identityHandle;
$bind('reservation')->invoke('confirm', ['id' => $resId], $clerk);
// 3. Stay (check-in → check-out)
$stay = $bind('stay')->invoke('checkIn', ['reservation' => $resId, 'actualCheckIn' => '2025-02-01'], $clerk);
$stayId = $stay->reference->identityHandle;
$bind('stay')->invoke('checkOut', ['id' => $stayId], $clerk);
// 4. Invoice (create → validate)
$invoice = $bind('invoice')->invoke('create', ['number' => 'INV-1', 'total' => 600, 'stay' => $stayId], $clerk);
$invId = $invoice->reference->identityHandle;
$bind('invoice')->invoke('validate', ['id' => $invId], $clerk);
// 5. Payment (register → confirm by manager)
$payment = $bind('payment')->invoke('register', ['amount' => 600, 'method' => 'card', 'invoice' => $invId], $clerk);
$payId = $payment->reference->identityHandle;
$bind('payment')->invoke('confirm', ['id' => $payId], $manager);
// 6. Housekeeping task
$task = $bind('housekeepingtask')->invoke('create', ['room' => $roomId, 'taskType' => 'cleaning'], $clerk);
$bind('housekeepingtask')->invoke('complete', ['id' => $task->reference->identityHandle], $clerk);

$ok('Test 10 — reservation confirmed', $state($driver, 'reservation', $resId, 'status') === 'confirmed');
$ok('Test 10 — stay checked out', $state($driver, 'stay', $stayId, 'status') === 'checked_out');
$ok('Test 10 — invoice validated', $state($driver, 'invoice', $invId, 'status') === 'validated');
$ok('Test 10 — payment confirmed', $state($driver, 'payment', $payId, 'status') === 'confirmed');
$ok('Test 10 — housekeeping task completed', $state($driver, 'housekeepingtask', $task->reference->identityHandle, 'status') === 'done');

echo "── Test 3 — runtime sequence (explicit) ────────────────────\n";
$ok('Test 3 — create guest', $guest->field('email') === 'awa@teranga.test');
$ok('Test 3 — create+confirm reservation', $state($driver, 'reservation', $resId, 'status') === 'confirmed');
$ok('Test 3 — check-in/check-out', $state($driver, 'stay', $stayId, 'status') === 'checked_out');
$ok('Test 3 — create invoice + register payment', $invoice->field('total') === 600 && $payment->field('amount') === 600);

echo "── Test 4 — API Runtime (schema / invoke / projection) ─────\n";
$api = new RuntimeApi($repo, $engine, $driver, $factory);
$h = ['X-Tenant-ID' => 'teranga', 'X-Actor-Type' => 'clerk'];
$schemaRes = $api->dispatch('GET', '/api/entities/reservation', $h);
$ok('Test 4 — GET schema', $schemaRes['status'] === 200
    && count($schemaRes['body']['actions']) === 3 && count($schemaRes['body']['projections']) === 2);
$invokeRes = $api->dispatch('POST', '/api/entities/guest/actions/create', $h, ['inputs' => ['firstName' => 'Bou', 'lastName' => 'Fall', 'email' => 'b@t.test']]);
$ok('Test 4 — POST invoke', $invokeRes['status'] === 200);
$projRes = $api->dispatch('GET', '/api/entities/payment/projections/board', $h);
$ok('Test 4 — GET projection', $projRes['status'] === 200 && count($projRes['body']['rows']) >= 1);

echo "── Test 6 — View System (PMS views map to real capabilities) ─\n";
/** @var \Ausus\View\ViewRegistry $views */
$views = require __DIR__ . '/../views/pms-views.php';
$nav = $views->navigation();
$ok('Test 6 — five PMS views', count($nav) === 5
    && array_column($nav, 'view') === ['dashboard', 'front-desk', 'housekeeping', 'billing', 'administration']);
$validSections = true;
$sectionCount = 0;
foreach ($views->all() as $view) {
    foreach ($view->pages as $page) {
        foreach ($page->sections as $section) {
            $sectionCount++;
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
$ok('Test 6 — every section maps to a real schema capability', $validSections && $sectionCount === 14);

echo "── Test 7 — expands (single-hop chains) ────────────────────\n";
$resDetail = $api->dispatch('GET', '/api/entities/reservation/projections/detail', $h)['body']['rows'];
$withGuestRoom = array_values(array_filter($resDetail, fn ($r) => is_array($r['guest'] ?? null) && is_array($r['room'] ?? null)));
$ok('Test 7 — Reservation → Guest', count($withGuestRoom) >= 1 && ($withGuestRoom[0]['guest']['lastName'] ?? null) === 'Diop');
$ok('Test 7 — Reservation → Room', ($withGuestRoom[0]['room']['number'] ?? null) === '101');
$roomDetail = $api->dispatch('GET', '/api/entities/room/projections/detail', $h)['body']['rows'];
$withType = array_values(array_filter($roomDetail, fn ($r) => is_array($r['roomType'] ?? null)));
$ok('Test 7 — Room → RoomType', count($withType) >= 1 && ($withType[0]['roomType']['name'] ?? null) === 'Deluxe');
// bonus chain links
$stayDetail = $api->dispatch('GET', '/api/entities/stay/projections/detail', $h)['body']['rows'];
$ok('Test 7 — Stay → Reservation (bonus)', is_array($stayDetail[0]['reservation'] ?? null));
$payBoard = $api->dispatch('GET', '/api/entities/payment/projections/board', $h)['body']['rows'];
$ok('Test 7 — Payment → Invoice (bonus)', is_array($payBoard[0]['invoice'] ?? null));

echo "── Test 9 — authorization (5 guard kinds) ──────────────────\n";
// permit + input-dependent: invoice.create total < 1,000,000
$permit = $api->dispatch('POST', '/api/entities/invoice/actions/create', $h, ['inputs' => ['number' => 'INV-OK', 'total' => 100, 'stay' => $stayId]]);
$ok('Test 9 — PERMIT (input guard): invoice.create total<1M → 200', $permit['status'] === 200);
$denyInput = $api->dispatch('POST', '/api/entities/invoice/actions/create', $h, ['inputs' => ['number' => 'INV-BIG', 'total' => 9999999, 'stay' => $stayId]]);
$ok('Test 9 — DENY (input guard): invoice.create total≥1M → 403', $denyInput['status'] === 403);
// subject-dependent: invoice.validate requires subject.total > 0
$zero = $bind('invoice')->invoke('create', ['number' => 'INV-ZERO', 'total' => 0, 'stay' => $stayId], $clerk);
$denied('Test 9 — DENY (subject guard): validate zero-total invoice', fn () => $bind('invoice')->invoke('validate', ['id' => $zero->reference->identityHandle], $clerk));
// actor-dependent + deny: payment.confirm requires actor.type=manager
$pay2 = $bind('payment')->invoke('register', ['amount' => 100, 'method' => 'cash', 'invoice' => $invId], $clerk);
$denied('Test 9 — DENY (actor guard): payment.confirm as clerk', fn () => $bind('payment')->invoke('confirm', ['id' => $pay2->reference->identityHandle], $clerk));
$ok('Test 9 — PERMIT (actor guard): payment.confirm as manager', $bind('payment')->invoke('confirm', ['id' => $pay2->reference->identityHandle], $manager)->field('status') === 'confirmed');

echo "── Test 8 — reload from .ausus (no recompilation) ──────────\n";
unset($repo, $engine, $driver, $api);
$repo2 = new FileSchemaRepository($root);
$engine2 = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo2);
$driver2 = new MemoryDriver();
$g = $engine2->bind($repo2->resolve('guest'), $driver2)->invoke('create', ['firstName' => 'Re', 'lastName' => 'Load', 'email' => 'r@l.test'], $clerk);
$ok('Test 8 — app functional after reload', $g->field('firstName') === 'Re'
    && count($engine2->bind($repo2->resolve('guest'), $driver2)->read('board', [], $clerk)) === 1);

// ── Fixtures for the React/View test (test 5) ───────────────────────────────
$fixtures = __DIR__ . '/.fixtures';
@mkdir($fixtures, 0o775, true);
$api2 = new RuntimeApi($repo2, $engine2, $driver2, $factory);
$schemas = [];
foreach ($pmsEntities as $e) {
    $schemas[$e] = $api2->dispatch('GET', "/api/entities/{$e}", $h)['body'];
}
file_put_contents($fixtures . '/schemas.json', json_encode($schemas));
file_put_contents($fixtures . '/views.json', json_encode($views->toArray()));
// a representative expanded projection (Reservation → Guest + Room), seeded on the reload driver
$rt2 = fn (string $e) => $engine2->bind($repo2->resolve($e), $driver2);
$g2 = $rt2('guest')->invoke('create', ['firstName' => 'Fatou', 'lastName' => 'Sow', 'email' => 'f@t.test'], $clerk);
$ty2 = $rt2('roomtype')->invoke('create', ['name' => 'Suite', 'capacity' => 4, 'baseRate' => 300], $clerk);
$rm2 = $rt2('room')->invoke('create', ['number' => '201', 'floor' => 2, 'roomType' => $ty2->reference->identityHandle], $clerk);
$rt2('reservation')->invoke('create', ['code' => 'RES-2', 'checkInDate' => '2025-03-01', 'checkOutDate' => '2025-03-03', 'room' => $rm2->reference->identityHandle, 'guest' => $g2->reference->identityHandle], $clerk);
file_put_contents($fixtures . '/reservation-detail.json', json_encode($api2->dispatch('GET', '/api/entities/reservation/projections/detail', $h)['body']));

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
    ? "VALIDATION-002 / Teranga PMS (PHP) OK — {$pass} checks passed\n"
    : "VALIDATION-002 / Teranga PMS (PHP) FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
