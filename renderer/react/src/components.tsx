import React from "react";
import { useState, useEffect, ReactNode } from "react";
import { useAction } from "./hooks.js";
import type {
  ActionDescriptor, FieldDescriptor, Reference, ViewSchema,
} from "./types.js";

// ─────────────────────────────────────────────────────────────────────────────
// WorkflowBadge — colored badge for enum state fields.
// V0 color palette: gray / blue / green / red per RFC-012 default theme.
// ─────────────────────────────────────────────────────────────────────────────

const BADGE_PALETTE: Record<string, string> = {
  DRAFT:     "ausus-badge ausus-badge--gray",
  ISSUED:    "ausus-badge ausus-badge--blue",
  PAID:      "ausus-badge ausus-badge--green",
  CANCELLED: "ausus-badge ausus-badge--red",
};

export function WorkflowBadge({ value }: { value: string | null | undefined }) {
  const v = value ?? "?";
  const cls = BADGE_PALETTE[v] ?? "ausus-badge ausus-badge--default";
  return <span className={cls}>{v}</span>;
}

// ─────────────────────────────────────────────────────────────────────────────
// Field cell — read-only renderer dispatched by field.type and hints.
// ─────────────────────────────────────────────────────────────────────────────

function formatMoney(value: any, opts?: FieldDescriptor["typeOptions"]): string {
  if (value == null) return "";
  const amount =
    typeof value === "object" && value !== null && "amount" in value
      ? String((value as any).amount)
      : String(value);
  const currency =
    (typeof value === "object" && value !== null && "currency" in value && (value as any).currency) ||
    opts?.currency || "";
  const n = Number(amount);
  const formatted = Number.isFinite(n) ? n.toFixed(2) : amount;
  return currency ? `${currency} ${formatted}` : formatted;
}

function isWorkflowStateField(field: FieldDescriptor): boolean {
  // V0 heuristic: enum field named "status" is the workflow state.
  // M2 will use an explicit field.hints.role marker.
  return field.type === "enum" && field.name === "status";
}

