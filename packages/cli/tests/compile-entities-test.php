<?php
declare(strict_types=1);

$autoload = [
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];
foreach ($autoload as $f) {
    if (file_exists($f)) {
        require $f;
        break;
    }
}

/**
 * IMPLEMENTATION-001 Phase 7 — `ausus compile` orchestration (Tests 1–10).
 */

use Ausus\Cli\Command\CompileEntitiesCommand;
use Ausus\Cli\Repository\FileSchemaRepository;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

$workspace = sys_get_temp_dir() . '/ausus-7-' . bin2hex(random_bytes(4));
$cmd = new CompileEntitiesCommand();

// Helpers --------------------------------------------------------------------
$write = function (string $dir, string $rel, string $body): void {
    $p = $dir . '/' . $rel;
    @mkdir(dirname($p), 0o775, true);
    file_put_contents($p, $body);
};
$valid = fn (string $id): string =>
    "<?php\nuse Ausus\\Authoring\\Dsl\\Definition;\nuse Ausus\\Definition\\Enum\\FieldType;\n"
    . "return Definition::make('{$id}', true)->field('amount', FieldType::Decimal)->build();\n";
$run = function (string $entities, string $root) use ($cmd): array {
    $out = fopen('php://memory', 'r+');
    $err = fopen('php://memory', 'r+');
    $code = $cmd->run($entities, $root, $out, $err);
    rewind($out);
    rewind($err);
    return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
};
$schemaFiles = fn (string $root): array => glob($root . '/schemas/*.json') ?: [];
$index = fn (string $root): array => is_file($root . '/index.json')
    ? (array) json_decode((string) file_get_contents($root . '/index.json'), true)
    : [];
$case = fn (string $n): array => [$workspace . "/{$n}/entities", $workspace . "/{$n}/.ausus"];

echo "── Test 1 — 1 DSL file → 1 schema + 1 index ────────────────\n";
[$e, $r] = $case('t1');
$write($e, 'Invoice.php', $valid('invoice'));
[$code, $out] = $run($e, $r);
$ok('Test 1 — exit SUCCESS', $code === CompileEntitiesCommand::SUCCESS);
$ok('Test 1 — 1 schema file', count($schemaFiles($r)) === 1);
$ok('Test 1 — index has invoice', $index($r) === ['invoice' => array_values($index($r))[0]]);
$ok('Test 1 — minimal output', str_contains($out, 'definitions : 1') && str_contains($out, 'schemas     : 1'));

echo "── Test 2 — 2 DSL files → 2 schemas + coherent index ───────\n";
[$e, $r] = $case('t2');
$write($e, 'Invoice.php', $valid('invoice'));
$write($e, 'Customer.php', $valid('customer'));
[$code] = $run($e, $r);
$ok('Test 2 — 2 schema files', $code === 0 && count($schemaFiles($r)) === 2);
$idx = $index($r);
$ok('Test 2 — index coherent (both ids)', isset($idx['invoice'], $idx['customer']) && count($idx) === 2);

echo "── Test 3 — compile twice → same hash, no needless rewrite ──\n";
[$e, $r] = $case('t3');
$write($e, 'Invoice.php', $valid('invoice'));
$run($e, $r);
$file = $schemaFiles($r)[0];
$past = 1_600_000_000;
touch($file, $past);
clearstatcache();
$run($e, $r); // second compile, same entities
clearstatcache();
$ok('Test 3 — schema file not rewritten (timestamp preserved)', filemtime($file) === $past);
$ok('Test 3 — still exactly 1 schema', count($schemaFiles($r)) === 1);

echo "── Test 4 — empty entities → deterministic ─────────────────\n";
[$e, $r] = $case('t4');
@mkdir($e, 0o775, true); // empty entities dir
[$code, $out] = $run($e, $r);
$ok('Test 4 — SUCCESS, 0 definitions/0 schemas', $code === 0 && str_contains($out, 'definitions : 0') && str_contains($out, 'schemas     : 0'));
$ok('Test 4 — no schema files written', count($schemaFiles($r)) === 0);

