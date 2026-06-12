<?php
declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Ausus\{StubActor, ActorRef};
use Ausus\ApplicationConfig;

/**
 * RFC-018 Phase 2 — actor attribute resolution (R-2).
 *
 * Scope: ONLY actor-attribute carrying/resolution. No data-aware authorization
 * is wired here (no FactResolver / GuardComposer / requireThat).
 */
$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

echo "── StubActor.attribute() ───────────────────────────────────\n";
$a = new StubActor(new ActorRef('user', 'maya', 'acme'), ['claims.adjuster'], [], ['authority_limit' => 10000, 'department' => 'claims']);
$ok('attribute(authority_limit) === 10000', $a->attribute('authority_limit') === 10000);
$ok('attribute(department) === "claims"',    $a->attribute('department') === 'claims');
$ok('attribute(absent) === null',            $a->attribute('nope') === null);

echo "── roleHash() unaffected by attributes (cache invariance) ──\n";
$noAttr = new StubActor(new ActorRef('user', 'maya', 'acme'), ['claims.adjuster']);
$ok('roleHash identical with/without attributes', $a->roleHash() === $noAttr->roleHash());

echo "── constructor backward-compatibility ──────────────────────\n";
$r2 = new StubActor(new ActorRef('user', 'x', 'acme'), ['r']);
$r3 = new StubActor(new ActorRef('user', 'x', 'acme'), ['r'], ['p']);
$ok('2-arg ctor → attribute() = null', $r2->attribute('k') === null);
$ok('3-arg ctor → attribute() = null', $r3->attribute('k') === null);

echo "── HTTP X-Actor-Attributes fail-safe (replicated parse) ────\n";
$parse = static function (string $raw): array {
    $out = [];
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                if ($v === null || is_int($v) || is_string($v) || is_float($v) || is_bool($v)) {
                    $out[(string) $k] = $v;
                }
            }
        }
    }
    return $out;
};
$ok('valid JSON object → parsed',     $parse('{"authority_limit":10000}') === ['authority_limit' => 10000]);
$ok('absent header ("") → []',        $parse('') === []);
$ok('invalid JSON → []',              $parse('{not json') === []);
$ok('non-object JSON (array) kept-scalar-only', $parse('[1,2,3]') === ['0' => 1, '1' => 2, '2' => 3] || $parse('[1,2,3]') === [0=>1,1=>2,2=>3]);
$ok('non-scalar values dropped',      $parse('{"a":1,"b":{"x":2},"c":"ok"}') === ['a' => 1, 'c' => 'ok']);

echo "── ApplicationConfig default actor attributes ──────────────\n";
$cfg = ApplicationConfig::make()
    ->actorId('maya')->roles(['claims.adjuster'])
    ->actorAttributes(['authority_limit' => 10000, 'bad' => ['nested' => 1]])
    ->sqlite(':memory:');
$arr = $cfg->toArray();
$ok('toArray() carries actorAttributes (scalar only)', ($arr['actorAttributes'] ?? null) === ['authority_limit' => 10000]);

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed={$pass} failed={$fail}\n";
exit($fail === 0 ? 0 : 1);
