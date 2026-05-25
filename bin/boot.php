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

use Ausus\Application;
use Acme\Billing\HelloInvoicePlugin;

echo "ausus/starter boot\n";

$dbPath = sys_get_temp_dir() . '/ausus_starter_boot.sqlite';
if (file_exists($dbPath)) unlink($dbPath);

// One bootstrap call composes the compiler, SQLite persistence, the runtime
// (Invoker + policy/workflow/effect/audit) and applies the derived schema.
$app = Application::create([
    'tenant'   => 'acme',
    'actorId'  => 'boot',
    'roles'    => ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'],
    'database' => $dbPath,
])->register(new HelloInvoicePlugin())->boot();
echo "  ✓ compiled graph (hash " . substr($app->graph()->hash, 0, 12) . "…)\n";
echo "  ✓ schema applied\n";

$create = $app->invoke('billing.invoice.create', null, [
    'number'        => 'INV-BOOT-001',
    'customer_name' => 'Boot Test Customer',
    'amount'        => ['amount' => '99.00', 'currency' => 'USD'],
]);
echo "  ✓ created invoice id=" . ($create['id'] ?? '?') . "\n";

$ref = $app->reference('billing.invoice', $create['id']);
$app->invoke('billing.invoice.issue', $ref, []);
echo "  ✓ issued invoice (DRAFT → ISSUED)\n";

$schema = $app->render('billing.invoice.summary');
$count  = is_array($schema['data']['items'] ?? null) ? count($schema['data']['items']) : 0;
echo "  ✓ rendered summary projection (items={$count})\n";

unlink($dbPath);
echo "OK — ausus/starter boots cleanly.\n";
exit(0);
