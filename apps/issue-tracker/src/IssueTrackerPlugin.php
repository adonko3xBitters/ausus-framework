<?php
declare(strict_types=1);

namespace IssueTracker;

use Ausus\{DslPlugin, Dsl, Field, Action};

/**
 * IssueTrackerPlugin — small production-style domain.
 *
 *   project ── 1 ────* ── issue ── 1 ────* ── comment
 *
 * The parent-child relations are encoded as **plain string fields**
 * (`project_id` on issue, `issue_id` on comment) because v0.1.x has no
 * foreign-key contract; see FRAMEWORK-FINDINGS.md §1 for the implications.
 *
 * Roles used:
 *   - tracker.member  — can create issues, advance issue workflow, post comments
 *   - tracker.admin   — can archive projects and force `wontfix`
 *   - tracker.viewer  — can read projections (HTTP layer does not enforce this)
 */
final class IssueTrackerPlugin extends DslPlugin
{
    public function name(): string         { return 'tracker'; }
    public function phpNamespace(): string { return 'IssueTracker'; }

    public function dsl(Dsl $dsl): void
    {
        $this->declareProject($dsl);
        $this->declareIssue($dsl);
        $this->declareComment($dsl);
    }

    private function declareProject(Dsl $dsl): void
    {
        $dsl->entity('project')
            ->fields([
                'key'    => Field::string()->unique()->max(20),
                'name'   => Field::string()->max(120),
                'owner'  => Field::string()->max(120),
                'status' => Field::enum('ACTIVE', 'ARCHIVED')->default('ACTIVE'),
            ])
            ->actions([
                'create'  => Action::create('key', 'name', 'owner')
                                ->requireRole('tracker.member'),
                // ADR-0002 dogfood: partial-PATCH edits on the project.
                'edit'    => Action::update('name', 'owner')
                                ->requireRole('tracker.admin'),
                'archive' => Action::transition('status', from: 'ACTIVE', to: 'ARCHIVED')
                                ->requireRole('tracker.admin'),
            ])
            ->workflow(field: 'status', initial: 'ACTIVE')
            ->projection(
                'summary',
                fields:  ['id', 'key', 'name', 'owner', 'status'],
                actions: ['create', 'archive'],
                role:    'tracker.viewer',
            )
            ->projection(
                'detail',
                fields:  ['id', 'key', 'name', 'owner', 'status', 'created_at', 'updated_at'],
                actions: ['edit', 'archive'],
                role:    'tracker.viewer',
            );
    }

    private function declareIssue(Dsl $dsl): void
    {
        $dsl->entity('issue')
            ->fields([
                // FK by convention only; ->label() gives the renderer a friendly column header.
                'project_id'  => Field::string()->max(26)->label('Project'),
                'title'       => Field::string()->max(200),
                'reporter'    => Field::string()->max(120),
                'assignee'    => Field::string()->max(120)->nullable(),
                'priority'    => Field::enum('LOW', 'NORMAL', 'HIGH', 'URGENT')->default('NORMAL'),
                'status'      => Field::enum('TODO', 'DOING', 'REVIEW', 'DONE', 'WONTFIX')->default('TODO'),
                'resolved_at' => Field::datetime()->nullable()->label('Resolved'),
            ])
            ->actions([
                'create'   => Action::create('project_id', 'title', 'reporter', 'assignee', 'priority')
                                 ->requireRole('tracker.member'),
                // ADR-0002 dogfood: three partial-PATCH actions that the renderer
                // can now draw forms for. Workflow state still flows through
                // start/review/done/wontfix — the DSL refuses update('status').
                'rename'   => Action::update('title')
                                 ->requireRole('tracker.member'),
                'reassign' => Action::update('assignee')
                                 ->requireRole('tracker.member'),
                'edit'     => Action::update('title', 'assignee', 'priority')
                                 ->requireRole('tracker.member'),
                'start'   => Action::transition('status', from: 'TODO',   to: 'DOING')
                                ->requireRole('tracker.member'),
                'review'  => Action::transition('status', from: 'DOING',  to: 'REVIEW')
                                ->requireRole('tracker.member'),
                'done'    => Action::transition('status', from: 'REVIEW', to: 'DONE')
                                ->stamp('resolved_at')
                                ->requireRole('tracker.member'),
                'wontfix' => Action::transition('status', from: 'TODO',   to: 'WONTFIX')
                                ->addTransition('status', from: 'DOING',  to: 'WONTFIX')
                                ->addTransition('status', from: 'REVIEW', to: 'WONTFIX')
                                ->stamp('resolved_at')
                                ->requireRole('tracker.admin'),
            ])
            ->workflow(field: 'status', initial: 'TODO')
            ->projection(
                'board',
                fields:  ['id', 'project_id', 'title', 'reporter', 'assignee', 'priority', 'status'],
                actions: ['create', 'start', 'review', 'done', 'wontfix'],
                role:    'tracker.viewer',
            )
            ->projection(
                'detail',
                fields:  ['id', 'project_id', 'title', 'reporter', 'assignee', 'priority', 'status', 'resolved_at', 'created_at', 'updated_at'],
                actions: ['rename', 'reassign', 'edit', 'start', 'review', 'done', 'wontfix'],
                role:    'tracker.viewer',
            );
    }

    private function declareComment(Dsl $dsl): void
    {
        // Comments are append-only — no workflow, no enum fields.
        $dsl->entity('comment')
            ->fields([
                'issue_id' => Field::string()->max(26)->label('Issue'),
                'author'   => Field::string()->max(120),
                'body'     => Field::string()->max(2000),
            ])
            ->actions([
                'post' => Action::create('issue_id', 'author', 'body')
                            ->requireRole('tracker.member'),
            ])
            ->projection(
                'list',
                fields:  ['id', 'issue_id', 'author', 'body', 'created_at'],
                actions: ['post'],
                role:    'tracker.viewer',
            );
    }
}
