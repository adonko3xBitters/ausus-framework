<?php
declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Ausus\{Application, ApplicationConfig, Reference};
use IssueTracker\IssueTrackerPlugin;

/**
 * Issue tracker — end-to-end smoke.
 *
 * Bootstraps the plugin into a fresh in-memory database, walks two projects
 * and several issues through their workflows, posts a comment, and renders
 * the projections. Asserts the runtime semantics every layer above the kernel
 * depends on.
 */

$BANNER = "═══ issue-tracker — end-to-end smoke ═══════════════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

// ── boot ─────────────────────────────────────────────────────────────────────
$app = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')
            ->roles(['tracker.member', 'tracker.admin', 'tracker.viewer'])
    )
    ->register(new IssueTrackerPlugin())
    ->boot();

$graph = $app->graph();

// ── test 1: plugin compiles & schema is derived ─────────────────────────────
echo "\n── test 1: plugin compiles + schema applied ─────────────────\n";
_assert('graph has 3 entities (project, issue, comment)', count($graph->entities) === 3,
        'actual=' . count($graph->entities));
_assert('graph has 12 actions (8 v0.1.x + 4 update from ADR-0002)',
        count($graph->actions) === 12,
        'actual=' . count($graph->actions));
_assert('graph has 2 workflows (project + issue; comment has none)',
        count($graph->workflows) === 2,
        'actual=' . count($graph->workflows));
_assert('graph has 5 projections (project×2 + issue×2 + comment×1)',
        count($graph->projections) === 5,
        'actual=' . count($graph->projections));

// ── test 2: create projects ─────────────────────────────────────────────────
echo "\n── test 2: create projects ──────────────────────────────────\n";
$eng = $app->run('tracker.project.create', null, [
    'key' => 'ENG', 'name' => 'Engineering', 'owner' => 'alice@acme',
]);
$ops = $app->run('tracker.project.create', null, [
    'key' => 'OPS', 'name' => 'Operations',  'owner' => 'bob@acme',
]);
_assert('ENG project created with status ACTIVE', $eng->output('status') === 'ACTIVE');
_assert('OPS project created with status ACTIVE', $ops->output('status') === 'ACTIVE');
_assert('projects have distinct ids',             $eng->id() !== $ops->id());

// ── test 3: create issues under each project (FK is convention only) ────────
echo "\n── test 3: create issues across projects ────────────────────\n";
$i1 = $app->run('tracker.issue.create', null, [
    'project_id' => $eng->id(), 'title' => 'Renderer crashes on null money',
    'reporter'   => 'alice@acme', 'assignee' => 'carol@acme', 'priority' => 'HIGH',
]);
// Workaround for FRAMEWORK-FINDINGS.md §3: pass-null serialises as the
// 4-char string "null". Omit nullable fields instead — the repo's create
// path then skips the column entirely and SQLite stores SQL NULL.
$i2 = $app->run('tracker.issue.create', null, [
    'project_id' => $eng->id(), 'title' => 'Add filter param to /projections',
    'reporter'   => 'alice@acme',                       'priority' => 'NORMAL',
]);

// Used to demonstrate the v0.1.x null-serialisation bug; the bug is now
// fixed (see packages/persistence-sql + apps/playground/null-roundtrip-test.php)
// so this same call now persists assignee as a real SQL NULL.
$nullFixed = $app->run('tracker.issue.create', null, [
    'project_id' => $eng->id(), 'title' => 'previously stored assignee as "null"',
    'reporter'   => 'alice@acme', 'assignee' => null, 'priority' => 'LOW',
]);
$i3 = $app->run('tracker.issue.create', null, [
    'project_id' => $ops->id(), 'title' => 'Provision new staging box',
    'reporter'   => 'bob@acme',  'assignee' => 'eve@acme',    'priority' => 'URGENT',
]);
_assert('issue 1 is TODO',           $i1->output('status') === 'TODO');
_assert('issue 1 has the ENG project_id',
        $i1->output('project_id') === $eng->id());
_assert('omitting assignee leaves it absent in outputs (SQL NULL on disk)',
        !array_key_exists('assignee', $i2->outputs));
_assert('explicit assignee=null now persists as PHP null (was "null" in v0.1.x; bug fixed)',
        $nullFixed->output('assignee') === null);

// FK is convention only — the runtime accepts a bogus project_id without complaint.
$ghost = $app->run('tracker.issue.create', null, [
    'project_id' => '01J-DOES-NOT-EXIST-________',
    'title'      => 'orphan issue (FK should fail; v0.1.x accepts it)',
    'reporter'   => 'mallory@acme', 'assignee' => null, 'priority' => 'LOW',
]);
_assert('FRICTION: dangling project_id accepted (no FK contract)',
        $ghost->output('status') === 'TODO');

