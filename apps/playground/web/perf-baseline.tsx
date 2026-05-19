import React from "react";
/**
 * AUSUS — React SSR + memory perf baseline.
 *
 * Measures, with performance.now() (sub-ms precision):
 *   1. renderToString(ListView, schema with N items)  N = 10, 100, 1000, 10000
 *   2. renderToString(DetailView, single item)
 *   3. renderToString(WorkflowBadge)
 *   4. Node heap delta + HTML size per scale step
 *
 * Variance: each metric runs `iters` times, reports min/p50/p95/max in ms.
 *
 * Run:   npx tsx apps/playground/web/perf-baseline.tsx
 */
import { renderToString } from "react-dom/server";
import { AususProvider, ListView, DetailView, WorkflowBadge } from "@ausus/renderer-react";
import type { ViewSchema } from "@ausus/renderer-react/types";

const ITERS  = parseInt(process.env.ITERS  ?? "100", 10);
const WARMUP = parseInt(process.env.WARMUP ?? "10",  10);

function percentile(sorted: number[], p: number): number {
  if (sorted.length === 0) return 0;
  const k = (p / 100) * (sorted.length - 1);
  const f = Math.floor(k), c = Math.ceil(k);
  if (f === c) return sorted[f];
  return sorted[f] + (sorted[c] - sorted[f]) * (k - f);
}

interface Sample { label: string; iters: number; min: number; p50: number; p95: number; max: number; mean: number; bytes?: number }

function bench(label: string, iters: number, warmup: number, fn: () => string): Sample {
  let bytes = 0;
  for (let i = 0; i < warmup; i++) fn();
  const t: number[] = [];
  for (let i = 0; i < iters; i++) {
    const t0 = performance.now();
    const html = fn();
    t.push(performance.now() - t0);
    if (i === 0) bytes = html.length;
  }
  t.sort((a, b) => a - b);
  return {
    label, iters, bytes,
    min:  t[0], p50: percentile(t, 50), p95: percentile(t, 95),
    max:  t[t.length-1], mean: t.reduce((a,b)=>a+b,0) / t.length,
  };
}

function makeListSchema(n: number): ViewSchema {
  const items = Array.from({ length: n }, (_, i) => ({
    id:            `01KRZ${(i+1000000).toString(36).toUpperCase().padStart(21, "0").slice(-21)}`,
    number:        `INV-${String(i).padStart(6, "0")}`,
    customer_name: `Customer ${i}`,
    status:        (i % 4 === 0 ? "DRAFT" : i % 4 === 1 ? "ISSUED" : i % 4 === 2 ? "PAID" : "CANCELLED"),
    amount:        { amount: String(100 + i * 17), currency: "USD" },
  }));
  return {
    schemaVersion: "1.0.0",
    targetProfile: "react.web.v1",
    metadata: { projection: "perf.list", tenant: "perf", entity: "perf.thing", locale: "en-US", generatedAt: "" },
    fields: [
      { name: "id",            label: "ID",       type: "string" },
      { name: "number",        label: "Number",   type: "string" },
      { name: "customer_name", label: "Customer", type: "string" },
      { name: "status",        label: "Status",   type: "enum" },
      { name: "amount",        label: "Amount",   type: "money", typeOptions: { currency: "USD" } },
    ],
    actions: [
      { fqn: "perf.thing.issue",  name: "issue",  label: "Issue",  subjectRequired: true },
      { fqn: "perf.thing.cancel", name: "cancel", label: "Cancel", subjectRequired: true },
    ],
    filters: [],
    data: { items },
  };
}

