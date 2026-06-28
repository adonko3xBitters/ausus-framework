<?php
declare(strict_types=1);

$autoload = [
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];
foreach ($autoload as $f) {
    if (file_exists($f)) {
        require $f;
        break;
    }
}

/**
 * IMPLEMENTATION-004 — View System metadata (Tests 1–5 + structural checks).
 */

use Ausus\View\PageDefinition;
use Ausus\View\SectionDefinition;
use Ausus\View\ViewDefinition;
use Ausus\View\ViewRegistry;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

echo "── Test 3 — SectionDefinition projection ───────────────────\n";
$board = SectionDefinition::projection('Customers', 'customer', 'board');
$ok('Test 3 — kind projection', $board->kind() === 'projection' && $board->projection === 'board' && $board->action === null);
$ok('Test 3 — bound to entity', $board->entity === 'customer' && $board->title === 'Customers');

echo "── Test 4 — SectionDefinition action ───────────────────────\n";
$create = SectionDefinition::action('Create Customer', 'customer', 'create');
$ok('Test 4 — kind action', $create->kind() === 'action' && $create->action === 'create' && $create->projection === null);

echo "── Structural — a section is never both ────────────────────\n";
$ok('exactly one of projection/action set (projection)', ($board->projection !== null) !== ($board->action !== null));
$ok('exactly one of projection/action set (action)', ($create->projection !== null) !== ($create->action !== null));

echo "── Test 2 — PageDefinition ─────────────────────────────────\n";
$page = new PageDefinition('customers', 'Customers', [$board, $create]);
$ok('Test 2 — identity/title/sections', $page->identity === 'customers' && $page->title === 'Customers' && count($page->sections) === 2);

echo "── Test 1 — ViewDefinition (Customer View, no React) ───────\n";
$view = new ViewDefinition('customer-view', 'Customers', [$page]);
$ok('Test 1 — identity/title/pages', $view->identity === 'customer-view' && $view->title === 'Customers' && count($view->pages) === 1);

// toArray / JSON shape consumed by the Renderer
$json = $view->toArray();
$ok('toArray — section kinds serialised',
    $json['pages'][0]['sections'][0]['kind'] === 'projection'
    && $json['pages'][0]['sections'][0]['projection'] === 'board'
    && $json['pages'][0]['sections'][1]['kind'] === 'action'
    && $json['pages'][0]['sections'][1]['action'] === 'create');
$ok('toArray — JSON-serialisable', json_encode($json) !== false);

echo "── Test 5 — navigation View → Pages ────────────────────────\n";
$invoiceView = new ViewDefinition('invoice-view', 'Invoices', [
    new PageDefinition('board', 'Board', [SectionDefinition::projection('All invoices', 'invoice', 'board')]),
    new PageDefinition('new', 'New invoice', [SectionDefinition::action('Create', 'invoice', 'create')]),
]);
$registry = new ViewRegistry();
$registry->register($view);
$registry->register($invoiceView);
$nav = $registry->navigation();
$ok('Test 5 — two views registered', count($nav) === 2);
$ok('Test 5 — invoice view → 2 pages', count($nav[1]['pages']) === 2
    && $nav[1]['pages'][0]['identity'] === 'board' && $nav[1]['pages'][1]['identity'] === 'new');
$ok('Test 5 — registry resolves by identity', $registry->get('customer-view')->identity === 'customer-view');
$ok('Test 5 — registry toArray has both views', count($registry->toArray()['views']) === 2);

echo "\n";
echo $fail === 0
    ? "IMPLEMENTATION-004 / ViewSystem OK — {$pass} checks passed\n"
    : "IMPLEMENTATION-004 / ViewSystem FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
