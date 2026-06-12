<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Application, Compiler, DslPlugin, Dsl, Field, Action, Reference, Subject};
use Ausus\ReferentialIntegrityViolation;

/**
 * RFC-015 — Entity Relations & Referential Integrity — acceptance tests.
 *
 * Proves the RFC-015 success criteria against the real runtime:
 *   - ghost references are impossible (compile-time AND write-time);
 *   - related entities can be expanded in projections;
 *   - Reference and Subject are one canonical identity value object.
 *
 * Run: php apps/playground/relations-test.php   (exit 0 on success)
 */

$BANNER = "═══ RFC-015 — relations & referential integrity ════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

// ── Fixture: project ← issue.project_id (reference) ─────────────────────────────
final class RelTrackerPlugin extends DslPlugin {
    public function name(): string         { return 'rel'; }
    public function phpNamespace(): string { return 'Rel'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('project')
            ->fields(['name' => Field::string()->max(50)])
            ->actions(['create' => Action::create('name')->requireRole('m')])
            ->projection('list', fields: ['id', 'name']);

        $dsl->entity('issue')
            ->fields([
                'title'      => Field::string()->max(80),
                'project_id' => Field::reference('rel.project')->nullable(),
            ])
            ->actions([
                'create'   => Action::create('title', 'project_id')->requireRole('m'),
                'reassign' => Action::update('project_id')->requireRole('m'),
            ])
            // Expand the reference into a folded `project_id_label` column.
            ->projection('board', fields: ['id', 'title', 'project_id'], expand: ['project_id' => 'name']);
    }
}

// ── Boot ────────────────────────────────────────────────────────────────────
$dbPath = __DIR__ . '/relations.sqlite';
if (file_exists($dbPath)) unlink($dbPath);
$pdo = new PDO("sqlite:{$dbPath}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$app = Application::create([
    'tenant'   => 'acme',
    'actorId'  => 'user1',
    'roles'    => ['m'],
    'database' => $pdo,
])->register(new RelTrackerPlugin())->boot();
_assert('valid relation graph compiles + boots', true);

// ── 1. Reference / Subject unification ───────────────────────────────────────
$r = new Reference('acme', 'rel.project', 'X');
_assert('Reference instanceof Subject (alias)', $r instanceof Subject);
_assert('new Subject(...) instanceof Reference', (new Subject('acme', 'rel.project', 'X')) instanceof Reference);
_assert('Subject::fromReference is the identity function', Subject::fromReference($r) === $r);
_assert('Subject and Reference are the same runtime class', get_class($r) === get_class(new Subject('a', 'b', 'c')));

// ── 2. Happy path: valid reference resolves ──────────────────────────────────
$proj = $app->run('rel.project.create', null, ['name' => 'Engineering']);
$projectId = $proj->id();
_assert('project created', $projectId !== null);

$i1 = $app->run('rel.issue.create', null, ['title' => 'Fix login', 'project_id' => $projectId]);
_assert('issue with a valid project_id is created', $i1->id() !== null);

// ── 3. Ghost references are impossible (write-time, create) ───────────────────
$ghost = '01J0000000GHOST0000000000_';
$threw = null;
try {
    $app->run('rel.issue.create', null, ['title' => 'Ghost', 'project_id' => $ghost]);
} catch (ReferentialIntegrityViolation $e) {
    $threw = $e;
}
_assert('create with a ghost project_id is REJECTED', $threw instanceof ReferentialIntegrityViolation,
    $threw === null ? 'no exception thrown' : null);

// The ghost issue must not have been persisted (clean rollback).
$board = $app->render('rel.issue.board');
$titles = array_column($board['data']['items'] ?? [], 'title');
_assert('the ghost issue was not persisted (rollback)', !in_array('Ghost', $titles, true));

// ── 4. Ghost references are impossible (write-time, update/reassign) ──────────
$threw = null;
try {
    $app->run('rel.issue.reassign', $i1->subject, ['project_id' => $ghost]);
} catch (ReferentialIntegrityViolation $e) {
    $threw = $e;
}
_assert('reassign to a ghost project_id is REJECTED', $threw instanceof ReferentialIntegrityViolation);

