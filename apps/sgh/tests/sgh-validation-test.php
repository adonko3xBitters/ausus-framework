<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

/**
 * VALIDATION-003 — SGH (Hospital), built ONLY from DSL + ViewDefinition.
 * Covers tests 1–10 (test 5 React side: sgh-renderer.test.ts).
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
$root = sys_get_temp_dir() . '/sgh-' . bin2hex(random_bytes(4));
$factory = new RequestContextFactory(new DateTimeImmutable('@1700000000'));
$ctx = fn (string $type, string $tenant = 'sgh') => $factory->fromHeaders(['X-Tenant-ID' => $tenant, 'X-Actor-Type' => $type]);
$clerk = $ctx('clerk');
$doctor = $ctx('doctor');
$manager = $ctx('manager');
$sghEntities = ['user', 'department', 'doctor', 'patient', 'appointment', 'consultation', 'admission', 'bed', 'prescription', 'invoice', 'payment', 'medicalrecord'];

$state = function (MemoryDriver $d, string $fqn, string $id, string $field) {
    $tenant = new Tenant(new TenantId('sgh'));
    $tx = $d->beginTransaction($tenant);
    $e = $d->context($tenant, $tx)->repository($fqn)->find(new Reference('sgh', $fqn, $id));
    $d->rollback($tx);
    return $e?->field($field);
};
$id = fn ($entity) => $entity->reference->identityHandle;

echo "── Test 1 — full SGH compilation (DSL → .ausus) ────────────\n";
$code = (new CompileEntitiesCommand())->run($entitiesDir, $root, fopen('php://memory', 'r+'), fopen('php://memory', 'r+'));
$ok('Test 1 — compile SUCCESS', $code === CompileEntitiesCommand::SUCCESS);
$ok('Test 1 — 12 entity schemas', count(glob($root . '/schemas/*.json') ?: []) === 12);

echo "── Test 2 — load from FileSchemaRepository (no recompilation) ─\n";
$repo = new FileSchemaRepository($root);
$ok('Test 2 — all 12 entities resolvable from disk', count(array_filter(array_map(fn ($e) => $repo->resolve($e)->identity, $sghEntities))) === 12);

$engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$driver = new MemoryDriver();
$bind = fn (string $e) => $engine->bind($repo->resolve($e), $driver);

echo "── Test 10 — full hospital workflow ────────────────────────\n";
echo "──   Patient→Appointment→Consultation→Prescription→Admission→Invoice→Payment→MedicalRecord\n";
$dept = $bind('department')->invoke('create', ['name' => 'Cardiology', 'code' => 'CARD'], $clerk);
$user = $bind('user')->invoke('create', ['name' => 'Dr House', 'email' => 'house@sgh.test', 'role' => 'doctor'], $clerk);
$doc = $bind('doctor')->invoke('create', ['firstName' => 'Greg', 'lastName' => 'House', 'speciality' => 'Cardio', 'department' => $id($dept), 'user' => $id($user)], $clerk);
$bed = $bind('bed')->invoke('create', ['number' => 'B12', 'ward' => 'ICU', 'department' => $id($dept)], $clerk);
$bind('bed')->invoke('occupy', ['id' => $id($bed)], $clerk);

$patient = $bind('patient')->invoke('create', ['firstName' => 'Awa', 'lastName' => 'Ndiaye', 'dob' => '1990-04-12', 'gender' => 'f', 'phone' => '77'], $clerk);
$appt = $bind('appointment')->invoke('create', ['code' => 'APT-1', 'date' => '2025-05-01', 'patient' => $id($patient), 'doctor' => $id($doc)], $clerk);
$bind('appointment')->invoke('confirm', ['id' => $id($appt)], $clerk);
$bind('appointment')->invoke('complete', ['id' => $id($appt)], $clerk);
$consult = $bind('consultation')->invoke('create', ['diagnosis' => 'Arrhythmia', 'notes' => 'monitor', 'appointment' => $id($appt), 'doctor' => $id($doc)], $doctor);
$bind('consultation')->invoke('close', ['id' => $id($consult)], $doctor);
$presc = $bind('prescription')->invoke('create', ['code' => 'RX-1', 'medication' => 'Beta-blocker', 'dosage' => '50mg', 'consultation' => $id($consult), 'patient' => $id($patient)], $doctor);
$bind('prescription')->invoke('fulfill', ['id' => $id($presc)], $clerk);
$adm = $bind('admission')->invoke('create', ['code' => 'ADM-1', 'reason' => 'observation', 'patient' => $id($patient), 'bed' => $id($bed), 'consultation' => $id($consult)], $clerk);
$inv = $bind('invoice')->invoke('create', ['number' => 'INV-1', 'total' => 1200, 'admission' => $id($adm), 'patient' => $id($patient)], $clerk);
$bind('invoice')->invoke('validate', ['id' => $id($inv)], $clerk);
$pay = $bind('payment')->invoke('register', ['amount' => 1200, 'method' => 'insurance', 'invoice' => $id($inv)], $clerk);
$bind('payment')->invoke('confirm', ['id' => $id($pay)], $manager);
$bind('invoice')->invoke('markPaid', ['id' => $id($inv)], $clerk);
$bind('admission')->invoke('discharge', ['id' => $id($adm)], $clerk);
$bind('bed')->invoke('release', ['id' => $id($bed)], $clerk);
$mr = $bind('medicalrecord')->invoke('create', ['summary' => 'Cardiac follow-up', 'patient' => $id($patient), 'consultation' => $id($consult)], $clerk);
$bind('medicalrecord')->invoke('archive', ['id' => $id($mr)], $clerk);

$ok('Test 10 — appointment completed', $state($driver, 'appointment', $id($appt), 'status') === 'completed');
$ok('Test 10 — consultation closed', $state($driver, 'consultation', $id($consult), 'status') === 'closed');
$ok('Test 10 — prescription fulfilled', $state($driver, 'prescription', $id($presc), 'status') === 'fulfilled');
$ok('Test 10 — admission discharged', $state($driver, 'admission', $id($adm), 'status') === 'discharged');
$ok('Test 10 — invoice paid', $state($driver, 'invoice', $id($inv), 'status') === 'paid');
$ok('Test 10 — payment confirmed', $state($driver, 'payment', $id($pay), 'status') === 'confirmed');
$ok('Test 10 — medical record archived', $state($driver, 'medicalrecord', $id($mr), 'status') === 'archived');

echo "── Test 3 — runtime sequence (explicit checkpoints) ────────\n";
$ok('Test 3 — patient created', $patient->field('lastName') === 'Ndiaye');
$ok('Test 3 — consultation by doctor', $consult->field('diagnosis') === 'Arrhythmia');
$ok('Test 3 — bed occupied then released', $state($driver, 'bed', $id($bed), 'status') === 'free');

echo "── Test 4 — API Runtime (schema / invoke / projection) ─────\n";
$api = new RuntimeApi($repo, $engine, $driver, $factory);
$h = ['X-Tenant-ID' => 'sgh', 'X-Actor-Type' => 'clerk'];
$schemaRes = $api->dispatch('GET', '/api/entities/appointment', $h);
$ok('Test 4 — GET schema', $schemaRes['status'] === 200 && count($schemaRes['body']['actions']) === 4);
$invokeRes = $api->dispatch('POST', '/api/entities/patient/actions/create', $h, ['inputs' => ['firstName' => 'Api', 'lastName' => 'Patient', 'dob' => '2000-01-01', 'gender' => 'm']]);
$ok('Test 4 — POST invoke', $invokeRes['status'] === 200);
$ok('Test 4 — GET projection', $api->dispatch('GET', '/api/entities/invoice/projections/board', $h)['status'] === 200);

echo "── Test 6 — View System (SGH views) ────────────────────────\n";
/** @var \Ausus\View\ViewRegistry $views */
$views = require __DIR__ . '/../views/sgh-views.php';
$nav = $views->navigation();
$ok('Test 6 — six SGH views', count($nav) === 6
    && array_column($nav, 'view') === ['dashboard', 'patients', 'consultations', 'admissions', 'billing', 'administration']);
