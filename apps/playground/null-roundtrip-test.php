<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Application, ApplicationConfig, Dsl, DslPlugin, Field, Action};

/**
 * AUSUS — null-serialisation roundtrip (regression test).
 *
 * Reproduces the v0.1.x bug in `SqliteRepository::serializeField()` where an
 * explicit PHP null was routed through `json_encode(null)` (because
 * `is_scalar(null)` is false), producing the 4-character string `"null"` on
 * disk. The patch adds a guard:
 *
 *     if ($value === null) return null;
 *
 * before any per-type coercion. This test asserts the full nullable contract:
 *   1. write path        — serializeField(null) returns null
 *   2. SQL persistence   — the column is SQL NULL (verifiable via raw SQL)
 *   3. runtime read-back — Repository::find() returns null on the field
 *   4. JSON projection   — the rendered ViewSchema carries null, not "null"
 *
 * Plus a regression block that confirms non-null values of every scalar
 * type still round-trip correctly.
 */

$BANNER = "═══ AUSUS — null-roundtrip regression ══════════════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

// A fixture plugin that exercises every nullable scalar branch of
// serializeField, plus one non-nullable enum to keep the workflow happy.
final class NullableTypesPlugin extends DslPlugin {
    public function name(): string         { return 'nulltypes'; }
    public function phpNamespace(): string { return 'NullTypes'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('row')
            ->fields([
                'tag'   => Field::string()->max(40)->nullable(),
                'count' => Field::integer()->nullable(),
                'when'  => Field::datetime()->nullable(),
                'kind'  => Field::enum('A', 'B', 'C')->nullable(),
                'price' => Field::money()->currency('USD')->nullable(),
                'state' => Field::enum('NEW', 'DONE')->default('NEW'),   // workflow anchor
            ])
            ->actions([
                'create' => Action::create('tag', 'count', 'when', 'kind', 'price')
                              ->requireRole('null.writer'),
            ])
            ->workflow(field: 'state', initial: 'NEW')
            ->projection(
                'all',
                fields:  ['id', 'tag', 'count', 'when', 'kind', 'price', 'state'],
                actions: ['create'],
                role:    'null.viewer',
            );
    }
}

$app = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')
            ->roles(['null.writer', 'null.viewer'])
    )
    ->register(new NullableTypesPlugin())
    ->boot();

// ── 1. write path — explicit nulls on every nullable scalar type ─────────────
echo "\n── 1. create with every nullable field = null ───────────────\n";
$nullRow = $app->run('nulltypes.row.create', null, [
    'tag'   => null,
    'count' => null,
    'when'  => null,
    'kind'  => null,
    'price' => null,
]);
$id = $nullRow->id();
_assert('outputs.tag   is PHP null (was the string "null" before the fix)',
        $nullRow->outputs['tag']   === null);
_assert('outputs.count is PHP null (was 0 before the fix)',
        $nullRow->outputs['count'] === null);
_assert('outputs.when  is PHP null (was "" before the fix)',
        $nullRow->outputs['when']  === null);
_assert('outputs.kind  is PHP null',
        $nullRow->outputs['kind']  === null);
_assert('outputs.price is PHP null (was ["amount"=>"","currency"=>"USD"] before the fix)',
        $nullRow->outputs['price'] === null);
_assert('non-nullable enum still seeds default (state=NEW)',
        $nullRow->outputs['state'] === 'NEW');

// ── 2. SQL persistence — each column is genuinely SQL NULL ──────────────────
echo "\n── 2. SQL persistence — columns are SQL NULL on disk ────────\n";
$pdo = $app->pdo();
// SchemaDeriver quotes column names, so `when` (a SQLite-reserved word) lives
// as the column literally named `"when"`. Quote every column to be safe.
$stmt = $pdo->prepare(
    'SELECT "tag" IS NULL  AS tag_n,
            "count" IS NULL AS count_n,
            "when" IS NULL  AS when_n,
            "kind" IS NULL  AS kind_n,
            "price" IS NULL AS price_n,
            "tag", "count", "when", "kind", "price"
       FROM "nulltypes_row" WHERE id = :id'
);
$stmt->execute(['id' => $id]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);
_assert('SQL: "tag" IS NULL',   (int) $row['tag_n']   === 1, 'literal=' . var_export($row['tag'], true));
_assert('SQL: "count" IS NULL', (int) $row['count_n'] === 1, 'literal=' . var_export($row['count'], true));
_assert('SQL: "when" IS NULL',  (int) $row['when_n']  === 1, 'literal=' . var_export($row['when'], true));
_assert('SQL: "kind" IS NULL',  (int) $row['kind_n']  === 1, 'literal=' . var_export($row['kind'], true));
_assert('SQL: "price" IS NULL', (int) $row['price_n'] === 1, 'literal=' . var_export($row['price'], true));

// And confirm the pre-fix corruption is gone — no column equals the literal
// string "null", "0", or "" for what should be SQL NULL.
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS c FROM "nulltypes_row"
      WHERE id = :id
        AND ("tag" = \'null\' OR "count" = \'0\' OR "when" = \'\' OR "kind" = \'null\' OR "price" = \'\')'
);
$stmt->execute(['id' => $id]);
_assert('SQL: no column carries the pre-fix corruption sentinel ("null"/"0"/"")',
        (int) $stmt->fetch(\PDO::FETCH_ASSOC)['c'] === 0);

