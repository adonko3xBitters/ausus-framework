<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Application, ApplicationConfig, Dsl, DslPlugin, Field, Action};
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;

/**
 * AUSUS — filtering over HTTP (v0.2 beta.1 prep).
 *
 * Exercises the `?filter.<field>.<op>=<value>` parsing layer end to end
 * through the L4 Router. The dotted-key syntax bypasses PHP parse_str's
 * '.→_' rewrite via getUri()->getQuery() walking; this test pins that
 * contract and the 400-on-malformed-input semantics.
 *
 * Coverage:
 *   1. eq             — narrows correctly
 *   2. in             — comma-separated list narrows correctly
 *   3. contains       — case-insensitive substring narrows correctly
 *   4. mixed          — eq + in combine, totalCount reflects intersection
 *   5. empty query    — no filters → every row
 *   6. unknown field  → 400 BadRequest with descriptive message
 *   7. unknown op     → 400 BadRequest with allowed-ops list
 *   8. malformed key  → 400 BadRequest with key shape hint
 *   9. empty in list  → 400 BadRequest
 *  10. empty in entry → 400 BadRequest (no silent empty-scalar smuggling)
 *  11. with pagination — filter + limit + offset compose correctly
 */

$BANNER = "═══ AUSUS — filtering over HTTP ════════════════════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

final class FilteringHttpPlugin extends DslPlugin {
    public function name(): string         { return 'fhttp'; }
    public function phpNamespace(): string { return 'FilteringHttpPlugin'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('row')
            ->fields([
                'name'   => Field::string()->max(64),
                'status' => Field::string()->max(16),
                'state'  => Field::enum('NEW','DONE')->default('NEW'),
            ])
            ->actions([
                'create' => Action::create('name','status')->requireRole('fhttp.writer'),
            ])
            ->workflow(field: 'state', initial: 'NEW')
            ->projection('all', fields: ['id','name','status','state']);
    }
}

$dbPath = sys_get_temp_dir() . '/ausus-filtering-http.sqlite';
@unlink($dbPath);
$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$factory = new Psr17Factory();
$app = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')->actorId('seed')->roles(['fhttp.writer'])
            ->pdo($pdo)->psr17($factory)
    )->register(new FilteringHttpPlugin())->boot();

$rows = [
    ['name' => 'ACME Corp',         'status' => 'ISSUED'],
    ['name' => 'Globex Industries', 'status' => 'DRAFT'],
    ['name' => 'acme holdings',     'status' => 'ISSUED'],
    ['name' => 'Initech LLC',       'status' => 'PAID'],
    ['name' => 'Vandelay',          'status' => 'ISSUED'],
    ['name' => 'Hooli Inc',         'status' => 'DRAFT'],
];
foreach ($rows as $r) {
    $app->invoke('fhttp.row.create', null, $r);
}

/** Send a GET /api/projections/fhttp.row.all?<query> and return decoded JSON + status. */
function get(Application $app, string $query): array {
    $factory = new Psr17Factory();
    $uri = new Uri('/api/projections/fhttp.row.all?' . $query);
    $req = (new ServerRequest('GET', $uri))
        ->withHeader('X-Tenant-ID', 'acme');
    $res = $app->http($req);
    $body = (string) $res->getBody();
    return [$res->getStatusCode(), json_decode($body, true)];
}

// ── 1. eq ──────────────────────────────────────────────────────────────────
[$status, $body] = get($app, 'filter.status.eq=ISSUED');
_assert('eq returns 200',                                $status === 200);
_assert('eq totalCount = 3',                             ($body['data']['pagination']['totalCount'] ?? -1) === 3);

// ── 2. in ──────────────────────────────────────────────────────────────────
[$status, $body] = get($app, 'filter.status.in=ISSUED,PAID');
_assert('in returns 200',                                $status === 200);
_assert('in totalCount = 4',                             ($body['data']['pagination']['totalCount'] ?? -1) === 4);

// ── 3. contains ────────────────────────────────────────────────────────────
[$status, $body] = get($app, 'filter.name.contains=acme');
_assert('contains returns 200',                          $status === 200);
_assert('contains case-insensitive (2 matches)',         ($body['data']['pagination']['totalCount'] ?? -1) === 2);

// ── 4. mixed eq + in ───────────────────────────────────────────────────────
[$status, $body] = get($app, 'filter.status.eq=ISSUED&filter.name.contains=acme');
_assert('mixed eq+contains returns 200',                 $status === 200);
_assert('mixed eq+contains narrows to 2',                ($body['data']['pagination']['totalCount'] ?? -1) === 2);

// ── 5. empty query — no filters ────────────────────────────────────────────
[$status, $body] = get($app, '');
_assert('empty query returns 200',                       $status === 200);
_assert('empty query returns every row',                 ($body['data']['pagination']['totalCount'] ?? -1) === 6);

// ── 6. unknown field — 400 ─────────────────────────────────────────────────
[$status, $body] = get($app, 'filter.bogus.eq=x');
_assert('unknown field returns 400',                     $status === 400);
_assert('unknown field surfaces "not declared" message',
    str_contains($body['error']['message'] ?? '', 'not declared'));

// ── 7. unknown operator — 400 ──────────────────────────────────────────────
[$status, $body] = get($app, 'filter.status.regex=foo');
_assert('unknown operator returns 400',                  $status === 400);
_assert('unknown op error lists allowed operators',
    str_contains($body['error']['message'] ?? '', 'allowed'));

// ── 8. malformed key — 400 ─────────────────────────────────────────────────
[$status, $body] = get($app, 'filter.status=ISSUED');
_assert('malformed (missing op) returns 400',            $status === 400);

// ── 9. empty in list — 400 ─────────────────────────────────────────────────
[$status, $body] = get($app, 'filter.status.in=');
_assert('empty in list returns 400',                     $status === 400);

// ── 10. empty in entry — 400 ───────────────────────────────────────────────
[$status, $body] = get($app, 'filter.status.in=ISSUED,,PAID');
_assert('empty in entry returns 400',                    $status === 400);

// ── 11. with pagination — compose correctly ────────────────────────────────
[$status, $body] = get($app, 'filter.status.eq=ISSUED&limit=2&offset=1');
_assert('filter+limit+offset returns 200',               $status === 200);
_assert('filter+limit+offset pageSize = 2',              count($body['data']['items'] ?? []) === 2);
_assert('filter+limit+offset totalCount unchanged',      ($body['data']['pagination']['totalCount'] ?? -1) === 3);
_assert('filter+limit+offset limit echoed',              ($body['data']['pagination']['limit'] ?? -1) === 2);
_assert('filter+limit+offset offset echoed',             ($body['data']['pagination']['offset'] ?? -1) === 1);

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed=$passed failed=$failed\n";
echo "{$BANNER}\n";
exit($failed === 0 ? 0 : 1);
