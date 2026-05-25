import React from "react";
/**
 * AUSUS L4 HTTP — live end-to-end trace.
 *
 * Renderer-react consumes the real PHP HTTP server (no mockApi.ts):
 *   1.  GET  /api/_health                        → graph hash, sanity check
 *   2.  GET  /api/projections/billing.invoice.summary
 *   3.  renderToString(ListView, schema)         → real HTML, real data
 *   4.  POST /api/actions/billing.invoice.issue (1st invoice)
 *   5.  re-GET summary → renderToString → assert DRAFT→ISSUED reflected
 *   6.  POST /api/actions/billing.invoice.cancel (2nd invoice)
 *   7.  re-GET summary → renderToString → assert →CANCELLED reflected
 *   8.  GET /api/projections/billing.invoice.detail?subject=<id>
 *   9.  renderToString(DetailView)
 *  10.  POST /api/actions/billing.invoice.cancel on already-CANCELLED row
 *       → expect 409 with error.kind === "WorkflowStateMismatch"
 *
 * Prereq: PHP server running at API_BASE_URL.
 *   php -S 127.0.0.1:8787 apps/playground/server.php
 *
 * Run:    npx tsx apps/playground/web/live-trace.tsx
 */
import { renderToString } from "react-dom/server";
import { AususProvider, ListView, DetailView } from "@ausus/renderer-react";
import type { Reference, ViewSchema } from "@ausus/renderer-react/types";

const API_BASE_URL = process.env.AUSUS_API_BASE_URL ?? "http://127.0.0.1:8787/api";
const TENANT = "acme";

const fetcher: typeof fetch = (url, init) =>
  fetch(url as string, init);

interface InvoiceRow { id: string; status: string; number: string }

function frame(title: string, html: string): string {
  const bar = "─".repeat(72);
  return ["", bar, " " + title, bar, html.replace(/></g, ">\n<"), ""].join("\n");
}

async function getJson(url: string): Promise<any> {
  const r = await fetcher(url, { headers: { "X-Tenant-ID": TENANT } });
  if (!r.ok) throw new Error(`GET ${url} → HTTP ${r.status}: ${await r.text()}`);
  return r.json();
}

async function postAction(actionFqn: string, subject: Reference | null, inputs: Record<string, unknown> = {}): Promise<any> {
  // X-Actor-Roles must be supplied explicitly. The Router used to fall back
  // to a hardcoded invoice.* role set when this header was missing; that
  // fallback has been removed in favour of fail-closed behaviour.
  const r = await fetcher(`${API_BASE_URL}/actions/${actionFqn}`, {
    method: "POST",
    headers: {
      "X-Tenant-ID":   TENANT,
      "X-Actor-Roles": "invoice.creator,invoice.issuer,invoice.canceler,invoice.viewer",
      "Content-Type":  "application/json",
    },
    body: JSON.stringify({ subject, inputs }),
  });
  const json = await r.json();
  return { status: r.status, json };
}

