<?php

declare(strict_types=1);

/**
 * L4 — Projection Aggregations test suite.
 *
 * Exercises count/sum/avg/min/max, WHERE + aggregate, orderBy + aggregate,
 * pagination-independence, tenant isolation, per-field visibility (hidden values
 * never contribute), the empty-aggregate no-op (regression), and fail-closed
 * validation — over the real runtime + Memory driver.
 */

$autoload = null;
foreach (['/../vendor/autoload.php', '/../../../vendor/autoload.php'] as $rel) {
    if (file_exists(__DIR__ . $rel)) { $autoload = __DIR__ . $rel; break; }
}
require $autoload;

use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;
use Ausus\Engine\Compile\Compiler;
use Ausus\Engine\Query\QueryError;
use Ausus\Engine\Repository\InMemorySchemaRepository;
use Ausus\Engine\Runtime\DefaultAuthorizationEvaluator;
use Ausus\Engine\Runtime\DefaultEntityEngine;
use Ausus\Persistence\Memory\MemoryDriver;
use Ausus\Api\Runtime\Http\RequestContextFactory;

$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

// ── task entity: numeric fields + a visibility-guarded numeric field ─────────
$task = Definition::make('task', true)
    ->field('title', FieldType::String)
    ->field('priority', FieldType::Integer)
    ->field('amount', FieldType::Integer)
    ->field('status', FieldType::Enum, ['default' => 'open', 'typeOptions' => ['values' => ['open', 'done']]])
    ->field('assignee', FieldType::String, ['nullable' => true])
    ->field('secret', FieldType::Integer, ['nullable' => true])
    ->action('create', ActionKind::Create, ['inputs' => ['title', 'priority', 'amount', 'status', 'assignee', 'secret']])
    ->projection('list', ['fields' => [
        ['field' => 'title'], ['field' => 'priority'], ['field' => 'amount'],
        ['field' => 'status'], ['field' => 'assignee'],
        // `secret` is visible only to an admin actor
        ['field' => 'secret', 'visibility' => Expr::eq(Expr::actor('type'), 'admin')],
    ]])
    ->build();

