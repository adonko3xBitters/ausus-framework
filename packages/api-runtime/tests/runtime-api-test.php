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
 * IMPLEMENTATION-002 — API Runtime (Invoke / Read / Schema + integration).
 */

use Ausus\Api\Runtime\Http\RequestContextFactory;
use Ausus\Api\Runtime\Http\RuntimeApi;
use Ausus\Cli\Command\CompileEntitiesCommand;
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
use Ausus\Engine\Repository\InMemorySchemaRepository;
use Ausus\Engine\Runtime\DefaultAuthorizationEvaluator;
use Ausus\Engine\Runtime\DefaultEntityEngine;
use Ausus\PersistenceContext;
use Ausus\PersistenceDriver;
use Ausus\Persistence\Memory\MemoryDriver;
use Ausus\Tenant;
use Ausus\TransactionHandle;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

// Compiled schemas (the API never compiles — this is test setup). ------------
$customerDef = new EntityDefinition('customer', true,
    [new FieldDefinition('name', FieldType::String, false)],
    [new ActionDefinition('create', ActionKind::Create, ['name'])],
    [new ProjectionDefinition('card', [new ExposedField('name')])]);
$invoiceDef = new EntityDefinition('invoice', true,
    [
        new FieldDefinition('amount', FieldType::Decimal, false),
        new FieldDefinition('status', FieldType::Enum, false, 'draft', true, ['values' => ['draft', 'approved']]),
        new FieldDefinition('secret', FieldType::String, true),
        new FieldDefinition('buyer', FieldType::Reference, true, null, false, ['target' => 'customer']),
    ],
    [
        new ActionDefinition('create', ActionKind::Create, ['amount', 'buyer', 'secret'],
            new Comparison(Comparator::Lt, new FactRef(FactSource::Input, 'amount'), new Literal(5000))),
        new ActionDefinition('approve', ActionKind::Transition, [],
            new Comparison(Comparator::Eq, new FactRef(FactSource::Actor, 'type'), new Literal('user')),
            new TransitionSpec('status', ['draft'], 'approved')),
    ],
    [new ProjectionDefinition('board',
        [new ExposedField('amount'), new ExposedField('secret', new Comparison(Comparator::Eq, new FactRef(FactSource::Actor, 'type'), new Literal('user')))],
        [new ExpandSpec('buyer', 'card')])]);