echo "── Test 5 — DSL error → no artifact written ────────────────\n";
[$e, $r] = $case('t5');
$write($e, 'Bad.php', "<?php\n\$x = getenv('HOME');\nreturn null;\n");
[$code] = $run($e, $r);
$ok('Test 5 — FAILURE', $code === CompileEntitiesCommand::FAILURE);
$ok('Test 5 — nothing written (.ausus absent or empty)', !is_dir($r . '/schemas') && !is_file($r . '/index.json'));

echo "── Test 6 — closure error → no artifact written ────────────\n";
[$e, $r] = $case('t6');
$write($e, 'Invoice.php',
    "<?php\nuse Ausus\\Authoring\\Dsl\\Definition;\nuse Ausus\\Definition\\Enum\\FieldType;\n"
    . "return Definition::make('invoice', true)->field('buyer', FieldType::Reference, ['nullable'=>true,'typeOptions'=>['target'=>'ghost']])->build();\n");
[$code, , $err] = $run($e, $r);
$ok('Test 6 — FAILURE on dangling reference', $code === 1 && str_contains($err, 'ausus:compile'));
$ok('Test 6 — nothing written', !is_dir($r . '/schemas') && !is_file($r . '/index.json'));

echo "── Test 7 — existing repo → correct update ─────────────────\n";
[$e, $r] = $case('t7');
$write($e, 'Invoice.php', $valid('invoice'));
$run($e, $r); // first compile (invoice only)
$write($e, 'Customer.php', $valid('customer')); // add an entity
[$code] = $run($e, $r); // recompile
$idx = $index($r);
$ok('Test 7 — index updated with both', $code === 0 && isset($idx['invoice'], $idx['customer']));
$ok('Test 7 — both resolvable', (new FileSchemaRepository($r))->resolve('invoice')->identity === 'invoice'
    && (new FileSchemaRepository($r))->resolve('customer')->identity === 'customer');

echo "── Test 8 — previous cache survives a failed compile ───────\n";
[$e, $r] = $case('t8');
$write($e, 'Invoice.php', $valid('invoice'));
$run($e, $r); // good compile
$goodIndex = $index($r);
$write($e, 'Broken.php', // introduce a failing definition (dangling ref)
    "<?php\nuse Ausus\\Authoring\\Dsl\\Definition;\nuse Ausus\\Definition\\Enum\\FieldType;\n"
    . "return Definition::make('broken', true)->field('x', FieldType::Reference, ['nullable'=>true,'typeOptions'=>['target'=>'ghost']])->build();\n");
[$code] = $run($e, $r); // must fail atomically
$ok('Test 8 — failing recompile returns FAILURE', $code === 1);
$ok('Test 8 — previous index intact', $index($r) === $goodIndex);
$ok('Test 8 — previous schema still resolvable', (new FileSchemaRepository($r))->resolve('invoice')->identity === 'invoice');

echo "── Test 9 — written schemas resolvable in a fresh repo ─────\n";
[$e, $r] = $case('t9');
$write($e, 'Invoice.php', $valid('invoice'));
$write($e, 'billing/Payment.php', $valid('payment'));
$run($e, $r);
$fresh = new FileSchemaRepository($r); // brand-new, no compiler
$ok('Test 9 — resolve(invoice)', $fresh->resolve('invoice')->identity === 'invoice');
$ok('Test 9 — resolve(payment) (discovered recursively)', $fresh->resolve('payment')->identity === 'payment');

echo "── Test 10 — only repository artifacts (no runtime) ────────\n";
[$e, $r] = $case('t10');
$write($e, 'Invoice.php', $valid('invoice'));
$run($e, $r);
$entries = array_values(array_diff(scandir($r) ?: [], ['.', '..']));
sort($entries);
$ok('Test 10 — .ausus contains exactly schemas/ + index.json', $entries === ['index.json', 'schemas']);

// Cleanup --------------------------------------------------------------------
$rrm = function (string $d) use (&$rrm): void {
    foreach (@scandir($d) ?: [] as $x) {
        if ($x === '.' || $x === '..') {
            continue;
        }
        $p = $d . '/' . $x;
        is_dir($p) ? $rrm($p) : @unlink($p);
    }
    @rmdir($d);
};
$rrm($workspace);

echo "\n";
echo $fail === 0
    ? "PHASE 7 / CompileEntitiesCommand OK — {$pass} checks passed\n"
    : "PHASE 7 / CompileEntitiesCommand FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
