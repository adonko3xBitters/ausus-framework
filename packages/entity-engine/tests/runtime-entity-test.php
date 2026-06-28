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
 * IMPLEMENTATION-001 Phase 10 — DefaultEntityEngine::bind() + DefaultRuntimeEntity
 * (T1–T14): the full RFC-011 vertical slice.
 */

use Ausus\ActorRef;
use Ausus\Cli\Command\CompileEntitiesCommand;
use Ausus\Cli\Repository\FileSchemaRepository;
use Ausus\Contracts\Context;
use Ausus\Contracts\RuntimeEntity;
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
use Ausus\Reference;
use Ausus\Tenant;
use Ausus\TenantId;
use Ausus\TransactionHandle;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};
$denied = function (string $label, callable $fn) use ($ok): void {
    try {
        $fn();
        $ok($label . ' → refused', false);
    } catch (\Throwable $e) {
        $ok($label, true);
    }
};

// Context (deterministic now). ----------------------------------------------
$ctx = fn (string $actorType): Context => new class($actorType) implements Context {
    public function __construct(private readonly string $type)
    {
    }

    public function actor(): ActorRef
    {
        return new ActorRef($this->type, 'u1', 'acme');
    }

    public function tenant(): Tenant
    {
        return new Tenant(new TenantId('acme'));
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@1600000000');
    }
};
$user = $ctx('user');
$system = $ctx('system');

// Schemas (compiled). -------------------------------------------------------
$customerDef = new EntityDefinition('customer', true,
    [new FieldDefinition('name', FieldType::String, false)],
    [new ActionDefinition('create', ActionKind::Create, ['name'])],
    [new ProjectionDefinition('card', [new ExposedField('name')])]);
