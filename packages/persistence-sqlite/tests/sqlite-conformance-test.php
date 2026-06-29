<?php

declare(strict_types=1);

$autoload = null;
foreach (['/../vendor/autoload.php', '/../../../vendor/autoload.php'] as $rel) {
    if (file_exists(__DIR__ . $rel)) { $autoload = __DIR__ . $rel; break; }
}
require $autoload;

/**
 * L1 — SqliteDriver conformance harness (T1–T10), the SQL counterpart of the
 * MemoryDriver conformance suite. Same assertions, real PDO SQLite on a file:
 * round-trips, read-your-writes, cross-transaction isolation, commit/rollback,
 * findAll, tenant isolation, optimistic concurrency, identity uniqueness.
 */

use Ausus\Persistence\Sqlite\SqliteDriver;
use Ausus\Reference;
use Ausus\Tenant;
use Ausus\TenantId;
use Ausus\Version;

$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

$dbFile = sys_get_temp_dir() . '/ausus-sqlite-conformance-' . bin2hex(random_bytes(5)) . '.db';
$newDriver = function () use (&$dbFile) { return new SqliteDriver($dbFile); };
$freshDb = function () use (&$dbFile) {
    foreach ([$dbFile, $dbFile . '-wal', $dbFile . '-shm'] as $f) { @unlink($f); }
    $dbFile = sys_get_temp_dir() . '/ausus-sqlite-conformance-' . bin2hex(random_bytes(5)) . '.db';
};

$tenant = new Tenant(new TenantId('acme'));
$fqn = 'invoice';
$ref = fn (string $id): Reference => new Reference('acme', $fqn, $id);

$committedFind = function (SqliteDriver $d, string $id) use ($tenant, $fqn, $ref) {
    $tx = $d->beginTransaction($tenant);
    $found = $d->context($tenant, $tx)->repository($fqn)->find($ref($id));
    $d->rollback($tx);
    return $found;
};

echo "── T1 — create → find round-trip ───────────────────────────\n";
$freshDb(); $d = $newDriver();
$tx = $d->beginTransaction($tenant);
$repo = $d->context($tenant, $tx)->repository($fqn);
$e = $repo->create(['amount' => 1000, 'status' => 'draft']);
$found = $repo->find($ref($e->reference->identityHandle));
$ok('T1 — read-your-writes', $found !== null
    && $found->reference->identityHandle === $e->reference->identityHandle
    && $found->field('amount') === 1000 && $found->field('status') === 'draft');
$d->commit($tx);

echo "── T2 — update visible + version bump ──────────────────────\n";
$tx = $d->beginTransaction($tenant);
$repo = $d->context($tenant, $tx)->repository($fqn);
$updated = $repo->update($ref($e->reference->identityHandle), ['status' => 'approved'], new Version('1'));
$ok('T2 — update applied + version 2', $updated->field('status') === 'approved'
    && $updated->field('amount') === 1000 && $updated->version->value === '2');
$d->commit($tx);
$ok('T2 — committed update visible afterwards', $committedFind($d, $e->reference->identityHandle)?->field('status') === 'approved');

echo "── T3 — generateIdentity uniqueness ────────────────────────\n";
$freshDb(); $d3 = $newDriver();
$a = $d3->generateIdentity($fqn); $b = $d3->generateIdentity($fqn);
$ok('T3 — distinct, non-empty identities', $a !== $b && $a !== '' && $b !== '');

echo "── T4 — create before commit → invisible to other txn ──────\n";
$freshDb(); $d = $newDriver();
$tx1 = $d->beginTransaction($tenant);
$r1 = $d->context($tenant, $tx1)->repository($fqn);
$e = $r1->create(['amount' => 5]);
$ok('T4 — visible to its own txn', $r1->find($ref($e->reference->identityHandle)) !== null);
$ok('T4 — invisible before commit (fresh txn)', $committedFind($d, $e->reference->identityHandle) === null);
$d->rollback($tx1);

