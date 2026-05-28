<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Application, ApplicationConfig, Dsl, DslPlugin, Field, Action};

/**
 * AUSUS — pagination support (v0.2 beta.1 prep).
 *
 * Exercises the new `?limit` / `?offset` projection contract end to end:
 *   1. wire shape carries `limit`, `offset`, `totalCount`, `pageSize`;
 *   2. ordering is stable across pages (same query → same items);
 *   3. limit boundary (`limit = totalCount`, `limit = 1`, `limit > totalCount`);
 *   4. offset beyond `totalCount` returns an empty items list, not 404;
 *   5. defaults (no `?limit` / `?offset`) match the documented API surface.
 *
 * The fixture plugin creates 7 rows with deterministic identifiers so every
 * pagination window is byte-comparable to the expectation.
 */

$BANNER = "═══ AUSUS — pagination support ═════════════════════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

// ── Fixture plugin: a 'row' entity with a single 'tag' column ───────────────
final class PaginationPlugin extends DslPlugin {
    public function name(): string         { return 'pgn'; }
    public function phpNamespace(): string { return 'PaginationPlugin'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('row')
            ->fields([
                'tag'   => Field::string()->max(20),
                'state' => Field::enum('NEW', 'DONE')->default('NEW'),
            ])
            ->actions([
                'create' => Action::create('tag')->requireRole('pgn.writer'),
            ])
            ->workflow(field: 'state', initial: 'NEW')
            ->projection('all', fields: ['id', 'tag', 'state']);
    }
}

$dbPath = sys_get_temp_dir() . '/ausus-pagination.sqlite';
@unlink($dbPath);

$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$app = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')
            ->actorId('seed')
            ->roles(['pgn.writer'])
            ->pdo($pdo)
    )
    ->register(new PaginationPlugin())
    ->boot();

// Seed 7 rows in a controlled order. The Ulid-based id will preserve insertion
// order under the deterministic `ORDER BY id` (Ulids are monotonic within a
// single process), so `tag` values are predictable per page.
for ($i = 1; $i <= 7; $i++) {
    $app->invoke('pgn.row.create', null, ['tag' => sprintf('tag-%02d', $i)]);
}

// ── 1. wire shape — every key present, correct types ─────────────────────────
$schema = $app->renderProjection('pgn.row.all');
_assert('wire schemaVersion = 1.1.0', ($schema['schemaVersion'] ?? null) === '1.1.0');
$pag = $schema['data']['pagination'] ?? null;
_assert('wire has data.pagination object',           is_array($pag));
_assert('pagination.limit is int',                   is_int($pag['limit'] ?? null));
_assert('pagination.offset is int',                  is_int($pag['offset'] ?? null));
_assert('pagination.totalCount is int',              is_int($pag['totalCount'] ?? null));
_assert('pagination.pageSize is int',                is_int($pag['pageSize'] ?? null));
_assert('pagination.nextCursor reserved (null)',
    array_key_exists('nextCursor', $pag) && $pag['nextCursor'] === null);

// Default behaviour (no explicit limit/offset).
_assert('default totalCount counts every row',       ($pag['totalCount'] ?? -1) === 7);
_assert('default limit = 50',                        ($pag['limit'] ?? -1) === 50);
_assert('default offset = 0',                        ($pag['offset'] ?? -1) === 0);
_assert('default pageSize matches items',            ($pag['pageSize'] ?? -1) === count($schema['data']['items']));

// ── 2. stable ordering — page 1 (limit=3, offset=0) ──────────────────────────
// "Stable" means: the same query returns the same items in the same order on
// every call. The tag↔id mapping is not asserted (Ulids are monotonic at
// millisecond resolution; a tight insert loop can land two Ids inside the
// same ms with a randomness-driven local re-ordering, which is fine —
// pagination only promises consistency, not tag-lexicographic ordering).
$page1 = $app->renderProjection('pgn.row.all', limit: 3, offset: 0);
$tags1 = array_column($page1['data']['items'], 'tag');
_assert('page1 has 3 items',                         count($tags1) === 3);
$page1Bis = $app->renderProjection('pgn.row.all', limit: 3, offset: 0);
_assert('page1 stable across calls (same order)',    $tags1 === array_column($page1Bis['data']['items'], 'tag'));
_assert('page1 totalCount unchanged by window',      ($page1['data']['pagination']['totalCount'] ?? -1) === 7);