function makeDetailSchema(): ViewSchema {
  return {
    schemaVersion: "1.0.0",
    targetProfile: "react.web.v1",
    metadata: { projection: "perf.detail", tenant: "perf", entity: "perf.thing", locale: "en-US", generatedAt: "" },
    fields: [
      { name: "id",            label: "ID",         type: "string" },
      { name: "number",        label: "Number",     type: "string" },
      { name: "customer_name", label: "Customer",   type: "string" },
      { name: "status",        label: "Status",     type: "enum" },
      { name: "amount",        label: "Amount",     type: "money", typeOptions: { currency: "USD" } },
      { name: "created_at",    label: "Created",    type: "datetime" },
      { name: "updated_at",    label: "Updated",    type: "datetime" },
      { name: "issued_at",     label: "Issued",     type: "datetime" },
    ],
    actions: [
      { fqn: "perf.thing.cancel", name: "cancel", label: "Cancel", subjectRequired: true },
    ],
    filters: [],
    data: { item: {
      id: "01KRZTEST",
      number: "INV-DETAIL-1",
      customer_name: "Detail customer",
      status: "ISSUED",
      amount: { amount: "1500.00", currency: "USD" },
      created_at: "2026-05-19T00:00:00Z",
      updated_at: "2026-05-19T00:00:00Z",
      issued_at:  "2026-05-19T01:00:00Z",
    }},
  };
}

const fetcher: typeof fetch = async () => new Response("{}");
const wrap = (children: React.ReactElement) =>
  <AususProvider apiBaseUrl="/" tenant="perf" fetcher={fetcher}>{children}</AususProvider>;

// ════════════════════════════════════════════════════════════════════════════
console.log(`AUSUS perf baseline — Node ${process.version}`);
console.log("═".repeat(120));
console.log(`config: iters=${ITERS} warmup=${WARMUP}  performance.now=ms-precision`);
console.log("");

const results: Sample[] = [];
const heap0 = process.memoryUsage().heapUsed;

function row(s: Sample) {
  return `  ${s.label.padEnd(46)}  n=${String(s.iters).padEnd(4)}  min ${s.min.toFixed(3).padStart(8)} ms  p50 ${s.p50.toFixed(3).padStart(8)} ms  p95 ${s.p95.toFixed(3).padStart(8)} ms  max ${s.max.toFixed(3).padStart(9)} ms  mean ${s.mean.toFixed(3).padStart(8)} ms  html=${(s.bytes ?? 0).toLocaleString()} B`;
}

console.log("── 1. renderToString(ListView, schema with N items) ─────────────────────────────────────────────────────────────────────");
for (const n of [10, 100, 1000, 10000]) {
  const schema = makeListSchema(n);
  const r = bench(`ListView N=${n.toLocaleString()}`, n >= 1000 ? Math.max(20, Math.floor(ITERS / 5)) : ITERS, WARMUP, () =>
    renderToString(wrap(<ListView schema={schema} onRefetch={() => {}} />))
  );
  results.push(r);
  console.log(row(r));
}
console.log("");

console.log("── 2. renderToString(DetailView, single item) ────────────────────────────────────────────────────────────────────────────");
{
  const schema = makeDetailSchema();
  const subject = { tenantId: "perf", entityFqn: "perf.thing", identityHandle: "01KRZTEST" };
  const r = bench("DetailView (8 fields)", ITERS, WARMUP, () =>
    renderToString(wrap(<DetailView schema={schema} subject={subject} onRefetch={() => {}} />))
  );
  results.push(r);
  console.log(row(r));
}
console.log("");

console.log("── 3. renderToString(WorkflowBadge) ──────────────────────────────────────────────────────────────────────────────────────");
{
  const r = bench("WorkflowBadge (single)", 1000, 20, () =>
    renderToString(<WorkflowBadge value="PAID" />)
  );
  results.push(r);
  console.log(row(r));
}
console.log("");

// ════════════════════════════════════════════════════════════════════════════
// Memory delta after all benches
const heap1 = process.memoryUsage().heapUsed;
const rss1  = process.memoryUsage().rss;
console.log("── 4. Memory footprint ───────────────────────────────────────────────────────────────────────────────────────────────────");
console.log(`  baseline heap=${(heap0/1048576).toFixed(2)} MB   post-bench heap=${(heap1/1048576).toFixed(2)} MB   delta=${((heap1-heap0)/1048576).toFixed(2)} MB   rss=${(rss1/1048576).toFixed(2)} MB`);
console.log("");

// ════════════════════════════════════════════════════════════════════════════
console.log("═".repeat(120));
console.log("  SUMMARY (p50 ms · HTML bytes)");
console.log("═".repeat(120));
for (const r of results) {
  console.log(`  ${r.label.padEnd(58)}  p50 ${r.p50.toFixed(3).padStart(8)} ms   html=${(r.bytes ?? 0).toLocaleString()} B   (n=${r.iters})`);
}