// ── 3. runtime read-back via Repository::find() ──────────────────────────────
echo "\n── 3. runtime read-back via Repository::find() ──────────────\n";
$ref = $app->reference('nulltypes.row', $id);
$tx  = $app->driver()->beginTransaction($app->tenant());
$entity = $app->driver()->context($app->tenant(), $tx)->repository('nulltypes.row')->find($ref);
$app->driver()->commit($tx);

_assert('find().fields.tag   is PHP null',
        array_key_exists('tag', $entity->fields) && $entity->fields['tag']   === null);
_assert('find().fields.count is PHP null',
        array_key_exists('count', $entity->fields) && $entity->fields['count'] === null);
_assert('find().fields.when  is PHP null',
        array_key_exists('when', $entity->fields) && $entity->fields['when']  === null);
_assert('find().fields.kind  is PHP null',
        array_key_exists('kind', $entity->fields) && $entity->fields['kind']  === null);

// ── 4. JSON projection — wire-format carries real null ──────────────────────
echo "\n── 4. JSON projection wire format ───────────────────────────\n";
$schema = $app->render('nulltypes.row.all');
$item = null;
foreach ($schema['data']['items'] as $row) {
    if ($row['id'] === $id) { $item = $row; break; }
}
_assert('projection item exists for our row',     $item !== null);
_assert('projection.tag   is PHP null',           $item['tag']   === null);
_assert('projection.count is PHP null',           $item['count'] === null);
_assert('projection.when  is PHP null',           $item['when']  === null);
_assert('projection.kind  is PHP null',           $item['kind']  === null);

// json_encode preserves PHP null as JSON `null`; the bug previously produced
// the JSON string `"null"`. Verify the wire format explicitly.
$json = json_encode($item, JSON_UNESCAPED_SLASHES);
_assert('wire JSON contains literal `null`, not the string "null"',
        str_contains($json, '"tag":null') && str_contains($json, '"count":null')
        && str_contains($json, '"when":null') && str_contains($json, '"kind":null'));
_assert('wire JSON does NOT contain "tag":"null" (the pre-fix bug)',
        !str_contains($json, '"tag":"null"'));

// ── 5. mixed payload — non-null values still round-trip cleanly ──────────────
echo "\n── 5. regression: non-null values still serialise correctly ─\n";
$mixed = $app->run('nulltypes.row.create', null, [
    'tag'   => 'hello',
    'count' => 42,
    'when'  => '2026-05-25T12:34:56Z',
    'kind'  => 'B',
    'price' => ['amount' => '99.50', 'currency' => 'USD'],
]);
_assert('non-null tag   round-trips as the string "hello"',
        $mixed->outputs['tag']   === 'hello');
_assert('non-null count round-trips as the integer 42',
        $mixed->outputs['count'] === 42);
_assert('non-null when  round-trips as the datetime string',
        $mixed->outputs['when']  === '2026-05-25T12:34:56Z');
_assert('non-null kind  round-trips as the enum string "B"',
        $mixed->outputs['kind']  === 'B');
_assert('non-null price round-trips as the {amount, currency} tuple',
        is_array($mixed->outputs['price'])
        && $mixed->outputs['price']['amount']   === '99.50'
        && $mixed->outputs['price']['currency'] === 'USD');

// ── 6. mixed payload with SOME nulls — partial nullability is fine ──────────
echo "\n── 6. partial-null payload mixes cleanly ────────────────────\n";
$partial = $app->run('nulltypes.row.create', null, [
    'tag'   => 'partial',
    'count' => null,
    'when'  => '2026-01-01T00:00:00Z',
    'kind'  => null,
    'price' => ['amount' => '1.00', 'currency' => 'USD'],
]);
_assert('partial: tag non-null, count null, when non-null, kind null, price non-null',
        $partial->outputs['tag']  === 'partial'
        && $partial->outputs['count'] === null
        && $partial->outputs['when']  === '2026-01-01T00:00:00Z'
        && $partial->outputs['kind']  === null
        && is_array($partial->outputs['price'])
        && $partial->outputs['price']['amount'] === '1.00');

// ── 7. existing nullable datetime keeps working (HelloInvoice issued_at) ────
// `Field::datetime()->nullable()` is the most common nullable in the existing
// codebase. The HelloInvoice DSL has `issued_at` nullable; verify the fix
// did not regress reading back a freshly-created invoice via Repository::find.
echo "\n── 7. existing nullable datetime (HelloInvoice issued_at) ───\n";
$hi = Application::create(
        ApplicationConfig::make()->tenant('acme')->roles([
            'invoice.creator','invoice.issuer','invoice.canceler','invoice.viewer',
        ])
    )
    ->register(new \Acme\Billing\HelloInvoiceDsl())
    ->boot();
$inv = $hi->run('billing.invoice.create', null, [
    'number' => 'INV-NULL-001', 'customer_name' => 'Null Test',
    'amount' => ['amount' => '10.00', 'currency' => 'USD'],
]);
$hiTx = $hi->driver()->beginTransaction($hi->tenant());
$hiEntity = $hi->driver()->context($hi->tenant(), $hiTx)
    ->repository('billing.invoice')->find($inv->subject);
$hi->driver()->commit($hiTx);
_assert('HelloInvoice: find().fields.issued_at is PHP null on a fresh create',
        array_key_exists('issued_at', $hiEntity->fields)
        && $hiEntity->fields['issued_at'] === null);

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed={$passed} failed={$failed}\n";
echo "{$BANNER}\n";

exit($failed > 0 ? 1 : 0);
