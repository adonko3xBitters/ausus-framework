<?php

declare(strict_types=1);

$autoload = null;
foreach (['/../vendor/autoload.php', '/../../../vendor/autoload.php'] as $rel) {
    if (file_exists(__DIR__ . $rel)) { $autoload = __DIR__ . $rel; break; }
}
require $autoload;

/**
 * L1 — SqliteDriver integration, driver parity, and real persistence.
 *
 * Drives the FULL Gen2 pipeline (Authoring → Compiler → Runtime → L3 queries →
 * L4 aggregations → guards → transitions) over the **real Hello Invoice entity**,
 * with NO business change — only the bound driver differs:
 *
 *   1. Parity     — the same scenario through MemoryDriver and SqliteDriver
 *                   yields byte-identical projection rows + aggregates.
 *   2. Guards     — a guest create is denied on both drivers.
 *   3. Persistence — SqliteDriver data survives dropping the driver and
 *                   reopening a fresh driver on the same file (process-restart).
 */

use Ausus\Engine\Compile\Compiler;
use Ausus\Engine\Repository\InMemorySchemaRepository;
use Ausus\Engine\Runtime\DefaultAuthorizationEvaluator;
use Ausus\Engine\Runtime\DefaultEntityEngine;
use Ausus\Persistence\Memory\MemoryDriver;
use Ausus\Persistence\Sqlite\SqliteDriver;
use Ausus\Api\Runtime\Http\RequestContextFactory;

$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

// The real application entity — required verbatim, never modified.
$invoiceDef = require __DIR__ . '/../../../apps/hello-invoice/entities/Invoice.php';

