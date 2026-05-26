import { useState } from 'react';
import { AususProvider, ViewSchemaConsumer } from '@ausus/renderer-react';
import type { Fetcher, Reference } from '@ausus/renderer-react';

/**
 * The renderer sends X-Tenant-ID from AususProvider. We wrap fetch to also send
 * X-Actor-Roles so action POSTs pass the policy check. v0.1.x has no auth
 * layer — in a real deployment this header is set by an authenticated gateway,
 * not in the browser.
 */
const fetcher: Fetcher = (url, init) =>
  fetch(url, {
    ...init,
    headers: {
      ...(init?.headers ?? {}),
      'X-Actor-Roles': 'tracker.member,tracker.admin,tracker.viewer',
    },
  });

const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL as string | undefined)
  ?? 'http://127.0.0.1:8787/api';

type Tab =
  | { kind: 'projects' }
  | { kind: 'issues' }
  | { kind: 'comments' }
  | { kind: 'project'; subject: Reference }
  | { kind: 'issue';   subject: Reference };

export function App() {
  const [tab, setTab] = useState<Tab>({ kind: 'projects' });

  return (
    <AususProvider apiBaseUrl={API_BASE_URL} tenant="acme" fetcher={fetcher}>
      <header className="ausus-header">
        <strong>AUSUS · Issue Tracker</strong>
        <nav className="tt-nav">
          <button className={tabClass(tab, 'projects')} onClick={() => setTab({ kind: 'projects' })}>Projects</button>
          <button className={tabClass(tab, 'issues')}   onClick={() => setTab({ kind: 'issues' })}>Issues</button>
          <button className={tabClass(tab, 'comments')} onClick={() => setTab({ kind: 'comments' })}>Comments</button>
          {(tab.kind === 'project' || tab.kind === 'issue') && (
            <button className="ausus-btn ausus-btn--ghost"
                    onClick={() => setTab({ kind: tab.kind === 'project' ? 'projects' : 'issues' })}>
              ← Back
            </button>
          )}
        </nav>
        <span className="tt-note">
          Tip: identifiers in <code>project_id</code> / <code>issue_id</code> are stored as
          plain strings — v0.1.x has no foreign-key contract. See FRAMEWORK-FINDINGS.md §1.
        </span>
      </header>

      <main className="tt-main">
        {tab.kind === 'projects' && (
          <ViewSchemaConsumer projection="tracker.project.summary" />
        )}
        {tab.kind === 'issues' && (
          <ViewSchemaConsumer projection="tracker.issue.board" />
        )}
        {tab.kind === 'comments' && (
          <ViewSchemaConsumer projection="tracker.comment.list" />
        )}
        {tab.kind === 'project' && (
          <ViewSchemaConsumer projection="tracker.project.detail" subject={tab.subject} />
        )}
        {tab.kind === 'issue' && (
          <ViewSchemaConsumer projection="tracker.issue.detail" subject={tab.subject} />
        )}
      </main>
    </AususProvider>
  );
}

function tabClass(tab: Tab, k: Tab['kind']): string {
  const base = 'ausus-btn';
  return tab.kind === k ? `${base} ausus-btn--primary` : `${base} ausus-btn--ghost`;
}