async function main(): Promise<void> {
  const traces: string[] = [];
  const t0 = performance.now();
  const tCheck: Record<string, number> = {};

  // ── 1. Health ─────────────────────────────────────────────────────────────
  const tHealthStart = performance.now();
  const health = await getJson(`${API_BASE_URL}/_health`);
  tCheck["health"] = performance.now() - tHealthStart;
  traces.push(frame("01 — GET /api/_health", JSON.stringify(health, null, 2)));

  // ── 2. GET summary ────────────────────────────────────────────────────────
  const summaryUrl = `${API_BASE_URL}/projections/billing.invoice.summary?locale=en-US&renderer=react.web.v1&acceptSchemaVersions=1.0.0`;
  const tSum1Start = performance.now();
  const schema1: ViewSchema = await getJson(summaryUrl);
  tCheck["GET summary (initial)"] = performance.now() - tSum1Start;
  const items1 = (schema1.data as { items: InvoiceRow[] }).items;

  // ── 3. Render ListView with real schema ───────────────────────────────────
  const html1 = renderToString(
    <AususProvider apiBaseUrl={API_BASE_URL} tenant={TENANT} fetcher={fetcher}>
      <ListView schema={schema1} onRefetch={() => {}} />
    </AususProvider>,
  );
  traces.push(frame("02 — renderToString(ListView) with live schema", html1));

  // ── 4. Issue first invoice ────────────────────────────────────────────────
  const invoice1 = items1[0];
  const issueRef: Reference = { tenantId: TENANT, entityFqn: "billing.invoice", identityHandle: invoice1.id };
  const tIssueStart = performance.now();
  const issued = await postAction("billing.invoice.issue", issueRef, {});
  tCheck["POST issue"] = performance.now() - tIssueStart;
  traces.push(frame("03 — POST issue", JSON.stringify(issued, null, 2)));

  // ── 5. Re-render after issue ──────────────────────────────────────────────
  const schema2: ViewSchema = await getJson(summaryUrl);
  const html2 = renderToString(
    <AususProvider apiBaseUrl={API_BASE_URL} tenant={TENANT} fetcher={fetcher}>
      <ListView schema={schema2} onRefetch={() => {}} />
    </AususProvider>,
  );

  // ── 6. Cancel second invoice ──────────────────────────────────────────────
  const invoice2 = (schema2.data as { items: InvoiceRow[] }).items[1];
  const cancelRef: Reference = { tenantId: TENANT, entityFqn: "billing.invoice", identityHandle: invoice2.id };
  const tCancelStart = performance.now();
  const cancelled = await postAction("billing.invoice.cancel", cancelRef, {});
  tCheck["POST cancel"] = performance.now() - tCancelStart;
  traces.push(frame("04 — POST cancel", JSON.stringify(cancelled, null, 2)));

  // ── 7. Re-render after cancel ─────────────────────────────────────────────
  const schema3: ViewSchema = await getJson(summaryUrl);
  const html3 = renderToString(
    <AususProvider apiBaseUrl={API_BASE_URL} tenant={TENANT} fetcher={fetcher}>
      <ListView schema={schema3} onRefetch={() => {}} />
    </AususProvider>,
  );

  // ── 8. DetailView for the ISSUED invoice ──────────────────────────────────
  const detailUrl = `${API_BASE_URL}/projections/billing.invoice.detail?locale=en-US&renderer=react.web.v1&acceptSchemaVersions=1.0.0&subject=${issueRef.identityHandle}`;
  const tDetStart = performance.now();
  const detailSchema: ViewSchema = await getJson(detailUrl);
  tCheck["GET detail"] = performance.now() - tDetStart;
  const html4 = renderToString(
    <AususProvider apiBaseUrl={API_BASE_URL} tenant={TENANT} fetcher={fetcher}>
      <DetailView schema={detailSchema} subject={issueRef} onRefetch={() => {}} />
    </AususProvider>,
  );
  traces.push(frame("05 — renderToString(DetailView) — ISSUED invoice", html4));

  // ── 9. Stale cancel — expect 409 WorkflowStateMismatch ────────────────────
  const tStaleStart = performance.now();
  const stale = await postAction("billing.invoice.cancel", cancelRef, {});
  tCheck["POST cancel (stale)"] = performance.now() - tStaleStart;
  traces.push(frame("06 — POST cancel on already-CANCELLED row", JSON.stringify(stale, null, 2)));

  // ── 10. Bad tenant — expect 400/403 ───────────────────────────────────────
  const badResp = await fetch(`${API_BASE_URL}/projections/billing.invoice.summary`, {
    headers: {},
  });
  const badBody = await badResp.json();
  traces.push(frame("07 — GET without X-Tenant-ID (expect 400)", `HTTP ${badResp.status}\n${JSON.stringify(badBody, null, 2)}`));

  // ── 11. Schema describes create inputs (the renderer's form metadata) ─────
  const createDescriptor = schema1.actions.find((a: any) => a.name === "create");
  traces.push(frame("08 — create action descriptor inputs",
    JSON.stringify({ fqn: createDescriptor?.fqn, inputs: createDescriptor?.inputs }, null, 2)));

  // ── 12. POST create with the exact payload shape the renderer would build ─
  const createResp = await fetch(`${API_BASE_URL}/actions/billing.invoice.create`, {
    method: "POST",
    headers: {
      "X-Tenant-ID":   TENANT,
      "X-Actor-Roles": "invoice.creator",
      "Content-Type":  "application/json",
    },
    body: JSON.stringify({
      subject: null,
      inputs: {
        number:        "INV-FORM-001",
        customer_name: "Form Co",
        amount:        { amount: "42.50", currency: "USD" },
      },
    }),
  });
  const createJson = await createResp.json();
  traces.push(frame("09 — POST create with renderer-shaped payload",
    `HTTP ${createResp.status}\n${JSON.stringify(createJson, null, 2)}`));

  // ── Dump ──────────────────────────────────────────────────────────────────
  console.log("══════════════════════════════════════════════════════════════════════");
  console.log("  AUSUS L4 — live HTTP integration trace");
  console.log("══════════════════════════════════════════════════════════════════════");
  for (const t of traces) console.log(t);

  // ── Assertions ────────────────────────────────────────────────────────────
  let pass = 0, fail = 0;
  const ok = (n: string, c: boolean) => c
    ? (pass++, console.log("  ✓ " + n))
    : (fail++, console.log("  ✗ " + n));

  console.log("\n── assertions ─────────────────────────────────────────────────────────");
  ok("health 200 + graph hash echoed",            !!health.ok && typeof health.graphHash === "string" && health.graphHash.length > 8);
  ok("summary schemaVersion = 1.0.0",             schema1.schemaVersion === "1.0.0");
  ok("summary contains 2 seeded invoices",        items1.length === 2);
  ok("initial render shows DRAFT for invoice 1",  html1.includes("ausus-badge--gray") && html1.includes(invoice1.id.slice(0,8)));
  ok("issue returns 200 ok",                      issued.status === 200 && issued.json.ok === true);
  ok("after issue: invoice 1 is ISSUED",          html2.includes("ausus-badge--blue") && html2.includes(invoice1.id.slice(0,8)));
  ok("cancel returns 200 ok",                     cancelled.status === 200 && cancelled.json.ok === true);
  ok("after cancel: invoice 2 is CANCELLED",      html3.includes("ausus-badge--red") && html3.includes(invoice2.id.slice(0,8)));
  ok("DetailView renders 8 dt headers",           (html4.match(/<dt>/g) ?? []).length === 8);
  ok("DetailView shows ISSUED badge",             html4.includes("ausus-badge--blue"));
  ok("stale cancel → 409 WorkflowStateMismatch",  stale.status === 409 && stale.json.ok === false && stale.json.error?.kind === "WorkflowStateMismatch");
  ok("missing X-Tenant-ID → 400 BadRequest",      badResp.status === 400 && badBody.error?.kind === "BadRequest");

  ok("create descriptor carries inputs metadata",
     Array.isArray(createDescriptor?.inputs) && (createDescriptor?.inputs?.length ?? 0) === 3);
  ok("create POST with renderer-shaped payload → 200",
     createResp.status === 200 && createJson.ok === true
     && typeof createJson.outputs?.id === "string" && createJson.outputs.id.length === 26);

  // ── Latency report (real timings) ─────────────────────────────────────────
  console.log("\n── measured latency (ms) ──────────────────────────────────────────────");
  for (const [k, v] of Object.entries(tCheck)) {
    console.log(`  ${k.padEnd(28)} ${v.toFixed(2)} ms`);
  }
  const total = performance.now() - t0;
  console.log(`  ${"total wall time".padEnd(28)} ${total.toFixed(2)} ms`);

  console.log(`\nRESULT: passed=${pass} failed=${fail}`);
  if (fail > 0) process.exit(1);
}

main().catch(e => { console.error(e); process.exit(1); });