$factory = new RequestContextFactory(new DateTimeImmutable('@1700000000'));
$user  = $factory->fromHeaders(['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user']);
$guest = $factory->fromHeaders(['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'guest']);

/**
 * Bind the invoice entity to $driver and run an identical business scenario.
 * Returns a driver-independent snapshot (no identities): board rows + aggregates.
 */
$scenario = function (object $driver) use ($invoiceDef, $user) {
    $repo = new InMemorySchemaRepository();
    foreach ((new Compiler())->compile([$invoiceDef])->schemas as $s) { $repo->putByHash($s); }
    $engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
    $rt = fn () => $engine->bind($repo->resolve('invoice'), $driver);

    $mk = fn (string $n, string $c, int $total) => $rt()->invoke('create', [
        'number' => $n, 'customer' => $c, 'issueDate' => '2025-01-01', 'dueDate' => '2025-02-01', 'total' => $total,
    ], $user);

    $a = $mk('INV-001', 'Globex', 1500);
    $b = $mk('INV-002', 'Initech', 800);
    $mk('INV-003', 'Umbrella', 1200);

    $rt()->invoke('pay', ['id' => $b->reference->identityHandle], $user);          // transition draft→paid
    $rt()->invoke('update', ['id' => $a->reference->identityHandle, 'total' => 1800], $user);

    // L3 — drafts only, by total desc
    $drafts = $rt()->read('board', [
        'where'   => [['field' => 'status', 'op' => 'eq', 'value' => 'draft']],
        'orderBy' => [['field' => 'total', 'dir' => 'desc']],
    ], $user);
    $rows = array_map(fn ($r) => [$r['number'], $r['status'], $r['total']], $drafts);

    // L4 — aggregates over the whole board
    $agg = $rt()->readWithAggregates('board', ['aggregate' => [
        ['op' => 'count', 'as' => 'n'],
        ['op' => 'sum', 'field' => 'total', 'as' => 'revenue'],
        ['op' => 'avg', 'field' => 'total', 'as' => 'average'],
        ['op' => 'min', 'field' => 'total', 'as' => 'lo'],
        ['op' => 'max', 'field' => 'total', 'as' => 'hi'],
    ]], $user)['aggregates'];

    return ['rows' => $rows, 'aggregates' => $agg];
};

echo "── Parity: MemoryDriver vs SqliteDriver (identical scenario) ─\n";
$dbFile = sys_get_temp_dir() . '/ausus-sqlite-integration-' . bin2hex(random_bytes(5)) . '.db';
$memOut = $scenario(new MemoryDriver());
$sqlOut = $scenario(new SqliteDriver($dbFile));

$ok('board rows identical across drivers', $memOut['rows'] === $sqlOut['rows']);
$ok('board rows are the two drafts, total desc → INV-001(1800), INV-003(1200)',
    $sqlOut['rows'] === [['INV-001', 'draft', 1800], ['INV-003', 'draft', 1200]]);
$ok('aggregates identical across drivers', $memOut['aggregates'] === $sqlOut['aggregates']);
$ok('aggregates correct: count=3, revenue=3800, avg≈1266.67, lo=800, hi=1800',
    $sqlOut['aggregates']['n'] === 3
    && $sqlOut['aggregates']['revenue'] === 3800
    && abs($sqlOut['aggregates']['average'] - (3800 / 3)) < 1e-9
    && $sqlOut['aggregates']['lo'] === 800
    && $sqlOut['aggregates']['hi'] === 1800);

echo "── Guards: guest create denied on both drivers ─────────────\n";
$denied = function (object $driver) use ($invoiceDef, $guest): bool {
    $repo = new InMemorySchemaRepository();
    foreach ((new Compiler())->compile([$invoiceDef])->schemas as $s) { $repo->putByHash($s); }
    $engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
    try {
        $engine->bind($repo->resolve('invoice'), $driver)->invoke('create', [
            'number' => 'X', 'customer' => 'Y', 'issueDate' => '2025-01-01', 'dueDate' => '2025-02-01', 'total' => 1,
        ], $guest);
        return false;
    } catch (\Throwable $e) {
        return true;
    }
};
$ok('Memory: guest create DENIED', $denied(new MemoryDriver()));
$ok('SQLite: guest create DENIED', $denied(new SqliteDriver(sys_get_temp_dir() . '/ausus-sqlite-guard-' . bin2hex(random_bytes(4)) . '.db')));

echo "── Real persistence: survive driver drop + reopen (restart) ─\n";
$restartFile = sys_get_temp_dir() . '/ausus-sqlite-restart-' . bin2hex(random_bytes(5)) . '.db';
(function () use ($invoiceDef, $user, $restartFile) {
    $driver = new SqliteDriver($restartFile);
    $repo = new InMemorySchemaRepository();
    foreach ((new Compiler())->compile([$invoiceDef])->schemas as $s) { $repo->putByHash($s); }
    $engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
    $engine->bind($repo->resolve('invoice'), $driver)->invoke('create', [
        'number' => 'PERSIST-1', 'customer' => 'Acme', 'issueDate' => '2025-01-01', 'dueDate' => '2025-02-01', 'total' => 4242,
    ], $user);
    // driver goes out of scope here → connections closed
})();

// brand-new driver + engine on the same file: data must still be there
$driver2 = new SqliteDriver($restartFile);
$repo2 = new InMemorySchemaRepository();
foreach ((new Compiler())->compile([$invoiceDef])->schemas as $s) { $repo2->putByHash($s); }
$engine2 = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo2);
$reread = $engine2->bind($repo2->resolve('invoice'), $driver2)->read('board', [], $user);
$ok('reopened driver sees persisted invoice', count($reread) === 1 && $reread[0]['number'] === 'PERSIST-1' && $reread[0]['total'] === 4242);

// control: a fresh MemoryDriver has no cross-instance persistence
$memControl = $engine2->bind($repo2->resolve('invoice'), new MemoryDriver())->read('board', [], $user);
$ok('control: a fresh MemoryDriver starts empty (no file persistence)', $memControl === []);

// cleanup
foreach (glob(sys_get_temp_dir() . '/ausus-sqlite-*') ?: [] as $f) { @unlink($f); }

echo "\n" . ($fail === 0 ? "L1 / SqliteDriver integration OK — {$pass} checks passed\n" : "L1 / SqliteDriver integration FAIL — {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