echo "── T5 — create + commit → present ──────────────────────────\n";
$freshDb(); $d = $newDriver();
$tx = $d->beginTransaction($tenant);
$e = $d->context($tenant, $tx)->repository($fqn)->create(['amount' => 7]);
$d->commit($tx);
$ok('T5 — present after commit', $committedFind($d, $e->reference->identityHandle)?->field('amount') === 7);

echo "── T6 — create + rollback → absent ─────────────────────────\n";
$freshDb(); $d = $newDriver();
$tx = $d->beginTransaction($tenant);
$e = $d->context($tenant, $tx)->repository($fqn)->create(['amount' => 9]);
$d->rollback($tx);
$ok('T6 — absent after rollback', $committedFind($d, $e->reference->identityHandle) === null);

echo "── T7 — multiple creates + commit → all visible ───────────\n";
$freshDb(); $d = $newDriver();
$tx = $d->beginTransaction($tenant);
$repo = $d->context($tenant, $tx)->repository($fqn);
$ids = [];
foreach ([10, 20, 30] as $amt) { $ids[] = $repo->create(['amount' => $amt])->reference->identityHandle; }
$d->commit($tx);
$all = array_map(fn (string $id) => $committedFind($d, $id), $ids);
$ok('T7 — all three committed', count(array_filter($all)) === 3);
$tx = $d->beginTransaction($tenant);
$ok('T7 — findAll returns three', count($d->context($tenant, $tx)->repository($fqn)->findAll()) === 3);
$d->rollback($tx);

echo "── T8 — multiple creates + rollback → none visible ────────\n";
$freshDb(); $d = $newDriver();
$tx = $d->beginTransaction($tenant);
$repo = $d->context($tenant, $tx)->repository($fqn);
foreach ([1, 2, 3] as $amt) { $repo->create(['amount' => $amt]); }
$d->rollback($tx);
$tx = $d->beginTransaction($tenant);
$ok('T8 — findAll empty after rollback', $d->context($tenant, $tx)->repository($fqn)->findAll() === []);
$d->rollback($tx);

echo "── T9 — empty repository find → null / findAll → [] ────────\n";
$freshDb(); $d = $newDriver();
$tx = $d->beginTransaction($tenant);
$repo = $d->context($tenant, $tx)->repository($fqn);
$ok('T9 — find(absent) → null', $repo->find($ref('nope')) === null);
$ok('T9 — findAll → []', $repo->findAll() === []);
$d->rollback($tx);

echo "── T10 — identity uniqueness, tenant isolation, version ────\n";
$freshDb(); $d = $newDriver();
$idsSeen = [];
$tx = $d->beginTransaction($tenant);
$repo = $d->context($tenant, $tx)->repository($fqn);
for ($i = 0; $i < 25; $i++) { $idsSeen[] = $repo->create(['n' => $i])->reference->identityHandle; }
$d->commit($tx);
$ok('T10 — 25 identities all unique', count(array_unique($idsSeen)) === 25);
$other = new Tenant(new TenantId('other'));
$tx = $d->beginTransaction($other);
$ok('T10 — tenant isolation (other tenant empty)', $d->context($other, $tx)->repository($fqn)->findAll() === []);
$d->rollback($tx);
$tx = $d->beginTransaction($tenant);
$repo = $d->context($tenant, $tx)->repository($fqn);
$first = $repo->findAll()[0];
$threw = false;
try { $repo->update($first->reference, ['n' => 999], new Version('999')); }
catch (\RuntimeException $ex) { $threw = true; }
$ok('T10 — update with wrong version → conflict', $threw);
$d->rollback($tx);
$ok('T10 — identities are UUID v4', (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $idsSeen[0]));

// cleanup
foreach ([$dbFile, $dbFile . '-wal', $dbFile . '-shm'] as $f) { @unlink($f); }

echo "\n" . ($fail === 0 ? "L1 / SqliteDriver conformance OK — {$pass} checks passed\n" : "L1 / SqliteDriver conformance FAIL — {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
