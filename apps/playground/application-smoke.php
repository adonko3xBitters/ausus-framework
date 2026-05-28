<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\Application;
use Ausus\{Compiler, Tenant, TenantId, ActorRef, StubActor, Reference};
use Ausus\Persistence\Sql\{SqlitePersistenceDriver, SchemaDeriver, DatabaseAuditSink};
use Ausus\Runtime\{
    PolicyEngine, WorkflowRuntime, TransitionSetIndex, EffectDispatcher,
    DefaultAuditor, SequenceCounter, Invoker,
};
use Acme\Billing\HelloInvoiceDsl;

/**
 * AUSUS — Ausus\Application bootstrap smoke test.
 *
 * Verifies the high-level Application facade:
 *   - bootstrap works (create → register → boot)
 *   - invoke works
 *   - workflows still execute and still reject illegal transitions
 *   - existing behaviour is preserved (policy denial, tenant boundary,
 *     projection render)
 *   - the low-level Invoker API is unchanged and still hand-constructable
 *
 * Run: php apps/playground/application-smoke.php   (exit 0 on success)
 */

$BANNER = "═══ AUSUS — Application bootstrap smoke ════════════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

$ROLES = ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'];

// ── test 1: bootstrap ─────────────────────────────────────────────────────────
echo "\n── test 1: bootstrap (create → register → boot) ──────────────\n";
$app = Application::create([
    'tenant' => 'acme',
    'roles'  => $ROLES,
])->register(new HelloInvoiceDsl())->boot();
_assert('boot() returns the Application',  $app instanceof Application);
_assert('isBooted() is true after boot()', $app->isBooted());
_assert('boot() is idempotent',            $app->boot() === $app);

// ── test 2: graph composed ────────────────────────────────────────────────────
echo "\n── test 2: graph composed ────────────────────────────────────\n";
$graph = $app->graph();
_assert('graph has 1 entity',    count($graph->entities) === 1,  'actual=' . count($graph->entities));
_assert('graph has 3 actions',   count($graph->actions) === 3,   'actual=' . count($graph->actions));
_assert('graph has 1 workflow',  count($graph->workflows) === 1, 'actual=' . count($graph->workflows));

// ── test 3: invoke — create ───────────────────────────────────────────────────
echo "\n── test 3: invoke — create ───────────────────────────────────\n";
$created = $app->invoke('billing.invoice.create', null, [
    'number'        => 'INV-APP-001',
    'customer_name' => 'Application Test Co',
    'amount'        => ['amount' => '500.00', 'currency' => 'USD'],
]);
_assert('create returns 26-char ULID id', isset($created['id']) && strlen($created['id']) === 26);
_assert('create returns status DRAFT',    ($created['status'] ?? null) === 'DRAFT');
$id = $created['id'];

// ── test 4: workflow transition — issue ───────────────────────────────────────
echo "\n── test 4: workflow transition — issue ───────────────────────\n";
$ref = $app->reference('billing.invoice', $id);
$issued = $app->invoke('billing.invoice.issue', $ref, []);
_assert('issue → status ISSUED',  ($issued['status'] ?? null) === 'ISSUED');
_assert('issue stamps issued_at', !empty($issued['issued_at']));

// ── test 5: workflow guard rejects illegal transition ─────────────────────────
echo "\n── test 5: workflow guard rejects illegal transition ─────────\n";
$caught = null;
try { $app->invoke('billing.invoice.issue', $ref, []); }
catch (\Throwable $e) { $caught = $e; }
_assert('re-issue from ISSUED throws WorkflowStateMismatch',
        $caught instanceof \Ausus\WorkflowStateMismatch,
        $caught !== null ? get_class($caught) : 'no exception');

// ── test 6: workflow transition — cancel ──────────────────────────────────────
echo "\n── test 6: workflow transition — cancel ──────────────────────\n";
$cancelled = $app->invoke('billing.invoice.cancel', $ref, []);
_assert('cancel from ISSUED → CANCELLED', ($cancelled['status'] ?? null) === 'CANCELLED');

// ── test 7: tenant boundary still enforced ────────────────────────────────────
echo "\n── test 7: tenant boundary still enforced ────────────────────\n";
$caught = null;
try { $app->invoke('billing.invoice.issue', new Reference('other-tenant', 'billing.invoice', $id), []); }
catch (\Throwable $e) { $caught = $e; }
_assert('cross-tenant ref throws TenantBoundaryViolation',
        $caught instanceof \Ausus\TenantBoundaryViolation,
        $caught !== null ? get_class($caught) : 'no exception');

