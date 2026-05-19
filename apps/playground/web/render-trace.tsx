import React from "react";
/**
 * Node-side render trace — uses react-dom/server to render the renderer
 * at multiple states and dump HTML so we can prove it works end-to-end
 * WITHOUT a browser.
 *
 * Exercises:
 *   1. Loading shell (useViewSchema's async fetch hasn't resolved yet)
 *   2. Populated list view (bypassing the hook with prefetched schema)
 *   3. Populated detail view
 *   4. Action invocation via the mock backend (DRAFT→ISSUED, ISSUED→CANCELLED)
 *   5. Workflow gate (cancel on CANCELLED → WorkflowStateMismatch)
 *
 * Run:  npx tsx render-trace.tsx
 */
import { renderToString } from "react-dom/server";
import { AususProvider, ListView, DetailView } from "@ausus/renderer-react";
import { App } from "./src/App";
import { createMockFetcher } from "./src/mockApi";
import { buildSummarySchema, buildDetailSchema } from "./src/fixtures";

function frame(title: string, html: string): string {
  const bar = "═".repeat(70);
  return [
    "",
    bar,
    `  ${title}`,
    bar,
    html.replace(/></g, ">\n<"),
    "",
  ].join("\n");
}

async function main() {
  const traces: string[] = [];

  // ───── trace 1: loading shell via the App ─────
  const mockApp = createMockFetcher();
  const html1 = renderToString(<App fetcher={mockApp.fetcher} />);
  traces.push(frame("TRACE 1 — App loading shell (useViewSchema async)", html1));

  // ───── trace 2: populated list — bypass the hook by rendering ListView with prefetched schema ─────
  const mockList = createMockFetcher();
  const schema1 = buildSummarySchema(mockList.state());
  const html2 = renderToString(
    <AususProvider apiBaseUrl="/api" tenant="acme" fetcher={mockList.fetcher}>
      <ListView schema={schema1} onRefetch={() => {}} />
    </AususProvider>,
  );
  traces.push(frame("TRACE 2 — populated ListView (2 invoices, DRAFT + ISSUED)", html2));

  // ───── trace 3: Issue action via mock + re-render ─────
  await mockList.fetcher("/api/actions/billing.invoice.issue", {
    method: "POST",
    body: JSON.stringify({
      subject: { tenantId: "acme", entityFqn: "billing.invoice", identityHandle: "01KRYTV1EK8CZKP1Q7BYE9JZJT" },
      inputs: {},
    }),
  });
  const schema2 = buildSummarySchema(mockList.state());
  const html3 = renderToString(
    <AususProvider apiBaseUrl="/api" tenant="acme" fetcher={mockList.fetcher}>
      <ListView schema={schema2} onRefetch={() => {}} />
    </AususProvider>,
  );
  traces.push(frame("TRACE 3 — ListView after Issue action (first row → ISSUED)", html3));

  // ───── trace 4: Cancel action ─────
  await mockList.fetcher("/api/actions/billing.invoice.cancel", {
    method: "POST",
    body: JSON.stringify({
      subject: { tenantId: "acme", entityFqn: "billing.invoice", identityHandle: "01KRYTV2A9B3C5D7E9F1H3J5K7" },
      inputs: {},
    }),
  });
  const schema3 = buildSummarySchema(mockList.state());
  const html4 = renderToString(
    <AususProvider apiBaseUrl="/api" tenant="acme" fetcher={mockList.fetcher}>
      <ListView schema={schema3} onRefetch={() => {}} />
    </AususProvider>,
  );
  traces.push(frame("TRACE 4 — ListView after Cancel action (second row → CANCELLED)", html4));

  // ───── trace 5: populated detail view ─────
  const subjectRef = { tenantId: "acme", entityFqn: "billing.invoice", identityHandle: "01KRYTV1EK8CZKP1Q7BYE9JZJT" };
  const detailSchema = buildDetailSchema(mockList.state()[0]);
  const html5 = renderToString(
    <AususProvider apiBaseUrl="/api" tenant="acme" fetcher={mockList.fetcher}>
      <DetailView schema={detailSchema} subject={subjectRef} onRefetch={() => {}} />
    </AususProvider>,
  );
  traces.push(frame("TRACE 5 — populated DetailView (ISSUED invoice; 8 fields)", html5));

  // ───── trace 6: stale Cancel → WorkflowStateMismatch ─────
  const failedResp = await mockList.fetcher("/api/actions/billing.invoice.cancel", {
    method: "POST",
    body: JSON.stringify({
      subject: { tenantId: "acme", entityFqn: "billing.invoice", identityHandle: "01KRYTV2A9B3C5D7E9F1H3J5K7" },
      inputs: {},
    }),
  });
  const failedJson = await failedResp.json();
  traces.push(frame("TRACE 6 — Cancel on already-CANCELLED row (WorkflowStateMismatch)", JSON.stringify(failedJson, null, 2)));

  // ───── Dump ─────
  console.log("\n══════════════════════════════════════════════════════════════════════");
  console.log("  AUSUS @ausus/renderer-react — V0 render traces");
  console.log("══════════════════════════════════════════════════════════════════════");
  traces.forEach(t => console.log(t));

  // ───── Assertions ─────
  let passed = 0, failed = 0;
  function check(name: string, cond: boolean, detail?: string) {
    if (cond) { console.log(`  ✓ ${name}`); passed++; }
    else      { console.log(`  ✗ ${name}${detail ? " — " + detail : ""}`); failed++; }
  }

  console.log("\n── assertions ─────────────────────────────────────────────────────────");
  check("App renders loading shell",                  html1.includes("ausus-loading"));
  check("ListView renders 2 data rows",               (html2.match(/<tr>/g) ?? []).length >= 3);
  check("ListView shows DRAFT badge for invoice 1",   html2.includes("ausus-badge--gray") && html2.includes("DRAFT"));
  check("ListView shows ISSUED badge for invoice 2",  html2.includes("ausus-badge--blue") && html2.includes("ISSUED"));
  check("After Issue: first invoice now ISSUED",      mockList.state()[0].status === "ISSUED");
  check("After Issue: HTML shows updated badge",      (html3.match(/ausus-badge--blue/g) ?? []).length >= 2);
  check("After Cancel: second invoice CANCELLED",     mockList.state()[1].status === "CANCELLED");
  check("After Cancel: HTML shows red badge",         html4.includes("ausus-badge--red") && html4.includes("CANCELLED"));
  check("Money formatted with currency",              html2.includes("USD 1500.00"));
  check("DetailView renders all 8 fields",            (html5.match(/<dt>/g) ?? []).length === 8);
  check("DetailView shows ISSUED status badge",       html5.includes("ausus-badge--blue"));
  check("Stale Cancel → WorkflowStateMismatch",       failedJson.ok === false && failedJson.error?.kind === "WorkflowStateMismatch");

  console.log(`\nRESULT: passed=${passed} failed=${failed}`);
  if (failed > 0) process.exit(1);
}

main().catch(e => { console.error(e); process.exit(1); });