$graph = (new Compiler())->compile([$customerDef, $invoiceDef]);
$repo = new InMemorySchemaRepository();
foreach ($graph->schemas as $s) {
    $repo->putByHash($s);
}
$engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$factory = new RequestContextFactory(new DateTimeImmutable('@1600000000')); // fixed clock
$userH = ['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user', 'X-Actor-Id' => 'u1'];
$systemH = ['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'system', 'X-Actor-Id' => 's1'];

$api = fn (PersistenceDriver $d): RuntimeApi => new RuntimeApi($repo, $engine, $d, $factory);

echo "── API Invoke ──────────────────────────────────────────────\n";
$d = new MemoryDriver();
$res = $api($d)->dispatch('POST', '/api/entities/invoice/actions/create', $userH, ['inputs' => ['amount' => 1000]]);
$ok('Invoke — authorized action → 200 + entity', $res['status'] === 200
    && ($res['body']['fields']['amount'] ?? null) === 1000
    && ($res['body']['fields']['status'] ?? null) === 'draft');

$res = $api($d)->dispatch('POST', '/api/entities/invoice/actions/create', $userH, ['inputs' => ['amount' => 9999]]);
$ok('Invoke — denied action (guard) → 403', $res['status'] === 403);

// transition denial via actor type
$created = $api($d)->dispatch('POST', '/api/entities/invoice/actions/create', $userH, ['inputs' => ['amount' => 100]]);
$id = $created['body']['reference']['identityHandle'];
$res = $api($d)->dispatch('POST', '/api/entities/invoice/actions/approve', $systemH, ['inputs' => ['id' => $id]]);
$ok('Invoke — transition denied (system) → 403', $res['status'] === 403);

echo "── API Invoke — transaction rollback → no persistence ──────\n";
$mem = new MemoryDriver();
$faulty = new class($mem) implements PersistenceDriver {
    public function __construct(private readonly PersistenceDriver $inner)
    {
    }

    public function beginTransaction(Tenant $t): TransactionHandle
    {
        return $this->inner->beginTransaction($t);
    }

    public function commit(TransactionHandle $h): void
    {
        throw new \RuntimeException('simulated commit failure');
    }

    public function rollback(TransactionHandle $h): void
    {
        $this->inner->rollback($h);
    }

    public function context(Tenant $t, TransactionHandle $h): PersistenceContext
    {
        return $this->inner->context($t, $h);
    }

    public function generateIdentity(string $fqn): string
    {
        return $this->inner->generateIdentity($fqn);
    }
};
$res = $api($faulty)->dispatch('POST', '/api/entities/invoice/actions/create', $userH, ['inputs' => ['amount' => 100]]);
$ok('Invoke — commit failure → 500', $res['status'] === 500);
$readBack = $api($mem)->dispatch('GET', '/api/entities/invoice/projections/board', $userH);
$ok('Invoke — rollback → nothing persisted', $readBack['status'] === 200 && $readBack['body']['rows'] === []);

echo "── API Read ────────────────────────────────────────────────\n";
$d = new MemoryDriver();
$api($d)->dispatch('POST', '/api/entities/invoice/actions/create', $userH, ['inputs' => ['amount' => 10, 'secret' => 'top']]);
$api($d)->dispatch('POST', '/api/entities/invoice/actions/create', $userH, ['inputs' => ['amount' => 20, 'secret' => 'low']]);
$res = $api($d)->dispatch('GET', '/api/entities/invoice/projections/board', $userH);
$ok('Read — simple projection → 200 + 2 rows', $res['status'] === 200 && count($res['body']['rows']) === 2);
$ok('Read — visibility permit → secret visible (user)', array_key_exists('secret', $res['body']['rows'][0]));

$resSys = $api($d)->dispatch('GET', '/api/entities/invoice/projections/board', $systemH);
$ok('Read — visibility deny → secret absent (system)', !array_key_exists('secret', $resSys['body']['rows'][0])
    && array_key_exists('amount', $resSys['body']['rows'][0]));

// expand single-hop
$d = new MemoryDriver();
$cust = $api($d)->dispatch('POST', '/api/entities/customer/actions/create', $userH, ['inputs' => ['name' => 'Acme']]);
$custId = $cust['body']['reference']['identityHandle'];
$api($d)->dispatch('POST', '/api/entities/invoice/actions/create', $userH, ['inputs' => ['amount' => 50, 'buyer' => $custId]]);
$res = $api($d)->dispatch('GET', '/api/entities/invoice/projections/board', $userH);
$ok('Read — expand single-hop → embedded customer.card', ($res['body']['rows'][0]['buyer']['name'] ?? null) === 'Acme');

echo "── API Schema ──────────────────────────────────────────────\n";
$res = $api(new MemoryDriver())->dispatch('GET', '/api/entities/invoice', $userH);
$actionNames = array_column($res['body']['actions'], 'name');
$projectionNames = array_column($res['body']['projections'], 'name');
$ok('Schema — 200 + identity', $res['status'] === 200 && $res['body']['identity'] === 'invoice');
$ok('Schema — actions discoverable', in_array('create', $actionNames, true) && in_array('approve', $actionNames, true));
$ok('Schema — projections discoverable', in_array('board', $projectionNames, true));
$ok('Schema — JSON-serialisable', json_encode($res['body']) !== false);
$ok('Schema — unknown entity → 404', $api(new MemoryDriver())->dispatch('GET', '/api/entities/ghost', $userH)['status'] === 404);

echo "── Integration — DSL → compile → .ausus → resolve → API ────\n";
$ws = sys_get_temp_dir() . '/ausus-api-' . bin2hex(random_bytes(4));
$entities = $ws . '/entities';
@mkdir($entities, 0o775, true);
file_put_contents($entities . '/Customer.php',
    "<?php\nuse Ausus\\Authoring\\Dsl\\Definition;\nuse Ausus\\Definition\\Enum\\FieldType;\nuse Ausus\\Definition\\Enum\\ActionKind;\n"
    . "return Definition::make('customer', true)->field('name', FieldType::String)->action('create', ActionKind::Create, ['inputs'=>['name']])->projection('card', ['fields'=>[['field'=>'name']]])->build();\n");
file_put_contents($entities . '/Invoice.php',
    "<?php\nuse Ausus\\Authoring\\Dsl\\Definition;\nuse Ausus\\Authoring\\Dsl\\Expr;\nuse Ausus\\Definition\\Enum\\FieldType;\nuse Ausus\\Definition\\Enum\\ActionKind;\n"
    . "return Definition::make('invoice', true)"
    . "->field('amount', FieldType::Decimal)"
    . "->field('buyer', FieldType::Reference, ['nullable'=>true,'typeOptions'=>['target'=>'customer']])"
    . "->action('create', ActionKind::Create, ['inputs'=>['amount','buyer'], 'guard'=>Expr::lt(Expr::input('amount'), 5000)])"
    . "->projection('board', ['fields'=>[['field'=>'amount']], 'expand'=>[['via'=>'buyer','projection'=>'card']]])->build();\n");

$aususRoot = $ws . '/.ausus';
$code = (new CompileEntitiesCommand())->run($entities, $aususRoot, fopen('php://memory', 'r+'), fopen('php://memory', 'r+'));
$fileRepo = new FileSchemaRepository($aususRoot);                         // resolve from disk — no recompilation
$intEngine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $fileRepo);
$intDriver = new MemoryDriver();
$intApi = new RuntimeApi($fileRepo, $intEngine, $intDriver, $factory);

$c = $intApi->dispatch('POST', '/api/entities/customer/actions/create', $userH, ['inputs' => ['name' => 'Globex']]);
$cid = $c['body']['reference']['identityHandle'];
$inv = $intApi->dispatch('POST', '/api/entities/invoice/actions/create', $userH, ['inputs' => ['amount' => 1200, 'buyer' => $cid]]);
$rows = $intApi->dispatch('GET', '/api/entities/invoice/projections/board', $userH);
$ok('Integration — compile succeeded', $code === CompileEntitiesCommand::SUCCESS);
$ok('Integration — API invoke (customer + invoice) → 200', $c['status'] === 200 && $inv['status'] === 200);
$ok('Integration — API read with expand → embedded',
    $rows['status'] === 200 && count($rows['body']['rows']) === 1
    && $rows['body']['rows'][0]['amount'] === 1200
    && ($rows['body']['rows'][0]['buyer']['name'] ?? null) === 'Globex');

$rrm = function (string $dir) use (&$rrm): void {
    foreach (@scandir($dir) ?: [] as $x) {
        if ($x === '.' || $x === '..') {
            continue;
        }
        $p = $dir . '/' . $x;
        is_dir($p) ? $rrm($p) : @unlink($p);
    }
    @rmdir($dir);
};
$rrm($ws);

echo "\n";
echo $fail === 0
    ? "IMPLEMENTATION-002 / RuntimeApi OK — {$pass} checks passed\n"
    : "IMPLEMENTATION-002 / RuntimeApi FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
