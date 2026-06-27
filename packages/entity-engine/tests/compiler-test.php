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
 * IMPLEMENTATION-001 Phase 4 — Compiler (EntityDefinition[] → CompiledGraph).
 */

use Ausus\Compiled\EntitySchema;
use Ausus\Definition\ActionDefinition;
use Ausus\Definition\EntityDefinition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\FieldDefinition;
use Ausus\Engine\Compile\Compiler;
use Ausus\Engine\Compile\CompilationError;
use Ausus\Engine\Compile\CompiledGraph;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

$compiler = new Compiler();
$dec = fn (string $n): FieldDefinition => new FieldDefinition($n, FieldType::Decimal, false);
$str = fn (string $n): FieldDefinition => new FieldDefinition($n, FieldType::String, false);
$invoice = new EntityDefinition('invoice', true, [$dec('amount')], [new ActionDefinition('create', ActionKind::Create, ['amount'])], []);
$customer = new EntityDefinition('customer', true, [$str('name')], [new ActionDefinition('create', ActionKind::Create, ['name'])], []);

echo "── TEST H1 — 1 valid definition → 1 EntitySchema ────────────\n";
$g1 = $compiler->compile([$invoice]);
$ok('H1 — returns CompiledGraph', $g1 instanceof CompiledGraph);
$ok('H1 — exactly 1 schema', count($g1->schemas) === 1 && $g1->schemas[0] instanceof EntitySchema);
$ok('H1 — schema identity + version stamps', $g1->schemas[0]->identity === 'invoice'
    && $g1->schemas[0]->version->schemaVersion === '0.1.0'
    && $g1->schemas[0]->version->kernelVersion === '0.1.0'
    && $g1->schemas[0]->version->engineVersion === '0.1.0');

echo "── TEST H2 — N valid definitions → N EntitySchema ───────────\n";
$g2 = $compiler->compile([$invoice, $customer]);
$ok('H2 — exactly 2 schemas', count($g2->schemas) === 2);
$ids = array_map(fn (EntitySchema $s): string => $s->identity, $g2->schemas);
$ok('H2 — both identities present', in_array('invoice', $ids, true) && in_array('customer', $ids, true));

echo "── TEST H3 — SchemaIndex correctly built ────────────────────\n";
$ok('H3 — index maps every EntityId', $g2->index->hashFor('invoice') !== null && $g2->index->hashFor('customer') !== null);
$ok('H3 — index hash === schema hash', $g2->index->hashFor('invoice') === $g2->schemas[array_search('invoice', $ids, true)]->hash);
$ok('H3 — unknown id → null', $g2->index->hashFor('absent') === null);

echo "── TEST H4 — same semantics → same hash ─────────────────────\n";
// Reordered fields/actions, semantically identical.
$reordered = new EntityDefinition('customer', true, [$str('name')], [new ActionDefinition('create', ActionKind::Create, ['name'])], []);
$hashA = $compiler->compile([$customer])->schemas[0]->hash;
$hashB = $compiler->compile([$reordered])->schemas[0]->hash;
$ok('H4 — identical semantics → identical hash', $hashA === $hashB);
$ok('H4 — hash is 64-hex SHA-256', (bool) preg_match('/^[0-9a-f]{64}$/', $hashA));

echo "── TEST H5 — closure error → no partial output ──────────────\n";
$bad = new EntityDefinition('broken', true,
    [new FieldDefinition('buyer', FieldType::Reference, true, null, false, ['target' => 'ghost'])], [], []);
$threw = false;
$result = 'sentinel';
try {
    $result = $compiler->compile([$invoice, $bad]); // valid + invalid in one set
} catch (CompilationError $e) {
    $threw = true;
}
$ok('H5 — throws CompilationError', $threw);
$ok('H5 — no partial CompiledGraph returned', $result === 'sentinel');

echo "\n";
echo $fail === 0
    ? "PHASE 4 / Compiler OK — {$pass} checks passed\n"
    : "PHASE 4 / Compiler FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