$valid = true;
$sections = 0;
foreach ($views->all() as $view) {
    foreach ($view->pages as $page) {
        foreach ($page->sections as $s) {
            $sections++;
            $schema = $repo->resolve($s->entity);
            $names = $s->kind() === 'projection' ? array_map(fn ($p) => $p->name, $schema->projections) : array_map(fn ($a) => $a->name, $schema->actions);
            if (!in_array($s->kind() === 'projection' ? $s->projection : $s->action, $names, true)) {
                $valid = false;
            }
        }
    }
}
$ok('Test 6 — every section maps to a real capability', $valid && $sections === 18);

echo "── Test 7 — expands (single-hop) ───────────────────────────\n";
$apptDetail = $api->dispatch('GET', '/api/entities/appointment/projections/detail', $h)['body']['rows'];
$withPD = array_values(array_filter($apptDetail, fn ($r) => is_array($r['patient'] ?? null) && is_array($r['doctor'] ?? null)));
$ok('Test 7 — Appointment → Patient', count($withPD) >= 1 && ($withPD[0]['patient']['lastName'] ?? null) === 'Ndiaye');
$ok('Test 7 — Appointment → Doctor', ($withPD[0]['doctor']['lastName'] ?? null) === 'House');
$docDetail = $api->dispatch('GET', '/api/entities/doctor/projections/detail', $h)['body']['rows'];
$ok('Test 7 — Doctor → Department', is_array($docDetail[0]['department'] ?? null) && ($docDetail[0]['department']['code'] ?? null) === 'CARD');
$invDetail = $api->dispatch('GET', '/api/entities/invoice/projections/detail', $h)['body']['rows'];
$ok('Test 7 — Invoice → Patient + Admission', is_array($invDetail[0]['patient'] ?? null) && is_array($invDetail[0]['admission'] ?? null));
$payBoard = $api->dispatch('GET', '/api/entities/payment/projections/board', $h)['body']['rows'];
$ok('Test 7 — Payment → Invoice', is_array($payBoard[0]['invoice'] ?? null));