// ── 5. Nullable references are allowed ───────────────────────────────────────
$i2 = $app->run('rel.issue.create', null, ['title' => 'Unassigned', 'project_id' => null]);
_assert('issue with null project_id is created (nullable reference)', $i2->id() !== null);

// ── 6. Projection expansion folds the parent display field ───────────────────
$board = $app->render('rel.issue.board');
$rowsById = [];
foreach ($board['data']['items'] as $row) { $rowsById[$row['id']] = $row; }

$fixLoginRow = $rowsById[$i1->id()] ?? null;
_assert('board row carries the raw project_id', ($fixLoginRow['project_id'] ?? null) === $projectId);
_assert('board row carries the expanded project_id_label = "Engineering"',
    ($fixLoginRow['project_id_label'] ?? null) === 'Engineering',
    'got: ' . var_export($fixLoginRow['project_id_label'] ?? null, true));

$unassignedRow = $rowsById[$i2->id()] ?? null;
_assert('null reference expands to a null label',
    is_array($unassignedRow)
        && array_key_exists('project_id_label', $unassignedRow)
        && $unassignedRow['project_id_label'] === null);

// The expanded column is advertised in the ViewSchema fields block.
$fieldNames = array_column($board['fields'], 'name');
_assert('ViewSchema fields block advertises project_id_label', in_array('project_id_label', $fieldNames, true));

// The reference field exposes its target FQN on the wire (FieldDescriptor.typeOptions).
$projIdField = null;
foreach ($board['fields'] as $f) { if ($f['name'] === 'project_id') { $projIdField = $f; break; } }
_assert('reference FieldDescriptor exposes targetEntityFqn',
    ($projIdField['typeOptions']['targetEntityFqn'] ?? null) === 'rel.project');

// ── 7. Dangling relation definitions fail at COMPILE time ────────────────────
$danglingPlugin = new class extends DslPlugin {
    public function name(): string         { return 'rel'; }
    public function phpNamespace(): string { return 'Rel'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('issue')
            ->fields([
                'title'      => Field::string()->max(80),
                'project_id' => Field::reference('rel.does_not_exist'),
            ])
            ->actions(['create' => Action::create('title', 'project_id')->requireRole('m')]);
    }
};
$threw = null;
try {
    (new Compiler())->compile([$danglingPlugin]);
} catch (\RuntimeException $e) {
    $threw = $e;
}
_assert('reference to an undeclared entity fails at compile time',
    $threw !== null && str_contains($threw->getMessage(), 'DanglingRelation'),
    $threw?->getMessage());

// `Field::reference()` rejects a non-FQN target up front.
$threw = null;
try { Field::reference('notfqn'); } catch (\InvalidArgumentException $e) { $threw = $e; }
_assert('Field::reference() rejects a non-qualified target', $threw instanceof \InvalidArgumentException);

// ── 8. Projection expand misconfiguration fails loudly ───────────────────────
$badExpand = new class extends DslPlugin {
    public function name(): string         { return 'rel'; }
    public function phpNamespace(): string { return 'Rel'; }
    public function dsl(Dsl $dsl): void {
        $dsl->entity('project')->fields(['name' => Field::string()->max(50)])
            ->actions(['create' => Action::create('name')->requireRole('m')]);
        $dsl->entity('issue')
            ->fields(['title' => Field::string()->max(80), 'project_id' => Field::reference('rel.project')])
            ->actions(['create' => Action::create('title', 'project_id')->requireRole('m')])
            // expands a field that exists, but a display field that does NOT exist on target
            ->projection('bad', fields: ['id', 'project_id'], expand: ['project_id' => 'nope']);
    }
};
$pdo2 = new PDO('sqlite::memory:');
$pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$app2 = Application::create(['tenant' => 'acme', 'actorId' => 'u', 'roles' => ['m'], 'database' => $pdo2])
    ->register($badExpand)->boot();
$threw = null;
try { $app2->render('rel.issue.bad'); } catch (\RuntimeException $e) { $threw = $e; }
_assert('expanding a non-existent target display field fails loudly',
    $threw !== null && str_contains($threw->getMessage(), 'ProjectionExpandInvalid'),
    $threw?->getMessage());

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed={$passed} failed={$failed}\n";
echo "{$BANNER}\n";
if (file_exists($dbPath)) unlink($dbPath);
exit($failed === 0 ? 0 : 1);
