<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Application, ApplicationConfig, Dsl, DslPlugin, Field, Action};
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;

/**
 * AUSUS — sorting over HTTP (v0.2 beta.1 prep).
 *
 * Exercises the `?sort=<field>:<dir>,<field>:<dir>` parsing layer end to end
 * through the L4 Router. The contract:
 *
 *   1. asc                — single column ascending
 *   2. desc               — single column descending
 *   3. multi-column       — comma-separated, deterministic
 *   4. with pagination    — sort applies before the page window
 *   5. unknown field      → 400 BadRequest
 *   6. invalid direction  → 400 BadRequest
 *   7. malformed clause   → 400 BadRequest (missing colon, empty parts)
 *   8. empty sort         → 400 BadRequest (deliberate user error, not a no-op)
 *   9. duplicate field    → 400 BadRequest (ambiguous user intent)
 *  10. capitalised dir    → 400 BadRequest ('ASC' refused — SQL pattern is exact)
 */

$BANNER = "═══ AUSUS — sorting over HTTP ══════════════════════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

final class SortingHttpPlugin extends DslPlugin {
    public function name(): string         { return 'shttp'; }
    public function phpNamespace(): string { return 'SortingHttpPlugin'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('row')
            ->fields([
                'name'  => Field::string()->max(64),
                'tier'  => Field::integer(),
                'state' => Field::enum('NEW','DONE')->default('NEW'),
            ])
            ->actions([
                'create' => Action::create('name','tier')->requireRole('shttp.writer'),
            ])
            ->workflow(field: 'state', initial: 'NEW')
            ->projection('all', fields: ['id','name','tier','state']);
    }
}

$dbPath = sys_get_temp_dir() . '/ausus-sorting-http.sqlite';
@unlink($dbPath);
$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$factory = new Psr17Factory();
$app = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')->actorId('seed')->roles(['shttp.writer'])
            ->pdo($pdo)->psr17($factory)
    )->register(new SortingHttpPlugin())->boot();

$rows = [
    ['name' => 'Charlie', 'tier' => 2],
    ['name' => 'Alice',   'tier' => 3],
    ['name' => 'Bob',     'tier' => 1],
    ['name' => 'Diana',   'tier' => 2],
    ['name' => 'Eve',     'tier' => 1],
    ['name' => 'Frank',   'tier' => 3],
];
foreach ($rows as $r) {
    $app->invoke('shttp.row.create', null, $r);
}

function get(Application $app, string $query): array {
    $uri = new Uri('/api/projections/shttp.row.all?' . $query);
    $req = (new ServerRequest('GET', $uri))->withHeader('X-Tenant-ID', 'acme');
    $res = $app->http($req);
    return [$res->getStatusCode(), json_decode((string) $res->getBody(), true)];
}

// ── 1. asc ──────────────────────────────────────────────────────────────────
[$status, $body] = get($app, 'sort=name:asc');
_assert('asc returns 200',                              $status === 200);
$names = array_column($body['data']['items'], 'name');
_assert('asc orders alphabetically',                    $names === ['Alice','Bob','Charlie','Diana','Eve','Frank']);

// ── 2. desc ─────────────────────────────────────────────────────────────────
[$status, $body] = get($app, 'sort=name:desc');
_assert('desc returns 200',                             $status === 200);
$names = array_column($body['data']['items'], 'name');
_assert('desc reverses asc',                            $names === ['Frank','Eve','Diana','Charlie','Bob','Alice']);

// ── 3. multi-column ─────────────────────────────────────────────────────────
[$status, $body] = get($app, 'sort=tier:desc,name:asc');
_assert('multi-column returns 200',                     $status === 200);
$names = array_column($body['data']['items'], 'name');
_assert('multi-column tier desc + name asc',
    $names === ['Alice','Frank','Charlie','Diana','Bob','Eve']);

// ── 4. with pagination ──────────────────────────────────────────────────────
[$status, $b1] = get($app, 'sort=name:asc&limit=3&offset=0');
[$status2, $b2] = get($app, 'sort=name:asc&limit=3&offset=3');
_assert('page1 returns 200',                            $status === 200);
_assert('page2 returns 200',                            $status2 === 200);
$combined = array_merge(
    array_column($b1['data']['items'], 'name'),
    array_column($b2['data']['items'], 'name')
);
_assert('sort + pagination yields full sorted set',
    $combined === ['Alice','Bob','Charlie','Diana','Eve','Frank']);

// ── 5. unknown field — 400 ──────────────────────────────────────────────────
[$status, $body] = get($app, 'sort=bogus:asc');
_assert('unknown sort field returns 400',               $status === 400);
_assert('unknown sort field message mentions "not declared"',
    str_contains($body['error']['message'] ?? '', 'not declared'));

// ── 6. invalid direction — 400 ──────────────────────────────────────────────
[$status, $body] = get($app, 'sort=name:sideways');
_assert('invalid direction returns 400',                $status === 400);
_assert('invalid direction message lists allowed',
    str_contains($body['error']['message'] ?? '', 'allowed:'));

// ── 7. malformed clause — 400 ───────────────────────────────────────────────
[$status, $body] = get($app, 'sort=name');
_assert('missing colon returns 400',                    $status === 400);
_assert('malformed message mentions expected form',
    str_contains($body['error']['message'] ?? '', '<field>:<asc|desc>'));

// ── 8. empty sort — 400 ─────────────────────────────────────────────────────
[$status, $body] = get($app, 'sort=');
_assert('empty sort returns 400',                       $status === 400);

// ── 9. duplicate field — 400 ────────────────────────────────────────────────
[$status, $body] = get($app, 'sort=tier:asc,tier:desc');
_assert('duplicate sort field returns 400',             $status === 400);
_assert('duplicate sort message mentions "more than once"',
    str_contains($body['error']['message'] ?? '', 'more than once'));

// ── 10. capitalised direction — 400 ─────────────────────────────────────────
[$status, $body] = get($app, 'sort=name:ASC');
_assert('uppercase ASC returns 400',                    $status === 400);

// ── 11. no sort param — defaults to deterministic id ASC ────────────────────
[$status, $body] = get($app, '');
_assert('no sort param returns 200',                    $status === 200);
$ids1 = array_column($body['data']['items'], 'id');
[$status, $body2] = get($app, '');
$ids2 = array_column($body2['data']['items'], 'id');
_assert('no sort param yields deterministic order',     $ids1 === $ids2);

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed=$passed failed=$failed\n";
echo "{$BANNER}\n";
exit($failed === 0 ? 0 : 1);
