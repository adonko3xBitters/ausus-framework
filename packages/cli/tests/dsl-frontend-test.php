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
 * IMPLEMENTATION-001 Phase 5B — DslFrontend (discovery + scan + load).
 */

use Ausus\Cli\Authoring\DefinitionFileLoader;
use Ausus\Cli\Authoring\DslFrontend;
use Ausus\Definition\EntityDefinition;
use Ausus\Engine\Compile\CompilationError;
use Ausus\Engine\Compile\Compiler;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

// Temp fixture workspace -----------------------------------------------------
$base = sys_get_temp_dir() . '/ausus-5b-' . bin2hex(random_bytes(4));
$mk = function (string $rel, string $body) use ($base): string {
    $path = $base . '/' . $rel;
    @mkdir(dirname($path), 0o775, true);
    file_put_contents($path, $body);
    return $path;
};
$header = "<?php\nuse Ausus\\Authoring\\Dsl\\Definition;\nuse Ausus\\Authoring\\Dsl\\Expr;\nuse Ausus\\Definition\\Enum\\FieldType;\nuse Ausus\\Definition\\Enum\\ActionKind;\n";
$valid = fn (string $id): string => $header . "return Definition::make('{$id}', true)->field('amount', FieldType::Decimal)->build();\n";

$frontend = new DslFrontend();
$loader = new DefinitionFileLoader();
$rejects = function (string $label, callable $fn) use ($ok): void {
    try {
        $fn();
        $ok($label . ' → CompilationError', false);
    } catch (CompilationError $e) {
        $ok($label . '  (' . $e->getMessage() . ')', true);
    }
};

echo "── Test 1 — valid file → EntityDefinition ──────────────────\n";
$f1 = $mk('entities/Invoice.php', $valid('invoice'));
$def = $loader->load($f1);
$ok('Test 1 — returns EntityDefinition', $def instanceof EntityDefinition && $def->identity === 'invoice');

echo "── Test 2 — two valid files → EntityDefinition[2] ──────────\n";
$dir2 = $base . '/two';
$mk('two/A.php', $valid('alpha'));
$mk('two/B.php', $valid('beta'));
$defs = $frontend->discover($dir2);
$ok('Test 2 — exactly 2 definitions', count($defs) === 2 && $defs[0] instanceof EntityDefinition && $defs[1] instanceof EntityDefinition);

echo "── Test 3 — returns non-EntityDefinition → reject ──────────\n";
$f3 = $mk('bad/Std.php', "<?php\nreturn new stdClass();\n");
$rejects('Test 3 — new stdClass()', fn () => $loader->load($f3));

echo "── Test 4 — no return → reject ─────────────────────────────\n";
$f4 = $mk('bad/NoReturn.php', $header . "\$x = Definition::make('x')->build();\n");
$rejects('Test 4 — no return value', fn () => $loader->load($f4));

echo "── Tests 5–8 — forbidden symbols rejected BEFORE evaluation ─\n";
$forb = $base . '/forbidden';
$f5 = $mk('forbidden/Env.php', $header . "\$h = getenv('HOME');\nreturn Definition::make('x')->build();\n");
$f6 = $mk('forbidden/Rand.php', $header . "\$n = rand(1, 9);\nreturn Definition::make('x')->build();\n");
$f7 = $mk('forbidden/Cuf.php', $header . "call_user_func('strlen', 'x');\nreturn Definition::make('x')->build();\n");
$f8 = $mk('forbidden/Refl.php', $header . "\$r = new ReflectionClass(Definition::class);\nreturn Definition::make('x')->build();\n");
$scan = (new \Ausus\Cli\Authoring\ForbiddenSymbolScanner());
$rejects('Test 5 — getenv', fn () => $scan->scan(file_get_contents($f5)));
$rejects('Test 6 — rand', fn () => $scan->scan(file_get_contents($f6)));
$rejects('Test 7 — call_user_func', fn () => $scan->scan(file_get_contents($f7)));
$rejects('Test 8 — ReflectionClass', fn () => $scan->scan(file_get_contents($f8)));
// And via the frontend (scan happens before load): forbidden dir aborts discovery.
$rejects('Tests 5–8 — frontend aborts on forbidden dir', fn () => $frontend->discover($forb));

echo "── Test 9 — recursive discovery ────────────────────────────\n";
$rec = $base . '/rec';
$mk('rec/Invoice.php', $valid('invoice'));
$mk('rec/billing/Payment.php', $valid('payment'));
$recDefs = $frontend->discover($rec);
$ids = array_map(fn (EntityDefinition $d): string => $d->identity, $recDefs);
sort($ids);
$ok('Test 9 — 2 definitions discovered recursively', count($recDefs) === 2 && $ids === ['invoice', 'payment']);
$ok('Test 9 — identity from content, not filename', in_array('payment', $ids, true));

echo "── Test 10 — output consumable by Compiler with no adaptation ─\n";
$pipeline = $base . '/pipeline';
$mk('pipeline/Invoice.php', $valid('invoice'));
$mk('pipeline/Customer.php', $valid('customer'));
$produced = $frontend->discover($pipeline);
$graph = (new Compiler())->compile($produced); // <-- no transformation between frontend and compiler
$ok('Test 10 — Compiler accepts frontend output directly', count($graph->schemas) === 2);
$ok('Test 10 — index built for both', $graph->index->hashFor('invoice') !== null && $graph->index->hashFor('customer') !== null);

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
$rrm($base);

echo "\n";
echo $fail === 0
    ? "PHASE 5B / DslFrontend OK — {$pass} checks passed\n"
    : "PHASE 5B / DslFrontend FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
