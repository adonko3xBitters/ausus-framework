<?php
declare(strict_types=1);
namespace Ausus\Persistence\Postgres\Tests;

use Ausus\{Tenant, TenantId, Reference, TenantBoundaryViolation, ConcurrencyConflict, Filter, Sort};

require __DIR__ . '/bootstrap.php';

$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; } else { $fail++; echo "  ✗ {$label}\n"; }
};

$graph = compat_graph();
$ranDrivers = [];
foreach (compat_drivers($graph) as [$name, $driver, $pdo]) {
    $ranDrivers[] = $name;
    echo "── driver: {$name} ──\n";
    compat_create_schema($pdo, $graph);

    $A = new Tenant(new TenantId('acme'));
    $B = new Tenant(new TenantId('beta'));
    $tx = $driver->beginTransaction($A);
    $repo = $driver->context($A, $tx)->repository('compat.doc');

    // Baseline: omit `status` (has default DRAFT); note=null explicit; parent_id=null.
    $ent = $repo->create([
        'title' => 'x', 'count' => 7, 'due' => '2026-01-02T03:04:05Z',
        'price' => ['amount' => '100', 'currency' => 'USD'], 'note' => null, 'parent_id' => null,
    ]);
    $got = $repo->find(new Reference('acme', 'compat.doc', $ent->reference->identityHandle));

    // A. Defaults
    $ok("[{$name}] A default status===DRAFT", $got->field('status') === 'DRAFT');
    // E. Integer
    $ok("[{$name}] E integer count===7 (int)", $got->field('count') === 7);
    // F. Datetime
    $ok("[{$name}] F datetime round-trip exact", $got->field('due') === '2026-01-02T03:04:05Z');
    // C. Nullable (explicit null)
    $ok("[{$name}] C note===null (explicit)", $got->field('note') === null);
    // D. Money (amount + currency reconstructed from field EUR, not written USD)
    $ok("[{$name}] D money {amount:100, currency:EUR}", $got->field('price') === ['amount' => '100', 'currency' => 'EUR']);

    // C. Nullable (absent → null)
    $ent2 = $repo->create([
        'title' => 'y', 'count' => 1, 'due' => '2026-01-01T00:00:00Z',
        'price' => ['amount' => '5', 'currency' => 'EUR'], 'parent_id' => null,
    ]);
    $got2 = $repo->find(new Reference('acme', 'compat.doc', $ent2->reference->identityHandle));
    $ok("[{$name}] C note===null (absent)", $got2->field('note') === null);

    // B. Required field omitted (title)
    $bExc = null;
    try {
        $repo->create(['count' => 1, 'due' => '2026-01-01T00:00:00Z',
            'price' => ['amount' => '1', 'currency' => 'EUR'], 'note' => null, 'parent_id' => null]);
    } catch (\Throwable $e) { $bExc = $e; }
    $ok("[{$name}] B required → RuntimeException", $bExc instanceof \RuntimeException);
    $ok("[{$name}] B required message", $bExc !== null && str_starts_with($bExc->getMessage(), 'FieldRequired: compat.doc.title'));

    // G. Tenant isolation — find with cross-tenant reference
    $gExc = null;
    try { $repo->find(new Reference('beta', 'compat.doc', $ent->reference->identityHandle)); }
    catch (\Throwable $e) { $gExc = $e; }
    $ok("[{$name}] G find boundary → TenantBoundaryViolation", $gExc instanceof TenantBoundaryViolation);
    $ok("[{$name}] G find boundary message", $gExc !== null && $gExc->getMessage() === 'ref tenant=beta active=acme');

    // G. Tenant isolation — context with mismatched tenant/handle
    $cExc = null;
    try { $driver->context($B, $tx); }
    catch (\Throwable $e) { $cExc = $e; }
    $ok("[{$name}] G context boundary message", $cExc instanceof TenantBoundaryViolation && $cExc->getMessage() === 'context tenant=beta handle=acme');

    // H. Simple references — parent_id=null and parent_id=existing
    $child = $repo->create([
        'title' => 'c', 'count' => 1, 'due' => '2026-01-01T00:00:00Z',
        'price' => ['amount' => '1', 'currency' => 'EUR'], 'note' => null,
        'parent_id' => $ent->reference->identityHandle,
    ]);
    $gotChild = $repo->find(new Reference('acme', 'compat.doc', $child->reference->identityHandle));
    $ok("[{$name}] H parent_id=null", $got->field('parent_id') === null);
    $ok("[{$name}] H parent_id=existing", $gotChild->field('parent_id') === $ent->reference->identityHandle);

    // ── C4: update() ──────────────────────────────────────────────────
    $r = new Reference('acme', 'compat.doc', $ent->reference->identityHandle);

    // A. Update nominal (title, count, note) + new version
    $u1 = $repo->update($r, ['title' => 'X2', 'count' => 42, 'note' => 'hello'], $ent->version);
    $ok("[{$name}] A update title===X2", $u1->field('title') === 'X2');
    $ok("[{$name}] A update count===42 (int)", $u1->field('count') === 42);
    $ok("[{$name}] A update note===hello", $u1->field('note') === 'hello');
    $ok("[{$name}] A new version generated", $u1->version->value !== $ent->version->value);

    // D/F/G. Nullable + datetime + money in one patch
    $u2 = $repo->update($r, ['due' => '2030-12-31T23:59:59Z', 'price' => ['amount' => '250', 'currency' => 'USD'], 'note' => null], $u1->version);
    $ok("[{$name}] F datetime updated exact", $u2->field('due') === '2030-12-31T23:59:59Z');
    $ok("[{$name}] G money updated {amount:250, currency:EUR}", $u2->field('price') === ['amount' => '250', 'currency' => 'EUR']);
    $ok("[{$name}] D note===null after update", $u2->field('note') === null);

    // B. Optimistic concurrency — stale version
    $bExc2 = null;
    try { $repo->update($r, ['title' => 'stale'], $ent->version); }
    catch (\Throwable $e) { $bExc2 = $e; }
    $ok("[{$name}] B concurrency → ConcurrencyConflict", $bExc2 instanceof ConcurrencyConflict);
    $expectedMsg = "ConcurrencyConflict: compat.doc/{$ent->reference->identityHandle} expected={$ent->version->value} actual={$u2->version->value}";
    $ok("[{$name}] B concurrency message", $bExc2 !== null && $bExc2->getMessage() === $expectedMsg);

    // C. Wrong tenant — update with cross-tenant reference
    $cExc2 = null;
    try { $repo->update(new Reference('beta', 'compat.doc', $ent->reference->identityHandle), ['title' => 'x'], $u2->version); }
    catch (\Throwable $e) { $cExc2 = $e; }
    $ok("[{$name}] C update wrong tenant → TenantBoundaryViolation", $cExc2 instanceof TenantBoundaryViolation);
    $ok("[{$name}] C update wrong tenant message", $cExc2 !== null && $cExc2->getMessage() === 'ref tenant=beta active=acme');

    $driver->commit($tx);

    // ── C5: findAll() / findPaged() / filters (fresh tenants/ids → clean slate) ──
    $run = bin2hex(random_bytes(6));
    $TA = new Tenant(new TenantId("c5a_{$run}"));
    $TB = new Tenant(new TenantId("c5b_{$run}"));
    $id1 = "{$run}-1"; $id2 = "{$run}-2"; $id3 = "{$run}-3"; $idb = "{$run}-b";
    $mk = fn(string $title, int $cnt, ?string $status): array => array_filter([
        'title' => $title, 'count' => $cnt, 'due' => '2026-01-01T00:00:00Z',
        'price' => ['amount' => '1', 'currency' => 'EUR'], 'note' => null, 'parent_id' => null,
        'status' => $status,
    ], fn($v) => $v !== null);
    $ids = fn(array $items): array => array_map(fn($e) => $e->reference->identityHandle, $items);

    $txa = $driver->beginTransaction($TA);
    $ra  = $driver->context($TA, $txa)->repository('compat.doc');

    // A. findAll empty
    $ok("[{$name}] C5 A findAll empty===[]", $ra->findAll() === []);

    $ra->create($mk('alpha', 0, null), $id1);
    $ra->create($mk('beta', 1, 'PUBLISHED'), $id2);
    $ra->create($mk('gamma', 2, null), $id3);

    // B. findAll multi-row (order id ASC, count, types)
    $all = $ra->findAll();
    $ok("[{$name}] C5 B findAll count===3", count($all) === 3);
    $ok("[{$name}] C5 B findAll order (id ASC)", $ids($all) === [$id1, $id2, $id3]);
    $ok("[{$name}] C5 B findAll types (count===0 int)", $all[0]->field('count') === 0);

    // C. Pagination
    $p1 = $ra->findPaged(2, 0);
    $ok("[{$name}] C5 C page1", $ids($p1['items']) === [$id1, $id2] && $p1['totalCount'] === 3);
    $p2 = $ra->findPaged(2, 2);
    $ok("[{$name}] C5 C page2 (last)", $ids($p2['items']) === [$id3] && $p2['totalCount'] === 3);
    $pe = $ra->findPaged(2, 10);
    $ok("[{$name}] C5 C empty page", $pe['items'] === [] && $pe['totalCount'] === 3);

    // D. Filter enum
    $dD = $ra->findPaged(10, 0, [new Filter('status', Filter::OP_EQ, 'DRAFT')]);
    $ok("[{$name}] C5 D enum DRAFT", $ids($dD['items']) === [$id1, $id3] && $dD['totalCount'] === 2);
    $dP = $ra->findPaged(10, 0, [new Filter('status', Filter::OP_EQ, 'PUBLISHED')]);
    $ok("[{$name}] C5 D enum PUBLISHED", $ids($dP['items']) === [$id2] && $dP['totalCount'] === 1);

    // E. Filter string (eq + contains)
    $eE = $ra->findPaged(10, 0, [new Filter('title', Filter::OP_EQ, 'beta')]);
    $ok("[{$name}] C5 E string eq", $ids($eE['items']) === [$id2] && $eE['totalCount'] === 1);
    $eC = $ra->findPaged(10, 0, [new Filter('title', Filter::OP_CONTAINS, 'mm')]);
    $ok("[{$name}] C5 E string contains", $ids($eC['items']) === [$id3] && $eC['totalCount'] === 1);

    // F. Filter integer
    $fI = $ra->findPaged(10, 0, [new Filter('count', Filter::OP_EQ, 1)]);
    $ok("[{$name}] C5 F integer eq", $ids($fI['items']) === [$id2] && $fI['totalCount'] === 1);

    // H. Unknown column
    $hExc = null;
    try { $ra->findPaged(10, 0, [new Filter('nope', Filter::OP_EQ, 'x')]); }
    catch (\Throwable $e) { $hExc = $e; }
    $ok("[{$name}] C5 H unknown column → InvalidArgumentException", $hExc instanceof \InvalidArgumentException);
    $ok("[{$name}] C5 H unknown column message", $hExc !== null && $hExc->getMessage() === "findPaged: unknown column 'nope' on entity compat.doc");

    $driver->commit($txa);

    // G. Tenant isolation
    $txb = $driver->beginTransaction($TB);
    $rb  = $driver->context($TB, $txb)->repository('compat.doc');
    $rb->create($mk('solo', 9, null), $idb);
    $ok("[{$name}] C5 G findAll(TB)===1 (no TA leak)", count($rb->findAll()) === 1);
    $ok("[{$name}] C5 G findPaged(TB) totalCount===1", $rb->findPaged(10, 0)['totalCount'] === 1);
    $driver->commit($txb);

    $txa2 = $driver->beginTransaction($TA);
    $ra2  = $driver->context($TA, $txa2)->repository('compat.doc');
    $ok("[{$name}] C5 G findAll(TA)===3 (no TB leak)", count($ra2->findAll()) === 3);
    $driver->commit($txa2);

    // ── C6: audit (PostgresAuditSink ↔ DatabaseAuditSink) ──
    $sink = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql'
        ? new \Ausus\Persistence\Postgres\PostgresAuditSink($pdo)
        : new \Ausus\Persistence\Sql\DatabaseAuditSink($pdo);
    $arun = bin2hex(random_bytes(6));
    $mkEntry = fn(string $eid, string $tenant, ?string $trace, array $in, array $out): \Ausus\AuditEntry => new \Ausus\AuditEntry(
        entryId: $eid, sequence: 7, actor: new \Ausus\ActorRef('user', 'maya', $tenant),
        tenant: $tenant, actionFqn: 'compat.doc.create',
        subject: new \Ausus\SingleSubject($tenant, 'compat.doc', 'subj-1'),
        inputs: $in, outputs: $out, timestamp: '2026-01-02T03:04:05Z',
        correlationId: 'corr-1', traceId: $trace, invocationClass: 'Standard', emitterVersion: '1.0.0',
    );
    $auditRows = function (string $tenant) use ($pdo): array {
        $st = $pdo->prepare('SELECT * FROM "kernel_audit_log" WHERE tenant_id = :t ORDER BY entry_id');
        $st->execute(['t' => $tenant]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    };
    $tnA = "audit_a_{$arun}"; $tnB = "audit_b_{$arun}"; $tnE = "audit_e_{$arun}"; $tnF = "audit_f_{$arun}";

    // A + B + C: nominal write, metadata (json), null trace
    $t6 = $driver->beginTransaction(new Tenant(new TenantId($tnA)));
    $sink->writeInTransaction($mkEntry("{$arun}-1", $tnA, null, ['k' => 'v', 'n' => 5], ['ok' => true]), $t6);
    $driver->commit($t6);
    $rowsA = $auditRows($tnA);
    $r0 = $rowsA[0] ?? [];
    $ok("[{$name}] C6 A one row written", count($rowsA) === 1);
    $ok("[{$name}] C6 A content exact", ($r0['entry_id'] ?? null) === "{$arun}-1" && (int) ($r0['sequence'] ?? -1) === 7
        && ($r0['action_fqn'] ?? null) === 'compat.doc.create' && ($r0['actor_id'] ?? null) === 'maya'
        && ($r0['actor_type'] ?? null) === 'user' && ($r0['invocation_class'] ?? null) === 'Standard'
        && ($r0['emitter_version'] ?? null) === '1.0.0' && ($r0['subject_entity_fqn'] ?? null) === 'compat.doc');
    $ok("[{$name}] C6 B metadata json round-trip", ($r0['inputs'] ?? null) === json_encode(['k' => 'v', 'n' => 5]) && ($r0['outputs'] ?? null) === json_encode(['ok' => true]));
    $ok("[{$name}] C6 C trace_id null", array_key_exists('trace_id', $r0) && $r0['trace_id'] === null);

    // D: multi-tenant isolation
    $t6b = $driver->beginTransaction(new Tenant(new TenantId($tnB)));
    $sink->writeInTransaction($mkEntry("{$arun}-2", $tnB, 'trace-x', [], []), $t6b);
    $driver->commit($t6b);
    $ok("[{$name}] C6 D tenant A still 1 (isolation)", count($auditRows($tnA)) === 1);
    $ok("[{$name}] C6 D tenant B 1", count($auditRows($tnB)) === 1);

    // E: multiple entries in one transaction
    $t6e = $driver->beginTransaction(new Tenant(new TenantId($tnE)));
    $sink->writeInTransaction($mkEntry("{$arun}-e1", $tnE, null, [], []), $t6e);
    $sink->writeInTransaction($mkEntry("{$arun}-e2", $tnE, null, [], []), $t6e);
    $sink->writeInTransaction($mkEntry("{$arun}-e3", $tnE, null, [], []), $t6e);
    $driver->commit($t6e);
    $ok("[{$name}] C6 E 3 rows in one tx", count($auditRows($tnE)) === 3);

    // F: rollback → no rows
    $t6f = $driver->beginTransaction(new Tenant(new TenantId($tnF)));
    $sink->writeInTransaction($mkEntry("{$arun}-f1", $tnF, null, [], []), $t6f);
    $sink->writeInTransaction($mkEntry("{$arun}-f2", $tnF, null, [], []), $t6f);
    $driver->rollback($t6f);
    $ok("[{$name}] C6 F rollback → 0 rows", count($auditRows($tnF)) === 0);

    // G: physical structure — all 17 columns + table present (portable check)
    $gOk = false;
    try {
        $pdo->query('SELECT entry_id, sequence, actor_type, actor_id, actor_home_tenant, tenant_id, action_fqn, subject_tenant_id, subject_entity_fqn, subject_identity_handle, inputs, outputs, timestamp, correlation_id, trace_id, invocation_class, emitter_version FROM "kernel_audit_log" LIMIT 0');
        $gOk = true;
    } catch (\Throwable $e) { $gOk = false; }
    $ok("[{$name}] C6 G kernel_audit_log + 17 columns present", $gOk);

    // ── RFC-015 parity: reference enforcement in create()/update() ──
    $rrun = bin2hex(random_bytes(6));
    $rTenant = "rfc_{$rrun}";
    $RT = new Tenant(new TenantId($rTenant));
    $rtx = $driver->beginTransaction($RT);
    $rr  = $driver->context($RT, $rtx)->repository('compat.doc');
    $base = fn(?string $parent): array => array_filter([
        'title' => 't', 'count' => 0, 'due' => '2026-01-01T00:00:00Z',
        'price' => ['amount' => '1', 'currency' => 'EUR'], 'note' => null, 'parent_id' => $parent,
    ], fn($v) => $v !== null);

    // A. create reference valide
    $parent = $rr->create($base(null), "{$rrun}-p");
    $child  = $rr->create($base($parent->reference->identityHandle), "{$rrun}-c");
    $ok("[{$name}] RFC015 A create valid ref OK", $child->field('parent_id') === $parent->reference->identityHandle);

    // B. create reference null
    $nul = $rr->create($base(null), "{$rrun}-n");
    $ok("[{$name}] RFC015 B create null ref OK", $nul->field('parent_id') === null);

    // C. create reference inexistante
    $cErr = null;
    try { $rr->create($base('GHOST'), "{$rrun}-x"); }
    catch (\Throwable $e) { $cErr = $e; }
    $ok("[{$name}] RFC015 C create dangling → ReferentialIntegrityViolation", $cErr instanceof \Ausus\ReferentialIntegrityViolation);
    $ok("[{$name}] RFC015 C message", $cErr !== null && $cErr->getMessage() === "ReferentialIntegrityViolation: compat.doc.parent_id → compat.doc 'GHOST' does not exist in tenant {$rTenant}.");

    // D. update reference valide
    $upd = $rr->update(new Reference($rTenant, 'compat.doc', $nul->reference->identityHandle), ['parent_id' => $parent->reference->identityHandle], $nul->version);
    $ok("[{$name}] RFC015 D update valid ref OK", $upd->field('parent_id') === $parent->reference->identityHandle);

    // E. update reference inexistante
    $eErr = null;
    try { $rr->update(new Reference($rTenant, 'compat.doc', $nul->reference->identityHandle), ['parent_id' => 'GHOST2'], $upd->version); }
    catch (\Throwable $e) { $eErr = $e; }
    $ok("[{$name}] RFC015 E update dangling → ReferentialIntegrityViolation", $eErr instanceof \Ausus\ReferentialIntegrityViolation);
    $ok("[{$name}] RFC015 E message", $eErr !== null && $eErr->getMessage() === "ReferentialIntegrityViolation: compat.doc.parent_id → compat.doc 'GHOST2' does not exist in tenant {$rTenant}.");

    $driver->commit($rtx);

    // ── C8: coverage closure (Filter IN / Sort / generateIdentity / guards) ──
    // These branches exist in src/ as exact copies of persistence-sql but were
    // not exercised by the committed harness. Cover them on BOTH drivers.

    // generateIdentity() — ULID shape (26 chars), identical on both drivers.
    $gid = $driver->generateIdentity('compat.doc');
    $ok("[{$name}] C8 generateIdentity ULID shape (26)", is_string($gid) && strlen($gid) === 26);

    $crun = bin2hex(random_bytes(6));
    $TC = new Tenant(new TenantId("c8_{$crun}"));
    $ci1 = "{$crun}-1"; $ci2 = "{$crun}-2"; $ci3 = "{$crun}-3";
    $txc = $driver->beginTransaction($TC);
    $rc  = $driver->context($TC, $txc)->repository('compat.doc');
    $rc->create($mk('one',   10, null), $ci1);
    $rc->create($mk('two',   20, 'PUBLISHED'), $ci2);
    $rc->create($mk('three', 30, null), $ci3);

    // Filter OP_IN — string column.
    $inT = $rc->findPaged(10, 0, [new Filter('title', Filter::OP_IN, ['one', 'three'])]);
    $ok("[{$name}] C8 IN string", $ids($inT['items']) === [$ci1, $ci3] && $inT['totalCount'] === 2);
    // Filter OP_IN — integer column.
    $inC = $rc->findPaged(10, 0, [new Filter('count', Filter::OP_IN, [10, 30])]);
    $ok("[{$name}] C8 IN integer", $ids($inC['items']) === [$ci1, $ci3] && $inC['totalCount'] === 2);
    // Filter OP_IN — no match.
    $inN = $rc->findPaged(10, 0, [new Filter('title', Filter::OP_IN, ['zzz'])]);
    $ok("[{$name}] C8 IN no match", $inN['items'] === [] && $inN['totalCount'] === 0);

    // Sort — integer column DESC / ASC (collation-stable).
    $sD = $rc->findPaged(10, 0, [], [new Sort('count', Sort::DIR_DESC)]);
    $ok("[{$name}] C8 Sort count DESC", $ids($sD['items']) === [$ci3, $ci2, $ci1]);
    $sA = $rc->findPaged(10, 0, [], [new Sort('count', Sort::DIR_ASC)]);
    $ok("[{$name}] C8 Sort count ASC", $ids($sA['items']) === [$ci1, $ci2, $ci3]);
    // Sort — explicit id pin (exercises the "id already sorted, no tie-break" branch).
    $sId = $rc->findPaged(10, 0, [], [new Sort('id', Sort::DIR_DESC)]);
    $ok("[{$name}] C8 Sort id DESC (no extra tiebreak)", $ids($sId['items']) === [$ci3, $ci2, $ci1]);

    // Guard — duplicate sort column.
    $dupExc = null;
    try { $rc->findPaged(10, 0, [], [new Sort('count', Sort::DIR_ASC), new Sort('count', Sort::DIR_DESC)]); }
    catch (\Throwable $e) { $dupExc = $e; }
    $ok("[{$name}] C8 duplicate sort → InvalidArgumentException", $dupExc instanceof \InvalidArgumentException);
    $ok("[{$name}] C8 duplicate sort message", $dupExc !== null && $dupExc->getMessage() === "findPaged: duplicate sort column 'count'");

    // Guard — non-Filter object in filters.
    $ifExc = null;
    try { $rc->findPaged(10, 0, ['notafilter']); }
    catch (\Throwable $e) { $ifExc = $e; }
    $ok("[{$name}] C8 invalid filter → InvalidArgumentException", $ifExc instanceof \InvalidArgumentException);
    $ok("[{$name}] C8 invalid filter message", $ifExc !== null && $ifExc->getMessage() === 'findPaged: every filter must be an Ausus\\Filter');

    // Guard — non-Sort object in sort.
    $isExc = null;
    try { $rc->findPaged(10, 0, [], ['notasort']); }
    catch (\Throwable $e) { $isExc = $e; }
    $ok("[{$name}] C8 invalid sort → InvalidArgumentException", $isExc instanceof \InvalidArgumentException);
    $ok("[{$name}] C8 invalid sort message", $isExc !== null && $isExc->getMessage() === 'findPaged: every sort entry must be an Ausus\\Sort');

    $driver->commit($txc);
}

// ── R3: anti-false-positive guard ─────────────────────────────────────────
// When the environment declares PostgreSQL is required (CI sets
// AUSUS_PG_REQUIRED=1), the postgres driver MUST have participated. A silent
// deactivation — missing pdo_pgsql, unreachable service, unset DSN — would
// otherwise yield a green run that only ever tested SQLite. Refuse it.
if (filter_var(getenv('AUSUS_PG_REQUIRED'), FILTER_VALIDATE_BOOLEAN)) {
    if (!in_array('postgres', $ranDrivers, true)) {
        echo "\n✗ AUSUS_PG_REQUIRED is set but the PostgreSQL driver did not run.\n";
        echo "  drivers exercised: " . implode(', ', $ranDrivers) . "\n";
        echo "  → refusing to report success on SQLite alone.\n";
        echo "RESULT: passed={$pass} failed=" . ($fail + 1) . " (postgres-required guard)\n";
        exit(1);
    }
    echo "\n✓ AUSUS_PG_REQUIRED guard: PostgreSQL driver participated (" . implode(', ', $ranDrivers) . ").\n";
}

echo "\nRESULT: passed={$pass} failed={$fail}\n";
exit($fail === 0 ? 0 : 1);
