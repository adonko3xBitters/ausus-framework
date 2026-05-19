import React from "react";
/**
 * AUSUS renderer-react — hardening + edge-case probe.
 *
 * Each probe exercises an adversarial input shape and verifies the
 * renderer either renders SOMETHING sensible or fails in a contained way
 * (never crashes the host React tree).
 *
 *   PREVENTED — renderToString completed AND output matches expectation
 *   UNHANDLED — silently rendered nonsense (no recovery)
 *   CRASHED   — exception propagated out of renderToString
 *
 * Run:  npx tsx apps/playground/web/hardening-trace.tsx
 */
import { renderToString } from "react-dom/server";
import {
  AususProvider, ListView, DetailView, WorkflowBadge, FieldDisplay,
} from "@ausus/renderer-react";
import type { ViewSchema, FieldDescriptor } from "@ausus/renderer-react/types";

const probes: { name: string; outcome: string; detail: string }[] = [];
const fetcher: typeof fetch = async () => new Response("{}");
const provider = (...kids: any[]) => (
  <AususProvider apiBaseUrl="/" tenant="t" fetcher={fetcher}>{kids}</AususProvider>
);

function probe(name: string, fn: () => string, ok: (html: string) => boolean): void {
  try {
    const html = fn();
    const passed = ok(html);
    probes.push({
      name,
      outcome: passed ? "PREVENTED" : "UNHANDLED",
      detail: passed ? html.slice(0, 80).replace(/\s+/g, " ") : `(html did not match: ${html.slice(0,120)})`,
    });
  } catch (e: any) {
    probes.push({ name, outcome: "CRASHED", detail: e?.message ?? String(e) });
  }
}

const baseSchema = (overrides: Partial<ViewSchema> = {}): ViewSchema => ({
  schemaVersion: "1.0.0",
  targetProfile: "react.web.v1",
  metadata: { projection: "demo.list", tenant: "t", entity: "demo.thing" },
  fields:  [],
  actions: [],
  data:    { items: [] },
  ...overrides,
});

// ─── R-01: empty data.items renders "No items" gracefully ────────────────────
probe(
  "R-01 ListView with zero items",
  () => renderToString(provider(<ListView schema={baseSchema({ fields: [{name:"id",label:"ID",type:"string"}] })} onRefetch={() => {}} />)),
  html => html.includes("No items") || html.includes("ausus-empty"),
);

// ─── R-02: missing data.items (totally absent) ───────────────────────────────
probe(
  "R-02 ListView with missing data.items",
  () => renderToString(provider(<ListView schema={baseSchema({ data: {} as any })} onRefetch={() => {}} />)),
  html => html.includes("No items") || html.includes("ausus-empty"),
);

// ─── R-03: data has wrong shape (items === string) ───────────────────────────
probe(
  "R-03 ListView with corrupt data.items (non-array)",
  () => renderToString(provider(<ListView schema={baseSchema({ data: { items: "not-an-array" } as any })} onRefetch={() => {}} />)),
  html => html.includes("ausus-empty") || html.length > 0,   // shouldn't crash; empty-state is best
);

// ─── R-04: field with unknown type ───────────────────────────────────────────
probe(
  "R-04 FieldDisplay with unknown field.type",
  () => renderToString(
    <FieldDisplay
      field={{ name: "x", label: "X", type: "alien_type" as any }}
      value="hello"
    />,
  ),
  html => html.includes("hello") && html.includes("ausus-cell"),
);

// ─── R-05: field value is null ───────────────────────────────────────────────
probe(
  "R-05 FieldDisplay with null value across all types",
  () => {
    const types: FieldDescriptor["type"][] = ["string","integer","enum","money","datetime"];
    return types.map(t => renderToString(
      <FieldDisplay field={{ name: "x", label: "X", type: t }} value={null} />,
    )).join("");
  },
  html => !html.includes("undefined") && !html.includes("NaN"),
);

// ─── R-06: money value is a string instead of {amount,currency} ──────────────
probe(
  "R-06 FieldDisplay money field with string scalar (legacy shape)",
  () => renderToString(
    <FieldDisplay
      field={{ name: "amount", label: "Amount", type: "money", typeOptions: { currency: "EUR" } }}
      value="42.50"
    />,
  ),
  html => html.includes("EUR 42.50"),
);

// ─── R-07: WorkflowBadge with unknown enum value ─────────────────────────────
probe(
  "R-07 WorkflowBadge with unknown enum value falls back to default",
  () => renderToString(<WorkflowBadge value="MYSTERY_STATE" />),
  html => html.includes("MYSTERY_STATE") && html.includes("ausus-badge"),
);

// ─── R-08: WorkflowBadge with null / undefined ───────────────────────────────
probe(
  "R-08 WorkflowBadge with null value",
  () => renderToString(<WorkflowBadge value={null} />),
  html => html.includes("ausus-badge") && (html.includes("?") || html.length > 10),
);