$repo = new InMemorySchemaRepository();
foreach ((new Compiler())->compile([$task])->schemas as $s) { $repo->putByHash($s); }
$engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$driver = new MemoryDriver();
$factory = new RequestContextFactory(new DateTimeImmutable('@1700000000'));
$user = $factory->fromHeaders(['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user']);
$admin = $factory->fromHeaders(['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'admin']);
$other = $factory->fromHeaders(['X-Tenant-ID' => 'globex', 'X-Actor-Type' => 'user']);

$bind = fn () => $engine->bind($repo->resolve('task'), $driver);

// seed (tenant acme): 5 rows
$seed = [
    ['title' => 'Alpha',   'priority' => 1, 'amount' => 100, 'status' => 'open', 'assignee' => 'ana',  'secret' => 10],
    ['title' => 'Beta',    'priority' => 3, 'amount' => 300, 'status' => 'open', 'assignee' => 'bob',  'secret' => 20],
    ['title' => 'Gamma',   'priority' => 2, 'amount' => 200, 'status' => 'done', 'assignee' => 'ana',  'secret' => 30],
    ['title' => 'Delta',   'priority' => 5, 'amount' => 200, 'status' => 'open'],                       // no assignee/secret
    ['title' => 'Epsilon', 'priority' => 2, 'amount' => 400, 'status' => 'done', 'assignee' => 'cara', 'secret' => 50],
];
foreach ($seed as $row) { $bind()->invoke('create', $row, $user); }
$bind()->invoke('create', ['title' => 'Foreign', 'priority' => 9, 'amount' => 999, 'status' => 'open'], $other);

$agg = fn (array $params, $ctx = null) => $bind()->readWithAggregates('list', $params, $ctx ?? $user);
$A = ['op' => 'count', 'as' => 'n'];

echo "── Regression: read() unchanged; empty aggregate is a no-op ──\n";
$ok('read() still returns a bare list', count($bind()->read('list', [], $user)) === 5);
$r0 = $agg([]);
$ok('readWithAggregates([]) → rows list + empty aggregates', count($r0['rows']) === 5 && $r0['aggregates'] === []);

echo "── Operators (tenant acme, 5 rows) ──\n";
$all = $agg(['aggregate' => [
    ['op' => 'count', 'as' => 'total'],
    ['op' => 'sum',   'field' => 'amount', 'as' => 'revenue'],
    ['op' => 'avg',   'field' => 'amount', 'as' => 'averageAmount'],
    ['op' => 'min',   'field' => 'amount', 'as' => 'cheapest'],
    ['op' => 'max',   'field' => 'amount', 'as' => 'dearest'],
    ['op' => 'count', 'field' => 'assignee', 'as' => 'assigned'],
]])['aggregates'];
$ok('count → 5', $all['total'] === 5);
$ok('sum amount → 1200', $all['revenue'] === 1200);
$ok('avg amount → 240', $all['averageAmount'] === 240.0);
$ok('min amount → 100', $all['cheapest'] === 100);
$ok('max amount → 400', $all['dearest'] === 400);
$ok('count(assignee) non-null → 4', $all['assigned'] === 4);

echo "── min/max on a string field ──\n";
$str = $agg(['aggregate' => [['op' => 'min', 'field' => 'title', 'as' => 'first'], ['op' => 'max', 'field' => 'title', 'as' => 'last']]])['aggregates'];
$ok('min(title) → Alpha', $str['first'] === 'Alpha');
$ok('max(title) → Gamma', $str['last'] === 'Gamma');

echo "── WHERE + aggregate (status=open → 3 rows) ──\n";
$open = $agg(['where' => [['field' => 'status', 'op' => 'eq', 'value' => 'open']], 'aggregate' => [
    ['op' => 'count', 'as' => 'n'], ['op' => 'sum', 'field' => 'amount', 'as' => 'rev'], ['op' => 'avg', 'field' => 'amount', 'as' => 'av'],
]])['aggregates'];
$ok('count(open) → 3', $open['n'] === 3);
$ok('sum(open.amount) → 600', $open['rev'] === 600);
$ok('avg(open.amount) → 200', $open['av'] === 200.0);

echo "── Pagination independence: aggregates ignore limit/offset ──\n";
$paged = $agg(['limit' => 1, 'offset' => 2, 'aggregate' => [['op' => 'count', 'as' => 'n'], ['op' => 'sum', 'field' => 'amount', 'as' => 'rev']]]);
$ok('rows respect limit=1', count($paged['rows']) === 1);
$ok('aggregates over full set: count=5', $paged['aggregates']['n'] === 5);
$ok('aggregates over full set: sum=1200', $paged['aggregates']['rev'] === 1200);

echo "── orderBy + aggregate (rows sorted; aggregates unaffected) ──\n";
$sorted = $agg(['orderBy' => [['field' => 'amount', 'dir' => 'desc']], 'aggregate' => [['op' => 'max', 'field' => 'amount', 'as' => 'top']]]);
$ok('rows sorted by amount desc', array_map(fn ($r) => $r['amount'], $sorted['rows']) === [400, 300, 200, 200, 100]);
$ok('max aggregate still 400', $sorted['aggregates']['top'] === 400);

echo "── Tenant isolation ──\n";
$g = $agg(['aggregate' => [['op' => 'count', 'as' => 'n'], ['op' => 'sum', 'field' => 'amount', 'as' => 'rev']]], $other)['aggregates'];
$ok('globex count → 1', $g['n'] === 1);
$ok('globex sum amount → 999', $g['rev'] === 999);

echo "── Visibility: hidden values never contribute ──\n";
$secU = $agg(['aggregate' => [['op' => 'sum', 'field' => 'secret', 'as' => 's'], ['op' => 'avg', 'field' => 'secret', 'as' => 'a'], ['op' => 'count', 'field' => 'secret', 'as' => 'c']]], $user)['aggregates'];
$ok('user: sum(secret) → 0 (hidden, no leak)', $secU['s'] === 0);
$ok('user: avg(secret) → null (no visible values)', $secU['a'] === null);
$ok('user: count(secret) → 0', $secU['c'] === 0);
$secA = $agg(['aggregate' => [['op' => 'sum', 'field' => 'secret', 'as' => 's'], ['op' => 'avg', 'field' => 'secret', 'as' => 'a'], ['op' => 'count', 'field' => 'secret', 'as' => 'c']]], $admin)['aggregates'];
$ok('admin: sum(secret) → 110', $secA['s'] === 110);
$ok('admin: avg(secret) → 27.5', $secA['a'] === 27.5);
$ok('admin: count(secret) → 4', $secA['c'] === 4);

echo "── Fail-closed validation ──\n";
$denied = function (string $label, callable $fn) use ($ok): void {
    try { $fn(); $ok($label . ' → rejected', false); }
    catch (QueryError $e) { $ok($label, true); }
};
$denied('unknown operator', fn () => $agg(['aggregate' => [['op' => 'median', 'field' => 'amount', 'as' => 'm']]]));
$denied('unexposed field', fn () => $agg(['aggregate' => [['op' => 'sum', 'field' => 'nope', 'as' => 'x']]]));
$denied('field not in projection (ssn)', fn () => $agg(['aggregate' => [['op' => 'sum', 'field' => 'ssn', 'as' => 'x']]]));
$denied('sum without field', fn () => $agg(['aggregate' => [['op' => 'sum', 'as' => 'x']]]));
$denied('missing alias (as)', fn () => $agg(['aggregate' => [['op' => 'count']]]));
$denied('duplicate alias', fn () => $agg(['aggregate' => [['op' => 'count', 'as' => 'n'], ['op' => 'sum', 'field' => 'amount', 'as' => 'n']]]));
$denied('type incompatible (sum on string)', fn () => $agg(['aggregate' => [['op' => 'sum', 'field' => 'title', 'as' => 'x']]]));
$denied('aggregate not a list', fn () => $agg(['aggregate' => 'count']));
$denied('empty aggregate list', fn () => $agg(['aggregate' => []]));

echo "\n" . ($fail === 0 ? "L4 PROJECTION AGGREGATION OK — {$pass} checks passed\n" : "FAILED ({$fail})\n");
exit($fail === 0 ? 0 : 1);
