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
 * IMPLEMENTATION-001 Phase 6 — FileSchemaRepository (Tests 1–7, 9, 10).
 */

use Ausus\Cli\Repository\FileSchemaRepository;
use Ausus\Definition\ActionDefinition;
use Ausus\Definition\EntityDefinition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\Comparator;
use Ausus\Definition\Enum\FactSource;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\ExpandSpec;
use Ausus\Definition\ExposedField;
use Ausus\Definition\Expression\Comparison;
use Ausus\Definition\Expression\FactRef;
use Ausus\Definition\Expression\Literal;
use Ausus\Definition\FieldDefinition;
use Ausus\Definition\ProjectionDefinition;
use Ausus\Definition\TransitionSpec;
use Ausus\Engine\Compile\Compiler;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};
$throws = function (string $label, callable $fn) use ($ok): void {
    try {
        $fn();
        $ok($label . ' → error', false);
    } catch (\Throwable $e) {
        $ok($label, true);
    }
};

// Rich schemas straight from the Compiler (the repository never compiles). -----
$customer = new EntityDefinition('customer', true, [new FieldDefinition('name', FieldType::String, false)], [],
    [new ProjectionDefinition('card', [new ExposedField('name')])]);
$invoice = new EntityDefinition('invoice', true,
    [
        new FieldDefinition('amount', FieldType::Decimal, false),
        new FieldDefinition('status', FieldType::Enum, false, 'draft', true, ['values' => ['draft', 'approved']]),
        new FieldDefinition('buyer', FieldType::Reference, true, null, false, ['target' => 'customer']),
    ],
    [
        new ActionDefinition('create', ActionKind::Create, ['amount', 'buyer']),
        new ActionDefinition('approve', ActionKind::Transition, [],
            new Comparison(Comparator::Gte, new FactRef(FactSource::Actor, 'limit'), new FactRef(FactSource::Subject, 'amount')),
            new TransitionSpec('status', ['draft'], 'approved')),
    ],
    [new ProjectionDefinition('board',
        [new ExposedField('amount'), new ExposedField('status', new Comparison(Comparator::Eq, new FactRef(FactSource::Actor, 'role'), new Literal('admin')))],
        [new ExpandSpec('buyer', 'card')])]);
$graph = (new Compiler())->compile([$customer, $invoice]);
[$customerSchema, $invoiceSchema] = $graph->schemas;

$root = sys_get_temp_dir() . '/ausus-6-' . bin2hex(random_bytes(4));
$repo = new FileSchemaRepository($root);

echo "── Test 1 — putByHash → getByHash → identical ──────────────\n";
$repo->putByHash($invoiceSchema);
$repo->putByHash($customerSchema);
$ok('Test 1 — getByHash == original (value equality)', $repo->getByHash($invoiceSchema->hash) == $invoiceSchema);

echo "── Test 2 — resolve(entityId) → right schema ───────────────\n";
$ok('Test 2 — resolve(invoice)', $repo->resolve('invoice') == $invoiceSchema);
$ok('Test 2 — resolve(customer)', $repo->resolve('customer') == $customerSchema);

echo "── Test 3 — getByHash(absent) → error ──────────────────────\n";
$throws('Test 3', fn () => $repo->getByHash('deadbeefdeadbeef'));

echo "── Test 4 — resolve(absent) → error ────────────────────────\n";
$throws('Test 4', fn () => $repo->resolve('ghost'));

echo "── Test 5 — putByHash twice same hash → no rewrite ─────────\n";
$path = $root . '/schemas/' . $invoiceSchema->hash . '.json';
$pastTime = 1_600_000_000; // a fixed time in the past
touch($path, $pastTime);
clearstatcache();
$repo->putByHash($invoiceSchema); // second store, same hash
clearstatcache();
$ok('Test 5 — schema file timestamp unchanged (not rewritten)', filemtime($path) === $pastTime);

echo "── Test 6 — two distinct schemas → two files ───────────────\n";
$files = glob($root . '/schemas/*.json');
$ok('Test 6 — exactly two schema files', is_array($files) && count($files) === 2);

echo "── Test 7 — index.json coherent (EntityId → hash only) ─────\n";
$index = json_decode((string) file_get_contents($root . '/index.json'), true);
$ok('Test 7 — maps each EntityId to its hash, nothing else',
    $index === ['customer' => $customerSchema->hash, 'invoice' => $invoiceSchema->hash]);

echo "── Test 9 — schema loaded from disk == original ────────────\n";
$fresh = new FileSchemaRepository($root); // brand-new instance, reads disk only
$loaded = $fresh->getByHash($invoiceSchema->hash);
$ok('Test 9 — round-trip value equality (full graph, guards, expand)', $loaded == $invoiceSchema);

echo "── Test 10 — no recompilation; works from EntitySchema only ─\n";
// A fresh repository, no Compiler, resolves straight from persisted artefacts.
$standalone = new FileSchemaRepository($root);
$resolved = $standalone->resolve('invoice');
$ok('Test 10 — resolve without any compiler', $resolved == $invoiceSchema && $resolved->hash === $invoiceSchema->hash);

// Cleanup --------------------------------------------------------------------
$rrm = function (string $d) use (&$rrm): void {
    foreach (@scandir($d) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $d . '/' . $e;
        is_dir($p) ? $rrm($p) : @unlink($p);
    }
    @rmdir($d);
};
$rrm($root);

echo "\n";
echo $fail === 0
    ? "PHASE 6 / FileSchemaRepository OK — {$pass} checks passed\n"
    : "PHASE 6 / FileSchemaRepository FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
