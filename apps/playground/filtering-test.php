<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Application, ApplicationConfig, Dsl, DslPlugin, Field, Action, Filter};

/**
 * AUSUS — filtering primitives (v0.2 beta.1 prep).
 *
 * Exercises the three whitelisted filter operators (eq / in / contains) end
 * to end through the SQL adapter, plus the defence-in-depth column whitelist
 * and the SQL injection refusal at the value-object layer.
 *
 * Coverage matches the sprint contract:
 *   1. eq          → exact match on a scalar value
 *   2. contains    → case-insensitive substring search
 *   3. in          → membership in a small whitelist (incl. ints)
 *   4. empty set   → behaves as no filter
 *   5. invalid op  → Filter constructor refuses, no SQL emitted
 *   6. invalid fld → SQL adapter refuses (defence in depth)
 *   7. injection   → quoted/parameter-bound, treated as literal
 *   8. unicode     → multi-byte literals round-trip correctly
 *   9. mixed       → eq + contains AND-combine to narrow the result
 *  10. totalCount  → reports the post-filter row count, not the table count
 */

$BANNER = "═══ AUSUS — filtering primitives ═══════════════════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

// ── Fixture ─────────────────────────────────────────────────────────────────
final class FilteringPlugin extends DslPlugin {
    public function name(): string         { return 'flt'; }
    public function phpNamespace(): string { return 'FilteringPlugin'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('row')
            ->fields([
                'name'   => Field::string()->max(64),
                'status' => Field::string()->max(16),
                'tier'   => Field::integer(),
                'state'  => Field::enum('NEW', 'DONE')->default('NEW'),
            ])
            ->actions([
                'create' => Action::create('name', 'status', 'tier')->requireRole('flt.writer'),
            ])
            ->workflow(field: 'state', initial: 'NEW')
            ->projection('all', fields: ['id', 'name', 'status', 'tier', 'state']);
    }
}

$dbPath = sys_get_temp_dir() . '/ausus-filtering.sqlite';
@unlink($dbPath);
$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$app = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')
            ->actorId('seed')
            ->roles(['flt.writer'])
            ->pdo($pdo)
    )
    ->register(new FilteringPlugin())
    ->boot();

// Seed a deliberate, slightly heterogeneous set so each filter narrows in a
// recognisable way.
$rows = [
    ['name' => 'ACME Corp',         'status' => 'ISSUED',  'tier' => 1],
    ['name' => 'Globex Industries', 'status' => 'DRAFT',   'tier' => 2],
    ['name' => 'acme holdings',     'status' => 'ISSUED',  'tier' => 3],
    ['name' => 'Initech LLC',       'status' => 'PAID',    'tier' => 1],
    ['name' => 'Vandelay',          'status' => 'ISSUED',  'tier' => 2],
    ['name' => 'Hooli Inc',         'status' => 'DRAFT',   'tier' => 3],
    ['name' => 'Café Saumon',       'status' => 'PAID',    'tier' => 1],   // unicode
];
foreach ($rows as $r) {
    $app->invoke('flt.row.create', null, $r);
}
$repo = $app->driver()->context(new Ausus\Tenant(new Ausus\TenantId('acme')),
            $app->driver()->beginTransaction(new Ausus\Tenant(new Ausus\TenantId('acme'))))
        ->repository('flt.row');

// ── 1. eq — exact scalar match ───────────────────────────────────────────────
$page = $repo->findPaged(50, 0, [new Filter('status', Filter::OP_EQ, 'ISSUED')]);
_assert('eq narrows to status=ISSUED',                       $page['totalCount'] === 3);
_assert('eq does not match other statuses',
    array_unique(array_map(fn($e) => $e->fields['status'], $page['items'])) === ['ISSUED']);

// ── 2. contains — case-insensitive substring ─────────────────────────────────
$page = $repo->findPaged(50, 0, [new Filter('name', Filter::OP_CONTAINS, 'acme')]);
$names = array_map(fn($e) => $e->fields['name'], $page['items']);
_assert('contains matches both ACME case variants',          count($names) === 2);
_assert('contains is case-insensitive',                      in_array('ACME Corp', $names) && in_array('acme holdings', $names));