// ── 3. stable ordering — page 2 (limit=3, offset=3) ──────────────────────────
$page2 = $app->renderProjection('pgn.row.all', limit: 3, offset: 3);
$tags2 = array_column($page2['data']['items'], 'tag');
_assert('page2 has 3 items',                         count($tags2) === 3);
$page2Bis = $app->renderProjection('pgn.row.all', limit: 3, offset: 3);
_assert('page2 stable across calls',                 $tags2 === array_column($page2Bis['data']['items'], 'tag'));
_assert('page2 no overlap with page1',               array_intersect($tags1, $tags2) === []);

// ── 4. stable ordering — page 3 partial (limit=3, offset=6) ──────────────────
$page3 = $app->renderProjection('pgn.row.all', limit: 3, offset: 6);
$tags3 = array_column($page3['data']['items'], 'tag');
_assert('page3 has 1 item (boundary)',               count($tags3) === 1);
_assert('page3 no overlap with page1 or page2',
    array_intersect($tags3, array_merge($tags1, $tags2)) === []);
_assert('page3 pageSize reports 1',                  ($page3['data']['pagination']['pageSize'] ?? -1) === 1);

// ── 5. limit boundary — limit equals totalCount ──────────────────────────────
$full = $app->renderProjection('pgn.row.all', limit: 7, offset: 0);
_assert('limit=totalCount returns every row',        count($full['data']['items']) === 7);
_assert('limit=totalCount pageSize = 7',             ($full['data']['pagination']['pageSize'] ?? -1) === 7);

// ── 6. limit boundary — limit > totalCount ───────────────────────────────────
$over = $app->renderProjection('pgn.row.all', limit: 100, offset: 0);
_assert('limit>totalCount returns every row',        count($over['data']['items']) === 7);
_assert('limit>totalCount totalCount unchanged',     ($over['data']['pagination']['totalCount'] ?? -1) === 7);

// ── 7. offset > totalCount — empty items, NOT error ──────────────────────────
$past = $app->renderProjection('pgn.row.all', limit: 5, offset: 50);
_assert('offset>totalCount returns empty items',     $past['data']['items'] === []);
_assert('offset>totalCount totalCount unchanged',    ($past['data']['pagination']['totalCount'] ?? -1) === 7);
_assert('offset>totalCount pageSize = 0',            ($past['data']['pagination']['pageSize'] ?? -1) === 0);

// ── 8. limit = 1 (smallest legal value) ──────────────────────────────────────
$one = $app->renderProjection('pgn.row.all', limit: 1, offset: 0);
_assert('limit=1 returns exactly one item',          count($one['data']['items']) === 1);
$oneBis = $app->renderProjection('pgn.row.all', limit: 1, offset: 0);
_assert('limit=1 stable across calls',
    $one['data']['items'][0]['tag'] === $oneBis['data']['items'][0]['tag']);

// ── 9. internal renderer clamps — protect against direct misuse ──────────────
$clamped = $app->renderProjection('pgn.row.all', limit: -5, offset: -10);
_assert('limit clamped from -5 to 1',                ($clamped['data']['pagination']['limit'] ?? -1) === 1);
_assert('offset clamped from -10 to 0',              ($clamped['data']['pagination']['offset'] ?? -1) === 0);

// ── 10. cross-page consistency — concat covers every row exactly once ────────
$allTags = array_merge($tags1, $tags2, $tags3);
sort($allTags);
$expected = ['tag-01','tag-02','tag-03','tag-04','tag-05','tag-06','tag-07'];
_assert('pages 1+2+3 cover every row exactly once',  $allTags === $expected);

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed=$passed failed=$failed\n";
echo "{$BANNER}\n";
exit($failed === 0 ? 0 : 1);