// ── test 4: walk issue 1 through TODO → DOING → REVIEW → DONE ──────────────
echo "\n── test 4: walk one issue through the workflow ───────────────\n";
$i1Ref = $i1->subject;
$app->invoke('tracker.issue.start',  $i1Ref);
$app->invoke('tracker.issue.review', $i1Ref);
$doneOut = $app->invoke('tracker.issue.done', $i1Ref);
_assert('issue 1 → DONE',                ($doneOut['status'] ?? null) === 'DONE');
_assert('issue 1 has resolved_at stamp', !empty($doneOut['resolved_at']));

// Illegal transition: start on DONE
$caught = null;
try { $app->invoke('tracker.issue.start', $i1Ref); }
catch (\Throwable $e) { $caught = $e; }
_assert('illegal start on DONE → WorkflowStateMismatch',
        $caught instanceof \Ausus\WorkflowStateMismatch);

// ── test 5: wontfix from arbitrary states ───────────────────────────────────
echo "\n── test 5: multi-source transition (wontfix) ────────────────\n";
$wfx = $app->invoke('tracker.issue.wontfix', $i2->subject);
_assert('issue 2 (TODO) → WONTFIX',  ($wfx['status'] ?? null) === 'WONTFIX');

// ── test 6: post a comment (no workflow entity) ─────────────────────────────
echo "\n── test 6: comment append on an entity with no workflow ─────\n";
$c1 = $app->run('tracker.comment.post', null, [
    'issue_id' => $i1->id(), 'author' => 'carol@acme', 'body' => 'PR opened, see #42.',
]);
_assert('comment created (no status field, no workflow)',
        $c1->subject !== null && $c1->output('body') === 'PR opened, see #42.');

// ── test 7: render projections ──────────────────────────────────────────────
echo "\n── test 7: projections render with current data ─────────────\n";
$projSummary = $app->render('tracker.project.summary');
$issueBoard  = $app->render('tracker.issue.board');
$commentList = $app->render('tracker.comment.list');
_assert('project.summary lists 2 projects',
        count($projSummary['data']['items']) === 2);
_assert('issue.board lists 5 issues across both projects',
        count($issueBoard['data']['items']) === 5,
        'actual=' . count($issueBoard['data']['items']));
_assert('comment.list lists 1 comment',
        count($commentList['data']['items']) === 1);

// FRICTION: there is no metadata-level "filter issues by project" — every
// projection returns all rows for the tenant; a UI showing project ENG must
// filter client-side.
$engIssues = array_values(array_filter(
    $issueBoard['data']['items'],
    fn(array $row) => ($row['project_id'] ?? null) === $eng->id(),
));
_assert('FRICTION: client-side filter is the only "by project" path',
        count($engIssues) === 3, 'ENG=' . count($engIssues));

// ── test 8: action input metadata is emitted (renderer can build forms) ─────
echo "\n── test 8: ViewSchema action.inputs available to renderer ───\n";
$boardActions = [];
foreach ($issueBoard['actions'] as $a) $boardActions[$a['name']] = $a;
_assert('issue.create exposes 5 inputs (project_id, title, reporter, assignee, priority)',
        count($boardActions['create']['inputs']) === 5,
        'actual=' . count($boardActions['create']['inputs']));

$createInputs = [];
foreach ($boardActions['create']['inputs'] as $i) $createInputs[$i['name']] = $i;
_assert('issue.create input "priority" defaults to NORMAL',
        ($createInputs['priority']['default'] ?? null) === 'NORMAL');
_assert('issue.create input "assignee" is nullable + not required',
        ($createInputs['assignee']['nullable'] ?? null) === true
        && ($createInputs['assignee']['required'] ?? null) === false);

// transition actions emit inputs=[] by design (state moves only).
_assert('transition actions still emit inputs=[] (state moves only)',
        $boardActions['start']['inputs'] === []);

// ── ADR-0002 demonstration: rename / reassign / edit through UpdateEffect ───
echo "\n── ADR-0002: rename, reassign, edit on a real issue ──────────\n";
$demoRef = $i1->subject;

// rename — only the title is patched
$renamedOut = $app->invoke('tracker.issue.rename', $demoRef, [
    'title' => 'Renderer crashes on null money (confirmed in 0.1.1)',
]);
_assert('rename: outputs.title is the new title',
        ($renamedOut['title'] ?? null) === 'Renderer crashes on null money (confirmed in 0.1.1)');
