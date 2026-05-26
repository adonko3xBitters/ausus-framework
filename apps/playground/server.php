<?php
declare(strict_types=1);

/**
 * AUSUS L4 HTTP demo — front controller.
 *
 * Boots a single-process PHP server that serves the HelloInvoice plugin's
 * full ViewSchema + Action surface over real HTTP. Run with:
 *
 *   php -S localhost:8787 apps/playground/server.php
 *
 * Then point @ausus/renderer-react at http://localhost:8787/api and
 * everything routes through the Router → Invoker → kernel chain.
 *
 * Persistence: SQLite at $AUSUS_DB_PATH (default: /tmp/ausus-server.sqlite).
 * Schema + seed run once on first request; subsequent requests reuse the file.
 * Force a fresh DB with AUSUS_RESET_DB=1.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Application, ApplicationConfig};
use Ausus\Api\Http\Emitter;
use Acme\Billing\HelloInvoicePlugin;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

// ── Bootstrap (idempotent across requests) ───────────────────────────────────
$dbPath = getenv('AUSUS_DB_PATH') ?: sys_get_temp_dir() . '/ausus-server.sqlite';
$reset  = getenv('AUSUS_RESET_DB') === '1';
clearstatcache(true, $dbPath);
$fresh  = !file_exists($dbPath) || $reset;
if ($reset && file_exists($dbPath)) @unlink($dbPath);

$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Single PSR-17 factory; both the inbound ServerRequestCreator and the
// internal Router used by $app->http() consume it.
$factory = new Psr17Factory();

// Compile + wire the runtime, then expose it over HTTP — all in one chain.
// $app->http($request) returns the response; no Router construction here.
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
    error_log('[ausus] seeded fresh DB at ' . $dbPath);
}

// ── Dispatch + emit ──────────────────────────────────────────────────────────
$creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
Emitter::emit($app->http($creator->fromGlobals()));
