<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Application, ApplicationConfig, Dsl, DslPlugin, Field, Action};
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;

/**
 * AUSUS — filter + sort HTTP integration coverage (v0.2 beta.1 prep).
 *
 * Exercises the FULL list-mode HTTP surface against a live Router with the
 * combined ?filter / ?sort / ?limit / ?offset query parameters and the new
 * 1.2.0 wire shape (filters[] + sort[] echoes + pagination block).
 *
 * Coverage target: 20+ new assertions covering:
 *   1. pagination + sorting compose
 *   2. filtering + sorting compose
 *   3. stable page traversal across all four parameters
 *   4. mixed query parameters in arbitrary order
 *   5. invalid query → 400 with precise message
 *   6. schemaVersion = 1.2.0
 *   7. wire echoes the applied filters[] and sort[]
 */

$BANNER = "═══ AUSUS — integration: filter + sort wire ════════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

final class IntegrationPlugin extends DslPlugin {
    public function name(): string         { return 'igr'; }
    public function phpNamespace(): string { return 'IntegrationPlugin'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('item')
            ->fields([
                'name'     => Field::string()->max(64),
                'category' => Field::string()->max(16),
                'price'    => Field::integer(),
                'state'    => Field::enum('NEW', 'DONE')->default('NEW'),
            ])
            ->actions([
                'create' => Action::create('name', 'category', 'price')->requireRole('igr.writer'),
            ])
            ->workflow(field: 'state', initial: 'NEW')
            ->projection('all', fields: ['id', 'name', 'category', 'price', 'state']);
    }
}

$dbPath = sys_get_temp_dir() . '/ausus-integration-fs.sqlite';
@unlink($dbPath);
$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$factory = new Psr17Factory();
$app = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')->actorId('seed')->roles(['igr.writer'])
            ->pdo($pdo)->psr17($factory)
    )->register(new IntegrationPlugin())->boot();

// Twelve rows across three categories with deliberately varied prices.
$rows = [
    ['name' => 'Widget A',    'category' => 'gadget',  'price' => 100],
    ['name' => 'Widget B',    'category' => 'gadget',  'price' => 250],
    ['name' => 'Widget C',    'category' => 'gadget',  'price' => 175],
    ['name' => 'Gizmo X',     'category' => 'tool',    'price' => 500],
    ['name' => 'Gizmo Y',     'category' => 'tool',    'price' => 350],
    ['name' => 'Gizmo Z',     'category' => 'tool',    'price' => 425],
    ['name' => 'Sprocket Q',  'category' => 'parts',   'price' => 50],
    ['name' => 'Sprocket R',  'category' => 'parts',   'price' => 75],
    ['name' => 'Sprocket S',  'category' => 'parts',   'price' => 90],
    ['name' => 'Doohickey 1', 'category' => 'gadget',  'price' => 999],
    ['name' => 'Doohickey 2', 'category' => 'tool',    'price' => 888],
    ['name' => 'Doohickey 3', 'category' => 'parts',   'price' => 777],
];
foreach ($rows as $r) {
    $app->invoke('igr.item.create', null, $r);
}

function get(Application $app, string $query): array {
    $uri = new Uri('/api/projections/igr.item.all?' . $query);
    $req = (new ServerRequest('GET', $uri))->withHeader('X-Tenant-ID', 'acme');
    $res = $app->http($req);
    return [$res->getStatusCode(), json_decode((string) $res->getBody(), true)];
}

// ── 1. wire shape — schemaVersion 1.2.0 ─────────────────────────────────────
[$status, $body] = get($app, '');
_assert('plain GET returns 200',                              $status === 200);
_assert('schemaVersion = 1.2.0',                              $body['schemaVersion'] === '1.2.0');
_assert('top-level filters[] echo present',                   isset($body['filters']) && is_array($body['filters']));
_assert('top-level sort[] echo present',                      isset($body['sort'])    && is_array($body['sort']));
_assert('empty query → empty filters echo',                   $body['filters'] === []);
_assert('empty query → empty sort echo',                      $body['sort']    === []);

