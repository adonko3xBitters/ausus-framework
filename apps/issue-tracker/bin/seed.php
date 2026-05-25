<?php
declare(strict_types=1);

// Resolve the autoloader for both standalone (vendor/autoload.php beside this app)
// and monorepo (root vendor) layouts.
$autoload = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];
foreach ($autoload as $f) { if (file_exists($f)) { require $f; break; } }

use Ausus\{Application, ApplicationConfig};
use IssueTracker\IssueTrackerPlugin;

$dbPath = $argv[1] ?? (__DIR__ . '/../tracker.sqlite');
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$app = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')
            ->actorId('seed')
            ->roles(['tracker.member', 'tracker.admin', 'tracker.viewer'])
            ->sqlite($dbPath)
    )
    ->register(new IssueTrackerPlugin())
    ->boot();

echo "[seed] database: {$dbPath}\n";

// Projects ─────────────────────────────────────────────────────────────────────
$projects = [];
foreach ([
    ['key' => 'ENG', 'name' => 'Engineering',   'owner' => 'alice@acme'],
    ['key' => 'OPS', 'name' => 'Operations',    'owner' => 'bob@acme'],
    ['key' => 'DOC', 'name' => 'Documentation', 'owner' => 'dave@acme'],
] as $row) {
    $p = $app->run('tracker.project.create', null, $row);
    $projects[$row['key']] = $p;
    echo "[seed]   project {$row['key']} → {$p->id()}\n";
}

// Issues ───────────────────────────────────────────────────────────────────────
// Note: omit `assignee` to leave it null (see FRAMEWORK-FINDINGS.md §3 about
// the explicit-null serialization bug — passing assignee=null would store the
// 4-char string "null", not SQL NULL).
$issues = [];
foreach ([
    ['ENG', 'Renderer crashes on null money',    'alice@acme', 'carol@acme', 'HIGH',   ['start']],
    ['ENG', 'Add filter param to /projections',  'alice@acme', null,         'NORMAL', []],
    ['ENG', 'Document workflow guard semantics', 'alice@acme', 'dave@acme',  'LOW',    ['start', 'review']],
    ['OPS', 'Provision new staging box',         'bob@acme',   'eve@acme',   'URGENT', ['start']],
    ['OPS', 'Rotate Slack webhook secret',       'bob@acme',   'eve@acme',   'NORMAL', []],
    ['DOC', 'Translate troubleshooting page',    'dave@acme',  null,         'LOW',    []],
] as [$projectKey, $title, $reporter, $assignee, $priority, $advanceVia]) {
    $payload = [
        'project_id' => $projects[$projectKey]->id(),
        'title'      => $title,
        'reporter'   => $reporter,
        'priority'   => $priority,
    ];
    if ($assignee !== null) $payload['assignee'] = $assignee;

    $issue = $app->run('tracker.issue.create', null, $payload);
    $issues[] = $issue;
    foreach ($advanceVia as $transition) {
        $app->invoke("tracker.issue.{$transition}", $issue->subject);
    }
    echo "[seed]   issue {$projectKey} \"{$title}\" → " . $issue->id() . "\n";
}

// One comment on the first issue.
$app->run('tracker.comment.post', null, [
    'issue_id' => $issues[0]->id(),
    'author'   => 'carol@acme',
    'body'     => 'Repro: list view with an unpriced row. Pushed a fix in #42.',
]);

echo "[seed] done — " . count($projects) . " projects, " . count($issues) . " issues, 1 comment.\n";
