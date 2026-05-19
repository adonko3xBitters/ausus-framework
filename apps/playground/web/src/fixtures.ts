/**
 * ViewSchema fixtures for the demo — hand-extracted from the PHP runtime's
 * ProjectionRenderer output (RFC-004 §3.1 wire format).
 *
 * In production, these come from L4's GET /api/projections/{fqn}.
 */
import type { ViewSchema } from "@ausus/renderer-react/types";

export interface InvoiceRow {
  id: string;
  number: string;
  customer_name: string;
  status: "DRAFT" | "ISSUED" | "CANCELLED";
  amount: number;
  issued_at: string | null;
  created_at: string;
  updated_at: string;
}

export const initialInvoices: InvoiceRow[] = [
  {
    id: "01KRYTV1EK8CZKP1Q7BYE9JZJT",
    number: "INV-2026-001",
    customer_name: "ACME Corporation",
    status: "DRAFT",
    amount: 1500,
    issued_at: null,
    created_at: "2026-05-18T09:12:00Z",
    updated_at: "2026-05-18T09:12:00Z",
  },
  {
    id: "01KRYTV2A9B3C5D7E9F1H3J5K7",
    number: "INV-2026-002",
    customer_name: "Globex Inc",
    status: "ISSUED",
    amount: 850,
    issued_at: "2026-05-19T08:00:00Z",
    created_at: "2026-05-18T10:00:00Z",
    updated_at: "2026-05-19T08:00:00Z",
  },
];

export function buildSummarySchema(items: InvoiceRow[]): ViewSchema {
  return {
    schemaVersion: "1.0.0",
    targetProfile: "react.web.v1",
    metadata: {
      projection: "billing.invoice.summary",
      entity: "billing.invoice",
      tenant: "acme",
      locale: "en-US",
      generatedAt: new Date().toISOString(),
    },
    fields: [
      { name: "id",            type: "identity", label: "ID" },
      { name: "number",        type: "string",   label: "Number",   typeOptions: { maxLength: 32 } },
      { name: "customer_name", type: "string",   label: "Customer", typeOptions: { maxLength: 200 } },
      { name: "status",        type: "enum",     label: "Status",   typeOptions: { options: ["DRAFT","ISSUED","CANCELLED"] } },
      { name: "amount",        type: "money",    label: "Amount",   typeOptions: { currency: "USD" } },
    ],
    actions: [
      { fqn: "billing.invoice.create", name: "create", label: "New invoice", subjectRequired: false,
        confirmation: { required: false } },
      { fqn: "billing.invoice.issue",  name: "issue",  label: "Issue",       subjectRequired: true,
        confirmation: { required: true, prompt: "Issue this invoice?" } },
      { fqn: "billing.invoice.cancel", name: "cancel", label: "Cancel",      subjectRequired: true,
        confirmation: { required: true, prompt: "Cancel this invoice? This cannot be undone." } },
    ],
    filters: [],
    data: { items },
  };
}

export function buildDetailSchema(row: InvoiceRow | null): ViewSchema {
  return {
    schemaVersion: "1.0.0",
    targetProfile: "react.web.v1",
    metadata: {
      projection: "billing.invoice.detail",
      entity: "billing.invoice",
      tenant: "acme",
      locale: "en-US",
      generatedAt: new Date().toISOString(),
    },
    fields: [
      { name: "id",            type: "identity", label: "ID" },
      { name: "number",        type: "string",   label: "Number" },
      { name: "customer_name", type: "string",   label: "Customer" },
      { name: "status",        type: "enum",     label: "Status",     typeOptions: { options: ["DRAFT","ISSUED","CANCELLED"] } },
      { name: "amount",        type: "money",    label: "Amount",     typeOptions: { currency: "USD" } },
      { name: "issued_at",     type: "datetime", label: "Issued at" },
      { name: "created_at",    type: "datetime", label: "Created at" },
      { name: "updated_at",    type: "datetime", label: "Updated at" },
    ],
    actions: [
      { fqn: "billing.invoice.issue",  name: "issue",  label: "Issue",  subjectRequired: true,
        confirmation: { required: true, prompt: "Issue this invoice?" } },
      { fqn: "billing.invoice.cancel", name: "cancel", label: "Cancel", subjectRequired: true,
        confirmation: { required: true, prompt: "Cancel this invoice? This cannot be undone." } },
    ],
    filters: [],
    data: { item: row as unknown as Record<string, unknown> | null },
  };
}
