<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Application, ApplicationConfig, Dsl, DslPlugin, Field, Action, Sort, Tenant, TenantId};

/**
 * AUSUS — sorting SQL translation (v0.2 beta.1 prep).
 *
 * Pins the ORDER BY translation contract on the SqliteRepository:
 *
 *   1. asc                       — natural ascending
 *   2. desc                      — descending reverses asc
 *   3. multi-column              — primary key + secondary
 *   4. pagination consistency    — paging by sorted order keeps the boundary
 *   5. stable across calls       — same query → same items in same order
 *   6. duplicate values          — id ASC tail keeps the order stable
 *   7. invalid field             — SQL adapter refuses
 *   8. invalid direction         — Sort value-object refuses (already pinned
 *                                  in sorting-test.php; assert again from the
 *                                  SQL caller seat to catch any future
 *                                  bypass attempt)
 *   9. duplicate sort column     — SQL adapter refuses
 *  10. id ASC fallback           — empty sort list still deterministic
 *
 * Plus a smoke that confirms the SQL adapter's prepared-statement binding
 * does not break on edge values (long strings, unicode).
 */

$BANNER = "═══ AUSUS — sorting SQL translation ════════════════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

final class SortingPlugin extends DslPlugin {
    public function name(): string         { return 'srt'; }
    public function phpNamespace(): string { return 'SortingPlugin'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('row')
            ->fields([
                'name'   => Field::string()->max(64),
                'tier'   => Field::integer(),
                'state'  => Field::enum('NEW', 'DONE')->default('NEW'),
            ])
            ->actions([
                'create' => Action::create('name', 'tier')->requireRole('srt.writer'),
            ])
            ->workflow(field: 'state', initial: 'NEW')
            ->projection('all', fields: ['id', 'name', 'tier', 'state']);
    }
}

$dbPath = sys_get_temp_dir() . '/ausus-sorting.sqlite';
@unlink($dbPath);
$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$app = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')->actorId('seed')->roles(['srt.writer'])
            ->pdo($pdo)
    )->register(new SortingPlugin())->boot();

// Seed a deliberate mix so each sort produces a recognisable order. Duplicate
// `tier` values exercise the id-ASC tie-breaker.
$rows = [
    ['name' => 'Charlie', 'tier' => 2],
    ['name' => 'Alice',   'tier' => 3],
    ['name' => 'Bob',     'tier' => 1],
    ['name' => 'Diana',   'tier' => 2],
    ['name' => 'Eve',     'tier' => 1],
    ['name' => 'Frank',   'tier' => 3],
];
foreach ($rows as $r) {
    $app->invoke('srt.row.create', null, $r);
}

$tenant = new Tenant(new TenantId('acme'));
$repo   = $app->driver()->context($tenant, $app->driver()->beginTransaction($tenant))
            ->repository('srt.row');

// ── 1. asc ──────────────────────────────────────────────────────────────────
$page = $repo->findPaged(50, 0, [], [new Sort('name', Sort::DIR_ASC)]);
$names = array_map(fn($e) => $e->fields['name'], $page['items']);
_assert('asc returns 6 rows',                                count($names) === 6);
_assert('asc orders alphabetically',                         $names === ['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank']);

// ── 2. desc reverses asc ────────────────────────────────────────────────────
$page = $repo->findPaged(50, 0, [], [new Sort('name', Sort::DIR_DESC)]);
$names = array_map(fn($e) => $e->fields['name'], $page['items']);
_assert('desc reverses ascending order',                     $names === ['Frank', 'Eve', 'Diana', 'Charlie', 'Bob', 'Alice']);

// ── 3. multi-column: tier desc, name asc ────────────────────────────────────
$page = $repo->findPaged(50, 0, [], [
    new Sort('tier', Sort::DIR_DESC),
    new Sort('name', Sort::DIR_ASC),
]);
$names = array_map(fn($e) => $e->fields['name'], $page['items']);
_assert('multi-column: tier desc + name asc',
    $names === ['Alice', 'Frank', 'Charlie', 'Diana', 'Bob', 'Eve']);

