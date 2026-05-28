<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\Sort;

/**
 * AUSUS — sorting primitives (value-object layer).
 *
 * Pins the contract of the `Ausus\Sort` value object that the SQL adapter
 * relies on. A malformed Sort cannot ever reach the ORDER BY translator —
 * every defect is rejected at construction time.
 *
 * Coverage:
 *   1. asc / desc construction
 *   2. case-sensitivity on direction (the SQL layer pattern-matches exactly)
 *   3. empty field rejected
 *   4. empty direction rejected
 *   5. unknown direction rejected
 *   6. Sort::DIRS constant matches actual allowed values
 *   7. readonly: properties are not mutable after construction
 */

$BANNER = "═══ AUSUS — sorting primitives (value object) ══════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

// ── 1-2. happy path ─────────────────────────────────────────────────────────
$asc  = new Sort('createdAt', Sort::DIR_ASC);
$desc = new Sort('createdAt', Sort::DIR_DESC);
_assert('asc constructor accepts DIR_ASC',                  $asc->direction === 'asc');
_assert('desc constructor accepts DIR_DESC',                $desc->direction === 'desc');
_assert('field preserved verbatim',                         $asc->field === 'createdAt');

// ── 3. case sensitivity on direction ────────────────────────────────────────
$caught = false;
try { new Sort('createdAt', 'ASC'); }
catch (InvalidArgumentException $e) { $caught = true; }
_assert('uppercase "ASC" rejected (lower-case enum)',       $caught);

$caught = false;
try { new Sort('createdAt', 'Desc'); }
catch (InvalidArgumentException $e) { $caught = true; }
_assert('mixed-case "Desc" rejected',                       $caught);

// ── 4. empty field ──────────────────────────────────────────────────────────
$caught = false;
try { new Sort('', Sort::DIR_ASC); }
catch (InvalidArgumentException $e) { $caught = str_contains($e->getMessage(), 'not be empty'); }
_assert('empty field rejected with descriptive message',    $caught);

// ── 5. unknown direction ────────────────────────────────────────────────────
$caught = false;
try { new Sort('id', 'random'); }
catch (InvalidArgumentException $e) {
    $caught = str_contains($e->getMessage(), 'random')
           && str_contains($e->getMessage(), 'allowed:');
}
_assert('unknown direction rejected with allowed list',     $caught);

// ── 6. DIRS constant matches the only two accepted values ───────────────────
_assert('Sort::DIRS = [asc, desc]',                         Sort::DIRS === [Sort::DIR_ASC, Sort::DIR_DESC]);

// ── 7. readonly enforcement ─────────────────────────────────────────────────
$ro = false;
try { $asc->direction = 'desc'; }
catch (Error $e) { $ro = str_contains($e->getMessage(), 'readonly') || str_contains($e->getMessage(), 'Cannot modify'); }
_assert('readonly direction is immutable',                  $ro);

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed=$passed failed=$failed\n";
echo "{$BANNER}\n";
exit($failed === 0 ? 0 : 1);
