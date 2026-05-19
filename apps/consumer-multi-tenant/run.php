<?php
declare(strict_types=1);

/**
 * Multi-tenant consumer — same plugin under alpha + beta tenants.
 *
 * Verifies the row-strategy tenancy invariants (RFC-003 §3):
 *   - alpha's invoices invisible to beta
 *   - beta's invoices invisible to alpha
 *   - cross-tenant Reference rejected with TenantBoundaryViolation
 *
 * DX measurement:
 *   - same boot pattern as App 1, but `Invoker` rebuilt per tenant
 *   - the consumer never writes `WHERE tenant_id = ?` — it's enforced
 *     entirely by the persistence layer
 */

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Compiler, Tenant, TenantId, ActorRef, StubActor, Reference,
           TenantBoundaryViolation};
use Ausus\Persistence\Sql\{SqlitePersistenceDriver, SchemaDeriver, DatabaseAuditSink};
use Ausus\Runtime\{Invoker, ProjectionRenderer};
use BillingMt\Domain\InvoicePlugin;

$t0 = hrtime(true);

// ── one DB, one graph, one driver — shared across tenants ─────────────────
$dbPath = sys_get_temp_dir() . '/billing-mt.sqlite';
if (file_exists($dbPath)) unlink($dbPath);
$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$compiler = new Compiler();
$graph    = $compiler->compile([new InvoicePlugin()]);
foreach (SchemaDeriver::deriveAll($graph) as $stmt) $pdo->exec($stmt);
$driver = new SqlitePersistenceDriver($pdo, $graph);
$sink   = new DatabaseAuditSink($pdo);

// ── tiny helper that builds a tenant-scoped Invoker — pure consumer code ──
// Each call uses Invoker::standard(...) — 5 args instead of 9, no engines.
$invokerFor = fn(string $tenantId): Invoker => Invoker::standard(
    $graph, $driver, $sink,
    new Tenant(new TenantId($tenantId)),
    new StubActor(new ActorRef('user', 'svc', $tenantId), ['biller']),
);

// ── exercise: each tenant creates 2 invoices ───────────────────────────────
$alpha = $invokerFor('alpha');
$beta  = $invokerFor('beta');

$a1 = $alpha->invoke('billing.invoice.create', null, ['number' => 'ALPHA-001']);
$a2 = $alpha->invoke('billing.invoice.create', null, ['number' => 'ALPHA-002']);
$b1 = $beta ->invoke('billing.invoice.create', null, ['number' => 'BETA-001']);

// ── verify visibility (each tenant only sees its own rows) ────────────────
$alphaList = (new ProjectionRenderer($graph, $driver, new Tenant(new TenantId('alpha'))))
    ->render('billing.invoice.list');
$betaList  = (new ProjectionRenderer($graph, $driver, new Tenant(new TenantId('beta'))))
    ->render('billing.invoice.list');

assert(count($alphaList['data']['items']) === 2, 'alpha must see 2');
assert(count($betaList['data']['items'])  === 1, 'beta must see 1');

foreach ($alphaList['data']['items'] as $row) {
    assert(str_starts_with($row['number'], 'ALPHA-'), "alpha must NOT see " . $row['number']);
}
foreach ($betaList['data']['items'] as $row) {
    assert(str_starts_with($row['number'], 'BETA-'), "beta must NOT see " . $row['number']);
}

// ── verify cross-tenant Reference is rejected with the typed exception ────
// (DX nuance: TenantBoundaryViolation fires when ref.tenantId differs from
//  the invoker's active tenant. If the consumer instead supplies an id that
//  belongs to another tenant BUT spells the tenantId on the ref correctly,
//  the persistence layer treats it as "no such row" → WorkflowSubjectNotFound.
//  Both are valid tenant-safety mechanisms; the typed exception names which
//  one tripped. See docs/CONSUMER-DX-PASS.md §2.)
$crossTenantRef = new Reference('alpha', 'billing.invoice', $a1['id']);   // alpha tenantId on ref, but invoker is beta
$caught = null;
try {
    $beta->invoke('billing.invoice.pay', $crossTenantRef, []);
} catch (TenantBoundaryViolation $e) {
    $caught = $e;
}
assert($caught instanceof TenantBoundaryViolation, 'cross-tenant must raise TenantBoundaryViolation');

// ── verify pay() works inside the same tenant ──────────────────────────────
$paid = $alpha->invoke('billing.invoice.pay',
    new Reference('alpha', 'billing.invoice', $a1['id']), []);
assert($paid['status'] === 'PAID', 'pay should transition DRAFT → PAID');

// ── verify alpha-pay didn't leak into beta's view ─────────────────────────
$alphaListV2 = (new ProjectionRenderer($graph, $driver, new Tenant(new TenantId('alpha'))))
    ->render('billing.invoice.list');
$betaListV2  = (new ProjectionRenderer($graph, $driver, new Tenant(new TenantId('beta'))))
    ->render('billing.invoice.list');
$alphaPaidCount = count(array_filter($alphaListV2['data']['items'], fn($r) => $r['status'] === 'PAID'));
$betaPaidCount  = count(array_filter($betaListV2 ['data']['items'], fn($r) => $r['status'] === 'PAID'));
assert($alphaPaidCount === 1, 'alpha must show 1 PAID');
assert($betaPaidCount  === 0, 'beta must show 0 PAID');

$wall = (hrtime(true) - $t0) / 1_000_000.0;

// ── report ─────────────────────────────────────────────────────────────────
echo "consumer-multi-tenant — OK\n";
echo sprintf("  alpha invoices  = %d (1 PAID, 1 DRAFT)\n", count($alphaListV2['data']['items']));
echo sprintf("  beta invoices   = %d (all DRAFT)\n",        count($betaListV2 ['data']['items']));
echo sprintf("  cross-tenant invoke raised: %s\n",          (new \ReflectionClass($caught))->getShortName());
echo sprintf("  graph hash      = %s\n",                    substr($graph->hash, 0, 16) . '…');
echo sprintf("  wall (boot→done)= %.2f ms\n",               $wall);

unlink($dbPath);