$invoiceDef = new EntityDefinition('invoice', true,
    [
        new FieldDefinition('amount', FieldType::Decimal, false),
        new FieldDefinition('status', FieldType::Enum, false, 'draft', true, ['values' => ['draft', 'approved', 'rejected']]),
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
$invoiceSchema = $repo->resolve('invoice');
$customerSchema = $repo->resolve('customer');
$engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);

$findEntity = function (PersistenceDriver $d, string $fqn, string $id) {
    $tenant = new Tenant(new TenantId('acme'));
    $tx = $d->beginTransaction($tenant);
    $e = $d->context($tenant, $tx)->repository($fqn)->find(new Reference('acme', $fqn, $id));
    $d->rollback($tx);
    return $e;
};

echo "── T1 — create, guard permit → entity created ──────────────\n";
$d = new MemoryDriver();
$rt = $engine->bind($invoiceSchema, $d);
$e = $rt->invoke('create', ['amount' => 1000], $user);
$ok('T1 — entity created with amount + default status', $e->field('amount') === 1000 && $e->field('status') === 'draft');
$ok('T1 — persisted (findAll = 1)', count($rt->read('board', [], $user)) === 1);

echo "── T2 — create, guard deny → refused ───────────────────────\n";
$d = new MemoryDriver();
$rt = $engine->bind($invoiceSchema, $d);
$denied('T2 — amount 9999 ≥ 5000 denied', fn () => $rt->invoke('create', ['amount' => 9999], $user));
$ok('T2 — nothing persisted', $rt->read('board', [], $user) === []);

echo "── T3 — transition permit → state changed ──────────────────\n";
$d = new MemoryDriver();
$rt = $engine->bind($invoiceSchema, $d);
$e = $rt->invoke('create', ['amount' => 100], $user);
$approved = $rt->invoke('approve', ['id' => $e->reference->identityHandle], $user);
$ok('T3 — status draft → approved', $approved->field('status') === 'approved');

echo "── T4 — transition deny → refused ──────────────────────────\n";
$d = new MemoryDriver();
$rt = $engine->bind($invoiceSchema, $d);
$e = $rt->invoke('create', ['amount' => 100], $user);
$denied('T4 — approve as system denied', fn () => $rt->invoke('approve', ['id' => $e->reference->identityHandle], $system));
$ok('T4 — status unchanged (draft)', $findEntity($d, 'invoice', $e->reference->identityHandle)->field('status') === 'draft');

echo "── T5 — invalid transition → refused ───────────────────────\n";
$d = new MemoryDriver();
$rt = $engine->bind($invoiceSchema, $d);
$e = $rt->invoke('create', ['amount' => 100], $user);
$rt->invoke('approve', ['id' => $e->reference->identityHandle], $user); // draft → approved
$denied('T5 — approve again (not in from) refused', fn () => $rt->invoke('approve', ['id' => $e->reference->identityHandle], $user));

echo "── T6 — error during create → rollback ─────────────────────\n";
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
$rtFaulty = $engine->bind($invoiceSchema, $faulty);
$denied('T6 — create fails at commit', fn () => $rtFaulty->invoke('create', ['amount' => 100], $user));
$ok('T6 — nothing committed (rollback)', $engine->bind($invoiceSchema, $mem)->read('board', [], $user) === []);

echo "── T7 — error during transition → rollback ─────────────────\n";
$mem = new MemoryDriver();
$seed = $engine->bind($invoiceSchema, $mem)->invoke('create', ['amount' => 100], $user); // committed, draft
$faulty2 = new class($mem) implements PersistenceDriver {
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
$denied('T7 — transition fails at commit', fn () => $engine->bind($invoiceSchema, $faulty2)->invoke('approve', ['id' => $seed->reference->identityHandle], $user));
$ok('T7 — status preserved (draft) after rollback', $findEntity($mem, 'invoice', $seed->reference->identityHandle)->field('status') === 'draft');

echo "── T8 — simple projection → exposed fields ─────────────────\n";
$d = new MemoryDriver();
$rt = $engine->bind($invoiceSchema, $d);
$rt->invoke('create', ['amount' => 10, 'secret' => 'x'], $user);
$rt->invoke('create', ['amount' => 20, 'secret' => 'y'], $user);
$rows = $rt->read('board', [], $user);
$ok('T8 — two rows with amount exposed', count($rows) === 2 && isset($rows[0]['amount'], $rows[1]['amount']));

echo "── T9 — visibility permit → field visible ──────────────────\n";
$d = new MemoryDriver();
$rt = $engine->bind($invoiceSchema, $d);
$rt->invoke('create', ['amount' => 10, 'secret' => 'top'], $user);
$rowUser = $rt->read('board', [], $user)[0];
$ok('T9 — secret visible to user', array_key_exists('secret', $rowUser) && $rowUser['secret'] === 'top');

echo "── T10 — visibility deny → field absent ────────────────────\n";
$rowSystem = $rt->read('board', [], $system)[0];
$ok('T10 — secret absent for system', !array_key_exists('secret', $rowSystem) && array_key_exists('amount', $rowSystem));

echo "── T11 — expand single-hop → embedded projection ───────────\n";
$d = new MemoryDriver();
$customerRt = $engine->bind($customerSchema, $d);
$cust = $customerRt->invoke('create', ['name' => 'Acme'], $user);
$invoiceRt = $engine->bind($invoiceSchema, $d);
$invoiceRt->invoke('create', ['amount' => 50, 'buyer' => $cust->reference->identityHandle], $user);
$row = $invoiceRt->read('board', [], $user)[0];
$ok('T11 — buyer expanded to customer.card', is_array($row['buyer']) && ($row['buyer']['name'] ?? null) === 'Acme');

echo "── T12 — bind returns a RuntimeEntity ──────────────────────\n";
$ok('T12 — bind(EntitySchema, MemoryDriver) → RuntimeEntity', $engine->bind($invoiceSchema, new MemoryDriver()) instanceof RuntimeEntity);

echo "── T13 — bind from a FileSchemaRepository-reloaded schema ──\n";
$diskRoot = sys_get_temp_dir() . '/ausus-10-' . bin2hex(random_bytes(4));
$fileRepo = new FileSchemaRepository($diskRoot);
foreach ($graph->schemas as $s) {
    $fileRepo->putByHash($s);
}
$reloaded = $fileRepo->resolve('invoice');
$engineFromDisk = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $fileRepo);
$rtDisk = $engineFromDisk->bind($reloaded, new MemoryDriver());
$eDisk = $rtDisk->invoke('create', ['amount' => 250], $user);
$ok('T13 — reloaded schema → functional runtime', $eDisk->field('amount') === 250 && count($rtDisk->read('board', [], $user)) === 1);

echo "── T14 — DSL → compile → repository → resolve → bind → … ───\n";
$ws = sys_get_temp_dir() . '/ausus-10-int-' . bin2hex(random_bytes(4));
$entities = $ws . '/entities';
@mkdir($entities, 0o775, true);
file_put_contents($entities . '/Customer.php',
    "<?php\nuse Ausus\\Authoring\\Dsl\\Definition;\nuse Ausus\\Definition\\Enum\\FieldType;\nuse Ausus\\Definition\\Enum\\ActionKind;\n"
    . "return Definition::make('customer', true)->field('name', FieldType::String)->action('create', ActionKind::Create, ['inputs'=>['name']])->projection('card', ['fields'=>[['field'=>'name']]])->build();\n");
file_put_contents($entities . '/Invoice.php',
    "<?php\nuse Ausus\\Authoring\\Dsl\\Definition;\nuse Ausus\\Authoring\\Dsl\\Expr;\nuse Ausus\\Definition\\Enum\\FieldType;\nuse Ausus\\Definition\\Enum\\ActionKind;\n"
    . "return Definition::make('invoice', true)"
    . "->field('amount', FieldType::Decimal)"
    . "->field('status', FieldType::Enum, ['default'=>'draft','writeProtected'=>true,'typeOptions'=>['values'=>['draft','approved']]])"
    . "->field('buyer', FieldType::Reference, ['nullable'=>true,'typeOptions'=>['target'=>'customer']])"
    . "->action('create', ActionKind::Create, ['inputs'=>['amount','buyer'], 'guard'=>Expr::lt(Expr::input('amount'), 5000)])"
    . "->projection('board', ['fields'=>[['field'=>'amount']], 'expand'=>[['via'=>'buyer','projection'=>'card']]])->build();\n");

$aususRoot = $ws . '/.ausus';
$code = (new CompileEntitiesCommand())->run($entities, $aususRoot, fopen('php://memory', 'r+'), fopen('php://memory', 'r+'));
$intRepo = new FileSchemaRepository($aususRoot);             // fresh repo: no recompilation beyond this point
$intEngine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $intRepo);
$intDriver = new MemoryDriver();
$custId = $intEngine->bind($intRepo->resolve('customer'), $intDriver)->invoke('create', ['name' => 'Globex'], $user)->reference->identityHandle;
$invoiceRtInt = $intEngine->bind($intRepo->resolve('invoice'), $intDriver);
$invoiceRtInt->invoke('create', ['amount' => 1200, 'buyer' => $custId], $user);
$intRows = $invoiceRtInt->read('board', [], $user);
$ok('T14 — compile succeeded', $code === CompileEntitiesCommand::SUCCESS);
$ok('T14 — DSL→compile→resolve→bind→invoke→read end-to-end',
    count($intRows) === 1 && $intRows[0]['amount'] === 1200 && ($intRows[0]['buyer']['name'] ?? null) === 'Globex');

// Cleanup --------------------------------------------------------------------
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
$rrm($diskRoot);
$rrm($ws);

echo "\n";
echo $fail === 0
    ? "PHASE 10 / RuntimeEntity OK — {$pass} checks passed\n"
    : "PHASE 10 / RuntimeEntity FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
