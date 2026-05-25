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
import {
  AususProvider, ListView, DetailView, ActionModal,
  shapeValue, validateInputs, inputDefault, isRequired,
  // ADR-0002 helpers
  initialFor, isUnchanged, buildCreatePayload, buildUpdatePayload,
} from "@ausus/renderer-react";
import type { FieldDescriptor } from "@ausus/renderer-react";
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

  // ───── trace 7: ActionModal renders a create form from action.inputs ─────
  const summaryForForm = buildSummarySchema(mockList.state());
  const createAction   = summaryForForm.actions.find(a => a.name === "create")!;
  const issueAction    = summaryForForm.actions.find(a => a.name === "issue")!;
  const mockForm = createMockFetcher();
  const html7 = renderToString(
    <AususProvider apiBaseUrl="/api" tenant="acme" fetcher={mockForm.fetcher}>
      <ActionModal action={createAction} onClose={() => {}} />
    </AususProvider>,
  );
  traces.push(frame("TRACE 7 — ActionModal renders create form from action.inputs", html7));

  // ───── trace 8: ActionModal with no inputs falls back to confirmation prompt ─────
  const html8 = renderToString(
    <AususProvider apiBaseUrl="/api" tenant="acme" fetcher={mockForm.fetcher}>
      <ActionModal
        action={issueAction}
        subject={{ tenantId: "acme", entityFqn: "billing.invoice", identityHandle: "x" }}
        onClose={() => {}}
      />
    </AususProvider>,
  );
  traces.push(frame("TRACE 8 — ActionModal falls back to confirmation prompt", html8));

  // ───── trace 9: ActionModal prefills from initialValues (ADR-0002 update) ─────
  // The update fixture lives on the DETAIL builder (initialValues is only
  // meaningful for a single rendered subject).
  const detailWithUpdate = buildDetailSchema(mockList.state()[0]);
  const renameAction = detailWithUpdate.actions.find(a => a.name === "rename")!;
  const html9 = renderToString(
    <AususProvider apiBaseUrl="/api" tenant="acme" fetcher={mockForm.fetcher}>
      <ActionModal
        action={renameAction}
        subject={{ tenantId: "acme", entityFqn: "billing.invoice", identityHandle: "x" }}
        onClose={() => {}}
      />
    </AususProvider>,
  );
  traces.push(frame("TRACE 9 — ActionModal prefills from initialValues (update)", html9));

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

  // ── form rendering (TRACE 7) ──
  check("ActionModal renders 3 inputs (number, customer_name, amount)",
        (html7.match(/class="ausus-modal__input"/g) ?? []).length === 3);
  check("Required inputs carry the ausus-required marker",
        (html7.match(/class="ausus-required"/g) ?? []).length === 3);
  check("Money input renders the currency label (USD)",
        html7.includes("ausus-money-input__currency") && html7.includes("USD"));
  check("String input renders maxLength=200 for customer_name",
        html7.includes('maxLength="200"') || html7.includes('maxlength="200"'));

  // ── confirmation fallback (TRACE 8) ──
  check("Inputless action falls back to confirmation prompt",
        html8.includes("Issue this invoice?") && !html8.includes("ausus-modal__inputs"));

  // ── pure helpers (validation + payload shaping) ──
  const createInputs = createAction.inputs ?? [];
  const emptyValues: Record<string, unknown> = {};
  for (const f of createInputs) emptyValues[f.name] = inputDefault(f);
  const errsEmpty = validateInputs(createInputs, emptyValues);
  check("validateInputs flags all 3 required fields when empty",
        Object.keys(errsEmpty).length === 3);

  const okValues: Record<string, unknown> = {
    number: "INV-FORM-001", customer_name: "Form Co", amount: "42.50",
  };
  const errsOk = validateInputs(createInputs, okValues);
  check("validateInputs returns no errors when required fields are filled",
        Object.keys(errsOk).length === 0);

  const moneyInput: FieldDescriptor = createInputs.find(f => f.name === "amount")!;
  const shapedMoney = shapeValue(moneyInput, "42.50") as { amount: string; currency: string };
  check("shapeValue(money) → { amount, currency } shape",
        shapedMoney?.amount === "42.50" && shapedMoney?.currency === "USD");

  const fakeInt: FieldDescriptor = { name: "n", type: "integer", label: "N", required: true };
  check("shapeValue(integer) → truncated number",
        shapeValue(fakeInt, "7.9") === 7);

  // ── ADR-0002 helpers + prefilled form (TRACE 9) ──
  check("ActionModal with initialValues prefills the input (renames already-named row)",
        html9.includes('value="ACME Corporation"'));
  check("update descriptor (rename) does NOT show the confirmation prompt",
        !html9.includes("Confirm Rename?"));

  // initialFor — flatten money compound; otherwise pass-through.
  const stringInput: FieldDescriptor = { name: "title", type: "string", label: "Title", nullable: false };
  check("initialFor(string, 'hello') → 'hello'",
        initialFor(stringInput, "hello") === "hello");
  check("initialFor(string, null) → inputDefault (empty string)",
        initialFor(stringInput, null) === "");

  const moneyInput2: FieldDescriptor = { name: "price", type: "money", label: "Price", typeOptions: { currency: "USD" } };
  check("initialFor(money, {amount, currency}) flattens to amount string for the form",
        initialFor(moneyInput2, { amount: "12.34", currency: "USD" }) === "12.34");

  // isUnchanged — string + money compound.
  check("isUnchanged(string, 'x', 'x') is true",
        isUnchanged(stringInput, "x", "x"));
  check("isUnchanged(string, 'x', 'y') is false",
        !isUnchanged(stringInput, "x", "y"));
  check("isUnchanged(money, {12.34 USD}, {12.34 USD}) is true",
        isUnchanged(moneyInput2, { amount: "12.34", currency: "USD" }, { amount: "12.34", currency: "USD" }));

  // buildCreatePayload — every non-empty value included.
  const createInputsSet: FieldDescriptor[] = [stringInput, moneyInput2];
  const createPayload = buildCreatePayload(createInputsSet, { title: "hi", price: "9.99" });
  check("buildCreatePayload includes every non-empty value",
        createPayload.title === "hi"
        && typeof createPayload.price === "object"
        && (createPayload.price as { amount: string }).amount === "9.99");

  // buildUpdatePayload — diff against initialValues; unchanged keys omitted.
  const updatePayload = buildUpdatePayload(
    createInputsSet,
    { title: "hi", price: "9.99" },           // user values match initial
    { title: "hi", price: { amount: "9.99", currency: "USD" } },
  );
  check("buildUpdatePayload omits unchanged fields entirely",
        Object.keys(updatePayload).length === 0);
  const updatePayload2 = buildUpdatePayload(
    createInputsSet,
    { title: "hi (renamed)", price: "9.99" }, // only title changed
    { title: "hi", price: { amount: "9.99", currency: "USD" } },
  );
  check("buildUpdatePayload includes only the changed field",
        Object.keys(updatePayload2).length === 1 && updatePayload2.title === "hi (renamed)");

  console.log(`\nRESULT: passed=${passed} failed=${failed}`);
  if (failed > 0) process.exit(1);
}

main().catch(e => { console.error(e); process.exit(1); });
