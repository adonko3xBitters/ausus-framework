<?php

declare(strict_types=1);

/**
 * L3 — Projection Query Language test suite.
 *
 * Exercises WHERE (all operators + AND/OR), ORDER BY, LIMIT/OFFSET, tenant
 * isolation, per-field visibility guards, the empty-params no-op (regression),
 * and fail-closed validation — over the real runtime + Memory driver.
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

// ── task entity: scalar fields + a visibility-guarded field ────────────────
$task = Definition::make('task', true)
    ->field('title', FieldType::String)
    ->field('priority', FieldType::Integer)
    ->field('status', FieldType::Enum, ['default' => 'open', 'typeOptions' => ['values' => ['open', 'done']]])
    ->field('assignee', FieldType::String, ['nullable' => true])
    ->field('secret', FieldType::String, ['nullable' => true])
    ->action('create', ActionKind::Create, ['inputs' => ['title', 'priority', 'status', 'assignee', 'secret']])
    ->projection('list', ['fields' => [
        ['field' => 'title'], ['field' => 'priority'], ['field' => 'status'], ['field' => 'assignee'],
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

// seed (tenant acme)
$seed = [
    ['title' => 'Alpha',    'priority' => 1, 'status' => 'open', 'assignee' => 'ana',  'secret' => 's1'],
    ['title' => 'Beta',     'priority' => 3, 'status' => 'open', 'assignee' => 'bob',  'secret' => 's2'],
    ['title' => 'Gamma',    'priority' => 2, 'status' => 'done', 'assignee' => 'ana',  'secret' => 's3'],
    ['title' => 'Alphabet', 'priority' => 5, 'status' => 'open'],                       // no assignee
    ['title' => 'Delta',    'priority' => 2, 'status' => 'done', 'assignee' => 'cara', 'secret' => 's5'],
];
foreach ($seed as $row) { $bind()->invoke('create', $row, $user); }
// one row in a different tenant (must never appear for acme)
$bind()->invoke('create', ['title' => 'Foreign', 'priority' => 9, 'status' => 'open'], $other);

$titles = function (array $rows): array {
    $t = array_map(fn ($r) => $r['title'], $rows); sort($t); return $t;
};
$read = fn (array $params, $ctx = null) => $bind()->read('list', $params, $ctx ?? $user);

echo "── Regression: empty params returns all (tenant acme) ──\n";
$ok('empty params → 5 rows', count($read([])) === 5);

echo "── Operators ──\n";
$ok('eq status=open → Alpha,Alphabet,Beta', $titles($read(['where' => [['field' => 'status', 'op' => 'eq', 'value' => 'open']]])) === ['Alpha', 'Alphabet', 'Beta']);
$ok('ne status=open → Delta,Gamma', $titles($read(['where' => [['field' => 'status', 'op' => 'ne', 'value' => 'open']]])) === ['Delta', 'Gamma']);
$ok('lt priority<3 → Alpha,Delta,Gamma', $titles($read(['where' => [['field' => 'priority', 'op' => 'lt', 'value' => 3]]])) === ['Alpha', 'Delta', 'Gamma']);
$ok('lte priority<=2 → Alpha,Delta,Gamma', $titles($read(['where' => [['field' => 'priority', 'op' => 'lte', 'value' => 2]]])) === ['Alpha', 'Delta', 'Gamma']);
$ok('gt priority>2 → Alphabet,Beta', $titles($read(['where' => [['field' => 'priority', 'op' => 'gt', 'value' => 2]]])) === ['Alphabet', 'Beta']);
$ok('gte priority>=3 → Alphabet,Beta', $titles($read(['where' => [['field' => 'priority', 'op' => 'gte', 'value' => 3]]])) === ['Alphabet', 'Beta']);
$ok('contains title~lph → Alpha,Alphabet', $titles($read(['where' => [['field' => 'title', 'op' => 'contains', 'value' => 'lph']]])) === ['Alpha', 'Alphabet']);
$ok('startsWith title=Alph → Alpha,Alphabet', $titles($read(['where' => [['field' => 'title', 'op' => 'startsWith', 'value' => 'Alph']]])) === ['Alpha', 'Alphabet']);
$ok('endsWith title=a → Alpha,Beta,Delta,Gamma', $titles($read(['where' => [['field' => 'title', 'op' => 'endsWith', 'value' => 'a']]])) === ['Alpha', 'Beta', 'Delta', 'Gamma']);
$ok('isNull assignee → Alphabet', $titles($read(['where' => [['field' => 'assignee', 'op' => 'isNull']]])) === ['Alphabet']);
$ok('isNotNull assignee → 4 rows', count($read(['where' => [['field' => 'assignee', 'op' => 'isNotNull']]])) === 4);

echo "── AND / OR ──\n";
$ok('AND status=open & priority>=3 → Alphabet,Beta', $titles($read(['where' => ['and' => [['field' => 'status', 'op' => 'eq', 'value' => 'open'], ['field' => 'priority', 'op' => 'gte', 'value' => 3]]]])) === ['Alphabet', 'Beta']);
$ok('OR status=done | priority=1 → Alpha,Delta,Gamma', $titles($read(['where' => ['or' => [['field' => 'status', 'op' => 'eq', 'value' => 'done'], ['field' => 'priority', 'op' => 'eq', 'value' => 1]]]])) === ['Alpha', 'Delta', 'Gamma']);

echo "── ORDER BY / pagination ──\n";
$asc = array_map(fn ($r) => $r['priority'], $read(['orderBy' => [['field' => 'priority', 'dir' => 'asc']]]));
$ok('orderBy priority asc', $asc === [1, 2, 2, 3, 5]);
$desc = array_map(fn ($r) => $r['priority'], $read(['orderBy' => [['field' => 'priority', 'dir' => 'desc']]]));
$ok('orderBy priority desc', $desc === [5, 3, 2, 2, 1]);
$ok('limit 2 → 2 rows', count($read(['limit' => 2])) === 2);
$page = array_map(fn ($r) => $r['priority'], $read(['orderBy' => [['field' => 'priority', 'dir' => 'asc']], 'offset' => 2, 'limit' => 2]));
$ok('offset 2 limit 2 (sorted) → [2,3]', $page === [2, 3]);
$combo = array_map(fn ($r) => $r['title'], $read(['where' => [['field' => 'status', 'op' => 'eq', 'value' => 'open']], 'orderBy' => [['field' => 'priority', 'dir' => 'desc']], 'limit' => 2]));
$ok('combo: open, priority desc, limit 2 → [Alphabet,Beta]', $combo === ['Alphabet', 'Beta']);

echo "── Tenant + guards ──\n";
$ok('tenant globex sees only its row', $titles($read([], $other)) === ['Foreign']);
$ok('tenant acme never sees Foreign', !in_array('Foreign', $titles($read([])), true));
$rowsUser = $read(['where' => [['field' => 'title', 'op' => 'eq', 'value' => 'Alpha']]], $user);
$rowsAdmin = $read(['where' => [['field' => 'title', 'op' => 'eq', 'value' => 'Alpha']]], $admin);
$ok('visibility: secret hidden from user', !array_key_exists('secret', $rowsUser[0]));
$ok('visibility: secret shown to admin', ($rowsAdmin[0]['secret'] ?? null) === 's1');
$ok('filter on visible field works for non-admin', count($rowsUser) === 1);

echo "── Fail-closed validation ──\n";
$denied = function (string $label, callable $fn) use ($ok): void {
    try { $fn(); $ok($label . ' → rejected', false); }
    catch (QueryError $e) { $ok($label, true); }
};
$denied('unknown operator', fn () => $read(['where' => [['field' => 'status', 'op' => 'like', 'value' => 'x']]]));
$denied('unknown field', fn () => $read(['where' => [['field' => 'nope', 'op' => 'eq', 'value' => 'x']]]));
$denied('field not exposed (secret IS exposed, ssn is not)', fn () => $read(['where' => [['field' => 'ssn', 'op' => 'eq', 'value' => 'x']]]));
$denied('unknown parameter', fn () => $read(['groupBy' => 'status']));
$denied('missing value', fn () => $read(['where' => [['field' => 'status', 'op' => 'eq']]]));
$denied('bad sort direction', fn () => $read(['orderBy' => [['field' => 'priority', 'dir' => 'sideways']]]));
$denied('negative limit', fn () => $read(['limit' => -1]));
$denied('sort by unexposed field', fn () => $read(['orderBy' => [['field' => 'ssn', 'dir' => 'asc']]]));

echo "\n" . ($fail === 0 ? "L3 PROJECTION QUERY OK — {$pass} checks passed\n" : "FAILED ({$fail})\n");
exit($fail === 0 ? 0 : 1);
