<?php

declare(strict_types=1);

/**
 * L4 — Projection Aggregations over the HTTP API.
 *
 * Exercises the `aggregate` query-string clause through RuntimeApi::dispatch():
 * the { rows, aggregates } envelope, where + aggregate, backward compatibility
 * (no aggregate ⇒ rows-only, no `aggregates` key), and the fail-closed 400.
 * Runtime semantics are covered by entity-engine/tests/projection-aggregation-test.php.
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
    ->field('amount', FieldType::Integer)
    ->field('status', FieldType::Enum, ['default' => 'open', 'typeOptions' => ['values' => ['open', 'done']]])
    ->action('create', ActionKind::Create, ['inputs' => ['amount', 'status']])
    ->projection('list', ['fields' => [['field' => 'amount'], ['field' => 'status']]])
    ->build();

$repo = new InMemorySchemaRepository();
foreach ((new Compiler())->compile([$task])->schemas as $s) { $repo->putByHash($s); }
$engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$driver = new MemoryDriver();
$factory = new RequestContextFactory(new DateTimeImmutable('@1700000000'));
$h = ['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user'];
$ctx = $factory->fromHeaders($h);

foreach ([
    ['amount' => 100, 'status' => 'open'],
    ['amount' => 300, 'status' => 'open'],
    ['amount' => 200, 'status' => 'done'],
] as $row) { $engine->bind($repo->resolve('task'), $driver)->invoke('create', $row, $ctx); }

$api = new RuntimeApi($repo, $engine, $driver, $factory);
$get = fn (array $query) => $api->dispatch('GET', '/api/entities/task/projections/list', $h, $query);

echo "── Aggregate envelope ──\n";
$r = $get(['aggregate' => 'count:total,sum:amount:revenue,avg:amount:av,min:amount:lo,max:amount:hi']);
$ok('200 with rows + aggregates', $r['status'] === 200 && isset($r['body']['rows'], $r['body']['aggregates']));
$ok('count → 3', ($r['body']['aggregates']['total'] ?? null) === 3);
$ok('sum amount → 600', ($r['body']['aggregates']['revenue'] ?? null) === 600);
$ok('avg amount → 200', ($r['body']['aggregates']['av'] ?? null) === 200.0);
$ok('min amount → 100', ($r['body']['aggregates']['lo'] ?? null) === 100);
$ok('max amount → 300', ($r['body']['aggregates']['hi'] ?? null) === 300);
$ok('rows still present (3)', count($r['body']['rows']) === 3);

echo "── where + aggregate ──\n";
$w = $get(['where' => 'status:eq:open', 'aggregate' => 'count:n,sum:amount:rev']);
$ok('count(open) → 2', ($w['body']['aggregates']['n'] ?? null) === 2);
$ok('sum(open.amount) → 400', ($w['body']['aggregates']['rev'] ?? null) === 400);

echo "── Backward compatibility ──\n";
$plain = $get([]);
$ok('no aggregate → 200', $plain['status'] === 200);
$ok('no aggregate → rows present', isset($plain['body']['rows']) && count($plain['body']['rows']) === 3);
$ok('no aggregate → NO aggregates key', !array_key_exists('aggregates', $plain['body']));

echo "── Fail-closed → 400 ──\n";
$ok('unknown op ?aggregate=median:amount:x → 400', $get(['aggregate' => 'median:amount:x'])['status'] === 400);
$ok('unexposed field ?aggregate=sum:nope:x → 400', $get(['aggregate' => 'sum:nope:x'])['status'] === 400);
$ok('sum without field ?aggregate=sum:x → 400', $get(['aggregate' => 'sum:x'])['status'] === 400);

echo "\n" . ($fail === 0 ? "L4 HTTP AGGREGATION OK — {$pass} checks passed\n" : "FAILED ({$fail})\n");
exit($fail === 0 ? 0 : 1);