// ── test 8: projection render ─────────────────────────────────────────────────
echo "\n── test 8: projection render ─────────────────────────────────\n";
$summary = $app->render('billing.invoice.summary');
_assert('viewschema schemaVersion == 1.1.0', ($summary['schemaVersion'] ?? null) === '1.1.0');
_assert('viewschema renders 1 invoice',      count($summary['data']['items'] ?? []) === 1);
_assert('rendered invoice is CANCELLED',
        ($summary['data']['items'][0]['status'] ?? null) === 'CANCELLED');

// ── test 9: policy denial preserved (default actor, no roles) ─────────────────
echo "\n── test 9: policy denial preserved ───────────────────────────\n";
$noRoleApp = Application::create(['tenant' => 'acme'])   // no roles → policy must deny
    ->register(new HelloInvoiceDsl());
$caught = null;
try {
    $noRoleApp->invoke('billing.invoice.create', null, [
        'number' => 'INV-DENY-001', 'customer_name' => 'No Role',
        'amount' => ['amount' => '1.00', 'currency' => 'USD'],
    ]);
} catch (\Throwable $e) { $caught = $e; }
_assert('roleless actor is denied (PolicyDenied)',
        $caught instanceof \Ausus\PolicyDenied,
        $caught !== null ? get_class($caught) : 'no exception');

// ── test 10: lazy boot — invoke() without an explicit boot() ──────────────────
echo "\n── test 10: lazy boot ────────────────────────────────────────\n";
$lazy = Application::create(['tenant' => 'acme', 'roles' => $ROLES])
    ->register(new HelloInvoiceDsl());
_assert('not booted before first use', !$lazy->isBooted());
$lazyOut = $lazy->invoke('billing.invoice.create', null, [
    'number' => 'INV-LAZY-001', 'customer_name' => 'Lazy Boot',
    'amount' => ['amount' => '10.00', 'currency' => 'USD'],
]);
_assert('invoke() booted lazily and succeeded', ($lazyOut['status'] ?? null) === 'DRAFT');
_assert('isBooted() true after lazy invoke',    $lazy->isBooted());

// ── test 11: low-level Invoker API unchanged + hand-constructable ─────────────
echo "\n── test 11: low-level Invoker API preserved ──────────────────\n";
_assert('app->invoker() exposes the runtime Invoker', $app->invoker() instanceof Invoker);

// Build the runtime entirely by hand — the pre-Application wiring path.
$pdoManual = new PDO('sqlite::memory:');
$pdoManual->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$graphManual = (new Compiler())->compile([new HelloInvoiceDsl()]);
foreach (SchemaDeriver::deriveAll($graphManual) as $stmt) $pdoManual->exec($stmt);
$tenantManual = new Tenant(new TenantId('acme'));
$actorManual  = new StubActor(new ActorRef('user', 'manual', 'acme'), $ROLES);
$driverManual = new SqlitePersistenceDriver($pdoManual, $graphManual);
$invokerManual = new Invoker(
    $graphManual, $driverManual,
    new PolicyEngine($graphManual),
    new WorkflowRuntime(new TransitionSetIndex($graphManual)),
    new EffectDispatcher(),
    new DefaultAuditor(new DatabaseAuditSink($pdoManual)),
    new SequenceCounter(),
    $tenantManual, $actorManual,
);
$manualOut = $invokerManual->invoke('billing.invoice.create', null, [
    'number' => 'INV-MANUAL-001', 'customer_name' => 'Hand Wired',
    'amount' => ['amount' => '20.00', 'currency' => 'USD'],
]);
_assert('hand-built Invoker still works unchanged', ($manualOut['status'] ?? null) === 'DRAFT');

// ── test 12: config validation ────────────────────────────────────────────────
echo "\n── test 12: config validation ────────────────────────────────\n";
$caught = null;
try { Application::create(['tenent' => 'acme']); }   // deliberate typo
catch (\Throwable $e) { $caught = $e; }
_assert('unknown config key throws InvalidArgumentException',
        $caught instanceof \InvalidArgumentException,
        $caught !== null ? get_class($caught) : 'no exception');

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed={$passed} failed={$failed}\n";
echo "{$BANNER}\n";

exit($failed > 0 ? 1 : 0);
