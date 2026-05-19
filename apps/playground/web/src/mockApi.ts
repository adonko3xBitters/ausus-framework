/**
 * Mock backend — intercepts fetch() URLs and serves ViewSchema fixtures
 * with workflow-aware mutation on Action invocation.
 *
 * Substitutes RFC-005 Invoker chain server-side: enforces transition source-state
 * matching, returns ConcurrencyConflict-shaped errors when violated.
 */
import { initialInvoices, buildSummarySchema, buildDetailSchema, type InvoiceRow } from "./fixtures";
import type { Fetcher, ActionResult } from "@ausus/renderer-react/types";

const VALID_TRANSITIONS: Record<string, Record<string, string>> = {
  "billing.invoice.issue":  { DRAFT: "ISSUED" },
  "billing.invoice.cancel": { DRAFT: "CANCELLED", ISSUED: "CANCELLED" },
};

export function createMockFetcher(): { fetcher: Fetcher; reset: () => void; state: () => InvoiceRow[] } {
  let invoices: InvoiceRow[] = JSON.parse(JSON.stringify(initialInvoices));

  function makeResponse(body: unknown, ok = true, status = 200): Response {
    return new Response(JSON.stringify(body), {
      status, headers: { "Content-Type": "application/json" },
    });
  }

  const fetcher: Fetcher = async (input, init) => {
    const url = typeof input === "string" ? input : (input as URL).toString();
    const path = url.split("?")[0];
    const params = new URLSearchParams(url.split("?")[1] ?? "");

    // GET /projections/{fqn}
    if (init === undefined || (init.method ?? "GET").toUpperCase() === "GET") {
      const match = path.match(/\/projections\/([^/]+)$/);
      if (match) {
        const fqn = match[1];
        if (fqn === "billing.invoice.summary") {
          return makeResponse(buildSummarySchema(invoices));
        }
        if (fqn === "billing.invoice.detail") {
          const subjectId = params.get("subject");
          const row = invoices.find(r => r.id === subjectId) ?? null;
          return makeResponse(buildDetailSchema(row));
        }
        return makeResponse({ error: { kind: "ProjectionNotFound", message: fqn } }, false, 404);
      }
    }

    // POST /actions/{fqn}
    if ((init?.method ?? "").toUpperCase() === "POST") {
      const match = path.match(/\/actions\/([^/]+)$/);
      if (match) {
        const actionFqn = match[1];
        const body = JSON.parse((init!.body as string) ?? "{}");

        const transitions = VALID_TRANSITIONS[actionFqn];
        if (transitions) {
          const subject = body.subject;
          if (!subject) {
            return makeResponse({
              ok: false,
              error: { kind: "PolicySubjectRequired", message: `${actionFqn} requires subject` },
            } satisfies ActionResult);
          }
          const row = invoices.find(r => r.id === subject.identityHandle);
          if (!row) {
            return makeResponse({
              ok: false,
              error: { kind: "NotFound", message: `Invoice ${subject.identityHandle} not found` },
            } satisfies ActionResult);
          }
          const target = transitions[row.status];
          if (!target) {
            return makeResponse({
              ok: false,
              error: { kind: "WorkflowStateMismatch", message: `Cannot ${actionFqn.split(".").pop()} from ${row.status}` },
            } satisfies ActionResult);
          }
          row.status = target as InvoiceRow["status"];
          row.updated_at = new Date().toISOString();
          if (target === "ISSUED") row.issued_at = row.updated_at;

          return makeResponse({
            ok: true,
            outputs: { status: row.status, issued_at: row.issued_at, updated_at: row.updated_at },
          } satisfies ActionResult);
        }

        return makeResponse({
          ok: false,
          error: { kind: "UnknownAction", message: actionFqn },
        } satisfies ActionResult);
      }
    }

    return makeResponse({ error: { kind: "NotFound", message: `unhandled: ${path}` } }, false, 404);
  };

  return {
    fetcher,
    reset: () => { invoices = JSON.parse(JSON.stringify(initialInvoices)); },
    state: () => invoices,
  };
}