echo "── Test 8 — authorization (actor / tenant / subject / input) ─\n";
// input + permit: invoice.create total < 1M
$ok('Test 8 — PERMIT (input): invoice.create total<1M', $api->dispatch('POST', '/api/entities/invoice/actions/create', $h, ['inputs' => ['number' => 'INV-OK', 'total' => 50, 'patient' => $id($patient)]])['status'] === 200);
$ok('Test 8 — DENY (input): invoice.create total≥1M', $api->dispatch('POST', '/api/entities/invoice/actions/create', $h, ['inputs' => ['number' => 'INV-BIG', 'total' => 9999999, 'patient' => $id($patient)]])['status'] === 403);
// subject: invoice.validate requires total ≥ 1
$zero = $bind('invoice')->invoke('create', ['number' => 'INV-0', 'total' => 0, 'patient' => $id($patient)], $clerk);
$denied('Test 8 — DENY (subject): validate zero-total invoice', fn () => $bind('invoice')->invoke('validate', ['id' => $id($zero)], $clerk));
// actor: consultation.create requires doctor
$denied('Test 8 — DENY (actor): consultation.create as clerk', fn () => $bind('consultation')->invoke('create', ['diagnosis' => 'x', 'appointment' => $id($appt), 'doctor' => $id($doc)], $clerk));
$ok('Test 8 — PERMIT (actor): consultation.create as doctor', $bind('consultation')->invoke('create', ['diagnosis' => 'y', 'appointment' => $id($appt), 'doctor' => $id($doc)], $doctor)->field('diagnosis') === 'y');
// tenant: medicalrecord.create only in tenant 'sgh'
$ok('Test 8 — PERMIT (tenant): medicalrecord.create in sgh', $bind('medicalrecord')->invoke('create', ['summary' => 'ok', 'patient' => $id($patient)], $ctx('clerk', 'sgh'))->field('summary') === 'ok');
$denied('Test 8 — DENY (tenant): medicalrecord.create in other tenant', fn () => $bind('medicalrecord')->invoke('create', ['summary' => 'no', 'patient' => $id($patient)], $ctx('clerk', 'other')));

echo "── Test 9 — reload from .ausus (no recompilation) ──────────\n";
unset($repo, $engine, $driver, $api);
$repo2 = new FileSchemaRepository($root);
$engine2 = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo2);
$driver2 = new MemoryDriver();
$p = $engine2->bind($repo2->resolve('patient'), $driver2)->invoke('create', ['firstName' => 'Re', 'lastName' => 'Load', 'dob' => '1980-01-01', 'gender' => 'm'], $clerk);
$ok('Test 9 — app functional after reload', $p->field('firstName') === 'Re'
    && count($engine2->bind($repo2->resolve('patient'), $driver2)->read('board', [], $clerk)) === 1);

// ── fixtures for the React/View test ────────────────────────────────────────
$fixtures = __DIR__ . '/.fixtures';
@mkdir($fixtures, 0o775, true);
$api2 = new RuntimeApi($repo2, $engine2, $driver2, $factory);
$schemas = [];
foreach ($sghEntities as $e) {
    $schemas[$e] = $api2->dispatch('GET', "/api/entities/{$e}", $h)['body'];
}
file_put_contents($fixtures . '/schemas.json', json_encode($schemas));
file_put_contents($fixtures . '/views.json', json_encode($views->toArray()));
$rt2 = fn (string $e) => $engine2->bind($repo2->resolve($e), $driver2);
$d2 = $rt2('department')->invoke('create', ['name' => 'Neuro', 'code' => 'NEU'], $clerk);
$dr2 = $rt2('doctor')->invoke('create', ['firstName' => 'Ada', 'lastName' => 'Ba', 'speciality' => 'Neuro', 'department' => $id($d2)], $clerk);
$pt2 = $rt2('patient')->invoke('create', ['firstName' => 'Mor', 'lastName' => 'Sy', 'dob' => '1995-02-02', 'gender' => 'm'], $clerk);
$rt2('appointment')->invoke('create', ['code' => 'APT-9', 'date' => '2025-06-01', 'patient' => $id($pt2), 'doctor' => $id($dr2)], $clerk);
file_put_contents($fixtures . '/appointment-detail.json', json_encode($api2->dispatch('GET', '/api/entities/appointment/projections/detail', $h)['body']));

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
    ? "VALIDATION-003 / SGH (PHP) OK — {$pass} checks passed\n"
    : "VALIDATION-003 / SGH (PHP) FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