// ── 3. in — membership ───────────────────────────────────────────────────────
$page = $repo->findPaged(50, 0, [new Filter('status', Filter::OP_IN, ['ISSUED', 'PAID'])]);
_assert('in matches every listed status',                    $page['totalCount'] === 5);
$statuses = array_unique(array_map(fn($e) => $e->fields['status'], $page['items']));
sort($statuses);
_assert('in produces exactly the union ISSUED+PAID',         $statuses === ['ISSUED', 'PAID']);

// ── 3b. in with integer values ───────────────────────────────────────────────
$page = $repo->findPaged(50, 0, [new Filter('tier', Filter::OP_IN, [1, 2])]);
_assert('in with int values narrows correctly',              $page['totalCount'] === 5);

// ── 4. empty filter set — behaves identically to no filter ───────────────────
$pageEmpty = $repo->findPaged(50, 0, []);
$pageNoArg = $repo->findPaged(50, 0);
_assert('empty filter set = no filter (totalCount)',         $pageEmpty['totalCount'] === $pageNoArg['totalCount']);
_assert('empty filter set returns every row',                $pageEmpty['totalCount'] === 7);

// ── 5. invalid operator — Filter constructor refuses ─────────────────────────
$caught = false;
try { new Filter('status', 'regex', 'foo'); }
catch (InvalidArgumentException $e) { $caught = str_contains($e->getMessage(), 'regex'); }
_assert('invalid operator rejected at value-object layer',   $caught);

// ── 6. invalid field — SQL adapter refuses (defence in depth) ────────────────
$caught = false;
try { $repo->findPaged(50, 0, [new Filter('does_not_exist', Filter::OP_EQ, 'x')]); }
catch (InvalidArgumentException $e) { $caught = str_contains($e->getMessage(), 'unknown column'); }
_assert('unknown column rejected by SQL adapter',            $caught);

// ── 7. SQL injection attempts — treated as literal scalar ────────────────────
foreach ([
    "ISSUED' OR '1'='1",       // classic OR-truthy
    "ISSUED'; DROP TABLE flt_row; --",
    "ISSUED\\'; --",
] as $injection) {
    $page = $repo->findPaged(50, 0, [new Filter('status', Filter::OP_EQ, $injection)]);
    _assert("injection '" . substr($injection, 0, 24) . "...' yields 0 hits",
        $page['totalCount'] === 0);
}
// Table is still intact after the DROP-TABLE attempt.
$afterInjections = $repo->findPaged(50, 0);
_assert('table survived injection attempts (7 rows still)',  $afterInjections['totalCount'] === 7);

// ── 7b. LIKE metacharacter escape — '%' is a literal, not a wildcard ─────────
$page = $repo->findPaged(50, 0, [new Filter('name', Filter::OP_CONTAINS, '%')]);
_assert('contains "%" matches no rows (escaped)',            $page['totalCount'] === 0);

// ── 8. unicode round-trip ────────────────────────────────────────────────────
$page = $repo->findPaged(50, 0, [new Filter('name', Filter::OP_EQ, 'Café Saumon')]);
_assert('unicode eq match works',                            $page['totalCount'] === 1);
$page = $repo->findPaged(50, 0, [new Filter('name', Filter::OP_CONTAINS, 'café')]);
_assert('unicode contains match (case insensitive)',         $page['totalCount'] === 1);

// ── 9. mixed filters — AND-combine ───────────────────────────────────────────
$page = $repo->findPaged(50, 0, [
    new Filter('status', Filter::OP_EQ, 'ISSUED'),
    new Filter('tier',   Filter::OP_IN, [2, 3]),
]);
_assert('mixed eq+in narrows further than either alone',     $page['totalCount'] === 2);

// ── 10. totalCount reports post-filter row count, not table count ────────────
$page = $repo->findPaged(2, 0, [new Filter('status', Filter::OP_EQ, 'ISSUED')]);
_assert('totalCount counts filter matches, not all rows',    $page['totalCount'] === 3);
_assert('pageSize reflects limit, not totalCount',           count($page['items']) === 2);

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed=$passed failed=$failed\n";
echo "{$BANNER}\n";
exit($failed === 0 ? 0 : 1);
