<?php

declare(strict_types=1);

/**
 * L3 — Projection Query over the HTTP API.
 *
 * Exercises the flat query-string encoding through RuntimeApi::dispatch():
 * shorthand eq, explicit where (AND), orderBy, limit/offset, and the
 * fail-closed 400 for malformed queries. The runtime semantics are covered by
 * packages/entity-engine/tests/projection-query-test.php.
 */

$autoload = null;
foreach (['/../vendor/autoload.php', '/../../../vendor/autoload.php'] as $rel) {
    if (file_exists(__DIR__ . $rel)) { $autoload = __DIR__ . $rel; break; }
}
require $autoload;

use Ausus\Authoring\Dsl\Definition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;
use Ausus\Engine\Compile\Compiler;
use Ausus\Engine\Repository\InMemorySchemaRepository;
use Ausus\Engine\Runtime\DefaultAuthorizationEvaluator;
use Ausus\Engine\Runtime\DefaultEntityEngine;
use Ausus\Persistence\Memory\MemoryDriver;
use Ausus\Api\Runtime\Http\RequestContextFactory;
use Ausus\Api\Runtime\Http\RuntimeApi;

$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

$task = Definition::make('task', true)
    ->field('title', FieldType::String)
    ->field('priority', FieldType::Integer)
    ->field('status', FieldType::Enum, ['default' => 'open', 'typeOptions' => ['values' => ['open', 'done']]])
    ->action('create', ActionKind::Create, ['inputs' => ['title', 'priority', 'status']])
    ->projection('list', ['fields' => [['field' => 'title'], ['field' => 'priority'], ['field' => 'status']]])
    ->build();

$repo = new InMemorySchemaRepository();
foreach ((new Compiler())->compile([$task])->schemas as $s) { $repo->putByHash($s); }
$engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$driver = new MemoryDriver();
$factory = new RequestContextFactory(new DateTimeImmutable('@1700000000'));
$h = ['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user'];
$ctx = $factory->fromHeaders($h);

foreach ([
    ['title' => 'A', 'priority' => 1, 'status' => 'open'],
    ['title' => 'B', 'priority' => 3, 'status' => 'open'],
    ['title' => 'C', 'priority' => 2, 'status' => 'done'],
] as $row) { $engine->bind($repo->resolve('task'), $driver)->invoke('create', $row, $ctx); }

$api = new RuntimeApi($repo, $engine, $driver, $factory);
$get = fn (array $query) => $api->dispatch('GET', '/api/entities/task/projections/list', $h, $query);
$titles = function (array $res): array { $t = array_map(fn ($r) => $r['title'], $res['body']['rows']); sort($t); return $t; };

echo "── HTTP query encoding ──\n";
$ok('no params → 200, 3 rows (backward compatible)', $get([])['status'] === 200 && count($get([])['body']['rows']) === 3);
$ok('shorthand ?status=open → A,B', $titles($get(['status' => 'open'])) === ['A', 'B']);
$ok('explicit ?where=status:eq:done → C', $titles($get(['where' => 'status:eq:done'])) === ['C']);
$ok('where AND ?where=status:eq:open,priority:gte:3 → B', $titles($get(['where' => 'status:eq:open,priority:gte:3'])) === ['B']);
$desc = array_map(fn ($r) => $r['priority'], $get(['orderBy' => 'priority:desc'])['body']['rows']);
$ok('?orderBy=priority:desc → [3,2,1]', $desc === [3, 2, 1]);
$ok('?limit=2 → 2 rows', count($get(['limit' => '2'])['body']['rows']) === 2);
$page = array_map(fn ($r) => $r['priority'], $get(['sort' => 'priority:asc', 'offset' => '1', 'limit' => '1'])['body']['rows']);
$ok('?sort=priority:asc&offset=1&limit=1 → [2]', $page === [2]);

echo "── Fail-closed → 400 ──\n";
$ok('unknown field ?nope=x → 400', $get(['nope' => 'x'])['status'] === 400);
$ok('unknown operator ?where=status:like:x → 400', $get(['where' => 'status:like:x'])['status'] === 400);
$ok('bad limit ?limit=-1 → 400', $get(['limit' => '-1'])['status'] === 400);
$ok('200 OK still returns rows envelope', isset($get(['status' => 'open'])['body']['rows']));

echo "\n" . ($fail === 0 ? "L3 HTTP QUERY OK — {$pass} checks passed\n" : "FAILED ({$fail})\n");
exit($fail === 0 ? 0 : 1);