export function FieldDisplay({ field, value }: { field: FieldDescriptor; value: any }) {
  if (isWorkflowStateField(field)) return <WorkflowBadge value={String(value ?? "")} />;
  switch (field.type) {
    case "money":    return <span className="ausus-cell ausus-cell--money">{formatMoney(value, field.typeOptions)}</span>;
    case "enum":     return <span className="ausus-cell ausus-cell--enum">{String(value ?? "")}</span>;
    case "datetime": return <span className="ausus-cell ausus-cell--datetime">{value ? String(value) : "—"}</span>;
    case "integer":  return <span className="ausus-cell ausus-cell--num">{String(value ?? "")}</span>;
    default:         return <span className="ausus-cell">{String(value ?? "")}</span>;
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// ActionModal — confirmation + input form for an Action invocation.
// V0: no rich inputs; just confirmation + a basic dispatcher.
// ─────────────────────────────────────────────────────────────────────────────

export function ActionModal(props: {
  action: ActionDescriptor;
  subject?: Reference;
  onClose: () => void;
  onSuccess?: (outputs: Record<string, unknown>) => void;
}) {
  const { invoke, pending, lastError } = useAction(props.action.fqn);
  const hasInputs = (props.action.inputs?.length ?? 0) > 0;
  const [inputs, setInputs] = useState<Record<string, unknown>>({});

  async function handleConfirm() {
    const result = await invoke({ subject: props.subject, inputs });
    if (result.ok) {
      props.onSuccess?.(result.outputs);
      props.onClose();
    }
  }

  return (
    <div className="ausus-modal-backdrop" role="dialog" aria-modal="true">
      <div className="ausus-modal">
        <h2 className="ausus-modal__title">{props.action.label}</h2>
        <p className="ausus-modal__body">
          {props.action.confirmation?.prompt ?? `Confirm ${props.action.label}?`}
        </p>
        {hasInputs && (
          <div className="ausus-modal__inputs">
            {props.action.inputs!.map(input => (
              <label key={input.name} className="ausus-modal__input">
                <span>{input.label}</span>
                <input
                  type="text"
                  value={String(inputs[input.name] ?? "")}
                  onChange={e => setInputs(prev => ({ ...prev, [input.name]: e.target.value }))}
                  disabled={pending}
                />
              </label>
            ))}
          </div>
        )}
        {lastError && (
          <div className="ausus-modal__error">
            <strong>{props.action.label} failed:</strong> {lastError.message}
          </div>
        )}
        <div className="ausus-modal__actions">
          <button type="button" className="ausus-btn ausus-btn--ghost" onClick={props.onClose} disabled={pending}>
            Discard
          </button>
          <button type="button" className="ausus-btn ausus-btn--primary" onClick={handleConfirm} disabled={pending}>
            {pending ? "…" : props.action.label}
          </button>
        </div>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// ActionButton — single button; orchestrates modal flow.
// ─────────────────────────────────────────────────────────────────────────────

function ActionButton(props: {
  action: ActionDescriptor;
  subject?: Reference;
  onSuccess?: (outputs: Record<string, unknown>) => void;
}) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button
        type="button"
        className="ausus-btn ausus-btn--action"
        onClick={() => setOpen(true)}
      >
        {props.action.label}
      </button>
      {open && (
        <ActionModal
          action={props.action}
          subject={props.subject}
          onClose={() => setOpen(false)}
          onSuccess={props.onSuccess}
        />
      )}
    </>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// ActionBar — renders Action buttons for a row or page.
// ─────────────────────────────────────────────────────────────────────────────

function ActionBar(props: {
  actions: ActionDescriptor[];
  subject?: Reference;
  onSuccess?: () => void;
}) {
  return (
    <div className="ausus-action-bar">
      {props.actions.map(a => (
        <ActionButton
          key={a.fqn}
          action={a}
          subject={a.subjectRequired ? props.subject : undefined}
          onSuccess={() => props.onSuccess?.()}
        />
      ))}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// ListView — table-based list rendering.
// ─────────────────────────────────────────────────────────────────────────────

export function ListView(props: {
  schema: ViewSchema;
  onRefetch: () => void;
}) {
  const data = props.schema.data as { items: Record<string, unknown>[] };
  const items = data?.items ?? [];

  const listActions = props.schema.actions.filter(a => !a.subjectRequired);
  const itemActions = props.schema.actions.filter(a =>  a.subjectRequired);

  return (
    <section className="ausus-list">
      <header className="ausus-list__header">
        <h1 className="ausus-list__title">{props.schema.metadata.projection}</h1>
        <ActionBar actions={listActions} onSuccess={props.onRefetch} />
      </header>
      <table className="ausus-table">
        <thead>
          <tr>
            {props.schema.fields.map(f => (
              <th key={f.name}>{f.label}</th>
            ))}
            {itemActions.length > 0 && <th className="ausus-table__actions-col">Actions</th>}
          </tr>
        </thead>
        <tbody>
          {items.length === 0 && (
            <tr>
              <td colSpan={props.schema.fields.length + (itemActions.length > 0 ? 1 : 0)} className="ausus-empty">
                No items.
              </td>
            </tr>
          )}
          {items.map((row, i) => {
            const subject: Reference = {
              tenantId: props.schema.metadata.tenant,
              entityFqn: props.schema.metadata.entity,
              identityHandle: String(row["id"] ?? ""),
            };
            return (
              <tr key={subject.identityHandle || i}>
                {props.schema.fields.map(f => (
                  <td key={f.name}><FieldDisplay field={f} value={row[f.name]} /></td>
                ))}
                {itemActions.length > 0 && (
                  <td className="ausus-table__actions-cell">
                    <ActionBar
                      actions={itemActions}
                      subject={subject}
                      onSuccess={props.onRefetch}
                    />
                  </td>
                )}
              </tr>
            );
          })}
        </tbody>
      </table>
    </section>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// DetailView — read-only field list + action bar.
// ─────────────────────────────────────────────────────────────────────────────

export function DetailView(props: {
  schema: ViewSchema;
  subject: Reference;
  onRefetch: () => void;
}) {
  const data = props.schema.data as { item: Record<string, unknown> | null };
  const item = data?.item;

  if (!item) {
    return <div className="ausus-empty">Item not found.</div>;
  }

  return (
    <section className="ausus-detail">
      <header className="ausus-detail__header">
        <h1 className="ausus-detail__title">{props.schema.metadata.projection}</h1>
      </header>
      <dl className="ausus-detail__list">
        {props.schema.fields.map(f => (
          <div className="ausus-detail__row" key={f.name}>
            <dt>{f.label}</dt>
            <dd><FieldDisplay field={f} value={item[f.name]} /></dd>
          </div>
        ))}
      </dl>
      <footer className="ausus-detail__footer">
        <ActionBar
          actions={props.schema.actions}
          subject={props.subject}
          onSuccess={props.onRefetch}
        />
      </footer>
    </section>
  );
}
