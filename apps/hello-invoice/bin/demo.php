<?php

declare(strict_types=1);

/**
 * Hello Invoice — the complete AUSUS 2.0 pipeline in one runnable script:
 *
 *   Authoring (DSL)  →  Compiler  →  immutable graph  →  Runtime  →  HTTP API
 *
 * Uses ONLY public packages: ausus/authoring, ausus/entity-engine,
 * ausus/persistence-memory, ausus/api-runtime (ausus/kernel is pulled in).
 *
 * Run:  php bin/demo.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Ausus\Engine\Compile\Compiler;
use Ausus\Engine\Repository\InMemorySchemaRepository;
use Ausus\Engine\Runtime\DefaultAuthorizationEvaluator;
use Ausus\Engine\Runtime\DefaultEntityEngine;
use Ausus\Persistence\Memory\MemoryDriver;
use Ausus\Api\Runtime\Http\RequestContextFactory;
use Ausus\Api\Runtime\Http\RuntimeApi;

$fail = 0;
$ok = function (string $label, bool $cond) use (&$fail): void {
    echo ($cond ? "  ✓ " : "  ✗ ") . $label . "\n";
    if (!$cond) { $fail++; }
};

// ── 1. Authoring — load the immutable EntityDefinition ─────────────────────
echo "1. Authoring (DSL)\n";
$invoice = require __DIR__ . '/../entities/Invoice.php';
$ok("entities/Invoice.php returns an EntityDefinition", $invoice instanceof \Ausus\Definition\EntityDefinition);

// ── 2. Compilation — Entity Engine compiles to a content-addressed schema ──
echo "2. Compilation (Entity Engine → EntitySchema)\n";
$graph = (new Compiler())->compile([$invoice]);
$schema = $graph->schemas[0];
$ok("compiled to 1 EntitySchema (hash " . substr($schema->hash, 0, 12) . "…)", count($graph->schemas) === 1);

// ── 3. Immutable graph → content-addressed repository ──────────────────────
echo "3. Immutable graph → repository\n";
$repo = new InMemorySchemaRepository();
foreach ($graph->schemas as $s) {
    $repo->putByHash($s);
}
$ok("invoice resolvable from the repository", $repo->resolve('invoice')->identity === 'invoice');

// ── 4. Runtime — bind the schema to a driver, invoke actions ───────────────
echo "4. Runtime (bind → invoke)\n";
$engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$driver = new MemoryDriver();
$factory = new RequestContextFactory(new DateTimeImmutable('@1700000000'));
$user = $factory->fromHeaders(['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user']);
$guest = $factory->fromHeaders(['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'guest']);

$invoiceRt = fn () => $engine->bind($repo->resolve('invoice'), $driver);

$created = $invoiceRt()->invoke('create', [
    'number' => 'INV-001', 'customer' => 'Globex', 'issueDate' => '2025-01-10',
    'dueDate' => '2025-02-10', 'total' => 1500,
], $user);
$id = $created->reference->identityHandle;
$ok("create → invoice #INV-001 ({$id})", $created->field('status') === 'draft');

$invoiceRt()->invoke('update', ['id' => $id, 'total' => 1800], $user);
$ok("update → total patched to 1800", true);

// authorization: a non-user actor may not create
$denied = false;
try { $invoiceRt()->invoke('create', ['number' => 'X', 'customer' => 'Y', 'issueDate' => '2025-01-01', 'dueDate' => '2025-01-02', 'total' => 1], $guest); }
catch (\Throwable $e) { $denied = true; }
$ok("authorization → guest create is DENIED (guard actor.type = user)", $denied);

$invoiceRt()->invoke('pay', ['id' => $id], $user);
$paid = $repo; // re-read below via API

// ── 5. HTTP API — the same domain over a framework-agnostic contract ───────
echo "5. HTTP API ({ status, body })\n";
$api = new RuntimeApi($repo, $engine, $driver, $factory);
$headers = ['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user'];

$board = $api->dispatch('GET', '/api/entities/invoice/projections/board', $headers);
$rows = $board['body']['rows'] ?? $board['body']['data'] ?? [];
$ok("GET …/projections/board → {$board['status']} with " . count($rows) . " row(s)", $board['status'] === 200 && count($rows) === 1);
$ok("invoice is now 'paid' (transition applied)", ($rows[0]['status'] ?? null) === 'paid');

$schemaRes = $api->dispatch('GET', '/api/entities/invoice', $headers);
$ok("GET …/entities/invoice → {$schemaRes['status']} (schema discovery for the renderer)", $schemaRes['status'] === 200);

echo "\n" . ($fail === 0 ? "OK — Hello Invoice pipeline green (Authoring → Compile → Runtime → API)\n" : "FAILED ({$fail})\n");
exit($fail === 0 ? 0 : 1);