_assert('rename: no untouched fields in outputs (PATCH not PUT)',
        !array_key_exists('assignee', $renamedOut)
        && !array_key_exists('priority', $renamedOut));

// reassign — assignee changes; null on a nullable field accepted
$reassignedOut = $app->invoke('tracker.issue.reassign', $demoRef, [
    'assignee' => 'bob@acme',
]);
_assert('reassign: outputs.assignee = bob@acme',
        ($reassignedOut['assignee'] ?? null) === 'bob@acme');

$unassignedOut = $app->invoke('tracker.issue.reassign', $demoRef, [
    'assignee' => null,
]);
_assert('reassign null on a nullable field is accepted (clears the assignee)',
        array_key_exists('assignee', $unassignedOut) && $unassignedOut['assignee'] === null);

// edit — touches title + priority simultaneously
$editedOut = $app->invoke('tracker.issue.edit', $demoRef, [
    'title'    => 'Renderer crashes on null money — fix shipped in 0.1.1',
    'priority' => 'URGENT',
]);
_assert('edit: outputs.title is the new title',
        ($editedOut['title'] ?? null) === 'Renderer crashes on null money — fix shipped in 0.1.1');
_assert('edit: outputs.priority is URGENT',
        ($editedOut['priority'] ?? null) === 'URGENT');

// ViewSchema: rename/reassign/edit live on issue.detail with initialValues
$detail = $app->render('tracker.issue.detail', $demoRef);
$detailActions = [];
foreach ($detail['actions'] as $a) $detailActions[$a['name']] = $a;
_assert('detail view: rename has initialValues.title prefilled',
        ($detailActions['rename']['initialValues']['title'] ?? null)
        === 'Renderer crashes on null money — fix shipped in 0.1.1');
_assert('detail view: edit has 3 prefill keys (title/assignee/priority)',
        is_array($detailActions['edit']['initialValues'] ?? null)
        && count($detailActions['edit']['initialValues']) === 3);
_assert('detail view: edit.initialValues.priority = URGENT (just set)',
        ($detailActions['edit']['initialValues']['priority'] ?? null) === 'URGENT');

// Compile-time refusal: trying to declare update('status') on this very
// plugin would refuse — the workflow state field is protected by ADR-0002 §7.
$caught = null;
try {
    (new class extends \Ausus\DslPlugin {
        public function name(): string         { return 'badstatus'; }
        public function phpNamespace(): string { return 'BadStatus'; }
        public function dsl(\Ausus\Dsl $dsl): void {
            $dsl->entity('issue')
                ->fields([
                    'status' => \Ausus\Field::enum('A','B')->default('A'),
                ])
                ->actions([
                    'create' => \Ausus\Action::create()->requireRole('r'),
                    'tweak'  => \Ausus\Action::update('status')->requireRole('r'),
                ])
                ->workflow(field: 'status', initial: 'A');
        }
    })->describe();
} catch (\Throwable $e) { $caught = $e; }
_assert('Action::update(\'status\') on a workflow field rejected at compile',
        $caught !== null && str_contains($caught->getMessage(), 'cannot patch the workflow state field'));

// ── test 9: policy gating ───────────────────────────────────────────────────
echo "\n── test 9: policy denial for the wrong role ─────────────────\n";
$viewerOnly = Application::create(
        ApplicationConfig::make()->tenant('acme')->roles(['tracker.viewer'])
    )
    ->register(new IssueTrackerPlugin())
    ->boot();
$caught = null;
try {
    $viewerOnly->invoke('tracker.project.create', null, [
        'key' => 'X', 'name' => 'X', 'owner' => 'x@acme',
    ]);
} catch (\Throwable $e) { $caught = $e; }
_assert('viewer role cannot create a project (PolicyDenied)',
        $caught instanceof \Ausus\PolicyDenied);

$caught = null;
try {
    // member can create but cannot archive
    $memberOnly = Application::create(
            ApplicationConfig::make()->tenant('acme')->roles(['tracker.member'])
        )
        ->register(new IssueTrackerPlugin())
        ->boot();
    $p = $memberOnly->run('tracker.project.create', null, [
        'key' => 'M', 'name' => 'M', 'owner' => 'm@acme',
    ]);
    $memberOnly->invoke('tracker.project.archive', $p->subject);
} catch (\Throwable $e) { $caught = $e; }
_assert('member role cannot archive a project (admin required)',
        $caught instanceof \Ausus\PolicyDenied);

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed={$passed} failed={$failed}\n";
echo "{$BANNER}\n";

exit($failed > 0 ? 1 : 0);