// ── 2. pagination + sorting compose ────────────────────────────────────────
[$status, $b1] = get($app, 'sort=price:asc&limit=4&offset=0');
[$_, $b2]       = get($app, 'sort=price:asc&limit=4&offset=4');
[$_, $b3]       = get($app, 'sort=price:asc&limit=4&offset=8');
_assert('paged sort: page1 has 4 items',                      count($b1['data']['items']) === 4);
_assert('paged sort: page3 totalCount = 12',                  $b3['data']['pagination']['totalCount'] === 12);
$prices = [
    ...array_column($b1['data']['items'], 'price'),
    ...array_column($b2['data']['items'], 'price'),
    ...array_column($b3['data']['items'], 'price'),
];
$sorted = $prices;
sort($sorted, SORT_NUMERIC);
_assert('paged sort: concatenation is monotonically asc',     $prices === $sorted);

// ── 3. filtering + sorting compose ─────────────────────────────────────────
[$status, $body] = get($app, 'filter.category.eq=gadget&sort=price:desc');
_assert('filter+sort returns 200',                            $status === 200);
$names = array_column($body['data']['items'], 'name');
_assert('filter+sort: gadgets ordered by price desc',
    $names === ['Doohickey 1', 'Widget B', 'Widget C', 'Widget A']);
_assert('filter+sort: filters[] echoed',
    $body['filters'] === [['field' => 'category', 'op' => 'eq', 'value' => 'gadget']]);
_assert('filter+sort: sort[] echoed',
    $body['sort'] === [['field' => 'price', 'direction' => 'desc']]);

// ── 4. stable page traversal across all parameters ────────────────────────
[$_, $a] = get($app, 'filter.category.in=tool,parts&sort=name:asc&limit=3&offset=0');
[$_, $b] = get($app, 'filter.category.in=tool,parts&sort=name:asc&limit=3&offset=0');
_assert('stable across calls (4-param query)',
    array_column($a['data']['items'], 'name') === array_column($b['data']['items'], 'name'));

// ── 5. mixed query parameters in arbitrary order ──────────────────────────
[$status, $a] = get($app, 'sort=price:asc&filter.category.eq=tool&limit=5&offset=1');
[$_, $b]      = get($app, 'limit=5&filter.category.eq=tool&offset=1&sort=price:asc');
_assert('parameter order independence (status)',              $status === 200);
_assert('parameter order independence (items)',
    array_column($a['data']['items'], 'name') === array_column($b['data']['items'], 'name'));
_assert('parameter order independence (totalCount)',
    $a['data']['pagination']['totalCount'] === $b['data']['pagination']['totalCount']);

// ── 6. invalid combinations — 400 with precise message ────────────────────
[$status, $body] = get($app, 'filter.bogus.eq=x&sort=price:asc');
_assert('invalid filter field + valid sort → 400',            $status === 400);
_assert('error mentions "not declared"',
    str_contains($body['error']['message'] ?? '', 'not declared'));

[$status, $body] = get($app, 'filter.category.eq=tool&sort=bogus:asc');
_assert('valid filter + invalid sort field → 400',            $status === 400);
_assert('error mentions "not declared" (sort path)',
    str_contains($body['error']['message'] ?? '', 'not declared'));

[$status, $body] = get($app, 'filter.category.in=tool,parts&sort=price:UPPERCASE');
_assert('valid filter + invalid sort direction → 400',        $status === 400);

[$status, $body] = get($app, 'filter.price.contains=99&sort=price:asc');
_assert('contains on integer still returns 200',              $status === 200);

// ── 7. wire echoes — filters[] and sort[] reflect applied query ───────────
[$status, $body] = get($app, 'filter.category.in=tool,parts&sort=category:asc,price:desc');
_assert('multi-filter + multi-sort returns 200',              $status === 200);
_assert('filters echoed: 1 entry (category in)',              count($body['filters']) === 1);
_assert('sort echoed: 2 entries',                             count($body['sort']) === 2);
$expected = [
    ['field' => 'category', 'direction' => 'asc'],
    ['field' => 'price',    'direction' => 'desc'],
];
_assert('sort echo preserves order',                          $body['sort'] === $expected);
_assert('filters echo: value list preserved',
    $body['filters'][0]['value'] === ['tool', 'parts']);

// ── 8. filtered totalCount reflects post-filter row count ─────────────────
[$status, $body] = get($app, 'filter.category.eq=gadget&limit=2');
_assert('filtered totalCount = 4 (the 4 gadgets)',            $body['data']['pagination']['totalCount'] === 4);
_assert('pageSize = limit',                                   $body['data']['pagination']['pageSize'] === 2);

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed=$passed failed=$failed\n";
echo "{$BANNER}\n";
exit($failed === 0 ? 0 : 1);
