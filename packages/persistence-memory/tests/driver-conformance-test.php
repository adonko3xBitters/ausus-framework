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
 * IMPLEMENTATION-001 Phase 9 — PersistenceDriver conformance harness for
 * MemoryDriver (T1–T10).
 */

use Ausus\Persistence\Memory\MemoryDriver;
use Ausus\Reference;
use Ausus\Tenant;
use Ausus\TenantId;
use Ausus\Version;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

$tenant = new Tenant(new TenantId('acme'));
$fqn = 'invoice';
$ref = fn (string $id): Reference => new Reference('acme', $fqn, $id);

// Read committed state via a fresh, isolated transaction.
$committedFind = function (MemoryDriver $d, string $id) use ($tenant, $fqn, $ref) {
    $tx = $d->beginTransaction($tenant);
    $found = $d->context($tenant, $tx)->repository($fqn)->find($ref($id));
    $d->rollback($tx);
    return $found;
};

echo "── T1 — create → find round-trip ───────────────────────────\n";
$d = new MemoryDriver();
$tx = $d->beginTransaction($tenant);
$repo = $d->context($tenant, $tx)->repository($fqn);
$e = $repo->create(['amount' => 1000, 'status' => 'draft']);
$found = $repo->find($ref($e->reference->identityHandle));
$ok('T1 — find returns the created entity (read-your-writes)', $found !== null
    && $found->reference->identityHandle === $e->reference->identityHandle
    && $found->field('amount') === 1000 && $found->field('status') === 'draft');
$d->commit($tx);

echo "── T2 — update visible ─────────────────────────────────────\n";
$tx = $d->beginTransaction($tenant);
$repo = $d->context($tenant, $tx)->repository($fqn);
$updated = $repo->update($ref($e->reference->identityHandle), ['status' => 'approved'], new Version('1'));
$ok('T2 — update applied + version bumped', $updated->field('status') === 'approved'
    && $updated->field('amount') === 1000 && $updated->version->value === '2');
$d->commit($tx);
$ok('T2 — committed update visible afterwards', $committedFind($d, $e->reference->identityHandle)?->field('status') === 'approved');

echo "── T3 — generateIdentity uniqueness ────────────────────────\n";
$d3 = new MemoryDriver();
$a = $d3->generateIdentity($fqn);
$b = $d3->generateIdentity($fqn);
$ok('T3 — two calls → distinct identities', $a !== $b && $a !== '' && $b !== '');

echo "── T4 — create before commit → invisible to other txn ──────\n";
$d = new MemoryDriver();
$tx1 = $d->beginTransaction($tenant);
$r1 = $d->context($tenant, $tx1)->repository($fqn);
$e = $r1->create(['amount' => 5]);
$ok('T4 — visible to its own txn (read-your-writes)', $r1->find($ref($e->reference->identityHandle)) !== null);
$ok('T4 — invisible before commit (fresh txn)', $committedFind($d, $e->reference->identityHandle) === null);
$d->rollback($tx1);

echo "── T5 — create + commit → present ──────────────────────────\n";
$d = new MemoryDriver();
$tx = $d->beginTransaction($tenant);
$e = $d->context($tenant, $tx)->repository($fqn)->create(['amount' => 7]);
$d->commit($tx);
$ok('T5 — present after commit', $committedFind($d, $e->reference->identityHandle)?->field('amount') === 7);

echo "── T6 — create + rollback → absent ─────────────────────────\n";
$d = new MemoryDriver();
$tx = $d->beginTransaction($tenant);
$e = $d->context($tenant, $tx)->repository($fqn)->create(['amount' => 9]);
$d->rollback($tx);
$ok('T6 — absent after rollback', $committedFind($d, $e->reference->identityHandle) === null);

echo "── T7 — multiple creates + commit → all visible ───────────\n";
$d = new MemoryDriver();
$tx = $d->beginTransaction($tenant);
$repo = $d->context($tenant, $tx)->repository($fqn);
$ids = [];
foreach ([10, 20, 30] as $amt) {
    $ids[] = $repo->create(['amount' => $amt])->reference->identityHandle;
}
$d->commit($tx);
$all = array_map(fn (string $id) => $committedFind($d, $id), $ids);
$ok('T7 — all three committed and findable', count(array_filter($all)) === 3);
$tx = $d->beginTransaction($tenant);
$ok('T7 — findAll returns all three', count($d->context($tenant, $tx)->repository($fqn)->findAll()) === 3);
$d->rollback($tx);

echo "── T8 — multiple creates + rollback → none visible ────────\n";
$d = new MemoryDriver();
$tx = $d->beginTransaction($tenant);
$repo = $d->context($tenant, $tx)->repository($fqn);
$ids = [];
foreach ([1, 2, 3] as $amt) {
    $ids[] = $repo->create(['amount' => $amt])->reference->identityHandle;
}
$d->rollback($tx);
$visible = array_filter(array_map(fn (string $id) => $committedFind($d, $id), $ids));
$ok('T8 — none survive rollback', $visible === []);
$tx = $d->beginTransaction($tenant);
$ok('T8 — findAll empty after rollback', $d->context($tenant, $tx)->repository($fqn)->findAll() === []);
$d->rollback($tx);

echo "── T9 — empty repository find → null / findAll → [] ────────\n";
$d = new MemoryDriver();
$tx = $d->beginTransaction($tenant);
$repo = $d->context($tenant, $tx)->repository($fqn);
$ok('T9 — find(absent) → null (contract)', $repo->find($ref('nope')) === null);
$ok('T9 — findAll → []', $repo->findAll() === []);
$d->rollback($tx);

echo "── T10 — full conformance harness ──────────────────────────\n";
$d = new MemoryDriver();
// identities monotonic & unique across the whole driver
$idsSeen = [];
$tx = $d->beginTransaction($tenant);
$repo = $d->context($tenant, $tx)->repository($fqn);
for ($i = 0; $i < 25; $i++) {
    $idsSeen[] = $repo->create(['n' => $i])->reference->identityHandle;
}
$d->commit($tx);
$ok('T10 — 25 identities all unique', count(array_unique($idsSeen)) === 25);
// isolation: a second tenant does not see the first tenant's data
$other = new Tenant(new TenantId('other'));
$tx = $d->beginTransaction($other);
$ok('T10 — tenant isolation (other tenant empty)', $d->context($other, $tx)->repository($fqn)->findAll() === []);
$d->rollback($tx);
// optimistic version check on update
$tx = $d->beginTransaction($tenant);
$repo = $d->context($tenant, $tx)->repository($fqn);
$first = $repo->findAll()[0];
$threw = false;
try {
    $repo->update($first->reference, ['n' => 999], new Version('999')); // wrong expected version
} catch (\RuntimeException $ex) {
    $threw = true;
}
$ok('T10 — update with wrong version → conflict', $threw);
$d->rollback($tx);
$ok('T10 — determinism: identities are counter-based (mem-*)', str_starts_with($idsSeen[0], 'mem-'));

echo "\n";
echo $fail === 0
    ? "PHASE 9 / MemoryDriver conformance OK — {$pass} checks passed\n"
    : "PHASE 9 / MemoryDriver conformance FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