// ─── R-09: ListView with rows containing missing field values ────────────────
probe(
  "R-09 ListView row missing some field values",
  () => renderToString(provider(
    <ListView
      schema={baseSchema({
        fields: [
          { name: "id",     label: "ID",     type: "string" },
          { name: "status", label: "Status", type: "enum" },
          { name: "amount", label: "Amount", type: "money" },
        ],
        data: { items: [{ id: "X-1" /* status + amount missing */ }] },
      })}
      onRefetch={() => {}}
    />,
  )),
  html => html.includes("X-1") && !html.includes("undefined") && !html.includes("[object Object]"),
);

// ─── R-10: DetailView with null data.item ────────────────────────────────────
probe(
  "R-10 DetailView with null item",
  () => renderToString(provider(
    <DetailView
      schema={baseSchema({ fields: [{name:"id",label:"ID",type:"string"}], data: { item: null } as any })}
      subject={{ tenantId: "t", entityFqn: "demo.thing", identityHandle: "x" }}
      onRefetch={() => {}}
    />,
  )),
  html => html.includes("Item not found") || html.includes("ausus-empty"),
);

// ─── R-11: DetailView with item missing every declared field ─────────────────
probe(
  "R-11 DetailView with item missing every field",
  () => renderToString(provider(
    <DetailView
      schema={baseSchema({
        fields: [
          { name: "id",     label: "ID",     type: "string" },
          { name: "status", label: "Status", type: "enum" },
        ],
        data: { item: { unrelated_attr: "🎲" } } as any,
      })}
      subject={{ tenantId: "t", entityFqn: "demo.thing", identityHandle: "x" }}
      onRefetch={() => {}}
    />,
  )),
  html => (html.match(/<dt>/g) ?? []).length === 2 && !html.includes("undefined"),
);

// ─── R-12: Action with empty FQN renders no button (or no crash) ─────────────
probe(
  "R-12 Action with empty fqn",
  () => renderToString(provider(
    <ListView
      schema={baseSchema({
        fields: [{ name: "id", label: "ID", type: "string" }],
        actions: [{ fqn: "", label: "", subjectRequired: false } as any],
        data: { items: [] },
      })}
      onRefetch={() => {}}
    />,
  )),
  // Should NOT render a button labeled "" — best behavior is to skip silently
  // OR render a labelless button. Either way, must not throw.
  html => html.length > 0 && !html.includes("[object Object]"),
);

// ─── R-13: schemaVersion 2.0.0 — handled by useViewSchema, not by ListView ──
//          ListView accepts whatever schema you pass; version gating is
//          the hook's responsibility. Just confirm direct ListView is robust.
probe(
  "R-13 ListView with foreign schemaVersion still renders (gating is hook-side)",
  () => renderToString(provider(
    <ListView
      schema={baseSchema({ schemaVersion: "2.0.0", fields: [{name:"id",label:"ID",type:"string"}], data: { items: [{id:"Z"}] } })}
      onRefetch={() => {}}
    />,
  )),
  html => html.includes("Z"),
);

// ─── R-14: metadata block totally missing ────────────────────────────────────
probe(
  "R-14 ListView with no metadata block",
  () => renderToString(provider(
    <ListView
      schema={{ schemaVersion:"1.0.0", targetProfile:"react.web.v1", fields:[{name:"id",label:"ID",type:"string"}], actions:[], data:{items:[{id:"Y"}]} } as any}
      onRefetch={() => {}}
    />,
  )),
  html => html.includes("Y") && !html.includes("[object"),
);

// ─── R-15: Subject missing on DetailView prop ────────────────────────────────
probe(
  "R-15 DetailView without subject prop — V0 requires it",
  () => {
    try {
      return renderToString(provider(
        // @ts-expect-error intentionally missing required prop
        <DetailView schema={baseSchema({ fields: [], data: { item: {id:"X"} } as any })} onRefetch={() => {}} />,
      ));
    } catch (e: any) {
      return `CRASHED:${e?.message}`;
    }
  },
  // Either renders an empty/fallback OR a contained error message — but
  // must not throw out of renderToString.
  html => !html.startsWith("CRASHED:"),
);

// ─── Summary + assertions ────────────────────────────────────────────────────
const COL = (s: string, n: number) => s.padEnd(n);
console.log("══════════════════════════════════════════════════════════════════════");
console.log("  AUSUS renderer-react — hardening probes");
console.log("══════════════════════════════════════════════════════════════════════");

let pass = 0, fail = 0, crash = 0;
for (const p of probes) {
  if (p.outcome === "PREVENTED") pass++;
  else if (p.outcome === "CRASHED") crash++;
  else fail++;
  console.log(`  ${COL(p.outcome, 9)}  ${COL(p.name, 60)}  ${p.detail.slice(0,80)}`);
}
console.log("");
console.log(`Prevented: ${pass}   Unhandled: ${fail}   Crashed: ${crash}`);
if (fail + crash > 0) process.exit(1);