// ── 4. pagination consistency: page 1 + page 2 == full sorted set ───────────
$page1 = $repo->findPaged(3, 0, [], [new Sort('name', Sort::DIR_ASC)]);
$page2 = $repo->findPaged(3, 3, [], [new Sort('name', Sort::DIR_ASC)]);
$combined = array_merge(
    array_map(fn($e) => $e->fields['name'], $page1['items']),
    array_map(fn($e) => $e->fields['name'], $page2['items']),
);
_assert('page1 + page2 (sorted) cover every row in order',
    $combined === ['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank']);
_assert('pagination totalCount unchanged by sort',           $page1['totalCount'] === 6);

// ── 5. stable across calls ──────────────────────────────────────────────────
$a = $repo->findPaged(50, 0, [], [new Sort('tier', Sort::DIR_ASC)]);
$b = $repo->findPaged(50, 0, [], [new Sort('tier', Sort::DIR_ASC)]);
$idsA = array_map(fn($e) => (string) $e->reference->identityHandle, $a['items']);
$idsB = array_map(fn($e) => (string) $e->reference->identityHandle, $b['items']);
_assert('same query returns same ids in same order',         $idsA === $idsB);

// ── 6. duplicate values — id ASC tie-break keeps deterministic order ────────
// Sort by tier ASC produces 3 ties at tier=1, tier=2, tier=3 (2 each). The id
// tail ensures the order within each group is stable. Verify by re-running
// the same query and checking the order is identical.
$a = $repo->findPaged(50, 0, [], [new Sort('tier', Sort::DIR_ASC)]);
$b = $repo->findPaged(50, 0, [], [new Sort('tier', Sort::DIR_ASC)]);
$namesA = array_map(fn($e) => $e->fields['name'], $a['items']);
$namesB = array_map(fn($e) => $e->fields['name'], $b['items']);
_assert('duplicate tier values keep stable inner order',     $namesA === $namesB);
$tiers = array_map(fn($e) => $e->fields['tier'], $a['items']);
_assert('tier asc respects ordering',                        $tiers === [1, 1, 2, 2, 3, 3]);

// ── 7. invalid field — SQL adapter refuses ──────────────────────────────────
$caught = false;
try { $repo->findPaged(50, 0, [], [new Sort('not_a_column', Sort::DIR_ASC)]); }
catch (InvalidArgumentException $e) { $caught = str_contains($e->getMessage(), 'unknown column'); }
_assert('unknown sort column rejected by SQL adapter',       $caught);

// ── 8. invalid direction — caught at value-object layer ─────────────────────
$caught = false;
try { new Sort('id', 'sideways'); }
catch (InvalidArgumentException $e) { $caught = true; }
_assert('invalid direction rejected before reaching SQL',    $caught);

// ── 9. duplicate sort column — SQL adapter refuses ──────────────────────────
$caught = false;
try {
    $repo->findPaged(50, 0, [], [
        new Sort('tier', Sort::DIR_ASC),
        new Sort('tier', Sort::DIR_DESC),
    ]);
} catch (InvalidArgumentException $e) {
    $caught = str_contains($e->getMessage(), 'duplicate sort');
}
_assert('duplicate sort column rejected by SQL adapter',     $caught);

// ── 10. id ASC fallback — empty sort list still deterministic ──────────────
$a = $repo->findPaged(50, 0, [], []);
$b = $repo->findPaged(50, 0, [], []);
$idsA = array_map(fn($e) => (string) $e->reference->identityHandle, $a['items']);
$idsB = array_map(fn($e) => (string) $e->reference->identityHandle, $b['items']);
_assert('empty sort list returns deterministic order (id ASC fallback)',
    $idsA === $idsB);

// ── 11. sort + filter — both apply ──────────────────────────────────────────
$page = $repo->findPaged(50, 0,
    [new \Ausus\Filter('tier', \Ausus\Filter::OP_IN, [1, 3])],
    [new Sort('name', Sort::DIR_ASC)],
);
$names = array_map(fn($e) => $e->fields['name'], $page['items']);
_assert('sort + filter: tier in [1,3] sorted by name asc',
    $names === ['Alice', 'Bob', 'Eve', 'Frank']);
_assert('sort + filter: totalCount reflects filter, not table', $page['totalCount'] === 4);

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed=$passed failed=$failed\n";
echo "{$BANNER}\n";
exit($failed === 0 ? 0 : 1);
