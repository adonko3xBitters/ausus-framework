<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Application, Compiler, Reference};
use Acme\Billing\{HelloInvoicePlugin, HelloInvoiceDsl};

/**
 * AUSUS V0 First Real Implementation Pass — end-to-end runner.
 *
 * Boots: kernel + persistence + runtime + audit + HelloInvoice plugin.
 * Exercises: create → issue → cancel-fail-from-cancelled → ViewSchema render.
 * Asserts: persistence, tenancy, audit trail, workflow gate, optimistic lock.
 *
 * Run: php apps/playground/run.php
 */

$BANNER = "═══ AUSUS V0 first real pass ═══════════════════════════════";
echo "{$BANNER}\n";

// -----------------------------------------------------------------------------
// BOOTSTRAP
// -----------------------------------------------------------------------------

$dbPath = __DIR__ . '/playground.sqlite';
if (file_exists($dbPath)) unlink($dbPath);
$pdo = new PDO("sqlite:{$dbPath}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
echo "[boot] SQLite db: {$dbPath}\n";

// Application::create()->register()->boot() composes the compiler, the SQLite
// persistence driver, the runtime (Invoker + policy/workflow/effect/audit) and
// applies the derived schema. The booted services are still reachable via the
// accessors below, so the low-level assertions further down keep working.
$app = Application::create([
    'tenant'   => 'acme',
    'actorId'  => 'user42',
    'roles'    => ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'],
    'database' => $pdo,
])->register(new HelloInvoicePlugin())->boot();

$graph    = $app->graph();
$driver   = $app->driver();
$invoker  = $app->invoker();
$tenant   = $app->tenant();
$actor    = $app->actor();
$compiler = new Compiler();   // still used directly for the DSL-parity hash test

echo "[boot] graph hash: " . substr($graph->hash, 0, 16) . "...\n";
echo "[boot] entities={".count($graph->entities)."} actions={".count($graph->actions)."} policies={".count($graph->policies)."} workflows={".count($graph->workflows)."} projections={".count($graph->projections)."}\n";
echo "[boot] schema applied\n";
echo "[boot] runtime ready\n\n";

// -----------------------------------------------------------------------------
// TEST ASSERTIONS
// -----------------------------------------------------------------------------

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null) {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

echo "── test 1: create invoice ────────────────────────────────────\n";
$outputs = $invoker->invoke('billing.invoice.create', null, [
    'number'        => 'INV-2026-001',
    'customer_name' => 'ACME Corporation',
    'amount'        => ['amount' => '1500.00', 'currency' => 'USD'],
]);
_assert('outputs.id is set',         isset($outputs['id']) && strlen($outputs['id']) === 26);
_assert('outputs.status == DRAFT',   ($outputs['status'] ?? null) === 'DRAFT');
$invoiceId = $outputs['id'];

echo "\n── test 2: issue invoice ─────────────────────────────────────\n";
$invoiceRef = new Reference('acme', 'billing.invoice', $invoiceId);
$out2 = $invoker->invoke('billing.invoice.issue', $invoiceRef);
_assert('outputs.status == ISSUED',  ($out2['status'] ?? null) === 'ISSUED');
_assert('outputs.issued_at set',     isset($out2['issued_at']) && str_starts_with($out2['issued_at'], '20'));

echo "\n── test 3: verify persistence ────────────────────────────────\n";
$row = $pdo->query("SELECT status, issued_at FROM billing_invoice WHERE id = " . $pdo->quote($invoiceId))->fetch(PDO::FETCH_ASSOC);
_assert('row.status == ISSUED in db',     ($row['status'] ?? null) === 'ISSUED');
_assert('row.issued_at set in db',        !empty($row['issued_at']));

echo "\n── test 4: verify audit trail ────────────────────────────────\n";
$auditRows = $pdo->query("SELECT action_fqn, invocation_class, sequence, correlation_id FROM kernel_audit_log ORDER BY timestamp")->fetchAll(PDO::FETCH_ASSOC);
_assert('audit has 2 entries',                  count($auditRows) === 2,
        "actual=" . count($auditRows));
_assert('audit[0].action == create',            ($auditRows[0]['action_fqn'] ?? null) === 'billing.invoice.create');
_assert('audit[1].action == issue',             ($auditRows[1]['action_fqn'] ?? null) === 'billing.invoice.issue');
_assert('audit entries have distinct correlations',
        ($auditRows[0]['correlation_id'] ?? '') !== ($auditRows[1]['correlation_id'] ?? '_'));

echo "\n── test 5: workflow gate (issue from ISSUED → should reject) ─\n";
$caught = null;
try {
    $invoker->invoke('billing.invoice.issue', $invoiceRef);
} catch (\Throwable $e) { $caught = $e; }
_assert('issue from ISSUED throws',     $caught !== null,
        $caught === null ? 'NO exception thrown' : null);
_assert('exception is WorkflowStateMismatch',
        $caught !== null && str_contains($caught::class, 'WorkflowStateMismatch'),
        $caught !== null ? get_class($caught) : 'no exception');

echo "\n── test 6: workflow allows ISSUED → CANCELLED ────────────────\n";
$out6 = $invoker->invoke('billing.invoice.cancel', $invoiceRef);
_assert('outputs.status == CANCELLED',  ($out6['status'] ?? null) === 'CANCELLED');

echo "\n── test 7: tenancy isolation ─────────────────────────────────\n";
$wrongRef = new Reference('other-tenant', 'billing.invoice', $invoiceId);
$caught = null;
try { $invoker->invoke('billing.invoice.issue', $wrongRef); } catch (\Throwable $e) { $caught = $e; }
_assert('cross-tenant ref rejected',
        $caught !== null && str_contains($caught::class, 'TenantBoundaryViolation'),
        $caught !== null ? get_class($caught) : 'no exception');

echo "\n── test 8: optimistic locking via direct repo update ─────────\n";
// Read current version
$tx = $driver->beginTransaction($tenant);
$pctx = $driver->context($tenant, $tx);
$repo = $pctx->repository('billing.invoice');
$current = $repo->find($invoiceRef);
$staleVersion = $current->version;
// Update via repo to bump version
$repo->update($invoiceRef, ['customer_name' => 'New Name'], $staleVersion);
$caught = null;
try {
    $repo->update($invoiceRef, ['customer_name' => 'Bad Name'], $staleVersion);   // stale
} catch (\Throwable $e) { $caught = $e; }
$driver->commit($tx);
_assert('stale update raises ConcurrencyConflict',
        $caught !== null && str_contains($caught::class, 'ConcurrencyConflict'),
        $caught !== null ? get_class($caught) : 'no exception');

echo "\n── test 9: render projection ViewSchema ──────────────────────\n";
$renderer = $app->renderer();
$summary = $renderer->render('billing.invoice.summary');
_assert('viewschema.schemaVersion == 1.0.0',  $summary['schemaVersion'] === '1.0.0');
_assert('viewschema.targetProfile == react.web.v1', $summary['targetProfile'] === 'react.web.v1');
_assert('viewschema.fields has 5 fields',      count($summary['fields']) === 5);
_assert('viewschema.actions has 2 actions',    count($summary['actions']) === 2);
_assert('viewschema.data.items has 1 invoice', count($summary['data']['items']) === 1);
_assert('rendered invoice status == CANCELLED',
        ($summary['data']['items'][0]['status'] ?? null) === 'CANCELLED');

$detail = $renderer->render('billing.invoice.detail', $invoiceRef);
_assert('detail viewschema renders item',     isset($detail['data']['item']));
_assert('detail.fields has 8 fields',          count($detail['fields']) === 8);

// -----------------------------------------------------------------------------
// RFC-011 DSL PARITY TESTS
// -----------------------------------------------------------------------------

echo "\n── test 10: DSL plugin compiles + byte-identical hash ────────\n";
$dslPlugin = new HelloInvoiceDsl();
$graphDsl  = $compiler->compile([$dslPlugin]);
_assert('DSL graph hash == manual graph hash',
        $graphDsl->hash === $graph->hash,
        "manual={$graph->hash}\n        dsl   ={$graphDsl->hash}");
_assert('entity FQNs match',     array_keys($graphDsl->entities)    === array_keys($graph->entities));
_assert('action FQNs match',     array_keys($graphDsl->actions)     === array_keys($graph->actions));
_assert('policy FQNs match',     array_keys($graphDsl->policies)    === array_keys($graph->policies));
_assert('workflow FQNs match',   array_keys($graphDsl->workflows)   === array_keys($graph->workflows));
_assert('projection FQNs match', array_keys($graphDsl->projections) === array_keys($graph->projections));

echo "\n── test 11: DSL plugin — full pipeline integration ───────────\n";
// Re-bootstrap a second Application against the DSL plugin (fresh SQLite db).
$dbPath2 = __DIR__ . '/playground-dsl.sqlite';
if (file_exists($dbPath2)) unlink($dbPath2);
$pdo2 = new PDO("sqlite:{$dbPath2}");
$pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$app2 = Application::create([
    'tenant'   => 'acme',
    'actorId'  => 'user42',
    'roles'    => ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'],
    'database' => $pdo2,
])->register(new HelloInvoiceDsl())->boot();
$invoker2 = $app2->invoker();

$o = $invoker2->invoke('billing.invoice.create', null, [
    'number' => 'INV-DSL-001', 'customer_name' => 'DSL Customer',
    'amount' => ['amount' => '900.00', 'currency' => 'USD'],
]);
_assert('DSL: create returns DRAFT', ($o['status'] ?? null) === 'DRAFT');
$dslRef = new Reference('acme', 'billing.invoice', $o['id']);

$o2 = $invoker2->invoke('billing.invoice.issue', $dslRef);
_assert('DSL: issue → ISSUED',       ($o2['status'] ?? null) === 'ISSUED');

$o3 = $invoker2->invoke('billing.invoice.cancel', $dslRef);
_assert('DSL: cancel from ISSUED → CANCELLED', ($o3['status'] ?? null) === 'CANCELLED');

$dslAudit = $pdo2->query("SELECT action_fqn FROM kernel_audit_log ORDER BY timestamp")->fetchAll(PDO::FETCH_ASSOC);
_assert('DSL: audit has 3 entries',  count($dslAudit) === 3, "actual=" . count($dslAudit));

// -----------------------------------------------------------------------------
// KPI METRICS — measured against both plugin files
// -----------------------------------------------------------------------------

echo "\n── test 12: RFC-011 KPI delta (measured) ─────────────────────\n";
$manualFile = __DIR__ . '/../../packages/starter/src/HelloInvoice.php';
$dslFile    = __DIR__ . '/../../packages/starter/src/HelloInvoiceDsl.php';

function _count_file_loc(string $f): int { return count(file($f, FILE_IGNORE_NEW_LINES)); }
function _count_imports(string $f): int  { return (int) preg_match_all('/^use\s+/m', file_get_contents($f)); }

/** RFC-011 §11.1 strict: lines inside the describe()/dsl() method body, excluding blank lines. */
function _count_dsl_loc(string $f): int {
    $src = file_get_contents($f);
    if (preg_match('/function\s+(?:dsl|describe)\s*\([^)]*\)\s*:\s*\w+\s*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
        $start = $m[0][1] + strlen($m[0][0]);
        $depth = 1; $i = $start;
        while ($i < strlen($src) && $depth > 0) {
            $c = $src[$i];
            if ($c === '{') $depth++;
            elseif ($c === '}') $depth--;
            $i++;
        }
        $body = substr($src, $start, $i - $start - 1);
        return count(array_filter(explode("\n", $body), fn($l) => trim($l) !== ''));
    }
    return _count_file_loc($f);
}

/** RFC-011 §11.3 "manual FQNs": 3+ segment dot-notation strings + ::class refs. EXCLUDES 2-segment role tokens like 'invoice.creator'. */
function _count_domain_fqns(string $f): int {
    $src = file_get_contents($f);
    preg_match_all('/[\'"]([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){2,})[\'"]/', $src, $deepDot);
    preg_match_all('/\\\\[A-Z][A-Za-z0-9_]+(?:\\\\[A-Z][A-Za-z0-9_]+)+::class/', $src, $classes);
    return count(array_unique(array_merge($deepDot[1] ?? [], $classes[0] ?? [])));
}

/** Informational only: 2-segment role/permission tokens like 'invoice.creator'. */
function _count_role_tokens(string $f): int {
    $src = file_get_contents($f);
    preg_match_all('/[\'"]([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)[\'"]/', $src, $m);
    return count(array_unique($m[1] ?? []));
}

$manualFileLoc = _count_file_loc($manualFile);  $dslFileLoc = _count_file_loc($dslFile);
$manualDslLoc  = _count_dsl_loc($manualFile);   $dslDslLoc  = _count_dsl_loc($dslFile);
$manualImp     = _count_imports($manualFile);   $dslImp     = _count_imports($dslFile);
$manualFqn     = _count_domain_fqns($manualFile); $dslFqn   = _count_domain_fqns($dslFile);
$manualRoles   = _count_role_tokens($manualFile); $dslRoles = _count_role_tokens($dslFile);

echo sprintf("    manual: file %3d LOC  | dsl-body %3d LOC | %2d imports | %2d domain FQNs | (%d role tokens)\n",
             $manualFileLoc, $manualDslLoc, $manualImp, $manualFqn, $manualRoles);
echo sprintf("    DSL   : file %3d LOC  | dsl-body %3d LOC | %2d imports | %2d domain FQNs | (%d role tokens)\n",
             $dslFileLoc, $dslDslLoc, $dslImp, $dslFqn, $dslRoles);
echo sprintf("    delta : file %+3d LOC | dsl-body %+3d LOC | %+2d imports | %+2d domain FQNs\n",
             $dslFileLoc - $manualFileLoc, $dslDslLoc - $manualDslLoc,
             $dslImp - $manualImp, $dslFqn - $manualFqn);

_assert("RFC-011 §11.1 KPI: dsl() body ≤ 40 LOC", $dslDslLoc <= 40, "actual={$dslDslLoc}");
_assert("RFC-011 §11.2 KPI: imports ≤ 10",         $dslImp <= 10);
_assert("RFC-011 §11.3 KPI: domain FQNs ≤ 3",      $dslFqn <= 3, "actual={$dslFqn}");

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed={$passed} failed={$failed}\n";
echo "{$BANNER}\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
