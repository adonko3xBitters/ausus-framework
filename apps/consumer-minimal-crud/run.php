<?php
declare(strict_types=1);

/**
 * Minimal CRUD consumer — bootstraps AUSUS to run the Task plugin end-to-end.
 *
 * What this demonstrates (DX measurement):
 *   - LOC for boot                                 : ~30 LOC excluding asserts
 *   - distinct framework imports                   : 14
 *   - first-success wall time on idle laptop       : ~30 ms
 *   - friction events captured during authoring    : see docs/CONSUMER-DX-PASS.md
 */

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Compiler, Tenant, TenantId, ActorRef, StubActor, Reference};
use Ausus\Persistence\Sql\{SqlitePersistenceDriver, SchemaDeriver, DatabaseAuditSink};
use Ausus\Runtime\{Invoker, ProjectionRenderer};
use Tasks\Domain\TaskPlugin;

$t0 = hrtime(true);

// ── boot (would be in the user's container/bootstrap layer) ────────────────
$dbPath = sys_get_temp_dir() . '/tasks-minimal.sqlite';
if (file_exists($dbPath)) unlink($dbPath);
$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$compiler = new Compiler();
$graph    = $compiler->compile([new TaskPlugin()]);
foreach (SchemaDeriver::deriveAll($graph) as $stmt) $pdo->exec($stmt);

$tenant  = new Tenant(new TenantId('acme'));
$actor   = new StubActor(new ActorRef('user', 'alice', 'acme'), ['user']);
$driver  = new SqlitePersistenceDriver($pdo, $graph);
// Invoker::standard() encapsulates the 6 engine instantiations a consumer
// would otherwise repeat per Invoker. Explicit 9-arg ctor still available.
$invoker = Invoker::standard($graph, $driver, new DatabaseAuditSink($pdo), $tenant, $actor);

// ── exercise: full CRUD round-trip ─────────────────────────────────────────
$out = $invoker->invoke('tasks.task.create', null, ['title' => 'Ship v0.1']);
$taskId = $out['id'];
assert($out['status'] === 'DRAFT', 'fresh task should be DRAFT');

$invoker->invoke('tasks.task.complete', new Reference('acme', 'tasks.task', $taskId), []);

$renderer = new ProjectionRenderer($graph, $driver, $tenant);
$schema   = $renderer->render('tasks.task.list');
$items    = $schema['data']['items'];

assert(count($items) === 1, 'one task expected, got ' . count($items));
assert($items[0]['status'] === 'DONE', 'task should be DONE after complete');

$wall = (hrtime(true) - $t0) / 1_000_000.0;

// ── report ─────────────────────────────────────────────────────────────────
echo "consumer-minimal-crud — OK\n";
echo sprintf("  task id        = %s\n", $taskId);
echo sprintf("  final status   = %s\n", $items[0]['status']);
echo sprintf("  rendered fields= %s\n", implode(', ', array_map(fn($f) => $f['name'], $schema['fields'])));
echo sprintf("  graph hash     = %s\n", substr($graph->hash, 0, 16) . '…');
echo sprintf("  wall (boot→done)= %.2f ms\n", $wall);

unlink($dbPath);
