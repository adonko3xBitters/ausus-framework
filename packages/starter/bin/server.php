#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * AUSUS starter — built-in dev server front controller.
 *
 * Boots the HelloInvoice sample plugin and serves its full ViewSchema +
 * Action HTTP surface on a single-process PHP server. Usage:
 *
 *   composer serve                    # bound to localhost:8000 by default
 *   composer serve -- 0.0.0.0 8080    # custom host/port
 *
 * Or directly:
 *
 *   php -S localhost:8000 bin/server.php
 *
 * Persistence: SQLite at $AUSUS_DB_PATH (default: ./tickets.sqlite next to
 * the starter root). Schema + seed run once on first request; subsequent
 * requests reuse the file. Force a fresh DB with AUSUS_RESET_DB=1.
 *
 * This file is the canonical dev entry point shipped with the starter. The
 * monorepo playground keeps apps/playground/server.php for its own validation
 * matrix.
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
    fwrite(STDERR, "[server] vendor/ not installed yet — run `composer install` first.\n");
    http_response_code(503);
    exit(0);
}

use Ausus\{Application, ApplicationConfig};
use Ausus\Api\Http\Emitter;
use Acme\Billing\HelloInvoicePlugin;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

// ─── Bootstrap (idempotent across requests) ─────────────────────────────────
$starterRoot = dirname(__DIR__);
$dbPath = getenv('AUSUS_DB_PATH') ?: $starterRoot . '/tickets.sqlite';
$reset  = getenv('AUSUS_RESET_DB') === '1';
clearstatcache(true, $dbPath);
$fresh  = !file_exists($dbPath) || $reset;
if ($reset && file_exists($dbPath)) {
    @unlink($dbPath);
}

$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$factory = new Psr17Factory();

$app = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')
            ->actorId('seed')
            ->roles(['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'])
            ->pdo($pdo)
            ->psr17($factory)
    )
    ->register(new HelloInvoicePlugin())
    ->boot();

if ($fresh) {
    // Seed two invoices so the renderer has data to render on first GET.
    $app->invoke('billing.invoice.create', null, [
        'number'        => 'INV-2026-001',
        'customer_name' => 'ACME Corporation',
        'amount'        => ['amount' => '1500.00', 'currency' => 'USD'],
    ]);
    $app->invoke('billing.invoice.create', null, [
        'number'        => 'INV-2026-002',
        'customer_name' => 'Globex Industries',
        'amount'        => ['amount' => '2750.00', 'currency' => 'USD'],
    ]);
    error_log("[ausus] seeded fresh DB at $dbPath");
}

// ─── Dispatch + emit ────────────────────────────────────────────────────────
$creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
Emitter::emit($app->http($creator->fromGlobals()));
