import React from "react";
/**
 * Custom-renderer consumer — a consumer who wants a *card grid* UI instead
 * of the default ListView's table. Demonstrates that the renderer's public
 * surface (AususProvider, useAction, FieldDisplay + the ViewSchema types)
 * is enough to build alternative UIs WITHOUT touching ListView/DetailView
 * and WITHOUT touching renderer-react source.
 *
 * DX measurement:
 *   - distinct framework imports                   : 5  (Provider + 2 types + FieldDisplay + WorkflowBadge)
 *   - LOC for the custom component                 : ~45
 *   - friction events                              : see docs/CONSUMER-DX-PASS.md §3
 *
 * Run:  npx tsx apps/consumer-custom-renderer/run.tsx
 */
import { renderToString } from "react-dom/server";
import {
  AususProvider, FieldDisplay, WorkflowBadge,
} from "@ausus/renderer-react";
import type {
  ViewSchema, FieldDescriptor,
} from "@ausus/renderer-react/types";

// ── consumer-authored card-grid component ─────────────────────────────────
//
// The point: it reuses FieldDisplay for type-dispatching field rendering,
// but composes its own outer layout. No subclassing, no overrides — just
// composition with the framework's public exports.
//
function CardGrid(props: { schema: ViewSchema }) {
  const items  = ("items" in (props.schema.data ?? {})
    ? (props.schema.data as { items: Record<string, unknown>[] }).items
    : []);
  const fields = props.schema.fields;

  return (
    <section className="customer-card-grid">
      <h2 style={{ fontFamily: "system-ui" }}>
        {props.schema.metadata.projection} — {items.length} item(s)
      </h2>
      <div className="customer-card-grid__cards" style={{ display: "grid", gap: 12 }}>
        {items.map((row, i) => (
          <article
            key={String(row["id"] ?? i)}
            style={{ border: "1px solid #ddd", borderRadius: 8, padding: 12 }}
          >
            {fields.map(f => (
              <div key={f.name} style={{ display: "flex", gap: 8, fontFamily: "system-ui" }}>
                <strong style={{ minWidth: 120 }}>{f.label}</strong>
                {/* Reuse the renderer-react FieldDisplay for type-aware values */}
                <FieldDisplay field={f as FieldDescriptor} value={row[f.name]} />
              </div>
            ))}
          </article>
        ))}
      </div>
    </section>
  );
}

// ── fixture (would normally come from useViewSchema or a server fetch) ────
const schema: ViewSchema = {
  schemaVersion: "1.0.0",
  targetProfile: "react.web.v1",
  metadata: { projection: "billing.invoice.summary", tenant: "acme", entity: "billing.invoice", locale: "en-US", generatedAt: "2026-05-19T00:00:00Z" },
  fields: [
    { name: "id",            label: "ID",       type: "string" },
    { name: "number",        label: "Number",   type: "string" },
    { name: "customer_name", label: "Customer", type: "string" },
    { name: "status",        label: "Status",   type: "enum" },
    { name: "amount",        label: "Amount",   type: "money", typeOptions: { currency: "USD" } },
  ],
  actions: [],
  filters: [],
  data: { items: [
    { id: "01KS0..1", number: "INV-2026-001", customer_name: "ACME Co",       status: "DRAFT",     amount: { amount: "1500.00", currency: "USD" } },
    { id: "01KS0..2", number: "INV-2026-002", customer_name: "Globex Inc.",   status: "ISSUED",    amount: { amount: "2750.00", currency: "USD" } },
    { id: "01KS0..3", number: "INV-2026-003", customer_name: "Initech LLC",   status: "PAID",      amount: { amount: "9999.99", currency: "USD" } },
    { id: "01KS0..4", number: "INV-2026-004", customer_name: "Stark Industries", status: "CANCELLED", amount: { amount: "120.00", currency: "USD" } },
  ]},
};

// ── render ─────────────────────────────────────────────────────────────────
const fetcher: typeof fetch = async () => new Response("{}");
const html = renderToString(
  <AususProvider apiBaseUrl="/" tenant="acme" fetcher={fetcher}>
    <CardGrid schema={schema} />
  </AususProvider>,
);

// ── lightweight assertions ─────────────────────────────────────────────────
let pass = 0, fail = 0;
const ok = (n: string, c: boolean) => c ? (pass++, console.log("  ✓ " + n)) : (fail++, console.log("  ✗ " + n));

console.log("consumer-custom-renderer — output:");
console.log("─".repeat(72));
console.log(html.slice(0, 200) + " …");
console.log("─".repeat(72));
console.log("");

ok("custom <section.customer-card-grid> wrapper present",  html.includes('class="customer-card-grid"'));
ok("4 <article> cards rendered",                            (html.match(/<article/g) ?? []).length === 4);
ok("FieldDisplay reused — DRAFT badge gray",                html.includes("ausus-badge--gray")  && html.includes("DRAFT"));
ok("FieldDisplay reused — ISSUED badge blue",               html.includes("ausus-badge--blue") && html.includes("ISSUED"));
ok("FieldDisplay reused — PAID badge green",                html.includes("ausus-badge--green") && html.includes("PAID"));
ok("FieldDisplay reused — CANCELLED badge red",             html.includes("ausus-badge--red")  && html.includes("CANCELLED"));
ok("FieldDisplay reused — money format applied",            html.includes("USD 1500.00"));
ok("custom inline styles preserved (display:grid)",         html.includes("display:grid"));

// Standalone WorkflowBadge — proves it's composable outside any ListView
const badge = renderToString(<WorkflowBadge value="PAID" />);
ok("WorkflowBadge usable standalone outside Provider",      badge.includes("ausus-badge--green"));

console.log(`\nRESULT: passed=${pass} failed=${fail}`);
if (fail > 0) process.exit(1);
