# Issue Tracker — architecture notes

A two-process deployment: a PHP process serving the AUSUS HTTP API on
`:8787`, and a Vite-served React UI on `:5173` that consumes it.

## Layered view

```
                                          ┌─ tests/smoke.php  (PHP CLI)
                                          │
 IssueTrackerPlugin (DslPlugin)            │
   describe()  →  L1 Compiler  →  L0 MetadataGraph
                                          │
                                          ▼
                       L2 Runtime / Invoker  ─► L3 SQLite + audit
                                          │
                                          ├─ public/server.php  (PHP -S)
                                          │     uses Application::http()
                                          │
                                          ▼
                       L4 HTTP API (/api/projections, /api/actions)
                                          │
                                          │  ViewSchema JSON
                                          ▼
                       L6 @ausus/renderer-react (Vite dev server)
                                          │
                                          ▼
                                       Browser
```

The plugin is **the only domain code in the app**. Everything else —
persistence schema, HTTP surface, the UI's form fields and workflow buttons —
is derived from the compiled `MetadataGraph`.

## Persistence shape

`SchemaDeriver` produces three tables plus the audit log. Names are derived
from entity FQNs (`tracker.project` → `tracker_project`).

```sql
CREATE TABLE "tracker_project" (
  id          TEXT NOT NULL,           -- ULID
  tenant_id   TEXT NOT NULL,
  _version    TEXT NOT NULL,           -- ULID, bumped on update
  created_at  TEXT,
  updated_at  TEXT,
  key         TEXT,                    -- string field
  name        TEXT,
  owner       TEXT,
  status      TEXT,                    -- enum field
  PRIMARY KEY (id)
);

CREATE TABLE "tracker_issue"   (...);  -- same shape; project_id TEXT (no FK)
CREATE TABLE "tracker_comment" (...);  -- no enum, no status; issue_id TEXT (no FK)

CREATE TABLE "kernel_audit_log" (
  entry_id      TEXT NOT NULL,
  sequence      INTEGER NOT NULL,
  actor_*       TEXT,                  -- type, id, home_tenant
  tenant        TEXT,
  action_fqn    TEXT,
  subject_*     TEXT,                  -- tenant_id, entity_fqn, identity_handle
  inputs        TEXT,                  -- json
  outputs       TEXT,                  -- json
  timestamp     TEXT,                  -- rfc-3339
  correlation_id TEXT,
  trace_id      TEXT,
  invocation_class TEXT,               -- Standard | Maintenance
  emitter_version TEXT
);
```

Every successful action appends one row to `kernel_audit_log` **in the same
transaction** as the row it changed. Failure rolls both back.

## Request lifecycle (an issue transition)

1. UI: user clicks **Review** on a row whose `status = DOING`.
2. `ActionModal` confirmation appears (no inputs for transitions); user
   confirms.
3. `useAction` POSTs `/api/actions/tracker.issue.review` with
   `{"subject":{"tenantId":"acme","entityFqn":"tracker.issue","identityHandle":"01J…"},"inputs":{}}`,
   custom `X-Actor-Roles: tracker.member,tracker.admin,tracker.viewer`,
   `X-Tenant-ID: acme` (from `AususProvider`).
4. `Application::http(request)` calls the cached `Router`, which:
   - resolves the actor and tenant from headers,
   - asks the `Invoker` to run `tracker.issue.review`.
5. `Invoker` runs preflight → policy (member role present) → workflow guard
   (`DOING → REVIEW` is legal) → `TransitionEffect` (writes
   `status='REVIEW'`) → audit. All inside one DB transaction.
6. Response: `{"ok":true,"outputs":{"status":"REVIEW","_version":"01J…"}}`.
7. The renderer's `ListView` refetches the projection; the badge flips colour.

The Invoker's chain is identical to the smoke test's path — there is one
runtime, exercised by both the CLI and HTTP.

## Roles and policies

- `tracker.member` — owns the day-to-day operations: create projects,
  create + advance issues, post comments.
- `tracker.admin` — can `archive` a project and force `wontfix` on an issue.
- `tracker.viewer` — purely a read role. Projection endpoints don't enforce
  it in v0.1.x (the HTTP layer does not gate `GET /projections/...`), so it
  is mostly documentary.

Each action carries one `requireRole(...)` policy. `RoleRequired` is the
single built-in policy in v0.1.x; multi-condition checks would require a
custom `Policy` class.

## Why this sample is "production-style" without inventing features

- It runs against the **same `Application`/`Invoker`** the playground and
  starter use; no custom runtime.
- The HTTP entry point is the new `Application::http()`, the smallest
  realistic server.php a v0.1.x consumer can write.
- The UI uses the published renderer's components with **no custom domain
  code** — every form input, badge, and action button comes from the
  metadata.
- The friction it exposes is the friction a real v0.1.x consumer would hit
  — documented in [FRAMEWORK-FINDINGS.md](./FRAMEWORK-FINDINGS.md), not
  papered over with workarounds.

## What is intentionally **not** in this sample

- No authentication (v0.1.x has no auth layer).
- No background jobs / queues / events.
- No filtering or pagination beyond client-side filtering of the full
  projection result.
- No update form for non-state fields (renderer can't draw one — see §4 of
  the findings).
- No relations / joins in projections (parent fields are surfaced as plain
  string ids).
- No file uploads.
- No multi-tenant deployment (`acme` only).
