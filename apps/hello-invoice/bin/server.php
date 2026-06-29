<?php

declare(strict_types=1);

/**
 * Hello Invoice — a minimal HTTP front controller for the AUSUS 2.0 API Runtime,
 * so the React Renderer (which speaks the HTTP contract only) can connect.
 *
 * It compiles the Invoice entity once, keeps an in-memory store for the process,
 * and maps every request to `RuntimeApi::dispatch(method, path, headers, body)`.
 *
 * Run:  php -S 127.0.0.1:8080 -t . bin/server.php
 *       (then point @ausus/react-renderer at http://127.0.0.1:8080)
 */

require __DIR__ . '/../vendor/autoload.php';

use Ausus\Engine\Compile\Compiler;
use Ausus\Engine\Repository\InMemorySchemaRepository;
use Ausus\Engine\Runtime\DefaultAuthorizationEvaluator;
use Ausus\Engine\Runtime\DefaultEntityEngine;
use Ausus\Persistence\Memory\MemoryDriver;
use Ausus\Api\Runtime\Http\RequestContextFactory;
use Ausus\Api\Runtime\Http\RuntimeApi;

// Compile + wire the runtime once per process.
$repo = new InMemorySchemaRepository();
foreach ((new Compiler())->compile([require __DIR__ . '/../entities/Invoice.php'])->schemas as $s) {
    $repo->putByHash($s);
}
$engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$driver = new MemoryDriver();
$factory = new RequestContextFactory(new DateTimeImmutable());
$api = new RuntimeApi($repo, $engine, $driver, $factory);

// Seed a couple of invoices at boot so the renderer always has data to show.
// NOTE: the reference in-memory driver lives for ONE process; with `php -S`
// each request runs this script fresh, so writes do not persist across
// requests — reads always reflect this seed. For a persistent server, swap in
// a persistent PersistenceDriver (see "Limitations" in the README).
$seedCtx = $factory->fromHeaders(['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user']);
$seed = $engine->bind($repo->resolve('invoice'), $driver);
$seed->invoke('create', ['number' => 'INV-001', 'customer' => 'Globex',  'issueDate' => '2025-01-10', 'dueDate' => '2025-02-10', 'total' => 1500], $seedCtx);
$paidId = $seed->invoke('create', ['number' => 'INV-002', 'customer' => 'Initech', 'issueDate' => '2025-01-12', 'dueDate' => '2025-02-12', 'total' => 900], $seedCtx)->reference->identityHandle;
$seed->invoke('pay', ['id' => $paidId], $seedCtx);

// Translate the PHP request into a dispatch() call.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
        $headers[$name] = $v;
    }
}
// Sensible defaults so the renderer works without auth headers in the demo.
$headers['X-Tenant-Id'] ??= 'acme';
$headers['X-Tenant-ID'] = $headers['X-Tenant-Id'];
$headers['X-Actor-Type'] ??= 'user';

$body = [];
if ($method === 'GET') {
    parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? '', $body);
} else {
    $raw = file_get_contents('php://input') ?: '';
    $body = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
}

$res = $api->dispatch($method, $path, $headers, $body);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
http_response_code($res['status']);
echo json_encode($res['body']);
