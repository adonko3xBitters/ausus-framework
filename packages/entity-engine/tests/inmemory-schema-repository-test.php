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
 * IMPLEMENTATION-001 Phase 6 — InMemorySchemaRepository (Test 8 + contract).
 */

use Ausus\Definition\ActionDefinition;
use Ausus\Definition\EntityDefinition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\FieldDefinition;
use Ausus\Engine\Compile\Compiler;
use Ausus\Engine\Repository\InMemorySchemaRepository;

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

// Schemas produced by the Compiler (the repository never compiles).
$dec = fn (string $n): FieldDefinition => new FieldDefinition($n, FieldType::Decimal, false);
$str = fn (string $n): FieldDefinition => new FieldDefinition($n, FieldType::String, false);
$graph = (new Compiler())->compile([
    new EntityDefinition('invoice', true, [$dec('amount')], [new ActionDefinition('create', ActionKind::Create, ['amount'])], []),
    new EntityDefinition('customer', true, [$str('name')], [new ActionDefinition('create', ActionKind::Create, ['name'])], []),
]);
[$invoice, $customer] = $graph->schemas;

echo "── Test 8 — InMemorySchemaRepository honours the contract ───\n";
$repo = new InMemorySchemaRepository();
$repo->putByHash($invoice);
$repo->putByHash($customer);

$ok('put → getByHash returns the schema', $repo->getByHash($invoice->hash) === $invoice);
$ok('resolve(entityId) returns the right schema', $repo->resolve('customer') === $customer);
$throws('getByHash(absent) → error', fn () => $repo->getByHash('deadbeef'));
$throws('resolve(absent) → error', fn () => $repo->resolve('ghost'));

// Q5 — same hash twice ⇒ no rewrite (first instance kept).
$repo->putByHash($invoice);
$ok('putByHash twice same hash → first instance kept', $repo->getByHash($invoice->hash) === $invoice);

// Two distinct schemas ⇒ two distinct hashes resolvable.
$ok('two distinct schemas stored independently',
    $repo->resolve('invoice')->hash !== $repo->resolve('customer')->hash);

echo "\n";
echo $fail === 0
    ? "PHASE 6 / InMemory OK — {$pass} checks passed\n"
    : "PHASE 6 / InMemory FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
