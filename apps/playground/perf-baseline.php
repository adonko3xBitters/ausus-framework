<?php
declare(strict_types=1);

/**
 * AUSUS — PHP performance baseline.
 *
 * Measures, with real hrtime(true) (ns precision):
 *   1. Compile graph (Compiler::compile) at N=1, 5, 25 plugins
 *   2. Cold boot (compile + apply schema + first invoke)
 *   3. Hot action invoke (Invoker::invoke after warmup), 200 iterations
 *   4. Projection render (ProjectionRenderer::render) at N=1, 100, 1000 rows
 *   5. Repository find / create / update at N=1, 100, 1000
 *   6. Memory footprint (peak via memory_get_peak_usage)
 *
 * Variance: each metric runs `iters` times (configurable), reports
 * min / p50 / p95 / max in microseconds.
 *
 * Run:  php apps/playground/perf-baseline.php
 * Optional:  ITERS=500 php apps/playground/perf-baseline.php
 *
 * NO MICRO-OPTIMIZATIONS: this script is read-only on the framework.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{
    Compiler, Tenant, TenantId, ActorRef, StubActor, Reference,
    FieldNode, ActionNode, PolicyNode, WorkflowNode, TransitionNode,
    ProjectionNode, EntityNode, Plugin
};
use Ausus\Persistence\Sql\{SqlitePersistenceDriver, SchemaDeriver, DatabaseAuditSink};
use Ausus\Runtime\{
    PolicyEngine, WorkflowRuntime, TransitionSetIndex, EffectDispatcher,
    DefaultAuditor, SequenceCounter, Invoker, ProjectionRenderer,
};
use Acme\Billing\HelloInvoicePlugin;

// ─── stats helpers ──────────────────────────────────────────────────────────
function ns_to_us(int $ns): float { return $ns / 1000.0; }
function percentile(array $sorted, float $p): float {
    if (empty($sorted)) return 0.0;
    $k = ($p / 100) * (count($sorted) - 1);
    $f = (int)floor($k); $c = (int)ceil($k);
    if ($f === $c) return $sorted[$f];
    return $sorted[$f] + ($sorted[$c] - $sorted[$f]) * ($k - $f);
}

function bench(string $label, int $iters, int $warmup, callable $body): array {
    for ($i = 0; $i < $warmup; $i++) $body();
    $samples = [];
    for ($i = 0; $i < $iters; $i++) {
        $t0 = hrtime(true);
        $body();
        $samples[] = hrtime(true) - $t0;
    }
    sort($samples);
    $min  = ns_to_us($samples[0]);
    $p50  = ns_to_us((int) percentile($samples, 50));
    $p95  = ns_to_us((int) percentile($samples, 95));
    $max  = ns_to_us($samples[count($samples)-1]);
    $mean = ns_to_us((int) (array_sum($samples) / count($samples)));
    return ['label' => $label, 'iters' => $iters, 'min' => $min, 'p50' => $p50, 'p95' => $p95, 'max' => $max, 'mean' => $mean];
}

function fmt_row(array $r): string {
    return sprintf("  %-44s  n=%-4d  min %8.2f µs  p50 %8.2f µs  p95 %8.2f µs  max %9.2f µs  mean %8.2f µs",
        $r['label'], $r['iters'], $r['min'], $r['p50'], $r['p95'], $r['max'], $r['mean']);
}

// ─── helpers: build a synthetic plugin with N entities ─────────────────────
function synth_plugin(int $entityCount): Plugin {
    return new class($entityCount) implements Plugin {
        public function __construct(private int $n) {}
        public function name(): string { return 'synth_' . $this->n; }
        public function phpNamespace(): string { return 'Synth\\'; }
        public function describe(): array {
            $entities = $actions = $policies = $workflows = $projections = [];
            for ($i = 0; $i < $this->n; $i++) {
                $efqn = "synth.thing{$i}";
                $entities[] = new EntityNode($efqn, true, [
                    new FieldNode('id','identity', true, false, [], null),
                    new FieldNode('tenant_id','system_string', true, false, [], null),
                    new FieldNode('_version','version', true, false, [], null),
                    new FieldNode('created_at','datetime', true, false, [], null),
                    new FieldNode('updated_at','datetime', true, false, [], null),
                    new FieldNode('name','string', false, false, [], null),
                    new FieldNode('status','enum', false, false, ['options'=>['DRAFT','DONE']], 'DRAFT'),
                ], [], [], []);
                $policies[] = new PolicyNode("{$efqn}.allow", \Ausus\Runtime\RoleRequired::class, ['role'=>'admin']);
                $actions[]  = new ActionNode("{$efqn}.create", $efqn, "{$efqn}.allow", false,
                    'kernel.builtin.create', ['entityFqn'=>$efqn,'workflowStateField'=>'status','workflowInitial'=>'DRAFT'],
                    [], 'standard');
                $actions[]  = new ActionNode("{$efqn}.done", $efqn, "{$efqn}.allow", true,
                    'kernel.builtin.transition', ['entityFqn'=>$efqn,'stateField'=>'status','target'=>'DONE'],
                    [], 'standard');
                $workflows[] = new WorkflowNode("{$efqn}.flow", $efqn, 'status',
                    ['DRAFT','DONE'], 'DRAFT',
                    [new TransitionNode('DRAFT','DONE',"{$efqn}.done")]);
                $projections[] = new ProjectionNode("{$efqn}.summary", $efqn,
                    ['id','name','status'], ["{$efqn}.done"]);
            }
            return compact('entities','actions','policies','workflows','projections');
        }
    };
}

// ────────────────────────────────────────────────────────────────────────────
$ITERS  = (int)(getenv('ITERS') ?: 100);
$WARMUP = (int)(getenv('WARMUP') ?: 10);

$results = [];
$baseline_memory = memory_get_peak_usage(true);

echo "AUSUS perf baseline — PHP " . PHP_VERSION . "\n";
echo str_repeat('═', 120) . "\n";
echo "config: iters=$ITERS warmup=$WARMUP  hrtime=ns  PHP=" . PHP_VERSION . "\n\n";

// ════════════════════════════════════════════════════════════════════════════
// 1. COMPILE GRAPH — N=1, 5, 25 plugins (entity counts)
// ════════════════════════════════════════════════════════════════════════════
echo "── 1. Compile graph (Compiler::compile) ───────────────────────────────────────────────────────────────────────────────────\n";
$compiler = new Compiler();
foreach ([1, 5, 25] as $n) {
    $p = synth_plugin($n);
    $r = bench("compile_graph entities=$n", $ITERS, $WARMUP, function() use ($compiler, $p) {
        $compiler->compile([$p]);
    });
    $results[] = $r;
    echo fmt_row($r) . "\n";
}
echo "\n";

// ════════════════════════════════════════════════════════════════════════════
// 2. COLD BOOT — compile + schema apply + first invoke
// ════════════════════════════════════════════════════════════════════════════
echo "── 2. Cold boot (compile + schema apply + first invoke) ───────────────────────────────────────────────────────────────────\n";
$dbPath_cold = sys_get_temp_dir() . '/ausus-perf-cold.sqlite';
$cold_iters = 50;
$cold_warmup = 3;
$r = bench("cold_boot (HelloInvoice)", $cold_iters, $cold_warmup, function() use ($dbPath_cold) {
    if (file_exists($dbPath_cold)) unlink($dbPath_cold);
    $pdo = new PDO("sqlite:$dbPath_cold");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $compiler = new Compiler();
    $graph    = $compiler->compile([new HelloInvoicePlugin()]);
    foreach (SchemaDeriver::deriveAll($graph) as $stmt) $pdo->exec($stmt);
    $driver  = new SqlitePersistenceDriver($pdo, $graph);
    $tenant  = new Tenant(new TenantId('acme'));
    $actor   = new StubActor(new ActorRef('user','x','acme'),
        ['invoice.creator','invoice.issuer','invoice.canceler','invoice.viewer']);
    $invoker = new Invoker($graph, $driver,
        new PolicyEngine($graph), new WorkflowRuntime(new TransitionSetIndex($graph)),
        new EffectDispatcher(), new DefaultAuditor(new DatabaseAuditSink($pdo)),
        new SequenceCounter(), $tenant, $actor);
    $invoker->invoke('billing.invoice.create', null, [
        'number'=>'INV-1','customer_name'=>'Co','amount'=>['amount'=>'10.00','currency'=>'USD']]);
});
$results[] = $r;
echo fmt_row($r) . "\n";
@unlink($dbPath_cold);
echo "\n";

// ════════════════════════════════════════════════════════════════════════════
// SHARED — bootstrap one Invoker for hot benches
// ════════════════════════════════════════════════════════════════════════════
$dbPath = sys_get_temp_dir() . '/ausus-perf-hot.sqlite';
if (file_exists($dbPath)) unlink($dbPath);
$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode=WAL');
$compiler = new Compiler();
$graph = $compiler->compile([new HelloInvoicePlugin()]);
foreach (SchemaDeriver::deriveAll($graph) as $stmt) $pdo->exec($stmt);

$driver = new SqlitePersistenceDriver($pdo, $graph);
$tenant = new Tenant(new TenantId('acme'));
$actor  = new StubActor(new ActorRef('user','perf','acme'),
    ['invoice.creator','invoice.issuer','invoice.canceler','invoice.viewer']);
$invoker = new Invoker($graph, $driver,
    new PolicyEngine($graph),
    new WorkflowRuntime(new TransitionSetIndex($graph)),
    new EffectDispatcher(),
    new DefaultAuditor(new DatabaseAuditSink($pdo)),
    new SequenceCounter(),
    $tenant, $actor);

// Seed a deterministic dataset for find / projection benches
$seed_handles = [];
for ($i = 0; $i < 1000; $i++) {
    $out = $invoker->invoke('billing.invoice.create', null, [
        'number'=>"INV-S-$i", 'customer_name'=>"Co $i",
        'amount'=>['amount'=>(string)(100 + $i), 'currency'=>'USD']]);
    $seed_handles[] = $out['id'];
}

// ════════════════════════════════════════════════════════════════════════════
// 3. HOT ACTION INVOKE — create + transition
// ════════════════════════════════════════════════════════════════════════════
echo "── 3. Hot action invoke (Invoker::invoke) ─────────────────────────────────────────────────────────────────────────────────\n";
$counter = 0;
$r = bench("hot_invoke billing.invoice.create", $ITERS, $WARMUP, function() use ($invoker, &$counter) {
    $invoker->invoke('billing.invoice.create', null, [
        'number'=>"INV-HOT-" . (++$counter), 'customer_name'=>"H",
        'amount'=>['amount'=>'1.00','currency'=>'USD']]);
});
$results[] = $r; echo fmt_row($r) . "\n";

// Transition: needs a fresh subject each iter (issue → ISSUED is one-shot)
$transition_pool = [];
for ($i = 0; $i < $ITERS + $WARMUP + 5; $i++) {
    $out = $invoker->invoke('billing.invoice.create', null, [
        'number'=>"INV-TR-$i", 'customer_name'=>"T",
        'amount'=>['amount'=>'1.00','currency'=>'USD']]);
    $transition_pool[] = $out['id'];
}
$idx = 0;
$r = bench("hot_invoke billing.invoice.issue (transition)", $ITERS, $WARMUP, function() use ($invoker, $transition_pool, &$idx) {
    $id = $transition_pool[$idx++];
    $invoker->invoke('billing.invoice.issue',
        new Reference('acme','billing.invoice',$id), []);
});
$results[] = $r; echo fmt_row($r) . "\n";
echo "\n";

// ════════════════════════════════════════════════════════════════════════════
// 4. PROJECTION RENDER — at N=1, 100, 1000 rows
// ════════════════════════════════════════════════════════════════════════════
echo "── 4. Projection render (ProjectionRenderer::render) ──────────────────────────────────────────────────────────────────────\n";
$renderer = new ProjectionRenderer($graph, $driver, $tenant);

// At this point the DB has ~1000 seeded + ~2*ITERS hot-invoke rows. Bench summary
// against the full table — but record how many rows are returned for context.
$r = bench("render summary (~N rows in DB)", 50, 5, function() use ($renderer) {
    $renderer->render('billing.invoice.summary');
});
$row_count = count($renderer->render('billing.invoice.summary')['data']['items'] ?? []);
$r['label'] .= " [returned=$row_count rows]";
$results[] = $r; echo fmt_row($r) . "\n";

// DetailView for a known seed handle
$detail_id = $seed_handles[0];
$r = bench("render detail (single row)", $ITERS, $WARMUP, function() use ($renderer, $detail_id) {
    $renderer->render('billing.invoice.detail',
        new Reference('acme','billing.invoice',$detail_id));
});
$results[] = $r; echo fmt_row($r) . "\n";
echo "\n";

// ════════════════════════════════════════════════════════════════════════════
// 5. PERSISTENCE — find / create / update at N=1, 100, 1000
// ════════════════════════════════════════════════════════════════════════════
echo "── 5. SQLite persistence (Repository::find/create/update) ─────────────────────────────────────────────────────────────────\n";
// Need direct Repository access; build one in a transaction
$pdo->beginTransaction();
try {
    $handle = $driver->beginTransaction($tenant);
} catch (\Throwable) {
    // beginTransaction may already have started a transaction; commit first
    if ($pdo->inTransaction()) $pdo->commit();
    $handle = $driver->beginTransaction($tenant);
}

// Build a fresh Repository instance via the context (idiomatic V0 path)
// To bench just the repo, we'll call $invoker again and isolate the Repository
// timing by stripping the surrounding Invoker chain.
$ctx = $driver->context($tenant, $handle);
$repo = $ctx->repository('billing.invoice');

$idx = 0;
$r = bench("repo.find (1000-row table, by ULID)", 200, $WARMUP, function() use ($repo, $seed_handles, &$idx) {
    $id = $seed_handles[$idx++ % count($seed_handles)];
    $repo->find(new Reference('acme','billing.invoice',$id));
});
$results[] = $r; echo fmt_row($r) . "\n";

$create_counter = 0;
$r = bench("repo.create (single row)", 100, 5, function() use ($repo, &$create_counter) {
    $repo->create([
        'number'=>"INV-RC-" . (++$create_counter), 'customer_name'=>"R",
        'amount'=>['amount'=>'1.00','currency'=>'USD'], 'status'=>'DRAFT']);
});
$results[] = $r; echo fmt_row($r) . "\n";

// Update bench: needs the current version snapshot for each iter (we cached after find above)
$update_pool = [];
foreach (array_slice($seed_handles, 0, 100) as $id) {
    $e = $repo->find(new Reference('acme','billing.invoice',$id));
    if ($e !== null) $update_pool[] = [$id, $e->version];
}
$idx = 0;
$r = bench("repo.update (refresh version each iter)", 100, 5, function() use ($repo, &$update_pool, &$idx) {
    $i = $idx++ % count($update_pool);
    [$id, $version] = $update_pool[$i];
    $entity = $repo->update(new Reference('acme','billing.invoice',$id),
        ['customer_name' => "U"], $version);
    // refresh the version so next iter against the same id doesn't conflict
    $update_pool[$i] = [$id, $entity->version];
});
$results[] = $r; echo fmt_row($r) . "\n";

if ($pdo->inTransaction()) $pdo->commit();
echo "\n";

// ════════════════════════════════════════════════════════════════════════════
// 6. MEMORY FOOTPRINT
// ════════════════════════════════════════════════════════════════════════════
echo "── 6. Memory footprint ────────────────────────────────────────────────────────────────────────────────────────────────────\n";
$peak_total = memory_get_peak_usage(true);
$peak_used  = memory_get_peak_usage(false);
$delta = $peak_total - $baseline_memory;
printf("  baseline alloc=%.2f MB   peak alloc=%.2f MB   peak used=%.2f MB   delta=%.2f MB\n",
    $baseline_memory / 1048576.0, $peak_total / 1048576.0, $peak_used / 1048576.0, $delta / 1048576.0);
echo "\n";

// ════════════════════════════════════════════════════════════════════════════
// SUMMARY (machine-readable line for the doc)
// ════════════════════════════════════════════════════════════════════════════
echo str_repeat('═', 120) . "\n";
echo "  SUMMARY (p50 µs)\n";
echo str_repeat('═', 120) . "\n";
foreach ($results as $r) {
    printf("  %-58s  p50 %9.2f µs   (n=%d)\n", $r['label'], $r['p50'], $r['iters']);
}
@unlink($dbPath);
