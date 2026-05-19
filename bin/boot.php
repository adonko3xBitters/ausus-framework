#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * AUSUS starter boot — proves install + autoload + persistence + workflow
 * round-trip end-to-end in ~50 LOC. Exits 0 on success.
 *
 * Usage:   composer boot
 * Or:      php bin/boot.php
 */

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',          // create-project: starter is the root
    __DIR__ . '/../../../autoload.php',           // installed as dep under vendor/ausus/starter/bin/
    __DIR__ . '/../../../vendor/autoload.php',    // monorepo dev (packages/starter/bin/)
];
$loaded = false;
foreach ($autoloadCandidates as $f) {
    if (file_exists($f)) { require $f; $loaded = true; break; }
}
if (!$loaded) {
    // Dependencies not installed yet (e.g. `composer create-project --no-install`).
    // Exit cleanly so the create-project lifecycle does not fail; the operator
    // will run `composer install && composer boot` to complete the install.
    fwrite(STDOUT, "[boot] vendor/ not installed yet — run `composer install && composer boot` to finish.\n");
    exit(0);
}

use Ausus\{Compiler, Tenant, TenantId, ActorRef, StubActor};
use Ausus\Persistence\Sql\{SqlitePersistenceDriver, SchemaDeriver, DatabaseAuditSink};
use Ausus\Runtime\{PolicyEngine, WorkflowRuntime, TransitionSetIndex, EffectDispatcher,
                   DefaultAuditor, SequenceCounter, Invoker, ProjectionRenderer};
use Acme\Billing\HelloInvoicePlugin;

echo "ausus/starter boot\n";

$dbPath = sys_get_temp_dir() . '/ausus_starter_boot.sqlite';
if (file_exists($dbPath)) unlink($dbPath);
$pdo = new PDO("sqlite:{$dbPath}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$compiler = new Compiler();
$graph    = $compiler->compile([new HelloInvoicePlugin()]);
foreach (SchemaDeriver::deriveAll($graph) as $stmt) $pdo->exec($stmt);
echo "  ✓ compiled graph (hash " . substr($graph->hash, 0, 12) . "…)\n";
echo "  ✓ schema applied\n";

$tenant   = new Tenant(new TenantId('acme'));
$actor    = new StubActor(new ActorRef('user', 'boot', 'acme'),
                          ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer']);
$driver   = new SqlitePersistenceDriver($pdo, $graph);
$invoker  = new Invoker(
    $graph, $driver,
    new PolicyEngine($graph),
    new WorkflowRuntime(new TransitionSetIndex($graph)),
    new EffectDispatcher(),
    new DefaultAuditor(new DatabaseAuditSink($pdo)),
    new SequenceCounter(),
    $tenant, $actor,
);

$create = $invoker->invoke('billing.invoice.create', null, [
    'number'        => 'INV-BOOT-001',
    'customer_name' => 'Boot Test Customer',
    'amount'        => ['amount' => '99.00', 'currency' => 'USD'],
]);
echo "  ✓ created invoice id=" . ($create['id'] ?? '?') . "\n";

$ref = new \Ausus\Reference('acme', 'billing.invoice', $create['id']);
$invoker->invoke('billing.invoice.issue', $ref, []);
echo "  ✓ issued invoice (DRAFT → ISSUED)\n";

$renderer = new ProjectionRenderer($graph, $driver, $tenant);
$schema   = $renderer->render('billing.invoice.summary');
$count    = is_array($schema['data']['items'] ?? null) ? count($schema['data']['items']) : 0;
echo "  ✓ rendered summary projection (items={$count})\n";

unlink($dbPath);
echo "OK — ausus/starter boots cleanly.\n";
exit(0);
